<?php

/**
 * Integriq LtiLaunchService.
 *
 * OIDC third-party-initiated login, signed-JWT launch validation, Platform-
 * role launch initiation, and Deep Linking 2.0 (both directions) for the
 * LTI 1.3 / LTI Advantage adapter.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/lti-platform/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Lti;

use DateTime;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\PS384;
use Jose\Component\Signature\Algorithm\PS512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWS;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\PemKeyFile;
use OCA\Integriq\Service\PemKeyFileRequest;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Core LTI 1.3 launch protocol implementation (both roles).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *
 * @spec openspec/specs/lti-platform/spec.md
 */
class LtiLaunchService implements LtiLaunchServiceInterface {

	/**
	 * LTI 1.3 claim URIs.
	 *
	 * @var string
	 */
	public const CLAIM_DEPLOYMENT_ID = 'https://purl.imsglobal.org/spec/lti/claim/deployment_id';
	public const CLAIM_MESSAGE_TYPE = 'https://purl.imsglobal.org/spec/lti/claim/message_type';
	public const CLAIM_VERSION = 'https://purl.imsglobal.org/spec/lti/claim/version';
	public const CLAIM_DL_SETTINGS = 'https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings';
	public const CLAIM_DL_ITEMS = 'https://purl.imsglobal.org/spec/lti-dl/claim/content_items';
	public const CLAIM_RESOURCE_LINK = 'https://purl.imsglobal.org/spec/lti/claim/resource_link';

	/**
	 * The only LTI version this adapter recognises (1.3-only, per design.md non-goals).
	 *
	 * @var string
	 */
	public const LTI_VERSION = '1.3.0';

	/**
	 * Recognised `message_type` values.
	 *
	 * @var string[]
	 */
	public const RECOGNISED_MESSAGE_TYPES = [
		'LtiResourceLinkRequest',
		'LtiDeepLinkingRequest',
		'LtiDeepLinkingResponse',
	];

	/**
	 * Nonce TTL in seconds (10 minutes — REQ-LTI-004).
	 *
	 * @var integer
	 */
	public const NONCE_TTL_SECONDS = 600;

	/**
	 * Launch-reference TTL in seconds (short-lived, single-use handoff to the consuming app).
	 *
	 * @var integer
	 */
	public const LAUNCH_REFERENCE_TTL_SECONDS = 300;

	/**
	 * Distributed cache for nonce single-use tracking (`integriq.lti.nonce`).
	 *
	 * @var ICache
	 */
	private readonly ICache $nonceCache;

	/**
	 * Distributed cache for short-lived, single-use launch references.
	 *
	 * @var ICache
	 */
	private readonly ICache $launchCache;

	/**
	 * Constructor.
	 *
	 * @param LtiRegistrationResolverService $resolver Registration/deployment lookups.
	 * @param OpenRegisterCredentialBridge $authorizationService Reused for iat/exp/nbf validation (no reimplementation).
	 * @param LtiJwksResolverService $jwksResolver External JWKS resolution (REQ-LTI-003).
	 * @param LtiKeyService $keyService This instance's own signing keys.
	 * @param ICacheFactory $cacheFactory Cache factory for nonce + launch-reference storage.
	 * @param LoggerInterface $logger Logger (never logs key material or raw id_token payloads).
	 */
	public function __construct(
		private readonly LtiRegistrationResolverService $resolver,
		private readonly OpenRegisterCredentialBridge $authorizationService,
		private readonly LtiJwksResolverService $jwksResolver,
		private readonly LtiKeyService $keyService,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->nonceCache = $cacheFactory->createDistributed('integriq.lti.nonce');
		$this->launchCache = $cacheFactory->createDistributed('integriq.lti.launch');

	}//end __construct()

	// =========================================================================
	// REQ-LTI-004 — OIDC third-party-initiated login (Tool role)
	// =========================================================================

