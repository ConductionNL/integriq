<?php
/**
 * OpenConnector LtiLaunchService.
 *
 * OIDC third-party-initiated login, signed-JWT launch validation, Platform-
 * role launch initiation, and Deep Linking 2.0 (both directions) for the
 * LTI 1.3 / LTI Advantage adapter.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-launch-idtoken-validation-and-dispatch-to-the-consuming-app-tool-role-req-lti-005
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Lti;

use DateTime;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\PS256;
use Jose\Component\Signature\Algorithm\PS384;
use Jose\Component\Signature\Algorithm\PS512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use OCA\OpenConnector\Exception\LtiValidationException;
use OCA\OpenConnector\Service\AuthorizationService;
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
 * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-launch-idtoken-validation-and-dispatch-to-the-consuming-app-tool-role-req-lti-005
 */
class LtiLaunchService
{

    /**
     * LTI 1.3 claim URIs.
     *
     * @var string
     */
    public const CLAIM_DEPLOYMENT_ID = 'https://purl.imsglobal.org/spec/lti/claim/deployment_id';
    public const CLAIM_MESSAGE_TYPE  = 'https://purl.imsglobal.org/spec/lti/claim/message_type';
    public const CLAIM_VERSION       = 'https://purl.imsglobal.org/spec/lti/claim/version';
    public const CLAIM_DL_SETTINGS   = 'https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings';
    public const CLAIM_DL_ITEMS      = 'https://purl.imsglobal.org/spec/lti-dl/claim/content_items';
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
     * Distributed cache for nonce single-use tracking (`openconnector.lti.nonce`).
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
     * @param LtiRegistrationResolverService $resolver             Registration/deployment lookups.
     * @param AuthorizationService           $authorizationService Reused for iat/exp/nbf validation (no reimplementation).
     * @param LtiJwksResolverService         $jwksResolver         External JWKS resolution (REQ-LTI-003).
     * @param LtiKeyService                  $keyService           This instance's own signing keys.
     * @param ICacheFactory                  $cacheFactory         Cache factory for nonce + launch-reference storage.
     * @param LoggerInterface                $logger               Logger (never logs key material or raw id_token payloads).
     */
    public function __construct(
        private readonly LtiRegistrationResolverService $resolver,
        private readonly AuthorizationService $authorizationService,
        private readonly LtiJwksResolverService $jwksResolver,
        private readonly LtiKeyService $keyService,
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
        $this->nonceCache  = $cacheFactory->createDistributed('openconnector.lti.nonce');
        $this->launchCache = $cacheFactory->createDistributed('openconnector.lti.launch');

    }//end __construct()

    // =========================================================================
    // REQ-LTI-004 — OIDC third-party-initiated login (Tool role)
    // =========================================================================

