<?php

/**
 * Integriq LtiAgsService.
 *
 * Assignment & Grade Services (AGS): RFC 7523 JWT-bearer service-token
 * issuance (Platform role), inbound score/line-item scope enforcement fanning
 * a received score out as a CloudEvent, and Tool-role outbound score publish
 * / result read reusing the existing OAuth + HTTP call machinery unmodified.
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

use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\CloudEventEmitterInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AGS service-token issuance + inbound score/line-item + outbound score
 * publish/result read.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/lti-platform/spec.md
 */
class LtiAgsService implements LtiAgsServiceInterface {

	/**
	 * AGS scope URIs (1EdTech LTI Advantage AGS 2.0).
	 *
	 * @var string
	 */
	public const SCOPE_SCORE = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';
	public const SCOPE_RESULT = 'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly';
	public const SCOPE_NRPS = 'https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly';

	/**
	 * All scopes a token issued by this service may carry (REQ-LTI-007/009).
	 *
	 * @var string[]
	 */
	public const ALLOWED_SCOPES = [
		self::SCOPE_LINEITEM,
		self::SCOPE_LINEITEM_READONLY,
		self::SCOPE_SCORE,
		self::SCOPE_RESULT,
		self::SCOPE_NRPS,
	];

	/**
	 * The CloudEvent `type` a received AGS score is published as (REQ-LTI-007).
	 *
	 * @var string
	 */
	public const AGS_SCORE_RECEIVED_EVENT_TYPE = 'nl.conduction.lti.ags.score.received';

	/**
	 * Issued access-token lifetime in seconds (1 hour).
	 *
	 * @var integer
	 */
	public const ACCESS_TOKEN_TTL_SECONDS = 3600;

	/**
	 * Distributed cache for issued access tokens.
	 *
	 * @var ICache
	 */
	private readonly ICache $tokenCache;

	/**
	 * Constructor.
	 *
	 * @param LtiRegistrationResolverService $resolver Registration/deployment lookups.
	 * @param LtiLaunchService $launchService Reused JWS-verification + timing/replay validation
	 *                                        (`validateTiming()`) for client assertions.
	 * @param LtiKeyService $keyService This instance's own signing keys (Tool-role assertions).
	 * @param AuthenticationService $authenticationService Reused `fetchOAuthTokens()` (Tool-role outbound grant).
	 * @param CallService $callService Outbound HTTP call engine for the AGS REST call itself.
	 * @param CloudEventEmitterInterface $eventService Reused `emitCloudEvent()` (AGS score passback fan-out).
	 * @param ICacheFactory $cacheFactory Cache factory for issued-token storage.
	 * @param LoggerInterface $logger Logger for issuance/dispatch outcomes.
	 */
	public function __construct(
		private readonly LtiRegistrationResolverService $resolver,
		private readonly LtiLaunchService $launchService,
		private readonly LtiKeyService $keyService,
		private readonly AuthenticationService $authenticationService,
		private readonly CallService $callService,
		private readonly CloudEventEmitterInterface $eventService,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->tokenCache = $cacheFactory->createDistributed('integriq.lti.token');

	}//end __construct()

	// =========================================================================
	// REQ-LTI-007 — service-token issuance (Platform role)
	// =========================================================================