	/**
	 * Validate a login-initiation request and build the redirect to the
	 * platform's authorization endpoint.
	 *
	 * @param string $deploymentUuid The `lti_deployment` route parameter.
	 * @param array $params Request params: `iss`, `login_hint`, `target_link_uri`, `client_id` (optional).
	 * @param string $launchUrl The absolute URL of this instance's launch route for this deployment.
	 *
	 * @return array{redirectUrl: string, state: string, nonce: string, registrationUuid: string}
	 *
	 * @throws LtiValidationException HTTP 400 when `iss`/`client_id` matches no registered `lti_platform`,
	 *                                or `login_hint`/`target_link_uri` is missing. No redirect is built
	 *                                and no nonce is persisted on failure.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function initiateLogin(string $deploymentUuid, array $params, string $launchUrl): array {
		$issuer = ($params['iss'] ?? '');
		if ($issuer === '') {
			throw new LtiValidationException(message: 'Missing iss', details: [], httpStatus: 400);
		}

		if (empty($params['login_hint'] ?? null) === true || empty($params['target_link_uri'] ?? null) === true) {
			throw new LtiValidationException(
				message: 'Missing login_hint or target_link_uri',
				details: [],
				httpStatus: 400
			);
		}

		$clientId = ($params['client_id'] ?? null);
		$platform = $this->resolver->findPlatformByIssuer(issuer: $issuer, clientId: $clientId);

		if ($platform === null) {
			// Reject-before-redirect (REQ-LTI-004 scenario): no redirect issued, no nonce persisted.
			throw new LtiValidationException(
				message: 'Unregistered LTI platform issuer/client_id',
				details: ['iss' => $issuer],
				httpStatus: 400
			);
		}

		$deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
		if ($deployment === null) {
			throw new LtiValidationException(message: 'Unknown LTI deployment', details: [], httpStatus: 400);
		}

		$platformData = $platform->getObject();
		$state = bin2hex(random_bytes(32));
		$nonce = bin2hex(random_bytes(32));

		$this->nonceCache->set(
			'nonce:' . $platform->getUuid() . ':' . $nonce,
			1,
			self::NONCE_TTL_SECONDS
		);

		$queryParams = [
			'scope' => 'openid',
			'response_type' => 'id_token',
			'response_mode' => 'form_post',
			'client_id' => ($platformData['clientId'] ?? ''),
			'redirect_uri' => $launchUrl,
			'state' => $state,
			'nonce' => $nonce,
			'prompt' => 'none',
			'login_hint' => $params['login_hint'],
		];
		if (empty($params['lti_message_hint'] ?? null) === false) {
			$queryParams['lti_message_hint'] = $params['lti_message_hint'];
		}

		$redirectUrl = ($platformData['authLoginUrl'] ?? '') . '?' . http_build_query($queryParams);

		return [
			'redirectUrl' => $redirectUrl,
			'state' => $state,
			'nonce' => $nonce,
			'registrationUuid' => $platform->getUuid(),
		];

	}//end initiateLogin()

	// =========================================================================
	// REQ-LTI-005 — Launch id_token validation (Tool role)
	// =========================================================================

	/**
	 * Validate a posted launch `id_token` and, on success, mint a short-lived
	 * single-use launch reference for the consuming app's redirect target.
	 *
	 * @param string $idToken The posted `id_token` JWS (compact serialization).
	 * @param string $deploymentUuid The `lti_deployment` route parameter.
	 * @param string|null $cookieState The `state` value round-tripped via the login's `SameSite=None` cookie; required.
	 * @param string|null $presentedState The `state` value posted alongside the `id_token`; required.
	 *
	 * @return array{launchReference: string, redirectUrl: string}
	 *
	 * @throws LtiValidationException On any validation failure (HTTP 400/401, no partial-trust fallback),
	 *                                including a missing or mismatching state (401).
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function validateLaunch(
		string $idToken,
		string $deploymentUuid,
		?string $cookieState,
		?string $presentedState,
	): array {
		$deployment = $this->requireDeployment(deploymentUuid: $deploymentUuid);
		$this->assertStatePresent(cookieState: $cookieState, presentedState: $presentedState);
		$this->assertStateMatches(cookieState: $cookieState, presentedState: $presentedState);

		[$payload, $platform] = $this->verifyIdTokenSignature(idToken: $idToken, registrationType: 'lti_platform');
		$this->assertTimingAndAudience(payload: $payload, platform: $platform);

		$run = new LaunchValidationRun(payload: $payload, platform: $platform, deployment: $deployment);
		$this->consumeNonce(run: $run);
		$matchedDeployment = $this->matchDeploymentClaim(run: $run);
		$this->assertMessageTypeAndVersion(payload: $payload);

		return $this->issueLaunchReference(run: $run, matchedDeployment: $matchedDeployment);
	}//end validateLaunch()

	/**
	 * The deployment the launch was posted to.
	 *
	 * @param string $deploymentUuid The `lti_deployment` route parameter.
	 *
	 * @return ObjectEntity The deployment.
	 *
	 * @throws LtiValidationException When no such deployment exists (400).
	 */
	private function requireDeployment(string $deploymentUuid): ObjectEntity {
		$deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
		if ($deployment === null) {
			throw new LtiValidationException(message: 'Unknown LTI deployment', details: [], httpStatus: 400);
		}

		return $deployment;

	}//end requireDeployment()

	/**
	 * State is mandatory: it binds the launch to the browser that started the login (OIDC login-CSRF).
	 *
	 * @param string|null $cookieState    The `state` round-tripped via the login cookie.
	 * @param string|null $presentedState The `state` posted alongside the `id_token`.
	 *
	 * @return void
	 *
	 * @throws LtiValidationException When either is missing (401).
	 */
	private function assertStatePresent(?string $cookieState, ?string $presentedState): void {
		if ($this->isBlankState(state: $cookieState) === true || $this->isBlankState(state: $presentedState) === true) {
			throw new LtiValidationException(message: 'Missing state', details: [], httpStatus: 401);
		}

	}//end assertStatePresent()

	/**
	 * The cookie state and the posted state must be the same value.
	 *
	 * @param string|null $cookieState    The `state` round-tripped via the login cookie (present).
	 * @param string|null $presentedState The `state` posted alongside the `id_token` (present).
	 *
	 * @return void
	 *
	 * @throws LtiValidationException When they differ (401).
	 */
	private function assertStateMatches(?string $cookieState, ?string $presentedState): void {
		if (hash_equals((string)$cookieState, (string)$presentedState) === false) {
			throw new LtiValidationException(message: 'State mismatch', details: [], httpStatus: 401);
		}

	}//end assertStateMatches()