    /**
     * Validate a login-initiation request and build the redirect to the
     * platform's authorization endpoint.
     *
     * @param string $deploymentUuid The `lti_deployment` route parameter.
     * @param array  $params         Request params: `iss`, `login_hint`, `target_link_uri`, `client_id` (optional).
     * @param string $launchUrl      The absolute URL of this instance's launch route for this deployment.
     *
     * @return array{redirectUrl: string, state: string, nonce: string, registrationUuid: string}
     *
     * @throws LtiValidationException HTTP 400 when `iss`/`client_id` matches no registered `lti_platform`,
     *                                 or `login_hint`/`target_link_uri` is missing. No redirect is built
     *                                 and no nonce is persisted on failure.
     *
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-oidc-thirdparty-initiated-login-tool-role-req-lti-004
     */
    public function initiateLogin(string $deploymentUuid, array $params, string $launchUrl): array
    {
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
        $state        = bin2hex(random_bytes(32));
        $nonce        = bin2hex(random_bytes(32));

        $this->nonceCache->set(
            'nonce:'.$platform->getUuid().':'.$nonce,
            1,
            self::NONCE_TTL_SECONDS
        );

        $queryParams = [
            'scope'         => 'openid',
            'response_type' => 'id_token',
            'response_mode' => 'form_post',
            'client_id'     => ($platformData['clientId'] ?? ''),
            'redirect_uri'  => $launchUrl,
            'state'         => $state,
            'nonce'         => $nonce,
            'prompt'        => 'none',
            'login_hint'    => $params['login_hint'],
        ];
        if (empty($params['lti_message_hint'] ?? null) === false) {
            $queryParams['lti_message_hint'] = $params['lti_message_hint'];
        }

        $redirectUrl = ($platformData['authLoginUrl'] ?? '').'?'.http_build_query($queryParams);

        return [
            'redirectUrl'      => $redirectUrl,
            'state'            => $state,
            'nonce'            => $nonce,
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
     * @param string      $idToken        The posted `id_token` JWS (compact serialization).
     * @param string      $deploymentUuid The `lti_deployment` route parameter.
     * @param string|null $cookieState    The `state` value round-tripped via the login's `SameSite=None` cookie.
     * @param string|null $presentedState The `state` value posted alongside the `id_token`.
     *
     * @return array{launchReference: string, redirectUrl: string}
     *
     * @throws LtiValidationException On any validation failure (HTTP 400/401, no partial-trust fallback).
     *
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-launch-idtoken-validation-and-dispatch-to-the-consuming-app-tool-role-req-lti-005
     */
    public function validateLaunch(
        string $idToken,
        string $deploymentUuid,
        ?string $cookieState,
        ?string $presentedState
    ): array {
        $deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
        if ($deployment === null) {
            throw new LtiValidationException(message: 'Unknown LTI deployment', details: [], httpStatus: 400);
        }

        if ($cookieState !== null && $presentedState !== null && hash_equals($cookieState, $presentedState) === false) {
            throw new LtiValidationException(message: 'State mismatch', details: [], httpStatus: 401);
        }

        [$payload, $platform] = $this->verifyIdTokenSignature(idToken: $idToken, registrationType: 'lti_platform');

        // Iat/exp/nbf — reused from AuthorizationService, no reimplementation (design.md D6).
        $this->validateTiming(payload: $payload);

        $platformData = $platform->getObject();
        $this->assertAudience(payload: $payload, expectedClientId: ($platformData['clientId'] ?? ''));

        // Nonce: present + atomic single-use consume (get-then-delete).
        $nonce = ($payload['nonce'] ?? null);
        if (empty($nonce) === true) {
            throw new LtiValidationException(message: 'Missing nonce claim', details: [], httpStatus: 400);
        }

        $nonceKey = 'nonce:'.$platform->getUuid().':'.$nonce;
        if ($this->nonceCache->get($nonceKey) === null) {
            throw new LtiValidationException(message: 'Nonce already consumed or unknown (replay)', details: [], httpStatus: 401);
        }

        $this->nonceCache->remove($nonceKey);

        // Deployment_id claim must match a registered lti_deployment under this platform.
        $deploymentIdClaim = ($payload[self::CLAIM_DEPLOYMENT_ID] ?? null);
        if (empty($deploymentIdClaim) === true) {
            throw new LtiValidationException(message: 'Missing deployment_id claim', details: [], httpStatus: 400);
        }

        $matchedDeployment = $this->resolver->findDeployment(
            registrationType: 'lti_platform',
            registrationUuid: $platform->getUuid(),
            deploymentIdClaim: $deploymentIdClaim
        );
        if ($matchedDeployment === null || $matchedDeployment->getUuid() !== $deployment->getUuid()) {
            throw new LtiValidationException(
                message: 'deployment_id claim not registered under the resolved platform',
                details: ['deploymentId' => $deploymentIdClaim],
                httpStatus: 400
            );
        }

        $this->assertMessageTypeAndVersion(payload: $payload);

        $deploymentData  = $matchedDeployment->getObject();
        $launchReference = bin2hex(random_bytes(24));
        $this->launchCache->set(
            'launch:'.$launchReference,
            json_encode(['claims' => $payload, 'deploymentUuid' => $matchedDeployment->getUuid()]),
            self::LAUNCH_REFERENCE_TTL_SECONDS
        );

        $this->logger->info(
            'LtiLaunchService: launch validated',
            ['registrationUuid' => $platform->getUuid(), 'deploymentUuid' => $matchedDeployment->getUuid()]
        );

        if (str_contains(($deploymentData['launchTargetUrl'] ?? ''), '?') === true) {
            $separator = '&';
        } else {
            $separator = '?';
        }

        return [
            'launchReference' => $launchReference,
            'redirectUrl'     => ($deploymentData['launchTargetUrl'] ?? '').$separator.'lti_launch='.$launchReference,
        ];

    }//end validateLaunch()

    /**
     * Resolve a previously-validated launch reference to its claims (single use).
     *
     * @param string $launchReference The reference minted by {@see validateLaunch()}.
     *
     * @return array|null The cached `{claims, deploymentUuid}` payload, or null if unknown/expired/already consumed.
     *
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-launch-idtoken-validation-and-dispatch-to-the-consuming-app-tool-role-req-lti-005
     */
    public function consumeLaunchReference(string $launchReference): ?array
    {
        $key    = 'launch:'.$launchReference;
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
     * @spec openspec/changes/lti-tool-provider-role/specs/lti-platform/spec.md#req-lti-013
     */
    public function resolveResourceMapping(string $deploymentUuid, string $resourceLinkId): ?array
    {
        $deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
        if ($deployment === null) {
            return null;
        }

        $mappings = ($deployment->getObject()['resourceLinkMappings'] ?? []);

        foreach ($mappings as $mapping) {
            if (($mapping['resourceLinkId'] ?? null) === $resourceLinkId) {
                return [
                    'targetType' => ($mapping['targetType'] ?? null),
                    'targetId'   => ($mapping['targetId'] ?? null),
                ];
            }
        }

        foreach ($mappings as $mapping) {
            if (($mapping['resourceLinkId'] ?? null) === '') {
                return [
                    'targetType' => ($mapping['targetType'] ?? null),
                    'targetId'   => ($mapping['targetId'] ?? null),
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
     * @param string $subject        The launched user's subject identifier.
     * @param string $messageType    `LtiResourceLinkRequest` or `LtiDeepLinkingRequest`.
     * @param array  $extraClaims    Additional LTI claims to merge (e.g. deep-linking settings, roles, context).
     *
     * @return array{formActionUrl: string, idToken: string}
     *
     * @throws LtiValidationException When the deployment/tool/active key cannot be resolved.
     *
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-platformrole-launch-initiation-and-deep-linking-20-both-directions-req-lti-006
     */
    public function initiatePlatformLaunch(
        string $deploymentUuid,
        string $platformIssuer,
        string $subject,
        string $messageType,
        array $extraClaims=[]
    ): array {
        $deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
        if ($deployment === null) {
            throw new LtiValidationException(message: 'Unknown LTI deployment', details: [], httpStatus: 400);
        }

        $deploymentData = $deployment->getObject();
        $toolUuid       = ($deploymentData['ltiToolId'] ?? null);
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

        $nonce = bin2hex(random_bytes(32));
        $now   = (new DateTime())->getTimestamp();

        $payload = array_merge(
            [
                'iss'                     => $platformIssuer,
                'aud'                     => ($toolData['clientId'] ?? ''),
                'sub'                     => $subject,
                'iat'                     => $now,
                'exp'                     => ($now + 300),
                'nonce'                   => $nonce,
                self::CLAIM_DEPLOYMENT_ID => ($deploymentData['deploymentId'] ?? ''),
                self::CLAIM_MESSAGE_TYPE  => $messageType,
                self::CLAIM_VERSION       => self::LTI_VERSION,
            ],
            $extraClaims
        );

        $idToken = $this->signJwt(payload: $payload, keyEntry: $keyEntry);

        return [
            'formActionUrl' => ($toolData['launchUrl'] ?? ''),
            'idToken'       => $idToken,
        ];

    }//end initiatePlatformLaunch()

    /**
     * Construct and sign an `LtiDeepLinkingResponse` (Tool role) to POST back
     * to the platform's `deep_link_return_url`.
     *
     * @param string $platformUuid      The `lti_platform` registration whose active key signs the response.
     * @param string $deepLinkReturnUrl The platform-supplied `deep_link_return_url` from the deep-linking request.
     * @param array  $contentItems      The selected content items (IMS content-item shape).
     * @param string $deploymentIdClaim The `deployment_id` claim value to echo back.
     * @param array  $baseClaims        Any additional required claims (`iss`/`aud`/`sub` etc. from the original request).
     *
     * @return array{formActionUrl: string, idToken: string}
     *
     * @throws LtiValidationException When the platform/active key cannot be resolved.
     *
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-platformrole-launch-initiation-and-deep-linking-20-both-directions-req-lti-006
     */
    public function buildDeepLinkingResponse(
        string $platformUuid,
        string $deepLinkReturnUrl,
        array $contentItems,
        string $deploymentIdClaim,
        array $baseClaims=[]
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
                'iat'                     => $now,
                'exp'                     => ($now + 300),
                self::CLAIM_MESSAGE_TYPE  => 'LtiDeepLinkingResponse',
                self::CLAIM_VERSION       => self::LTI_VERSION,
                self::CLAIM_DEPLOYMENT_ID => $deploymentIdClaim,
                self::CLAIM_DL_ITEMS      => $contentItems,
            ]
        );

        $idToken = $this->signJwt(payload: $payload, keyEntry: $keyEntry);

        return [
            'formActionUrl' => $deepLinkReturnUrl,
            'idToken'       => $idToken,
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
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-platformrole-launch-initiation-and-deep-linking-20-both-directions-req-lti-006
     */
    public function verifyDeepLinkingResponse(string $idToken): array
    {
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
     * claim" shape {@see \OCA\OpenConnector\Service\AuthorizationService::authorizeJwt()}
     * already uses. No claim is trusted for any authorization decision until
     * signature verification (step 4 below) succeeds.
     *
     * Public because {@see \OCA\OpenConnector\Service\Lti\LtiAgsService} and
     * {@see \OCA\OpenConnector\Service\Lti\LtiNrpsService} reuse this exact
     * verification (RFC 7523 client-assertion JWTs are the same JWS shape as
     * an id_token) rather than re-implementing JWS verification a second time.
     *
     * @param string $idToken          The posted id_token/client_assertion JWS (compact serialization).
     * @param string $registrationType `lti_platform` (verifying a launch, or an assertion FROM a platform) or
     *                                 `lti_tool` (verifying a Deep Linking response, or a service-token
     *                                 assertion FROM a tool).
     *
     * @return array{0: array, 1: ObjectEntity} The verified payload and the resolved registration.
     *
     * @throws LtiValidationException On any signature/registration/kid failure (HTTP 400/401, no partial trust).
     *
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-ags-service-token-issuance-and-inbound-scoreline-item-endpoints-platform-role-fanned-out-as-a-cloudevent-req-lti-007
     */
    public function verifyIdTokenSignature(string $idToken, string $registrationType): array
    {
        $serializerManager = new JWSSerializerManager([new CompactSerializer()]);

        try {
            $jws = $serializerManager->unserialize(input: $idToken);
        } catch (Throwable $exception) {
            throw new LtiValidationException(message: 'Malformed id_token', details: [], httpStatus: 400);
        }

        $header = $jws->getSignature(0)->getProtectedHeader();
        $kid    = ($header['kid'] ?? null);
        $alg    = ($header['alg'] ?? null);

        if ($kid === null || $alg === null) {
            throw new LtiValidationException(message: 'id_token header missing kid/alg', details: [], httpStatus: 400);
        }

        $payload = json_decode((string) $jws->getPayload(), true);
        if (is_array($payload) === false) {
            throw new LtiValidationException(message: 'id_token payload is not valid JSON', details: [], httpStatus: 400);
        }

        // Unverified `iss` used ONLY to select which registration's JWKS to
        // check against — no authorization decision is made on it.
        $issuer = ($payload['iss'] ?? null);
        if (empty($issuer) === true) {
            throw new LtiValidationException(message: 'id_token missing iss claim', details: [], httpStatus: 400);
        }

        if ($registrationType === 'lti_platform') {
            $registration = $this->resolver->findPlatformByIssuer(issuer: (string) $issuer);
        } else {
            // Deep Linking responses from a launched tool: the tool's `iss`
            // is the clientId we assigned it.
            $registration = $this->resolver->findToolByClientId(clientId: (string) $issuer);
        }

        if ($registration === null) {
            throw new LtiValidationException(
                message: 'Unregistered id_token issuer',
                details: ['iss' => $issuer],
                httpStatus: 400
            );
        }

        $registrationData = $registration->getObject();
        $jwksUri          = ($registrationData['jwksUri'] ?? '');
        if ($jwksUri === '') {
            throw new LtiValidationException(message: 'Registration has no jwks_uri configured', details: [], httpStatus: 400);
        }

        $jwk = $this->jwksResolver->resolveKey(
            registrationType: $registrationType,
            registrationUuid: $registration->getUuid(),
            jwksUri: $jwksUri,
            kid: (string) $kid
        );

        if ($jwk === null) {
            throw new LtiValidationException(
                message: 'Could not resolve a JWK for the presented kid',
                details: ['kid' => $kid],
                httpStatus: 401
            );
        }

        // Algorithm-confusion guard: the resolved JWK's own `alg` (when
        // present) must match the token header's `alg` exactly — mirrors
        // AuthorizationService::authorizeJwt()'s pinned-algorithm check.
        if ($jwk->has('alg') === true && $jwk->get('alg') !== $alg) {
            throw new LtiValidationException(
                message: 'Token algorithm does not match the resolved key\'s algorithm',
                details: [],
                httpStatus: 401
            );
        }

        if (in_array(needle: $alg, haystack: ['RS256', 'RS384', 'RS512', 'PS256', 'PS384', 'PS512'], strict: true) === false) {
            throw new LtiValidationException(message: 'Unsupported id_token algorithm', details: ['alg' => $alg], httpStatus: 401);
        }

        $algorithmManager = new AlgorithmManager([new RS256(), new RS384(), new RS512(), new PS256(), new PS384(), new PS512()]);
        $verifier         = new JWSVerifier($algorithmManager);
        $jwkSet           = new JWKSet([$jwk]);

        if ($verifier->verifyWithKeySet(jws: $jws, jwkset: $jwkSet, signatureIndex: 0) === false) {
            throw new LtiValidationException(message: 'id_token signature verification failed', details: [], httpStatus: 401);
        }

        return [$payload, $registration];

    }//end verifyIdTokenSignature()

    /**
     * Delegate `iat`/`exp`/`nbf`/`jti`-replay validation to the existing
     * {@see AuthorizationService::validatePayload()} (design.md D6 — no
     * reimplementation), converting its generic `AuthenticationException`
     * into an {@see LtiValidationException} carrying the HTTP 401 this
     * adapter's callers rely on (a plain `AuthenticationException` is NOT an
     * `LtiValidationException` — it would otherwise escape uncaught past
     * `LtiController`'s `catch (LtiValidationException $e)` blocks).
     *
     * Public because {@see \OCA\OpenConnector\Service\Lti\LtiAgsService}
     * reuses it for the same timing checks on RFC 7523 client assertions.
     *
     * @param array $payload The verified id_token/client_assertion payload.
     *
     * @return void
     *
     * @throws LtiValidationException HTTP 401 when the token is expired, not-yet-valid, or replayed (jti).
     *
     * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-launch-idtoken-validation-and-dispatch-to-the-consuming-app-tool-role-req-lti-005
     */
    public function validateTiming(array $payload): void
    {
        try {
            $this->authorizationService->validatePayload(payload: $payload);
        } catch (\OCA\OpenConnector\Exception\AuthenticationException $exception) {
            throw new LtiValidationException(message: $exception->getMessage(), details: $exception->getDetails(), httpStatus: 401);
        }

    }//end validateTiming()

    /**
     * Assert the `aud` claim (and `azp` when `aud` is an array) equals the
     * registration's `client_id`.
     *
     * @param array  $payload          The verified id_token payload.
     * @param string $expectedClientId The registration's configured `clientId`.
     *
     * @return void
     *
     * @throws LtiValidationException When `aud`/`azp` does not match.
     */
    private function assertAudience(array $payload, string $expectedClientId): void
    {
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
    private function assertMessageTypeAndVersion(array $payload): void
    {
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
     * @param array $payload  The JWT payload to sign.
     * @param array $keyEntry A signingKeys[] entry (`kid`, `algorithm`, `privateKeySecret`).
     *
     * @return string The compact-serialized JWS.
     *
     * @throws LtiValidationException When the stored private key material cannot be parsed.
     */
    private function signJwt(array $payload, array $keyEntry): string
    {
        $pem = base64_decode((string) ($keyEntry['privateKeySecret'] ?? ''), true);
        if ($pem === false || $pem === '') {
            throw new LtiValidationException(message: 'Stored signing key material is malformed', details: [], httpStatus: 500);
        }

        $algorithm = ($keyEntry['algorithm'] ?? 'RS256');
        $kid       = ($keyEntry['kid'] ?? '');

        $filename = tempnam(sys_get_temp_dir(), 'oc-lti-sign-');
        if ($filename === false) {
            throw new LtiValidationException(message: 'Could not allocate temp file for LTI signing', details: [], httpStatus: 500);
        }

        @chmod($filename, 0600);
        file_put_contents($filename, $pem);
        @chmod($filename, 0600);

        try {
            $jwk = JWKFactory::createFromKeyFile(
                $filename,
                null,
                ['kid' => $kid, 'alg' => $algorithm, 'use' => 'sig']
            );
        } finally {
            if (file_exists($filename) === true) {
                @unlink($filename);
            }
        }

        $algorithmManager = new AlgorithmManager([new RS256(), new RS384(), new RS512(), new PS256(), new PS384(), new PS512()]);
        $jwsBuilder       = new JWSBuilder($algorithmManager);
        $serializer       = new CompactSerializer();

        $header = ['alg' => $algorithm, 'typ' => 'JWT', 'kid' => $kid];

        $jws = $jwsBuilder
            ->create()
            ->withPayload(json_encode($payload))
            ->addSignature($jwk, $header)
            ->build();

        return $serializer->serialize($jws, 0);

    }//end signJwt()
}//end class