	/**
	 * RFC 7523 JWT-bearer client-credentials grant: exchange a signed
	 * `client_assertion` for a deployment-scoped access token.
	 *
	 * A conformant LTI Advantage token request carries no deployment (1EdTech
	 * Security Framework 4.1): the tool is named by the assertion's `iss`/`sub`.
	 * The token is still scoped to exactly one deployment (design.md D8,
	 * REQ-LTI-007): the asserting tool's only deployment, or, for a tool with
	 * several, the one the caller names in the optional `deployment_id`.
	 *
	 * @param string      $clientAssertion The `client_assertion` JWT posted to `POST /api/lti/token`.
	 * @param string      $requestedScope  Space-separated requested scopes.
	 * @param string|null $deploymentUuid  The `lti_deployment` to scope to; optional when the tool has one.
	 *
	 * @return array{access_token: string, token_type: string, expires_in: integer, scope: string}
	 *
	 * @throws LtiValidationException On any assertion/deployment/scope failure (no cross-deployment token — design.md D8).
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 * @spec openspec/changes/connectors-lti-platform-launch/specs/lti-platform/spec.md#requirement-a-launched-tool-can-send-a-grade-back-to-the-placement-req-ltil-003
	 */
	public function issueAccessToken(string $clientAssertion, string $requestedScope, ?string $deploymentUuid = null): array {
		$request = new AgsTokenRequest(clientAssertion: $clientAssertion, requestedScope: $requestedScope);
		$request->deploymentUuid = $deploymentUuid;

		return $this->issueToken(request: $request);

	}//end issueAccessToken()

	/**
	 * Verify the client assertion, scope the token to one deployment and grant only allowed scopes.
	 *
	 * @param AgsTokenRequest $request The token request.
	 *
	 * @return array{access_token: string, token_type: string, expires_in: int, scope: string}
	 *
	 * @throws LtiValidationException When the assertion, the deployment or a scope is refused.
	 */
	private function issueToken(AgsTokenRequest $request): array {
		[$payload, $tool] = $this->launchService->verifyIdTokenSignature(idToken: $request->clientAssertion, registrationType: 'lti_tool');
		$this->assertSelfIssued(payload: $payload);

		// Reused from LtiLaunchService — converts AuthorizationService's
		// generic AuthenticationException into an LtiValidationException
		// (HTTP 401) rather than letting it escape uncaught.
		$this->launchService->validateTiming(payload: $payload);

		$grant = [
			'deploymentUuid' => $this->tokenDeployment(toolUuid: $tool->getUuid(), deploymentUuid: $request->deploymentUuid),
			'toolUuid' => $tool->getUuid(),
			'scopes' => $this->grantedScopes(requestedScope: $request->requestedScope),
		];

		return $this->tokenResponse(accessToken: $this->cacheToken(grant: $grant), scopes: $grant['scopes']);

	}//end issueToken()

	/**
	 * Refuse an assertion that is not the client's own: RFC 7523 wants iss and sub both to be its identifier.
	 *
	 * @param array<string, mixed> $payload The verified assertion's claims.
	 *
	 * @return void
	 *
	 * @throws LtiValidationException When iss is missing or differs from sub.
	 */
	private function assertSelfIssued(array $payload): void {
		// RFC 7523: the assertion's iss and sub MUST both be the client's own
		// identifier — anything else is a forged/mismatched assertion.
		if (empty($payload['iss']) === true || $payload['iss'] !== ($payload['sub'] ?? null)) {
			throw new LtiValidationException(message: 'client_assertion iss/sub mismatch', details: [], httpStatus: 400);
		}

	}//end assertSelfIssued()

	/**
	 * The scopes granted: every requested one, which must all be allowed.
	 *
	 * @param string $requestedScope The space-separated scopes asked for.
	 *
	 * @return list<string> The granted scopes, in the order asked.
	 *
	 * @throws LtiValidationException When no scope is asked, or one is not allowed.
	 */
	private function grantedScopes(string $requestedScope): array {
		$requestedScopes = array_values(array_filter(explode(' ', trim($requestedScope))));
		if ($requestedScopes === []) {
			throw new LtiValidationException(message: 'No scope requested', details: [], httpStatus: 400);
		}

		$grantedScopes = array_values(array_intersect($requestedScopes, self::ALLOWED_SCOPES));
		if ($grantedScopes === [] || count($grantedScopes) !== count($requestedScopes)) {
			throw new LtiValidationException(message: 'Requested scope not permitted', details: ['scope' => $requestedScope], httpStatus: 400);
		}

		return $grantedScopes;

	}//end grantedScopes()