	/**
	 * Iat/exp/nbf/jti: OpenRegister's check through the bridge, no reimplementation (design.md D6);
	 * then the audience must name the platform registration's client id.
	 *
	 * @param array        $payload  The verified id_token payload.
	 * @param ObjectEntity $platform The platform registration the token was verified against.
	 *
	 * @return void
	 *
	 * @throws LtiValidationException When the timing or the audience is refused.
	 */
	private function assertTimingAndAudience(array $payload, ObjectEntity $platform): void {
		$this->validateTiming(payload: $payload);

		$platformData = $platform->getObject();
		$this->assertAudience(payload: $payload, expectedClientId: ($platformData['clientId'] ?? ''));

	}//end assertTimingAndAudience()

	/**
	 * Whether a state value is absent.
	 *
	 * @param string|null $state The state value.
	 *
	 * @return boolean True when null or empty.
	 */
	private function isBlankState(?string $state): bool {
		return ($state === null || $state === '');

	}//end isBlankState()

	/**
	 * Nonce: present + atomic single-use consume (get-then-delete).
	 *
	 * @param LaunchValidationRun $run The launch being validated.
	 *
	 * @return void
	 *
	 * @throws LtiValidationException When the nonce is missing (400) or unknown/already consumed (401).
	 */
	private function consumeNonce(LaunchValidationRun $run): void {
		$nonce = ($run->payload['nonce'] ?? null);
		if (empty($nonce) === true) {
			throw new LtiValidationException(message: 'Missing nonce claim', details: [], httpStatus: 400);
		}

		$nonceKey = 'nonce:' . $run->platform->getUuid() . ':' . $nonce;
		if ($this->nonceCache->get($nonceKey) === null) {
			throw new LtiValidationException(message: 'Nonce already consumed or unknown (replay)', details: [], httpStatus: 401);
		}

		$this->nonceCache->remove($nonceKey);

	}//end consumeNonce()

	/**
	 * Deployment_id claim must match a registered lti_deployment under this platform.
	 *
	 * @param LaunchValidationRun $run The launch being validated.
	 *
	 * @return ObjectEntity The matched deployment (the one the launch was posted to).
	 *
	 * @throws LtiValidationException When the claim is missing or names another or no deployment (400).
	 */
	private function matchDeploymentClaim(LaunchValidationRun $run): ObjectEntity {
		$deploymentIdClaim = $this->requireDeploymentIdClaim(payload: $run->payload);
		$matchedDeployment = $this->resolver->findDeployment(
			registrationType: 'lti_platform',
			registrationUuid: $run->platform->getUuid(),
			deploymentIdClaim: $deploymentIdClaim
		);
		if ($matchedDeployment === null || $matchedDeployment->getUuid() !== $run->deployment->getUuid()) {
			throw new LtiValidationException(
				message: 'deployment_id claim not registered under the resolved platform',
				details: ['deploymentId' => $deploymentIdClaim],
				httpStatus: 400
			);
		}

		return $matchedDeployment;

	}//end matchDeploymentClaim()

	/**
	 * The payload's deployment_id claim; it is required.
	 *
	 * @param array $payload The verified id_token payload.
	 *
	 * @return mixed The claim value.
	 *
	 * @throws LtiValidationException When the claim is missing or empty (400).
	 */
	private function requireDeploymentIdClaim(array $payload): mixed {
		$deploymentIdClaim = ($payload[self::CLAIM_DEPLOYMENT_ID] ?? null);
		if (empty($deploymentIdClaim) === true) {
			throw new LtiValidationException(message: 'Missing deployment_id claim', details: [], httpStatus: 400);
		}

		return $deploymentIdClaim;

	}//end requireDeploymentIdClaim()

	/**
	 * Mint the single-use launch reference and build the consuming app's redirect URL.
	 *
	 * @param LaunchValidationRun $run               The validated launch.
	 * @param ObjectEntity        $matchedDeployment The deployment the claim matched.
	 *
	 * @return array{launchReference: string, redirectUrl: string}
	 */
	private function issueLaunchReference(LaunchValidationRun $run, ObjectEntity $matchedDeployment): array {
		$deploymentData = $matchedDeployment->getObject();
		$launchReference = bin2hex(random_bytes(24));
		$this->launchCache->set(
			'launch:' . $launchReference,
			json_encode(['claims' => $run->payload, 'deploymentUuid' => $matchedDeployment->getUuid()]),
			self::LAUNCH_REFERENCE_TTL_SECONDS
		);

		$this->logger->info(
			'LtiLaunchService: launch validated',
			['registrationUuid' => $run->platform->getUuid(), 'deploymentUuid' => $matchedDeployment->getUuid()]
		);

		return [
			'launchReference' => $launchReference,
			'redirectUrl' => $this->launchRedirectUrl(launchTargetUrl: ($deploymentData['launchTargetUrl'] ?? ''), launchReference: $launchReference),
		];

	}//end issueLaunchReference()

