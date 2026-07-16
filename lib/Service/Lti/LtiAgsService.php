<?php
/**
 * OpenConnector LtiAgsService.
 *
 * Assignment & Grade Services (AGS): RFC 7523 JWT-bearer service-token
 * issuance (Platform role), inbound score/line-item scope enforcement fanning
 * a received score out as a CloudEvent, and Tool-role outbound score publish
 * / result read reusing the existing OAuth + HTTP call machinery unmodified.
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
 * @spec openspec/specs/lti-platform/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Lti;

use OCA\OpenConnector\Exception\LtiValidationException;
use OCA\OpenConnector\Service\AuthenticationService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\EventService;
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
class LtiAgsService
{

    /**
     * AGS scope URIs (1EdTech LTI Advantage AGS 2.0).
     *
     * @var string
     */
    public const SCOPE_LINEITEM = 'https://purl.imsglobal.org/spec/lti-ags/scope/lineitem';
    public const SCOPE_SCORE    = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';
    public const SCOPE_RESULT   = 'https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly';
    public const SCOPE_NRPS     = 'https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly';

    /**
     * All scopes a token issued by this service may carry (REQ-LTI-007/009).
     *
     * @var string[]
     */
    public const ALLOWED_SCOPES = [
        self::SCOPE_LINEITEM,
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
     * @param LtiRegistrationResolverService $resolver              Registration/deployment lookups.
     * @param LtiLaunchService               $launchService         Reused JWS-verification + timing/replay validation
     *                                                              (`validateTiming()`) for client assertions.
     * @param LtiKeyService                  $keyService            This instance's own signing keys (Tool-role assertions).
     * @param AuthenticationService          $authenticationService Reused `fetchOAuthTokens()` (Tool-role outbound grant).
     * @param CallService                    $callService           Outbound HTTP call engine for the AGS REST call itself.
     * @param EventService                   $eventService          Reused `emitCloudEvent()` (AGS score passback fan-out).
     * @param ICacheFactory                  $cacheFactory          Cache factory for issued-token storage.
     * @param LoggerInterface                $logger                Logger for issuance/dispatch outcomes.
     */
    public function __construct(
        private readonly LtiRegistrationResolverService $resolver,
        private readonly LtiLaunchService $launchService,
        private readonly LtiKeyService $keyService,
        private readonly AuthenticationService $authenticationService,
        private readonly CallService $callService,
        private readonly EventService $eventService,
        ICacheFactory $cacheFactory,
        private readonly LoggerInterface $logger,
    ) {
        $this->tokenCache = $cacheFactory->createDistributed('openconnector.lti.token');

    }//end __construct()

    // =========================================================================
    // REQ-LTI-007 — service-token issuance (Platform role)
    // =========================================================================

    /**
     * RFC 7523 JWT-bearer client-credentials grant: exchange a signed
     * `client_assertion` for a deployment-scoped access token.
     *
     * @param string $clientAssertion The `client_assertion` JWT posted to `POST /api/lti/token`.
     * @param string $requestedScope  Space-separated requested scopes.
     * @param string $deploymentUuid  The `lti_deployment` this token is scoped to.
     *
     * @return array{access_token: string, token_type: string, expires_in: integer, scope: string}
     *
     * @throws LtiValidationException On any assertion/deployment/scope failure (no cross-deployment token — design.md D8).
     *
     * @spec openspec/specs/lti-platform/spec.md
     */
    public function issueAccessToken(string $clientAssertion, string $requestedScope, string $deploymentUuid): array
    {
        [$payload, $tool] = $this->launchService->verifyIdTokenSignature(idToken: $clientAssertion, registrationType: 'lti_tool');

        // RFC 7523: the assertion's iss and sub MUST both be the client's own
        // identifier — anything else is a forged/mismatched assertion.
        if (empty($payload['iss']) === true || $payload['iss'] !== ($payload['sub'] ?? null)) {
            throw new LtiValidationException(message: 'client_assertion iss/sub mismatch', details: [], httpStatus: 400);
        }

        // Reused from LtiLaunchService — converts AuthorizationService's
        // generic AuthenticationException into an LtiValidationException
        // (HTTP 401) rather than letting it escape uncaught.
        $this->launchService->validateTiming(payload: $payload);

        $deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
        if ($deployment === null) {
            throw new LtiValidationException(message: 'Unknown lti_deployment', details: [], httpStatus: 400);
        }

        $deploymentData = $deployment->getObject();
        if (($deploymentData['ltiToolId'] ?? null) !== $tool->getUuid()) {
            // Per-deployment isolation (design.md D8): the asserting tool
            // must own this deployment — never issue a token scoped to a
            // deployment belonging to a different tool registration.
            throw new LtiValidationException(
                message: 'Deployment is not registered under the asserting tool',
                details: [],
                httpStatus: 403
            );
        }

        $requestedScopes = array_values(array_filter(explode(' ', trim($requestedScope))));
        if ($requestedScopes === []) {
            throw new LtiValidationException(message: 'No scope requested', details: [], httpStatus: 400);
        }

        $grantedScopes = array_values(array_intersect($requestedScopes, self::ALLOWED_SCOPES));
        if ($grantedScopes === [] || count($grantedScopes) !== count($requestedScopes)) {
            throw new LtiValidationException(message: 'Requested scope not permitted', details: ['scope' => $requestedScope], httpStatus: 400);
        }

        $accessToken = bin2hex(random_bytes(32));
        $this->tokenCache->set(
            'token:'.$accessToken,
            json_encode(
                [
                    'deploymentUuid' => $deployment->getUuid(),
                    'toolUuid'       => $tool->getUuid(),
                    'scopes'         => $grantedScopes,
                ]
            ),
            self::ACCESS_TOKEN_TTL_SECONDS
        );

        $this->logger->info(
            'LtiAgsService: issued access token',
            ['deploymentUuid' => $deployment->getUuid(), 'scopes' => $grantedScopes]
        );

        return [
            'access_token' => $accessToken,
            'token_type'   => 'Bearer',
            'expires_in'   => self::ACCESS_TOKEN_TTL_SECONDS,
            'scope'        => implode(' ', $grantedScopes),
        ];

    }//end issueAccessToken()

    /**
     * Resolve an issued access token to its bound deployment + granted scopes.
     *
     * @param string $accessToken The bearer token value.
     *
     * @return array{deploymentUuid: string, toolUuid: string, scopes: string[]}|null Null when unknown/expired.
     *
     * @spec openspec/specs/lti-platform/spec.md
     */
    public function resolveAccessToken(string $accessToken): ?array
    {
        $cached = $this->tokenCache->get('token:'.$accessToken);
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
     * Enforce that a token is valid, carries the required scope, and is
     * bound to the given deployment (route-layer enforcement, REQ-LTI-007
     * scenario: cross-deployment access rejected 403).
     *
     * @param string $accessToken    The bearer token value.
     * @param string $deploymentUuid The deployment the calling route is scoped to.
     * @param string $requiredScope  The scope the endpoint requires.
     *
     * @return array The resolved token data.
     *
     * @throws LtiValidationException 401 on an invalid/unknown token, 403 on a scope/deployment mismatch.
     *
     * @spec openspec/specs/lti-platform/spec.md
     */
    public function assertScopedToDeployment(string $accessToken, string $deploymentUuid, string $requiredScope): array
    {
        $tokenData = $this->resolveAccessToken(accessToken: $accessToken);
        if ($tokenData === null) {
            throw new LtiValidationException(message: 'Invalid or expired access token', details: [], httpStatus: 401);
        }

        if ($tokenData['deploymentUuid'] !== $deploymentUuid) {
            throw new LtiValidationException(
                message: 'Access token is not scoped to this deployment',
                details: [],
                httpStatus: 403
            );
        }

        if (in_array(needle: $requiredScope, haystack: $tokenData['scopes'], strict: true) === false) {
            throw new LtiValidationException(
                message: 'Access token lacks the required scope',
                details: ['requiredScope' => $requiredScope],
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
     * @param string $accessToken    The bearer token value.
     * @param string $deploymentUuid The deployment the score was posted under.
     * @param string $lineItemId     The AGS line item identifier.
     * @param array  $scorePayload   The IMS AGS score payload.
     *
     * @return array{messagesCreated: integer}
     *
     * @throws LtiValidationException On an invalid token/scope/deployment mismatch.
     *
     * @spec openspec/specs/lti-platform/spec.md
     */
    public function receiveScore(string $accessToken, string $deploymentUuid, string $lineItemId, array $scorePayload): array
    {
        $this->assertScopedToDeployment(accessToken: $accessToken, deploymentUuid: $deploymentUuid, requiredScope: self::SCOPE_SCORE);

        $deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
        if ($deployment === null) {
            throw new LtiValidationException(message: 'Unknown lti_deployment', details: [], httpStatus: 400);
        }

        $deploymentData = $deployment->getObject();

        // Never write directly to gradeSink — publish the CloudEvent and let
        // the consuming app's own event_subscription (with its own
        // mapping/authorization) do the write (design.md D7).
        $messages = $this->eventService->emitCloudEvent(
            type: self::AGS_SCORE_RECEIVED_EVENT_TYPE,
            source: 'lti_deployment/'.$deployment->getUuid(),
            subject: $lineItemId,
            data: [
                'deploymentUuid' => $deployment->getUuid(),
                'deploymentId'   => ($deploymentData['deploymentId'] ?? null),
                'lineItemId'     => $lineItemId,
                'gradeSink'      => ($deploymentData['gradeSink'] ?? null),
                'score'          => $scorePayload,
            ]
        );

        $this->logger->info(
            'LtiAgsService: score received, CloudEvent published',
            ['deploymentUuid' => $deployment->getUuid(), 'lineItemId' => $lineItemId, 'messagesCreated' => count($messages)]
        );

        return ['messagesCreated' => count($messages)];

    }//end receiveScore()

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
     * @param string       $scope    The requested AGS/NRPS scope.
     *
     * @return array The `fetchOAuthTokens()` configuration array.
     *
     * @throws LtiValidationException When the platform has no active signing key.
     */
    private function buildOutboundAssertionConfig(ObjectEntity $platform, string $scope): array
    {
        $platformData = $platform->getObject();
        $keyEntry     = $this->keyService->getActiveKeyEntry(registrationType: 'lti_platform', registrationUuid: $platform->getUuid());
        if ($keyEntry === null) {
            throw new LtiValidationException(message: 'lti_platform registration has no active signing key', details: [], httpStatus: 500);
        }

        $tokenUrl = ($platformData['authTokenUrl'] ?? '');
        $now      = time();

        $assertionClaims = [
            'iss' => ($platformData['clientId'] ?? ''),
            'sub' => ($platformData['clientId'] ?? ''),
            'aud' => $tokenUrl,
            'iat' => $now,
            'exp' => ($now + 300),
            'jti' => bin2hex(random_bytes(16)),
        ];

        return [
            'grant_type'            => 'client_credentials',
            'scope'                 => $scope,
            'authentication'        => 'body',
            'client_id'             => ($platformData['clientId'] ?? ''),
            'client_secret'         => '',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'private_key'           => $keyEntry['privateKeySecret'],
            'x5t'                   => null,
            'payload'               => json_encode($assertionClaims),
            'tokenUrl'              => $tokenUrl,
        ];

    }//end buildOutboundAssertionConfig()

    /**
     * Publish a score to a Platform's AGS line item (Tool role).
     *
     * @param string $deploymentUuid The `lti_deployment` (must reference an `lti_platform`).
     * @param string $lineItemUrl    The line item's AGS endpoint (`.../lineitems/{id}`).
     * @param array  $scorePayload   The IMS AGS score payload to POST to `{lineItemUrl}/scores`.
     *
     * @return array{statusCode: integer}
     *
     * @throws LtiValidationException When the deployment/platform/active key cannot be resolved, or the token
     *                                 endpoint call fails (never silently dropped — REQ-LTI-008 scenario 2).
     *
     * @spec openspec/specs/lti-platform/spec.md#requirement-ags-outbound-score-publish-and-result-read-tool-role-req-lti-008
     */
    public function publishScore(string $deploymentUuid, string $lineItemUrl, array $scorePayload): array
    {
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
            url: $lineItemUrl.'/scores',
            method: 'POST',
            accessToken: $accessToken,
            body: $scorePayload
        );

    }//end publishScore()

    /**
     * Read a result from a Platform's AGS line item (Tool role).
     *
     * @param string $deploymentUuid The `lti_deployment` (must reference an `lti_platform`).
     * @param string $lineItemUrl    The line item's AGS endpoint.
     *
     * @return array{statusCode: integer, body: mixed}
     *
     * @throws LtiValidationException When the deployment/platform/active key cannot be resolved, or the token
     *                                 endpoint call fails.
     *
     * @spec openspec/specs/lti-platform/spec.md#requirement-ags-outbound-score-publish-and-result-read-tool-role-req-lti-008
     */
    public function readResult(string $deploymentUuid, string $lineItemUrl): array
    {
        return $this->pullResourceForDeployment(deploymentUuid: $deploymentUuid, url: $lineItemUrl.'/results', scope: self::SCOPE_RESULT);

    }//end readResult()

    /**
     * Generic Tool-role outbound GET pull against a Platform-hosted resource,
     * reusing the RFC 7523 JWT-bearer client-credentials grant scoped to the
     * given AGS/NRPS scope. Shared by {@see readResult()} (AGS) and
     * {@see \OCA\OpenConnector\Service\Lti\LtiNrpsService::pullRoster()}
     * (NRPS) so the token-exchange + dispatch shape is not duplicated
     * (REQ-LTI-008/REQ-LTI-009 both reuse the same outbound mechanism).
     *
     * @param string $deploymentUuid The `lti_deployment` (must reference an `lti_platform`).
     * @param string $url            The absolute Platform-hosted resource URL to GET.
     * @param string $scope          The AGS/NRPS scope to request.
     *
     * @return array{statusCode: integer, body: mixed}
     *
     * @throws LtiValidationException When the deployment/platform/active key cannot be resolved, or the token
     *                                 endpoint call fails.
     *
     * @spec openspec/specs/lti-platform/spec.md
     */
    public function pullResourceForDeployment(string $deploymentUuid, string $url, string $scope): array
    {
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
    private function resolveOutboundPlatform(string $deploymentUuid): ObjectEntity
    {
        $deployment = $this->resolver->findDeploymentByUuid(deploymentUuid: $deploymentUuid);
        if ($deployment === null) {
            throw new LtiValidationException(message: 'Unknown lti_deployment', details: [], httpStatus: 400);
        }

        $deploymentData = $deployment->getObject();
        $platformUuid   = ($deploymentData['ltiPlatformId'] ?? null);
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
     * @param string     $url         The absolute AGS endpoint URL.
     * @param string     $method      `GET` or `POST`.
     * @param string     $accessToken The bearer token obtained from the platform's token endpoint.
     * @param array|null $body        The JSON body for a POST, or null for a GET.
     *
     * @return array{statusCode: integer, body: mixed}
     */
    private function dispatchAgsCall(string $url, string $method, string $accessToken, ?array $body): array
    {
        $parts    = parse_url($url);
        $location = (($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? ''));
        if (isset($parts['port']) === true) {
            $location .= ':'.$parts['port'];
        }

        $endpoint = ($parts['path'] ?? '');
        if (isset($parts['query']) === true) {
            $endpoint .= '?'.$parts['query'];
        }

        $source = new ObjectEntity();
        $source->setUuid('lti-ags-adhoc');
        $source->setObject(
            [
                'name'      => 'LTI AGS call (ad-hoc, not persisted)',
                'isEnabled' => true,
                'location'  => $location,
            ]
        );

        $config = [
            'headers' => [
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type'  => 'application/vnd.ims.lis.v1.score+json',
            ],
        ];
        if ($body !== null) {
            $config['json'] = $body;
        }

        $callLog = $this->callService->call(
            source: $source,
            endpoint: $endpoint,
            method: $method,
            config: $config,
            read: ($method === 'GET')
        );

        $logData = $callLog->getObject();

        return [
            'statusCode' => ($logData['response']['statusCode'] ?? $logData['statusCode'] ?? 0),
            'body'       => ($logData['response']['body'] ?? null),
        ];

    }//end dispatchAgsCall()
}//end class