	/**
	 * Mint an opaque access token and cache its grant for the token's lifetime.
	 *
	 * @param array{deploymentUuid: string, toolUuid: string, scopes: list<string>} $grant What the token allows.
	 *
	 * @return string The access token.
	 */
	private function cacheToken(array $grant): string {
		$accessToken = bin2hex(random_bytes(32));
		$this->tokenCache->set('token:' . $accessToken, json_encode($grant), self::ACCESS_TOKEN_TTL_SECONDS);

		$this->logger->info(
			'LtiAgsService: issued access token',
			['deploymentUuid' => $grant['deploymentUuid'], 'scopes' => $grant['scopes']]
		);

		return $accessToken;

	}//end cacheToken()

	/**
	 * The token endpoint's answer.
	 *
	 * @param string $accessToken The access token.
	 * @param list<string> $scopes The granted scopes.
	 *
	 * @return array{access_token: string, token_type: string, expires_in: int, scope: string}
	 */
	private function tokenResponse(string $accessToken, array $scopes): array {
		return [
			'access_token' => $accessToken,
			'token_type' => 'Bearer',
			'expires_in' => self::ACCESS_TOKEN_TTL_SECONDS,
			'scope' => implode(' ', $scopes),
		];

	}//end tokenResponse()

	/**
	 * The one deployment a token for this tool is scoped to.
	 *
	 * @param string      $toolUuid       The asserting tool's registration uuid.
	 * @param string|null $deploymentUuid A deployment the caller named, or null.
	 *
	 * @return string The deployment uuid.
	 *
	 * @throws LtiValidationException When the named deployment is unknown (400) or
	 *                                not the tool's (403), or, unnamed, the tool has
	 *                                no deployment or more than one (400).
	 */
	private function tokenDeployment(string $toolUuid, ?string $deploymentUuid): string {
		if ($deploymentUuid !== null && $deploymentUuid !== '') {
			$deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
			if ($deployment === null) {
				throw new LtiValidationException(message: 'Unknown lti_deployment', details: [], httpStatus: 400);
			}

			if (($deployment->getObject()['ltiToolId'] ?? null) !== $toolUuid) {
				// Per-deployment isolation (design.md D8): the asserting tool
				// must own this deployment.
				throw new LtiValidationException(
					message: 'Deployment is not registered under the asserting tool',
					details: [],
					httpStatus: 403
				);
			}

			return (string)$deployment->getUuid();
		}

		$deployments = $this->resolver->findDeploymentsForTool(toolUuid: $toolUuid);
		if (count($deployments) === 1) {
			return (string)$deployments[0]->getUuid();
		}

		if ($deployments === []) {
			throw new LtiValidationException(message: 'The asserting tool has no deployment', details: [], httpStatus: 400);
		}

		throw new LtiValidationException(
			message: 'The asserting tool has more than one deployment; name one with deployment_id',
			details: ['deployments' => count($deployments)],
			httpStatus: 400
		);
	}//end tokenDeployment()