	/**
	 * The launch target URL with the `lti_launch` reference appended.
	 *
	 * @param string $launchTargetUrl The deployment's launch target URL.
	 * @param string $launchReference The minted launch reference.
	 *
	 * @return string The redirect URL.
	 */
	private function launchRedirectUrl(string $launchTargetUrl, string $launchReference): string {
		if (str_contains($launchTargetUrl, '?') === true) {
			$separator = '&';
		} else {
			$separator = '?';
		}

		return $launchTargetUrl . $separator . 'lti_launch=' . $launchReference;

	}//end launchRedirectUrl()

	/**
	 * Resolve a previously-validated launch reference to its claims (single use).
	 *
	 * @param string $launchReference The reference minted by {@see validateLaunch()}.
	 *
	 * @return array|null The cached `{claims, deploymentUuid}` payload, or null if unknown/expired/already consumed.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function consumeLaunchReference(string $launchReference): ?array {
		$key = 'launch:' . $launchReference;
		$cached = $this->launchCache->get($key);
		if (is_string($cached) === false) {
			return null;
		}

		$this->launchCache->remove($key);

		return json_decode($cached, true);
	}//end consumeLaunchReference()

	// =========================================================================
	// REQ-LTI-013 — resource-link-to-consuming-app-object mapping seam
	// =========================================================================

	/**
	 * Resolve which consuming-app object a validated launch's
	 * `resource_link.id` claim maps to, per a deployment's configured
	 * `resourceLinkMappings[]` (REQ-LTI-013).
	 *
	 * Resolution order: exact `resourceLinkId` match, then the
	 * empty-`resourceLinkId` deployment-default entry, then `null`. This
	 * method only resolves *which* target a consuming app should read/write
	 * — it never performs the register/schema read/write itself (design.md
	 * D4 — mirrors `gradeSink`/`rosterSource`'s "route, don't own" shape).
	 *
	 * @param string $deploymentUuid The `lti_deployment` this launch resolved to.
	 * @param string $resourceLinkId The launch's `resource_link.id` claim value (may be empty).
	 *
	 * @return array{targetType: string, targetId: string}|null The resolved target, or null when unconfigured/no match.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function resolveResourceMapping(string $deploymentUuid, string $resourceLinkId): ?array {
		$deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
		if ($deployment === null) {
			return null;
		}

		$mappings = ($deployment->getObject()['resourceLinkMappings'] ?? []);

		foreach ($mappings as $mapping) {
			if (($mapping['resourceLinkId'] ?? null) === $resourceLinkId) {
				return [
					'targetType' => ($mapping['targetType'] ?? null),
					'targetId' => ($mapping['targetId'] ?? null),
				];
			}
		}

		foreach ($mappings as $mapping) {
			if (($mapping['resourceLinkId'] ?? null) === '') {
				return [
					'targetType' => ($mapping['targetType'] ?? null),
					'targetId' => ($mapping['targetId'] ?? null),
				];
			}
		}

		return null;
	}//end resolveResourceMapping()

	// =========================================================================
	// REQ-LTI-006 — Platform-role launch initiation + Deep Linking (both directions)
	// =========================================================================

	/**
	 * Build a signed `id_token` launching a registered `lti_tool` (Platform role).
	 *
	 * @param string $deploymentUuid The `lti_deployment` (must reference an `lti_tool`).
	 * @param string $platformIssuer This instance's own issuer identity (a stable base URL).
	 * @param string $subject The launched user's subject identifier.
	 * @param string $messageType `LtiResourceLinkRequest` or `LtiDeepLinkingRequest`.
	 * @param array $extraClaims Additional LTI claims to merge (e.g. deep-linking settings, roles, context).
	 * @param string|null $nonce The tool's own nonce from its authorization request (REQ-LTIL-002). A tool
	 *                           rejects an id_token whose nonce it did not issue, so the platform
	 *                           authorization endpoint always passes it; null keeps the old behaviour
	 *                           of minting one.
	 *
	 * @return array{formActionUrl: string, idToken: string}
	 *
	 * @throws LtiValidationException When the deployment/tool/active key cannot be resolved.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-the-platform-authorizes-the-tools-login-redirect-and-posts-the-launch-token-req-ltil-002
	 */
	public function initiatePlatformLaunch(
		string $deploymentUuid,
		string $platformIssuer,
		string $subject,
		string $messageType,
		array $extraClaims = [],
		?string $nonce = null,
	): array {
		$deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
		if ($deployment === null) {
			throw new LtiValidationException(message: 'Unknown LTI deployment', details: [], httpStatus: 400);
		}

		$deploymentData = $deployment->getObject();
		$toolUuid = ($deploymentData['ltiToolId'] ?? null);
		if (empty($toolUuid) === true) {
			throw new LtiValidationException(message: 'Deployment does not reference an lti_tool', details: [], httpStatus: 400);
		}

		$tool = $this->resolver->findRegistrationByUuid(registrationType: 'lti_tool', registrationUuid: $toolUuid);
		if ($tool === null) {
			throw new LtiValidationException(message: 'Referenced lti_tool not found', details: [], httpStatus: 400);
		}

		$toolData = $tool->getObject();
		$keyEntry = $this->keyService->getActiveKeyEntry(registrationType: 'lti_tool', registrationUuid: $tool->getUuid());
		if ($keyEntry === null) {
			throw new LtiValidationException(message: 'lti_tool registration has no active signing key', details: [], httpStatus: 400);
		}

		$nonce = ($nonce ?? bin2hex(random_bytes(32)));
		$now = (new DateTime())->getTimestamp();

		$payload = array_merge(
			[
				'iss' => $platformIssuer,
				'aud' => ($toolData['clientId'] ?? ''),
				'sub' => $subject,
				'iat' => $now,
				'exp' => ($now + 300),
				'nonce' => $nonce,
				self::CLAIM_DEPLOYMENT_ID => ($deploymentData['deploymentId'] ?? ''),
				self::CLAIM_MESSAGE_TYPE => $messageType,
				self::CLAIM_VERSION => self::LTI_VERSION,
			],
			$extraClaims
		);

		$idToken = $this->signJwt(payload: $payload, keyEntry: $keyEntry);

		return [
			'formActionUrl' => ($toolData['launchUrl'] ?? ''),
			'idToken' => $idToken,
		];

	}//end initiatePlatformLaunch()