	/**
	 * Resolve an issued access token to its bound deployment + granted scopes.
	 *
	 * @param string $accessToken The bearer token value.
	 *
	 * @return array{deploymentUuid: string, toolUuid: string, scopes: string[]}|null Null when unknown/expired.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function resolveAccessToken(string $accessToken): ?array {
		$cached = $this->tokenCache->get('token:' . $accessToken);
		if (is_string($cached) === false) {
			return null;
		}

		$decoded = json_decode($cached, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end resolveAccessToken()

	/**
	 * Enforce that a token is valid, carries one of the accepted scopes, and
	 * is bound to the given deployment (route-layer enforcement, REQ-LTI-007
	 * scenario: cross-deployment access rejected 403).
	 *
	 * @param string                    $accessToken    The bearer token value.
	 * @param string                    $deploymentUuid The deployment the calling route is scoped to.
	 * @param string|array<int, string> $requiredScope  The scope the endpoint requires, or the scopes any one of which suffices.
	 *
	 * @return array The resolved token data.
	 *
	 * @throws LtiValidationException 401 on an invalid/unknown token, 403 on a scope/deployment mismatch.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function assertScopedToDeployment(string $accessToken, string $deploymentUuid, string|array $requiredScope): array {
		$tokenData = $this->resolveAccessToken(accessToken: $accessToken);
		if ($tokenData === null) {
			throw new LtiValidationException(message: 'Invalid or expired access token', details: [], httpStatus: 401);
		}

		if (($tokenData['deploymentUuid'] ?? null) !== $deploymentUuid) {
			throw new LtiValidationException(
				message: 'Access token is not scoped to this deployment',
				details: [],
				httpStatus: 403
			);
		}

		$accepted = (array)$requiredScope;
		if (array_intersect($accepted, (array)($tokenData['scopes'] ?? [])) === []) {
			throw new LtiValidationException(
				message: 'Access token lacks the required scope',
				details: ['requiredScope' => implode(' ', $accepted)],
				httpStatus: 403
			);
		}

		return $tokenData;
	}//end assertScopedToDeployment()

	/**
	 * Handle an inbound AGS score POST: enforce the `score` scope + deployment
	 * binding, then publish the received score as a CloudEvent (never a
	 * direct write to `lti_deployment.gradeSink` — REQ-LTI-007).
	 *
	 * @param string $accessToken The bearer token value.
	 * @param string $deploymentUuid The deployment the score was posted under.
	 * @param string $lineItemId The AGS line item identifier.
	 * @param array $scorePayload The IMS AGS score payload.
	 *
	 * @return array{messagesCreated: integer}
	 *
	 * @throws LtiValidationException On an invalid token/scope/deployment mismatch.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function receiveScore(string $accessToken, string $deploymentUuid, string $lineItemId, array $scorePayload): array {
		$score = new AgsScoreSubmission(accessToken: $accessToken, deploymentUuid: $deploymentUuid);
		$score->lineItemId = $lineItemId;
		$score->payload = $scorePayload;

		return $this->receiveSubmittedScore(score: $score);
	}//end receiveScore()

	/**
	 * Check the token's scope and deployment, then publish the score as a CloudEvent.
	 *
	 * @param AgsScoreSubmission $score The posted score.
	 *
	 * @return array{messagesCreated: int}
	 *
	 * @throws LtiValidationException When the token is refused, or the deployment is unknown.
	 */
	private function receiveSubmittedScore(AgsScoreSubmission $score): array {
		$this->assertScopedToDeployment(accessToken: $score->accessToken, deploymentUuid: $score->deploymentUuid, requiredScope: self::SCOPE_SCORE);

		$deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $score->deploymentUuid);
		if ($deployment === null) {
			throw new LtiValidationException(message: 'Unknown lti_deployment', details: [], httpStatus: 400);
		}

		$messages = $this->publishScoreReceived(deployment: $deployment, score: $score);

		$this->logger->info(
			'LtiAgsService: score received, CloudEvent published',
			['deploymentUuid' => $deployment->getUuid(), 'lineItemId' => $score->lineItemId, 'messagesCreated' => count($messages)]
		);