	/**
	 * Construct and sign an `LtiDeepLinkingResponse` (Tool role) to POST back
	 * to the platform's `deep_link_return_url`.
	 *
	 * @param string $platformUuid The `lti_platform` registration whose active key signs the response.
	 * @param string $deepLinkReturnUrl The platform-supplied `deep_link_return_url` from the deep-linking request.
	 * @param array $contentItems The selected content items (IMS content-item shape).
	 * @param string $deploymentIdClaim The `deployment_id` claim value to echo back.
	 * @param array $baseClaims Any additional required claims (`iss`/`aud`/`sub` etc. from the original request).
	 *
	 * @return array{formActionUrl: string, idToken: string}
	 *
	 * @throws LtiValidationException When the platform/active key cannot be resolved.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function buildDeepLinkingResponse(
		string $platformUuid,
		string $deepLinkReturnUrl,
		array $contentItems,
		string $deploymentIdClaim,
		array $baseClaims = [],
	): array {
		$platform = $this->resolver->findRegistrationByUuid(registrationType: 'lti_platform', registrationUuid: $platformUuid);
		if ($platform === null) {
			throw new LtiValidationException(message: 'Unknown lti_platform', details: [], httpStatus: 400);
		}

		$keyEntry = $this->keyService->getActiveKeyEntry(registrationType: 'lti_platform', registrationUuid: $platform->getUuid());
		if ($keyEntry === null) {
			throw new LtiValidationException(message: 'lti_platform registration has no active signing key', details: [], httpStatus: 400);
		}

		$now = (new DateTime())->getTimestamp();

		$payload = array_merge(
			$baseClaims,
			[
				'iat' => $now,
				'exp' => ($now + 300),
				self::CLAIM_MESSAGE_TYPE => 'LtiDeepLinkingResponse',
				self::CLAIM_VERSION => self::LTI_VERSION,
				self::CLAIM_DEPLOYMENT_ID => $deploymentIdClaim,
				self::CLAIM_DL_ITEMS => $contentItems,
			]
		);

		$idToken = $this->signJwt(payload: $payload, keyEntry: $keyEntry);

		return [
			'formActionUrl' => $deepLinkReturnUrl,
			'idToken' => $idToken,
		];

	}//end buildDeepLinkingResponse()

	/**
	 * Verify an inbound `LtiDeepLinkingResponse` from a launched `lti_tool`
	 * (Platform role) — identical verification shape to REQ-LTI-005, against
	 * the `lti_tool` registry instead of `lti_platform`.
	 *
	 * @param string $idToken The posted `LtiDeepLinkingResponse` JWS.
	 *
	 * @return array The verified content items (`CLAIM_DL_ITEMS`).
	 *
	 * @throws LtiValidationException On any validation failure — only on success are content items returned.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function verifyDeepLinkingResponse(string $idToken): array {
		[$payload, $tool] = $this->verifyIdTokenSignature(idToken: $idToken, registrationType: 'lti_tool');

		$this->validateTiming(payload: $payload);

		$toolData = $tool->getObject();
		$this->assertAudience(payload: $payload, expectedClientId: ($toolData['clientId'] ?? ''));

		if (($payload[self::CLAIM_MESSAGE_TYPE] ?? null) !== 'LtiDeepLinkingResponse') {
			throw new LtiValidationException(message: 'Not an LtiDeepLinkingResponse', details: [], httpStatus: 400);
		}

		if (($payload[self::CLAIM_VERSION] ?? null) !== self::LTI_VERSION) {
			throw new LtiValidationException(message: 'Unsupported LTI version', details: [], httpStatus: 400);
		}

		return ($payload[self::CLAIM_DL_ITEMS] ?? []);
	}//end verifyDeepLinkingResponse()

	// =========================================================================
	// Shared verification internals
	// =========================================================================

	/**
	 * Verify an `id_token`'s JWS signature against a JWK resolved via
	 * REQ-LTI-003, resolving the issuing registration from the (still
	 * unverified at this point) `iss` claim first — the same "decode payload
	 * to find the issuer, THEN cryptographically verify before trusting any
	 * claim" shape {@see \OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge::authorizeJwt()}
	 * already uses. No claim is trusted for any authorization decision until
	 * signature verification (step 4 below) succeeds.
	 *
	 * Public because {@see \OCA\Integriq\Service\Lti\LtiAgsService} and
	 * {@see \OCA\Integriq\Service\Lti\LtiNrpsService} reuse this exact
	 * verification (RFC 7523 client-assertion JWTs are the same JWS shape as
	 * an id_token) rather than re-implementing JWS verification a second time.
	 *
	 * @param string $idToken The posted id_token/client_assertion JWS (compact serialization).
	 * @param string $registrationType `lti_platform` (verifying a launch, or an assertion FROM a platform) or
	 *                                 `lti_tool` (verifying a Deep Linking response, or a service-token
	 *                                 assertion FROM a tool).
	 *
	 * @return array{0: array, 1: ObjectEntity} The verified payload and the resolved registration.
	 *
	 * @throws LtiValidationException On any signature/registration/kid failure (HTTP 400/401, no partial trust).
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function verifyIdTokenSignature(string $idToken, string $registrationType): array {
		$run = new IdTokenVerificationRun(jws: $this->unserializeIdToken(idToken: $idToken), registrationType: $registrationType);
		$this->readKidAndAlg(run: $run);
		$this->readUnverifiedPayload(run: $run);
		$this->resolveIssuerRegistration(run: $run);
		$jwk = $this->resolvePresentedKey(run: $run);
		$this->assertAlgorithmAllowed(run: $run, jwk: $jwk);
		$this->assertSignatureVerifies(run: $run, jwk: $jwk);

		return [$run->payload, $run->registration];
	}//end verifyIdTokenSignature()

	/**
	 * Parse the compact-serialized id_token.
	 *
	 * @param string $idToken The posted id_token/client_assertion JWS.
	 *
	 * @return JWS The parsed token.
	 *
	 * @throws LtiValidationException When the token is not a compact JWS (400).
	 */
	private function unserializeIdToken(string $idToken): JWS {
		$serializerManager = new JWSSerializerManager([new CompactSerializer()]);

		try {
			return $serializerManager->unserialize(input: $idToken);
		} catch (Throwable $exception) {
			throw new LtiValidationException(message: 'Malformed id_token', details: [], httpStatus: 400);
		}

	}//end unserializeIdToken()