		return ['messagesCreated' => count($messages)];
	}//end receiveSubmittedScore()

	/**
	 * Publish the score-received CloudEvent.
	 *
	 * Never write directly to gradeSink — publish the CloudEvent and let the
	 * consuming app's own event_subscription (with its own
	 * mapping/authorization) do the write (design.md D7).
	 *
	 * @param ObjectEntity $deployment The deployment.
	 * @param AgsScoreSubmission $score The posted score.
	 *
	 * @return array The event messages created.
	 */
	private function publishScoreReceived(ObjectEntity $deployment, AgsScoreSubmission $score): array {
		$deploymentData = $deployment->getObject();

		return $this->eventService->emitCloudEvent(
			type: self::AGS_SCORE_RECEIVED_EVENT_TYPE,
			source: 'lti_deployment/' . $deployment->getUuid(),
			subject: $score->lineItemId,
			data: [
				'deploymentUuid' => $deployment->getUuid(),
				'deploymentId' => ($deploymentData['deploymentId'] ?? null),
				'lineItemId' => $score->lineItemId,
				'gradeSink' => ($deploymentData['gradeSink'] ?? null),
				'score' => $score->payload,
			]
		);
	}//end publishScoreReceived()

	// =========================================================================
	// REQ-LTI-008 — Tool-role outbound score publish / result read
	// =========================================================================

	/**
	 * Build the RFC 7523 JWT-bearer client-credentials configuration for an
	 * outbound call to a platform's token endpoint, reusing
	 * `AuthenticationService::fetchOAuthTokens()` unmodified.
	 *
	 * The `private_key` value is this registration's active-key
	 * `privateKeySecret` — stored as base64(PEM), the exact shape
	 * `AuthenticationService::getRSJWK()` already expects, so no new
	 * outbound-authentication code path is introduced (REQ-LTI-008).
	 *
	 * @param ObjectEntity $platform The `lti_platform` registration.
	 * @param string $scope The requested AGS/NRPS scope.
	 *
	 * @return array The `fetchOAuthTokens()` configuration array.
	 *
	 * @throws LtiValidationException When the platform has no active signing key.
	 */
	private function buildOutboundAssertionConfig(ObjectEntity $platform, string $scope): array {
		$platformData = $platform->getObject();
		$keyEntry = $this->keyService->getActiveKeyEntry(registrationType: 'lti_platform', registrationUuid: $platform->getUuid());
		if ($keyEntry === null) {
			throw new LtiValidationException(message: 'lti_platform registration has no active signing key', details: [], httpStatus: 500);
		}

		$tokenUrl = ($platformData['authTokenUrl'] ?? '');
		$now = time();

		$assertionClaims = [
			'iss' => ($platformData['clientId'] ?? ''),
			'sub' => ($platformData['clientId'] ?? ''),
			'aud' => $tokenUrl,
			'iat' => $now,
			'exp' => ($now + 300),
			'jti' => bin2hex(random_bytes(16)),
		];

		return [
			'grant_type' => 'client_credentials',
			'scope' => $scope,
			'authentication' => 'body',
			'client_id' => ($platformData['clientId'] ?? ''),
			'client_secret' => '',
			'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
			'private_key' => $keyEntry['privateKeySecret'],
			'x5t' => null,
			'payload' => json_encode($assertionClaims),
			'tokenUrl' => $tokenUrl,
		];

	}//end buildOutboundAssertionConfig()

	/**
	 * Publish a score to a Platform's AGS line item (Tool role).
	 *
	 * @param string $deploymentUuid The `lti_deployment` (must reference an `lti_platform`).
	 * @param string $lineItemUrl The line item's AGS endpoint (`.../lineitems/{id}`).
	 * @param array $scorePayload The IMS AGS score payload to POST to `{lineItemUrl}/scores`.
	 *
	 * @return array{statusCode: integer}
	 *
	 * @throws LtiValidationException When the deployment/platform/active key cannot be resolved, or the token
	 *                                endpoint call fails (never silently dropped — REQ-LTI-008 scenario 2).
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-ags-outbound-score-publish-and-result-read-tool-role-req-lti-008
	 */
	public function publishScore(string $deploymentUuid, string $lineItemUrl, array $scorePayload): array {
		$platform = $this->resolveOutboundPlatform(deploymentUuid: $deploymentUuid);

		try {
			$accessToken = $this->authenticationService->fetchOAuthTokens(
				configuration: $this->buildOutboundAssertionConfig(platform: $platform, scope: self::SCOPE_SCORE)
			);
		} catch (Throwable $exception) {
			// Never silently discard the score: log and propagate so the
			// caller surfaces a proper error rather than a dropped write.
			$this->logger->error(
				'LtiAgsService: token endpoint failure during score publish',
				['deploymentUuid' => $deploymentUuid, 'error' => $exception->getMessage()]
			);
			throw new LtiValidationException(message: 'Failed to obtain AGS access token from platform', details: [], httpStatus: 502);
		}

		return $this->dispatchAgsCall(
			url: $lineItemUrl . '/scores',
			method: 'POST',
			accessToken: $accessToken,
			body: $scorePayload
		);

	}//end publishScore()

	/**
	 * Read a result from a Platform's AGS line item (Tool role).
	 *
	 * @param string $deploymentUuid The `lti_deployment` (must reference an `lti_platform`).
	 * @param string $lineItemUrl The line item's AGS endpoint.
	 *
	 * @return array{statusCode: integer, body: mixed}
	 *
	 * @throws LtiValidationException When the deployment/platform/active key cannot be resolved, or the token
	 *                                endpoint call fails.
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-ags-outbound-score-publish-and-result-read-tool-role-req-lti-008
	 */
	public function readResult(string $deploymentUuid, string $lineItemUrl): array {
		return $this->pullResourceForDeployment(deploymentUuid: $deploymentUuid, url: $lineItemUrl . '/results', scope: self::SCOPE_RESULT);
	}//end readResult()

	/**
	 * Generic Tool-role outbound GET pull against a Platform-hosted resource,
	 * reusing the RFC 7523 JWT-bearer client-credentials grant scoped to the
	 * given AGS/NRPS scope. Shared by {@see readResult()} (AGS) and
	 * {@see \OCA\Integriq\Service\Lti\LtiNrpsService::pullRoster()}
	 * (NRPS) so the token-exchange + dispatch shape is not duplicated
	 * (REQ-LTI-008/REQ-LTI-009 both reuse the same outbound mechanism).
	 *
	 * @param string $deploymentUuid The `lti_deployment` (must reference an `lti_platform`).
	 * @param string $url The absolute Platform-hosted resource URL to GET.
	 * @param string $scope The AGS/NRPS scope to request.
	 *
	 * @return array{statusCode: integer, body: mixed}
	 *
	 * @throws LtiValidationException When the deployment/platform/active key cannot be resolved, or the token
	 *                                endpoint call fails.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function pullResourceForDeployment(string $deploymentUuid, string $url, string $scope): array {
		$platform = $this->resolveOutboundPlatform(deploymentUuid: $deploymentUuid);

		try {
			$accessToken = $this->authenticationService->fetchOAuthTokens(
				configuration: $this->buildOutboundAssertionConfig(platform: $platform, scope: $scope)
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'LtiAgsService: token endpoint failure during outbound pull',
				['deploymentUuid' => $deploymentUuid, 'scope' => $scope, 'error' => $exception->getMessage()]
			);
			throw new LtiValidationException(message: 'Failed to obtain access token from platform', details: [], httpStatus: 502);
		}

		return $this->dispatchAgsCall(url: $url, method: 'GET', accessToken: $accessToken, body: null);
	}//end pullResourceForDeployment()

	/**
	 * Resolve the `lti_platform` a deployment's outbound AGS calls target.
	 *
	 * @param string $deploymentUuid The `lti_deployment` UUID.
	 *
	 * @return ObjectEntity The resolved `lti_platform` registration.
	 *
	 * @throws LtiValidationException When the deployment/platform cannot be resolved.
	 */
	private function resolveOutboundPlatform(string $deploymentUuid): ObjectEntity {
		$deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
		if ($deployment === null) {
			throw new LtiValidationException(message: 'Unknown lti_deployment', details: [], httpStatus: 400);
		}

		$deploymentData = $deployment->getObject();
		$platformUuid = ($deploymentData['ltiPlatformId'] ?? null);
		if (empty($platformUuid) === true) {
			throw new LtiValidationException(message: 'Deployment does not reference an lti_platform', details: [], httpStatus: 400);
		}

		$platform = $this->resolver->findRegistrationByUuid(registrationType: 'lti_platform', registrationUuid: $platformUuid);
		if ($platform === null) {
			throw new LtiValidationException(message: 'Referenced lti_platform not found', details: [], httpStatus: 400);
		}

		return $platform;
	}//end resolveOutboundPlatform()

	/**
	 * Dispatch the actual AGS REST call through the existing outbound HTTP
	 * call machinery (`CallService`), inheriting CallLog observability —
	 * distinct from the token-endpoint exchange above, which reuses
	 * `fetchOAuthTokens()` unmodified per design.md.
	 *
	 * @param string $url The absolute AGS endpoint URL.
	 * @param string $method `GET` or `POST`.
	 * @param string $accessToken The bearer token obtained from the platform's token endpoint.
	 * @param array|null $body The JSON body for a POST, or null for a GET.
	 *
	 * @return array{statusCode: integer, body: mixed}
	 */
	private function dispatchAgsCall(string $url, string $method, string $accessToken, ?array $body): array {
		$parts = parse_url($url);

		// Ad-hoc, never-persisted source: see LtiJwksResolverService::fetchJwks()
		// for why its call is not written as a call log.
		$callLog = $this->callService->call(
			source: $this->adhocSource(parts: $parts),
			endpoint: $this->endpointOf(parts: $parts),
			method: $method,
			config: $this->requestConfig(accessToken: $accessToken, body: $body),
			read: ($method === 'GET'),
			persistLog: false
		);

		$logData = $callLog->getObject();

		return [
			'statusCode' => ($logData['response']['statusCode'] ?? $logData['statusCode'] ?? 0),
			'body' => ($logData['response']['body'] ?? null),
		];

	}//end dispatchAgsCall()

	/**
	 * The ad-hoc, never-persisted source for the URL's scheme, host and port.
	 *
	 * @param array<string, int|string>|false $parts The parsed AGS URL.
	 *
	 * @return ObjectEntity
	 */
	private function adhocSource(array|false $parts): ObjectEntity {
		$location = (($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? ''));
		if (isset($parts['port']) === true) {
			$location .= ':' . $parts['port'];
		}

		$source = new ObjectEntity();
		$source->setUuid('lti-ags-adhoc');
		$source->setObject(['name' => 'LTI AGS call (ad-hoc, not persisted)', 'isEnabled' => true, 'location' => $location]);

		return $source;

	}//end adhocSource()

	/**
	 * The URL's path with its query, as the endpoint on the ad-hoc source.
	 *
	 * @param array<string, int|string>|false $parts The parsed AGS URL.
	 *
	 * @return string
	 */
	private function endpointOf(array|false $parts): string {
		$endpoint = (string)($parts['path'] ?? '');
		if (isset($parts['query']) === true) {
			$endpoint .= '?' . $parts['query'];
		}

		return $endpoint;

	}//end endpointOf()

	/**
	 * The bearer and score content-type headers, with the JSON body for a POST.
	 *
	 * @param string     $accessToken The platform's bearer token.
	 * @param array|null $body        The JSON body, or null for a GET.
	 *
	 * @return array<string, mixed>
	 */
	private function requestConfig(string $accessToken, ?array $body): array {
		$config = [
			'headers' => [
				'Authorization' => 'Bearer ' . $accessToken,
				'Content-Type' => 'application/vnd.ims.lis.v1.score+json',
			],
		];
		if ($body !== null) {
			$config['json'] = $body;
		}

		return $config;

	}//end requestConfig()
}//end class