	/**
	 * Read the protected header's `kid` and `alg`; both are required.
	 *
	 * @param IdTokenVerificationRun $run The verification in progress.
	 *
	 * @return void
	 *
	 * @throws LtiValidationException When either is missing (400).
	 */
	private function readKidAndAlg(IdTokenVerificationRun $run): void {
		$header = $run->jws->getSignature(0)->getProtectedHeader();
		$run->kid = ($header['kid'] ?? null);
		$run->alg = ($header['alg'] ?? null);

		if ($run->kid === null || $run->alg === null) {
			throw new LtiValidationException(message: 'id_token header missing kid/alg', details: [], httpStatus: 400);
		}

	}//end readKidAndAlg()

	/**
	 * Decode the (not yet verified) payload JSON.
	 *
	 * @param IdTokenVerificationRun $run The verification in progress.
	 *
	 * @return void
	 *
	 * @throws LtiValidationException When the payload is not a JSON object (400).
	 */
	private function readUnverifiedPayload(IdTokenVerificationRun $run): void {
		$payload = json_decode((string)$run->jws->getPayload(), true);
		if (is_array($payload) === false) {
			throw new LtiValidationException(message: 'id_token payload is not valid JSON', details: [], httpStatus: 400);
		}

		$run->payload = $payload;

	}//end readUnverifiedPayload()

	/**
	 * Resolve the registration whose JWKS the token is checked against.
	 *
	 * The unverified `iss` is used ONLY to select which registration's JWKS to
	 * check against — no authorization decision is made on it.
	 *
	 * @param IdTokenVerificationRun $run The verification in progress.
	 *
	 * @return void
	 *
	 * @throws LtiValidationException When `iss` is missing or not registered (400).
	 */
	private function resolveIssuerRegistration(IdTokenVerificationRun $run): void {
		$issuer = ($run->payload['iss'] ?? null);
		if (empty($issuer) === true) {
			throw new LtiValidationException(message: 'id_token missing iss claim', details: [], httpStatus: 400);
		}

		$run->registration = $this->findIssuerRegistration(run: $run, issuer: (string)$issuer);
		if ($run->registration === null) {
			throw new LtiValidationException(
				message: 'Unregistered id_token issuer',
				details: ['iss' => $issuer],
				httpStatus: 400
			);
		}

	}//end resolveIssuerRegistration()

	/**
	 * Look the issuer up as a platform, or as a tool for `lti_tool` verification.
	 *
	 * @param IdTokenVerificationRun $run    The verification in progress.
	 * @param string                 $issuer The token's `iss` claim.
	 *
	 * @return ObjectEntity|null The registration, or null when none matches.
	 */
	private function findIssuerRegistration(IdTokenVerificationRun $run, string $issuer): ?ObjectEntity {
		if ($run->registrationType === 'lti_platform') {
			return $this->resolver->findPlatformByIssuer(issuer: $issuer);
		}

		// Deep Linking responses from a launched tool: the tool's `iss`
		// is the clientId we assigned it.
		return $this->resolver->findToolByClientId(clientId: $issuer);

	}//end findIssuerRegistration()

	/**
	 * Resolve the JWK for the presented `kid` from the registration's JWKS.
	 *
	 * @param IdTokenVerificationRun $run The verification in progress.
	 *
	 * @return JWK The resolved key.
	 *
	 * @throws LtiValidationException When no jwks_uri is configured (400) or no key matches the kid (401).
	 */
	private function resolvePresentedKey(IdTokenVerificationRun $run): JWK {
		$jwk = $this->jwksResolver->resolveKey(
			registrationType: $run->registrationType,
			registrationUuid: $run->registration->getUuid(),
			jwksUri: $this->requireJwksUri(registration: $run->registration),
			kid: (string)$run->kid
		);
		if ($jwk === null) {
			throw new LtiValidationException(
				message: 'Could not resolve a JWK for the presented kid',
				details: ['kid' => $run->kid],
				httpStatus: 401
			);
		}

		return $jwk;

	}//end resolvePresentedKey()

	/**
	 * The registration's configured `jwksUri`.
	 *
	 * @param ObjectEntity $registration The resolved registration.
	 *
	 * @return string The JWKS URI.
	 *
	 * @throws LtiValidationException When none is configured (400).
	 */
	private function requireJwksUri(ObjectEntity $registration): string {
		$registrationData = $registration->getObject();
		$jwksUri = ($registrationData['jwksUri'] ?? '');
		if ($jwksUri === '') {
			throw new LtiValidationException(message: 'Registration has no jwks_uri configured', details: [], httpStatus: 400);
		}

		return $jwksUri;

	}//end requireJwksUri()

	/**
	 * Refuse algorithm confusion and any algorithm outside the RSA families.
	 *
	 * The resolved JWK's own `alg` (when present) must match the token header's
	 * `alg` exactly — mirrors AuthorizationService::authorizeJwt()'s
	 * pinned-algorithm check.
	 *
	 * @param IdTokenVerificationRun $run The verification in progress.
	 * @param JWK                    $jwk The resolved key.
	 *
	 * @return void
	 *
	 * @throws LtiValidationException When the algorithms differ or the algorithm is not supported (401).
	 */
	private function assertAlgorithmAllowed(IdTokenVerificationRun $run, JWK $jwk): void {
		if ($jwk->has('alg') === true && $jwk->get('alg') !== $run->alg) {
			throw new LtiValidationException(
				message: 'Token algorithm does not match the resolved key\'s algorithm',
				details: [],
				httpStatus: 401
			);
		}

		if (in_array(needle: $run->alg, haystack: ['RS256', 'RS384', 'RS512', 'PS256', 'PS384', 'PS512'], strict: true) === false) {
			throw new LtiValidationException(message: 'Unsupported id_token algorithm', details: ['alg' => $run->alg], httpStatus: 401);
		}

	}//end assertAlgorithmAllowed()

	/**
	 * Verify the token's signature against the resolved key.
	 *
	 * @param IdTokenVerificationRun $run The verification in progress.
	 * @param JWK                    $jwk The resolved key.
	 *
	 * @return void
	 *
	 * @throws LtiValidationException When the signature does not verify (401).
	 */
	private function assertSignatureVerifies(IdTokenVerificationRun $run, JWK $jwk): void {
		$algorithmManager = new AlgorithmManager([new RS256(), new RS384(), new RS512(), new PS256(), new PS384(), new PS512()]);
		$verifier = new JWSVerifier($algorithmManager);
		$jwkSet = new JWKSet([$jwk]);

		if ($verifier->verifyWithKeySet(jws: $run->jws, jwkset: $jwkSet, signatureIndex: 0) === false) {
			throw new LtiValidationException(message: 'id_token signature verification failed', details: [], httpStatus: 401);
		}

	}//end assertSignatureVerifies()

	/**
	 * Delegate `iat`/`exp`/`nbf`/`jti`-replay validation to the existing
	 * {@see OpenRegisterCredentialBridge::validatePayload()} (design.md D6 — no
	 * reimplementation), converting its generic `AuthenticationException`
	 * into an {@see LtiValidationException} carrying the HTTP 401 this
	 * adapter's callers rely on (a plain `AuthenticationException` is NOT an
	 * `LtiValidationException` — it would otherwise escape uncaught past
	 * `LtiController`'s `catch (LtiValidationException $e)` blocks).
	 *
	 * Public because {@see \OCA\Integriq\Service\Lti\LtiAgsService}
	 * reuses it for the same timing checks on RFC 7523 client assertions.
	 *
	 * @param array $payload The verified id_token/client_assertion payload.
	 *
	 * @return void
	 *
	 * @throws LtiValidationException HTTP 401 when the token is expired, not-yet-valid, or replayed (jti).
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function validateTiming(array $payload): void {
		try {
			$this->authorizationService->validatePayload(payload: $payload);
		} catch (\OCA\Integriq\Exception\AuthenticationException $exception) {
			throw new LtiValidationException(message: $exception->getMessage(), details: $exception->getDetails(), httpStatus: 401);
		}

	}//end validateTiming()

	/**
	 * Assert the `aud` claim (and `azp` when `aud` is an array) equals the
	 * registration's `client_id`.
	 *
	 * @param array $payload The verified id_token payload.
	 * @param string $expectedClientId The registration's configured `clientId`.
	 *
	 * @return void
	 *
	 * @throws LtiValidationException When `aud`/`azp` does not match.
	 */
	private function assertAudience(array $payload, string $expectedClientId): void {
		$aud = ($payload['aud'] ?? null);

		if (is_array($aud) === true) {
			if (in_array(needle: $expectedClientId, haystack: $aud, strict: true) === false) {
				throw new LtiValidationException(message: 'aud does not contain the expected client_id', details: [], httpStatus: 401);
			}

			$azp = ($payload['azp'] ?? null);
			if ($azp !== $expectedClientId) {
				throw new LtiValidationException(message: 'azp does not match the expected client_id', details: [], httpStatus: 401);
			}

			return;
		}

		if ($aud !== $expectedClientId) {
			throw new LtiValidationException(message: 'aud does not match the expected client_id', details: [], httpStatus: 401);
		}

	}//end assertAudience()

	/**
	 * Assert `message_type`/`version` claims are present and recognised.
	 *
	 * @param array $payload The verified id_token payload.
	 *
	 * @return void
	 *
	 * @throws LtiValidationException When either claim is missing or unrecognised.
	 */
	private function assertMessageTypeAndVersion(array $payload): void {
		$messageType = ($payload[self::CLAIM_MESSAGE_TYPE] ?? null);
		if (in_array(needle: $messageType, haystack: self::RECOGNISED_MESSAGE_TYPES, strict: true) === false) {
			throw new LtiValidationException(
				message: 'Missing or unrecognised message_type claim',
				details: ['message_type' => $messageType],
				httpStatus: 400
			);
		}

		$version = ($payload[self::CLAIM_VERSION] ?? null);
		if ($version !== self::LTI_VERSION) {
			throw new LtiValidationException(
				message: 'Missing or unsupported LTI version claim',
				details: ['version' => $version],
				httpStatus: 400
			);
		}

	}//end assertMessageTypeAndVersion()

	/**
	 * Sign a payload with a stored signing-key entry (active key from {@see LtiKeyService}).
	 *
	 * The stored `privateKeySecret` is a base64-encoded PEM private key (the
	 * same shape `LtiKeyService::createKeyEntry()` generates, chosen so this
	 * exact key material is also directly consumable, unmodified, by
	 * `AuthenticationService::fetchJWTToken()`'s `getRSJWK()` path for
	 * REQ-LTI-008/009's outbound calls). Loaded via the same secure
	 * temp-file pattern already established by
	 * `AuthenticationService::getRSJWK()` / `AuthorizationService::getJWK()`
	 * (#1012 fix): unpredictable filename, `chmod 0600`, `try/finally` unlink.
	 *
	 * @param array $payload The JWT payload to sign.
	 * @param array $keyEntry A signingKeys[] entry (`kid`, `algorithm`, `privateKeySecret`).
	 *
	 * @return string The compact-serialized JWS.
	 *
	 * @throws LtiValidationException When the stored private key material cannot be parsed.
	 */
	private function signJwt(array $payload, array $keyEntry): string {
		$pem = base64_decode((string)($keyEntry['privateKeySecret'] ?? ''), true);
		if ($pem === false || $pem === '') {
			throw new LtiValidationException(message: 'Stored signing key material is malformed', details: [], httpStatus: 500);
		}

		$algorithm = ($keyEntry['algorithm'] ?? 'RS256');
		$kid = ($keyEntry['kid'] ?? '');

		$jwk = PemKeyFile::jwk(request: new PemKeyFileRequest(prefix: 'oc-lti-sign-', pem: $pem, kid: $kid, algorithm: $algorithm));
		if ($jwk === null) {
			throw new LtiValidationException(message: 'Could not allocate temp file for LTI signing', details: [], httpStatus: 500);
		}

		$algorithmManager = new AlgorithmManager([new RS256(), new RS384(), new RS512(), new PS256(), new PS384(), new PS512()]);
		$jwsBuilder = new JWSBuilder($algorithmManager);
		$serializer = new CompactSerializer();

		$header = ['alg' => $algorithm, 'typ' => 'JWT', 'kid' => $kid];

		$jws = $jwsBuilder
			->create()
			->withPayload(json_encode($payload))
			->addSignature($jwk, $header)
			->build();

		return $serializer->serialize($jws, 0);
	}//end signJwt()
}//end class
