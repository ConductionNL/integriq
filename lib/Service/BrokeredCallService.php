<?php
/**
 * OpenConnector Brokered Call Service.
 *
 * Isolates ALL coupling between the outbound HTTP call engine and the
 * OpenRegister credential broker (`OCA\OpenRegister\Service\Credential\
 * CredentialBrokerService`). A Source whose merged configuration carries
 * `authentication.credentialRef` is validated, resolved, and dispatched
 * IN-PROCESS through the broker's constrained proxy (owner IDOR →
 * allowedApps → provider allowRules → host-lock, secret injected
 * server-side) instead of the engine's internal Guzzle client. The broker's
 * `array{status, headers, body}` return is adapted to a PSR-7 response so
 * `CallService`'s CallLog / redaction / retention / rate-limit pipeline runs
 * unchanged. The consuming app NEVER holds the secret — with brokering it
 * never enters this process at all (REQ-SBC-004).
 *
 * Config errors (sibling embedded secrets, ambiguous names, missing
 * credentials, v1 scope violations, unavailable broker, sessionless calls
 * against a broker without acting-user support) throw
 * {@see BrokeredCallConfigurationException}; the engine maps them to
 * synthetic 409 config-error CallLogs. There is NO fallback to embedded
 * authentication under any circumstance.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use GuzzleHttp\Psr7\Response;
use OCA\OpenConnector\Exception\BrokeredCallConfigurationException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException;
use OCA\OpenRegister\Service\Credential\CredentialUpstreamException;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\App\IAppManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionMethod;
use Throwable;

/**
 * Validates, resolves, and dispatches brokered (credentialRef) source calls.
 *
 * The class-complexity suppression mirrors CallService: the guard chain is a
 * deliberately exhaustive set of small, single-purpose validators (every
 * violation is its own actionable 409) — splitting them across classes would
 * fragment the one brokered write path without reducing real complexity. The
 * app-side INJECTION path (hydrate* / resolveInjectableSecret) reuses the very
 * same resolution infrastructure (broker resolution, credentialName→id, owner
 * pinning) as the proxy path; keeping both here is what keeps that shared logic
 * DRY — at the cost of the class length, which is why it too is suppressed.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 *
 * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
 */
class BrokeredCallService
{

    /**
     * The app id the broker authorises against the credential's allowedApps.
     *
     * @var string
     */
    public const APP_ID = 'openconnector';

    /**
     * FQCN of the OpenRegister credential broker (resolved lazily — the class
     * only exists on OpenRegister versions that ship the credential broker).
     *
     * @var string
     */
    public const BROKER_CLASS = 'OCA\OpenRegister\Service\Credential\CredentialBrokerService';

    /**
     * OR register slug holding `brokeredcredential` metadata objects.
     *
     * @var string
     */
    public const CREDENTIAL_REGISTER = 'credential-broker';

    /**
     * OR schema slug for `brokeredcredential` metadata objects.
     *
     * @var string
     */
    public const CREDENTIAL_SCHEMA = 'brokeredcredential';

    /**
     * Name of the broker's optional acting-user parameter for in-process
     * trusted callers (ships with OR change `credential-doriath-leaf`;
     * feature-detected via reflection, never assumed).
     *
     * @var string
     */
    private const ACTING_USER_PARAMETER = 'actingUserId';

    /**
     * Lazily resolved broker instance (per-process memo).
     *
     * @var object|null
     */
    private ?object $broker = null;

    /**
     * Per-run memo of credentialName → credentialId resolutions, keyed by
     * `<actingUid>:<name>`. Service instances live per request / cron
     * process, so this is per-run memoisation only (design D5) — never a
     * cross-run cache (ownership and names can change).
     *
     * @var array<string, string>
     */
    private array $nameResolutionCache = [];

    /**
     * Constructor.
     *
     * @param ORObjectService $objectService OR object service (credential metadata reads; hard app dependency).
     * @param IAppManager     $appManager    App manager used for the openregister availability guard.
     * @param IUserSession    $userSession   Session used to determine the acting user.
     * @param IUserManager    $userManager   User manager used to assert the pinned owner still exists and is enabled.
     * @param LoggerInterface $logger        Logger for secret-free refusal diagnostics.
     */
    public function __construct(
        private readonly ORObjectService $objectService,
        private readonly IAppManager $appManager,
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()

    /**
     * Returns true when a merged call configuration carries an authentication credentialRef.
     *
     * Selection happens on the MERGED source configuration (CallService Phase 7
     * output) — before `normaliseRequestConfig()` strips authentication keys.
     *
     * @param array $config The merged call configuration.
     *
     * @return boolean Whether the call must be dispatched through the broker.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
     */
    public function hasCredentialRef(array $config): bool
    {
        $authentication = ($config['authentication'] ?? null);

        return (is_array($authentication) === true
            && array_key_exists('credentialRef', $authentication) === true);

    }//end hasCredentialRef()

    /**
     * Whether a source's authentication config carries app-inject credential placeholders.
     *
     * The APP-SIDE INJECTION path is the counterpart to the host-locked proxy above, for
     * arbitrary / self-hosted Sources that cannot be host-locked from OpenRegister's immutable
     * provider catalogue (ocon#147). Instead of proxying the whole call, ANY leaf value under
     * `configuration.authentication` may be a placeholder of the shape
     * `{"credentialRef": {"credentialId": "<uuid>"}}` (or `credentialName`). At call time the
     * secret is resolved from Doriath through the broker and substituted in place, so every
     * existing auth mechanism (apikey header, HTTP Basic, OAuth token exchange, JWT signing)
     * runs unchanged against a hydrated config — the schema stores only the reference, never
     * the secret.
     *
     * This is deliberately DISTINCT from {@see hasCredentialRef()}: that detects the TOP-LEVEL
     * proxy trigger (`authentication.credentialRef`), whereas a placeholder is NESTED at a
     * secret's own position (`authentication.<field> = {credentialRef: …}`). The two never
     * collide, and the engine only ever hydrates when the call is NOT a proxy call.
     *
     * @param array $sourceData The raw source data array.
     *
     * @return boolean Whether the source carries at least one injectable credential placeholder.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
     */
    public function hasInjectableCredentials(array $sourceData): bool
    {
        $authentication = ($sourceData['configuration']['authentication'] ?? null);
        if (is_array($authentication) === false) {
            return false;
        }

        return $this->containsPlaceholder(node: $authentication);

    }//end hasInjectableCredentials()

    /**
     * Resolves every credential placeholder under `configuration.authentication` to its plaintext.
     *
     * Walks the authentication subtree and replaces each `{credentialRef: {...}}` placeholder
     * with the raw secret resolved from Doriath through the broker's
     * {@see CredentialBrokerService::resolveInjectable()} (owner + allowedApps guards; the
     * host-lock and allow-rules do not apply to an app-injected secret). The returned source
     * data is byte-for-byte the input except that the placeholders are now literal secrets,
     * ready for the engine's normal Twig auth rendering.
     *
     * Every failure — an unavailable/older broker, an unknown or ambiguous credential, a
     * broker refusal, or a credential that is actually a host-locked PROXY credential (not
     * app-injectable) — throws {@see BrokeredCallConfigurationException} with a secret-free,
     * actionable message, exactly like the proxy path, so the engine records a synthetic 409
     * config-error CallLog. There is NO fallback to an embedded secret.
     *
     * @param array $sourceData The raw source data array (with placeholders under authentication).
     *
     * @return array The source data with authentication placeholders replaced by their secrets.
     *
     * @throws BrokeredCallConfigurationException On any resolution failure (mapped to a 409 CallLog).
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
     */
    public function hydrateInjectableCredentials(array $sourceData): array
    {
        $this->assertBrokerAvailable();
        $this->resolveBroker();

        $authentication = ($sourceData['configuration']['authentication'] ?? []);
        if (is_array($authentication) === false) {
            return $sourceData;
        }

        $sourceData['configuration']['authentication'] = $this->hydrateNode(node: $authentication);

        return $sourceData;

    }//end hydrateInjectableCredentials()

    /**
     * Whether any leaf under the given node is a credential placeholder (recursive).
     *
     * @param array $node The authentication subtree (or a nested array within it).
     *
     * @return boolean Whether a `{credentialRef: {...}}` placeholder is present.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
     */
    private function containsPlaceholder(array $node): bool
    {
        foreach ($node as $value) {
            if ($this->isPlaceholder(value: $value) === true) {
                return true;
            }

            if (is_array($value) === true && $this->containsPlaceholder(node: $value) === true) {
                return true;
            }
        }

        return false;

    }//end containsPlaceholder()

    /**
     * Whether a value is a credential placeholder (`{credentialRef: {...}}`, that key only).
     *
     * The single-key check keeps a NESTED placeholder distinct from a TOP-LEVEL proxy
     * `authentication` block (which is handled by {@see hasCredentialRef()} before hydration
     * ever runs) and from an ordinary object that merely happens to carry a credentialRef
     * alongside other data.
     *
     * @param mixed $value The candidate value.
     *
     * @return boolean Whether the value is a credential placeholder.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
     */
    private function isPlaceholder(mixed $value): bool
    {
        return (is_array($value) === true
            && array_keys($value) === ['credentialRef']
            && is_array($value['credentialRef']) === true);

    }//end isPlaceholder()

    /**
     * Recursively replaces placeholders under a node with their resolved secrets.
     *
     * @param array $node The authentication subtree (or a nested array within it).
     *
     * @return array The node with every placeholder replaced by its plaintext secret.
     *
     * @throws BrokeredCallConfigurationException On any resolution failure.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
     */
    private function hydrateNode(array $node): array
    {
        $hydrated = [];
        foreach ($node as $key => $value) {
            if ($this->isPlaceholder(value: $value) === true) {
                $hydrated[$key] = $this->resolveInjectableSecret(ref: $value['credentialRef']);
                continue;
            }

            if (is_array($value) === true) {
                $hydrated[$key] = $this->hydrateNode(node: $value);
                continue;
            }

            $hydrated[$key] = $value;
        }//end foreach

        return $hydrated;

    }//end hydrateNode()

    /**
     * Resolves one placeholder reference to its raw secret via the broker.
     *
     * Reuses the proxy path's credential resolution (name → id, existence, owner-pinned acting
     * user) and then reads the plaintext through the broker's inject-only resolver. A null
     * return means the credential is NOT inject-only — i.e. a host-locked proxy credential,
     * whose secret must never leave OpenRegister; that is a hard config error (the source must
     * either reference a generic inject-only credential here, or use a top-level credentialRef
     * proxy instead).
     *
     * @param array $ref The inner reference (`{credentialId|credentialName}`).
     *
     * @return string The resolved plaintext secret.
     *
     * @throws BrokeredCallConfigurationException On validation, resolution, or refusal failure.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
     */
    private function resolveInjectableSecret(array $ref): string
    {
        $validated    = $this->validateInnerRef(ref: $ref);
        $credentialId = $this->resolveCredentialId(ref: $validated);
        $credential   = $this->loadCredential(credentialId: $credentialId, ref: $validated);
        $actingUserId = $this->resolveActingUser(credential: $credential, credentialId: $credentialId);

        $broker = $this->resolveBroker();
        if (method_exists($broker, 'resolveInjectable') === false) {
            throw new BrokeredCallConfigurationException(
                message: 'This source uses an app-injected credential (a credentialRef placeholder under '
                    .'authentication), but the installed OpenRegister credential broker does not support '
                    .'app-side injection yet. Upgrade OpenRegister to a version whose CredentialBrokerService '
                    .'provides resolveInjectable(), or embed the secret via the broker proxy instead.'
            );
        }

        // Positional (not named) arguments: $broker is only known to be an object with a
        // resolveInjectable() method, so its parameter names cannot be verified statically.
        $arguments = [$credentialId, self::APP_ID];
        if ($actingUserId !== null) {
            $arguments[] = $actingUserId;
        }

        try {
            $secret = $broker->resolveInjectable(...$arguments);
        } catch (CredentialAccessDeniedException $exception) {
            $this->logger->warning(
                '[BrokeredCallService] credential broker refused an app-side injection',
                [
                    'credentialId' => $credentialId,
                    'sessionless'  => ($actingUserId !== null),
                    'reason'       => $exception->getMessage(),
                ]
            );
            throw new BrokeredCallConfigurationException(
                message: 'The credential broker refused to resolve the app-injected credential ('
                    .$exception->getMessage().'). Add "openconnector" to the credential\'s allowedApps and '
                    .'ensure the acting user owns it.'
            );
        }//end try

        if ($secret === null) {
            throw new BrokeredCallConfigurationException(
                message: 'The referenced credential is a host-locked proxy credential and cannot be injected '
                    .'app-side — its secret never leaves OpenRegister. Reference a generic (inject-only) '
                    .'credential for app-side injection, or dispatch the whole call through the broker with a '
                    .'top-level authentication.credentialRef instead.'
            );
        }

        return $secret;

    }//end resolveInjectableSecret()

    /**
     * Validates an inner credential reference (`{credentialId|credentialName}`) shape.
     *
     * @param array $ref The inner reference object.
     *
     * @return array{credentialId: string|null, credentialName: string|null} The validated reference.
     *
     * @throws BrokeredCallConfigurationException On any shape violation.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
     */
    private function validateInnerRef(array $ref): array
    {
        $unknownKeys = array_diff(array_keys($ref), ['credentialId', 'credentialName']);
        if (empty($unknownKeys) === false) {
            throw new BrokeredCallConfigurationException(
                message: 'A credentialRef placeholder only accepts credentialId or credentialName '
                    .'(found: '.implode(', ', array_map('strval', $unknownKeys)).').'
            );
        }

        return $this->extractSingleRefValue(ref: $ref);

    }//end validateInnerRef()

    /**
     * Validates a brokered call's configuration and resolves its credential.
     *
     * Runs the full config-guard chain, in order: credentialRef shape +
     * sibling-secret rejection (REQ-SBC-001), v1 scope guards (SOAP /
     * asynchronous / cert+ssl_key, REQ-SBC-002), broker availability
     * (REQ-SBC-004), credentialName resolution (REQ-SBC-001), credential
     * existence (REQ-SBC-004), and acting-user derivation for sessionless
     * callers (REQ-SBC-003). Every violation throws
     * {@see BrokeredCallConfigurationException} with a secret-free,
     * actionable message — there is NO fallback to embedded secrets.
     *
     * @param array   $config       The merged call configuration (CallService Phase 7 output).
     * @param array   $sourceData   The raw source data array.
     * @param boolean $asynchronous Whether the engine was asked to dispatch asynchronously.
     *
     * @return array{credentialId: string, actingUserId: string|null} The resolved dispatch identity.
     *
     * @throws BrokeredCallConfigurationException On any hard config error (mapped to a 409 CallLog).
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
     */
    public function prepare(array $config, array $sourceData, bool $asynchronous): array
    {
        $authentication = ($config['authentication'] ?? []);
        if (is_array($authentication) === false) {
            throw new BrokeredCallConfigurationException(
                message: 'credentialRef requires `authentication` to be an object in the source configuration.'
            );
        }

        $ref = $this->validateCredentialRef(authentication: $authentication);
        $this->assertScopeGuards(config: $config, sourceData: $sourceData, asynchronous: $asynchronous);
        $this->assertBrokerAvailable();

        // Resolve the broker eagerly so a container-resolution failure is a
        // 409 config error here — not an unhandled throw at dispatch time.
        $this->resolveBroker();

        $credentialId = $this->resolveCredentialId(ref: $ref);
        $credential   = $this->loadCredential(credentialId: $credentialId, ref: $ref);
        $actingUserId = $this->resolveActingUser(credential: $credential, credentialId: $credentialId);

        return [
            'credentialId' => $credentialId,
            'actingUserId' => $actingUserId,
        ];

    }//end prepare()

    /**
     * Dispatches one brokered call through the OpenRegister credential broker.
     *
     * Derives the provider-relative path (path + query of the composed URL,
     * with `config['query']` serialised into the query string — the provider
     * catalogue's host-lock is the SOLE authority for the target host),
     * forwards the normalised headers and body, and adapts the broker's
     * `array{status, headers, body}` return to a PSR-7 response so the
     * engine's `buildResponseData()` / `buildAndPersistCallLog()` /
     * `sourceRateLimit()` pipeline runs unchanged. Upstream non-2xx statuses
     * are COMPLETED calls and flow through verbatim. Broker refusals map to a
     * synthetic 403 response naming the refusal (guard family only — never
     * the payload or any secret); broker transport failures map to 502
     * (mirroring the engine's ConnectException→503 synthetic pattern).
     *
     * @param string      $credentialId The resolved `brokeredcredential` UUID.
     * @param string|null $actingUserId Acting user for sessionless (cron) calls, or null when a session exists.
     * @param string      $method       The HTTP method.
     * @param string      $url          The composed URL (`location` + `endpoint`); only its path+query is forwarded.
     * @param array       $config       The normalised request configuration (headers / query / body / json).
     *
     * @return Response The PSR-7 response for the engine's CallLog pipeline.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
     */
    public function dispatch(
        string $credentialId,
        ?string $actingUserId,
        string $method,
        string $url,
        array $config,
    ): Response {
        $broker  = $this->resolveBroker();
        $path    = $this->derivePathAndQuery(url: $url, config: $config);
        $headers = $this->deriveHeaders(config: $config);
        $body    = $this->deriveBody(config: $config, headers: $headers);

        $arguments = [
            'credentialId' => $credentialId,
            'appId'        => self::APP_ID,
            'method'       => $method,
            'path'         => $path,
            'headers'      => $headers,
            'body'         => $body,
        ];
        if ($actingUserId !== null) {
            $arguments[self::ACTING_USER_PARAMETER] = $actingUserId;
        }

        try {
            $result = $broker->request(...$arguments);
        } catch (CredentialAccessDeniedException $exception) {
            // Refusal logging is guard-family only (REQ-SBC-004): the broker's
            // message is secret-free by contract; the request payload and
            // headers are deliberately NOT logged.
            $this->logger->warning(
                '[BrokeredCallService] credential broker refused the call',
                [
                    'credentialId' => $credentialId,
                    'sessionless'  => ($actingUserId !== null),
                    'reason'       => $exception->getMessage(),
                ]
            );
            $message = $this->buildRefusalMessage(
                brokerReason: $exception->getMessage(),
                method: $method,
                sessionless: ($actingUserId !== null)
            );

            return new Response(status: 403, headers: [], body: $message, version: '1.1', reason: $message);
        } catch (CredentialUpstreamException $exception) {
            $this->logger->warning(
                '[BrokeredCallService] credential broker upstream request failed',
                [
                    'credentialId' => $credentialId,
                    'reason'       => $exception->getMessage(),
                ]
            );
            $message = 'Credential broker upstream request failed ('.$exception->getMessage().').';

            return new Response(status: 502, headers: [], body: $message, version: '1.1', reason: $message);
        }//end try

        return $this->adaptBrokerResponse(result: $result);

    }//end dispatch()

    /**
     * Builds the secret-free 403 refusal message for a broker access denial.
     *
     * The broker's own denial message is deliberately opaque ("Request not
     * permitted") — the real refusal reason (allowedApps miss, allow-rule
     * miss, host-lock, owner mismatch, OR an un-migrated vault secret that a
     * sessionless read cannot decrypt) is logged INSIDE OpenRegister and never
     * surfaced across the trust boundary. OpenConnector therefore cannot tell
     * the causes apart from the exception; the guidance instead covers the
     * likely fixes for the context it is in.
     *
     * For the SESSIONLESS (cron / background) path the message additionally
     * names the lazy-migration failure mode: the vault→Doriath migration only
     * runs in a user-session context, so a background read of a secret that
     * has never been read interactively fails CLOSED. The actionable fix —
     * sign in once as the owner (or open the credential) to trigger the
     * one-time migration — is distinct from the owner-gone / owner-disabled
     * config errors raised earlier in {@see resolveOwner()}.
     *
     * @param string  $brokerReason The broker's (opaque, secret-free) denial message.
     * @param string  $method       The HTTP method (echoed into the allow-rule hint).
     * @param boolean $sessionless  Whether the call ran without a user session (acting-user pinned).
     *
     * @return string The actionable, secret-free refusal message.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-secret-hygiene-and-refusal-logging-for-brokered-calls-req-sbc-004
     */
    private function buildRefusalMessage(string $brokerReason, string $method, bool $sessionless): string
    {
        $message = 'Credential broker refused the request ('.$brokerReason.'). '
            .'If OpenConnector should be allowed to use this credential, add "openconnector" to the '
            ."credential's allowedApps and verify the provider allow-rules cover ".$method.' requests.';

        if ($sessionless === true) {
            $message .= ' This call ran as a background job (no user session): if the credential\'s secret has '
                .'not yet been migrated to Doriath, a sessionless read of it fails closed because the '
                .'vault→Doriath migration only runs in a user session. Sign in once as the credential owner '
                .'(or open the credential in OpenRegister) to trigger the one-time migration, then re-run the sync.';
        }

        return $message;

    }//end buildRefusalMessage()

    /**
     * Returns whether the broker class is loadable (protected seam for tests).
     *
     * @return boolean Whether the OpenRegister credential broker class exists.
     */
    protected function isBrokerClassAvailable(): bool
    {
        return class_exists(self::BROKER_CLASS);

    }//end isBrokerClassAvailable()

    /**
     * Resolves the broker instance from the server container (protected seam for tests).
     *
     * @return object The CredentialBrokerService instance.
     *
     * @throws BrokeredCallConfigurationException When the container cannot resolve the broker.
     *
     * @SuppressWarnings(PHPMD.StaticAccess) -- \OCP\Server is the only way to
     * lazily resolve a cross-app class that may not exist at DI wiring time.
     */
    protected function resolveBroker(): object
    {
        if ($this->broker !== null) {
            return $this->broker;
        }

        try {
            $this->broker = \OCP\Server::get(self::BROKER_CLASS);
        } catch (Throwable $exception) {
            throw new BrokeredCallConfigurationException(
                message: 'credentialRef is configured but the OpenRegister credential broker could not be resolved: '
                    .$exception->getMessage()
            );
        }

        return $this->broker;

    }//end resolveBroker()

    /**
     * Validates the credentialRef object shape and rejects sibling secrets.
     *
     * Sibling fields under `authentication` are FORBIDDEN (hard 409, never
     * silently merged); only KEY names are echoed in the error — never values.
     * Exactly one of `credentialId` / `credentialName` must be a non-empty string.
     *
     * @param array $authentication The `authentication` object from the merged configuration.
     *
     * @return array{credentialId: string|null, credentialName: string|null} The validated reference.
     *
     * @throws BrokeredCallConfigurationException On any shape violation.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
     */
    private function validateCredentialRef(array $authentication): array
    {
        $siblings = array_diff(array_keys($authentication), ['credentialRef']);
        if (empty($siblings) === false) {
            // Secret hygiene: name the offending KEYS only, never their values.
            throw new BrokeredCallConfigurationException(
                message: 'Embedded authentication fields are forbidden alongside credentialRef '
                    .'(found: '.implode(', ', array_map('strval', $siblings)).'). '
                    .'Move the secret into the OpenRegister credential broker and reference it via credentialRef only.'
            );
        }

        $ref = $authentication['credentialRef'];
        if (is_array($ref) === false) {
            throw new BrokeredCallConfigurationException(
                message: 'credentialRef must be an object of the shape {"credentialId": "<uuid>"} '
                    .'or {"credentialName": "<name>"}.'
            );
        }

        $unknownKeys = array_diff(array_keys($ref), ['credentialId', 'credentialName']);
        if (empty($unknownKeys) === false) {
            throw new BrokeredCallConfigurationException(
                message: 'credentialRef only accepts credentialId or credentialName '
                    .'(found: '.implode(', ', array_map('strval', $unknownKeys)).').'
            );
        }

        return $this->extractSingleRefValue(ref: $ref);

    }//end validateCredentialRef()

    /**
     * Enforces the exactly-one-of contract and non-empty value on a shape-valid ref.
     *
     * @param array $ref The credentialRef object (unknown keys already rejected).
     *
     * @return array{credentialId: string|null, credentialName: string|null} The validated reference.
     *
     * @throws BrokeredCallConfigurationException When both/neither keys are set or the value is empty.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
     */
    private function extractSingleRefValue(array $ref): array
    {
        $hasId   = array_key_exists('credentialId', $ref);
        $hasName = array_key_exists('credentialName', $ref);
        if ($hasId === true && $hasName === true) {
            throw new BrokeredCallConfigurationException(
                message: 'credentialRef must set exactly one of credentialId or credentialName, not both.'
            );
        }

        if ($hasId === false && $hasName === false) {
            throw new BrokeredCallConfigurationException(
                message: 'credentialRef must set credentialId or credentialName.'
            );
        }

        $key = 'credentialName';
        if ($hasId === true) {
            $key = 'credentialId';
        }

        $value = $ref[$key];
        if (is_string($value) === false || trim($value) === '') {
            throw new BrokeredCallConfigurationException(
                message: 'credentialRef.'.$key.' must be a non-empty string.'
            );
        }

        $reference       = [
            'credentialId'   => null,
            'credentialName' => null,
        ];
        $reference[$key] = $value;

        return $reference;

    }//end extractSingleRefValue()

    /**
     * Enforces the v1 scope guards: no SOAP, no async, no TLS client certs.
     *
     * @param array   $config       The merged call configuration.
     * @param array   $sourceData   The raw source data array.
     * @param boolean $asynchronous Whether asynchronous dispatch was requested.
     *
     * @return void
     *
     * @throws BrokeredCallConfigurationException On any v1 scope violation.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
     */
    private function assertScopeGuards(array $config, array $sourceData, bool $asynchronous): void
    {
        if (($sourceData['type'] ?? null) === 'soap') {
            throw new BrokeredCallConfigurationException(
                message: 'credentialRef is not supported on SOAP sources (v1 scope) — the SOAP transport '
                    .'bypasses the brokered HTTP path.'
            );
        }

        if ($asynchronous === true) {
            throw new BrokeredCallConfigurationException(
                message: 'credentialRef does not support asynchronous dispatch (v1 scope) — the brokered '
                    .'call is synchronous in-process.'
            );
        }

        if (isset($config['cert']) === true || isset($config['ssl_key']) === true) {
            throw new BrokeredCallConfigurationException(
                message: 'TLS client-certificate configuration (cert / ssl_key) is not supported alongside '
                    .'credentialRef (v1 scope) — outbound TLS identity is the broker\'s concern once brokered.'
            );
        }

    }//end assertScopeGuards()

    /**
     * Soft-fail guard: the broker class must exist AND openregister must be enabled.
     *
     * @return void
     *
     * @throws BrokeredCallConfigurationException When the broker is unavailable (NO fallback to embedded secrets).
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-secret-hygiene-and-refusal-logging-for-brokered-calls-req-sbc-004
     */
    private function assertBrokerAvailable(): void
    {
        if ($this->isBrokerClassAvailable() === false
            || $this->appManager->isEnabledForUser('openregister') === false
        ) {
            throw new BrokeredCallConfigurationException(
                message: 'credentialRef is configured but the OpenRegister credential broker is unavailable. '
                    .'Enable the openregister app on a version that ships CredentialBrokerService, or remove '
                    .'credentialRef from the source. Embedded-secret fallback is not permitted.'
            );
        }

    }//end assertBrokerAvailable()

    /**
     * Resolves the reference to a credential UUID; names are resolved per-call.
     *
     * A `credentialName` is matched against the acting user's OR
     * `brokeredcredential` metadata objects (sessionless callers require a
     * globally unique name match — the owner is not yet known). Exactly one
     * match resolves; zero or multiple matches are a hard config error naming
     * the reference and the match count — never a guess. Resolutions are
     * memoised per run only (design D5).
     *
     * @param array{credentialId: string|null, credentialName: string|null} $ref The validated reference.
     *
     * @return string The credential UUID.
     *
     * @throws BrokeredCallConfigurationException On zero or multiple name matches.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
     */
    private function resolveCredentialId(array $ref): string
    {
        if ($ref['credentialId'] !== null) {
            return $ref['credentialId'];
        }

        $name      = (string) $ref['credentialName'];
        $user      = $this->userSession->getUser();
        $actingUid = '';
        if ($user !== null) {
            $actingUid = $user->getUID();
        }

        $cacheKey = $actingUid.':'.$name;
        if (isset($this->nameResolutionCache[$cacheKey]) === true) {
            return $this->nameResolutionCache[$cacheKey];
        }

        try {
            $matches = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => self::CREDENTIAL_REGISTER,
                        'schema'   => self::CREDENTIAL_SCHEMA,
                        'name'     => $name,
                    ],
                ]
            );
        } catch (Throwable $exception) {
            throw new BrokeredCallConfigurationException(
                message: 'credentialRef.credentialName "'.$name.'" could not be resolved: '.$exception->getMessage()
            );
        }

        $rows = ($matches['results'] ?? $matches);
        if (is_array($rows) === false) {
            $rows = [];
        }

        // Scope the match to the acting user's credentials when a session exists.
        if ($actingUid !== '') {
            $rows = array_values(
                array_filter(
                    $rows,
                    function ($row) use ($actingUid) {
                        return ($row instanceof ObjectEntity && $row->getOwner() === $actingUid);
                    }
                )
            );
        }

        $count = count($rows);
        if ($count !== 1) {
            throw new BrokeredCallConfigurationException(
                message: 'credentialRef.credentialName "'.$name.'" resolves to '.$count.' credentials for the '
                    .'acting user — expected exactly 1. Reference the credential by credentialId instead.'
            );
        }

        $credentialId = (string) $rows[0]->getUuid();
        $this->nameResolutionCache[$cacheKey] = $credentialId;

        return $credentialId;

    }//end resolveCredentialId()

    /**
     * Loads the credential metadata object; a missing credential is a hard config error.
     *
     * Mirrors the broker's own metadata load (`_rbac: false` — only existence
     * and ownership are read here; every authorisation guard still runs
     * inside the broker at dispatch time).
     *
     * @param string                                                        $credentialId The credential UUID.
     * @param array{credentialId: string|null, credentialName: string|null} $ref          The original reference (for the error message).
     *
     * @return ObjectEntity The credential metadata object.
     *
     * @throws BrokeredCallConfigurationException When the referenced credential no longer exists.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-secret-hygiene-and-refusal-logging-for-brokered-calls-req-sbc-004
     */
    private function loadCredential(string $credentialId, array $ref): ObjectEntity
    {
        try {
            $credential = $this->objectService->find(
                id: $credentialId,
                register: self::CREDENTIAL_REGISTER,
                schema: self::CREDENTIAL_SCHEMA,
                _rbac: false
            );
        } catch (Throwable) {
            $credential = null;
        }

        if ($credential instanceof ObjectEntity === false) {
            $reference = ($ref['credentialName'] ?? null);
            if ($reference === null) {
                $reference = $credentialId;
            }

            throw new BrokeredCallConfigurationException(
                message: 'The credential referenced by credentialRef ("'.$reference.'") was not found in the '
                    .'OpenRegister credential broker. Recreate the credential or update the source configuration.'
            );
        }

        return $credential;

    }//end loadCredential()

    /**
     * Derives the acting user for the brokered dispatch (owner-pinning policy).
     *
     * Interactive calls (a session user exists) rely on the broker's own
     * session-derived owner guard — no acting user is passed. Sessionless
     * (cron) calls pin the acting user to the credential OWNER read from the
     * OR metadata object at call time (design: owner-pinning — the simple,
     * robust default), passed via the broker's optional acting-user
     * parameter (feature-detected by reflection on `request()` so an older
     * broker soft-fails with a config error — never a TypeError). The acting
     * user substitutes ONLY the session identity: allowedApps, allowRules,
     * and host-lock remain enforced by the broker.
     *
     * A resolved owner that is empty, no longer exists, or is disabled fails
     * CLOSED with an actionable config error (see {@see resolveOwner()}) — a
     * sessionless brokered call NEVER falls back to acting as no-one or as an
     * administrator.
     *
     * @param ObjectEntity $credential   The credential metadata object.
     * @param string       $credentialId The credential UUID (for error messages).
     *
     * @return string|null The acting user id for sessionless calls, or null when a session exists.
     *
     * @throws BrokeredCallConfigurationException When sessionless dispatch is impossible.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-acting-user-for-sessionless-brokered-calls-req-sbc-003
     */
    private function resolveActingUser(ObjectEntity $credential, string $credentialId): ?string
    {
        if ($this->userSession->getUser() !== null) {
            return null;
        }

        $broker = $this->resolveBroker();
        if ($this->brokerSupportsActingUser(broker: $broker) === false) {
            throw new BrokeredCallConfigurationException(
                message: 'This brokered call runs without a user session (background job), but the installed '
                    .'OpenRegister credential broker does not support the acting-user parameter yet. Upgrade '
                    .'OpenRegister to a version whose CredentialBrokerService::request() accepts '
                    .self::ACTING_USER_PARAMETER.', or trigger the call from a user session.'
            );
        }

        return $this->resolveOwner(credential: $credential, credentialId: $credentialId);

    }//end resolveActingUser()

    /**
     * Owner-pinning: read the credential OWNER and assert it is usable.
     *
     * The acting user for a sessionless brokered call is deterministically the
     * OR credential object's `owner` at call time — never a cached, guessed,
     * or configured identity. Three fail-closed guards protect the pin:
     *
     *   1. empty owner        — a corrupt / owner-less credential metadata object;
     *   2. owner gone         — the owner uid no longer resolves to a Nextcloud user
     *                           (deleted account);
     *   3. owner disabled     — the owner account exists but is disabled.
     *
     * Each is a hard config error (mapped to a 409 CallLog) and is logged at
     * warning level with the guard name, the owner uid, and the credential id
     * ONLY — never any secret material (the metadata object holds none, but the
     * log is deliberately restricted to the pin identity regardless).
     *
     * @param ObjectEntity $credential   The credential metadata object.
     * @param string       $credentialId The credential UUID (for error messages).
     *
     * @return string The pinned owner uid.
     *
     * @throws BrokeredCallConfigurationException When the owner is empty, gone, or disabled.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-acting-user-for-sessionless-brokered-calls-req-sbc-003
     */
    private function resolveOwner(ObjectEntity $credential, string $credentialId): string
    {
        $owner = (string) ($credential->getOwner() ?? '');
        if ($owner === '') {
            $this->logOwnerRefusal(guard: 'owner-empty', owner: '', credentialId: $credentialId);
            throw new BrokeredCallConfigurationException(
                message: 'The credential referenced by credentialRef ("'.$credentialId.'") has no owner recorded — '
                    .'cannot derive the acting user for a background (sessionless) brokered call.'
            );
        }

        $ownerUser = $this->userManager->get($owner);
        if ($ownerUser === null) {
            $this->logOwnerRefusal(guard: 'owner-gone', owner: $owner, credentialId: $credentialId);
            throw new BrokeredCallConfigurationException(
                message: 'The owner ("'.$owner.'") of the credential referenced by credentialRef ("'.$credentialId
                    .'") no longer exists — a background (sessionless) brokered call cannot act as a deleted user. '
                    .'Re-assign the credential to a current user or remove the source.'
            );
        }

        if ($ownerUser->isEnabled() === false) {
            $this->logOwnerRefusal(guard: 'owner-disabled', owner: $owner, credentialId: $credentialId);
            throw new BrokeredCallConfigurationException(
                message: 'The owner ("'.$owner.'") of the credential referenced by credentialRef ("'.$credentialId
                    .'") is disabled — a background (sessionless) brokered call cannot act as a disabled user. '
                    .'Re-enable the account or re-assign the credential to a current user.'
            );
        }

        return $owner;

    }//end resolveOwner()

    /**
     * Logs an owner-pin refusal — guard name + pin identity only, never a secret.
     *
     * @param string $guard        The refusal guard name (owner-empty / owner-gone / owner-disabled).
     * @param string $owner        The pinned owner uid (a Nextcloud user id, not secret material).
     * @param string $credentialId The credential UUID.
     *
     * @return void
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-secret-hygiene-and-refusal-logging-for-brokered-calls-req-sbc-004
     */
    private function logOwnerRefusal(string $guard, string $owner, string $credentialId): void
    {
        $this->logger->warning(
            '[BrokeredCallService] sessionless brokered call refused: '.$guard,
            [
                'guard'        => $guard,
                'owner'        => $owner,
                'credentialId' => $credentialId,
            ]
        );

    }//end logOwnerRefusal()

    /**
     * Feature-detects the broker's optional acting-user parameter via reflection.
     *
     * @param object $broker The resolved broker instance.
     *
     * @return boolean Whether `request()` accepts the acting-user parameter.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-acting-user-for-sessionless-brokered-calls-req-sbc-003
     */
    private function brokerSupportsActingUser(object $broker): bool
    {
        try {
            $method = new ReflectionMethod($broker, 'request');
        } catch (ReflectionException) {
            return false;
        }

        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getName() === self::ACTING_USER_PARAMETER) {
                return true;
            }
        }

        return false;

    }//end brokerSupportsActingUser()

    /**
     * Derives the provider-relative path + query string from the composed URL.
     *
     * The broker accepts ONLY a path — the provider catalogue's host-lock is
     * the sole authority for the target host; the source `location` host is
     * configuration documentation (design D4). `config['query']` is
     * serialised into the query string because the broker signature carries
     * no query array.
     *
     * @param string $url    The composed URL (`location` + `endpoint`).
     * @param array  $config The normalised request configuration.
     *
     * @return string The path (+ optional query string), always slash-rooted.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
     */
    private function derivePathAndQuery(string $url, array $config): string
    {
        $parts = parse_url($url);
        if (is_array($parts) === false) {
            $parts = [];
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            $path = '/';
        }

        if (str_starts_with($path, '/') === false) {
            $path = '/'.$path;
        }

        $querySegments = $this->collectQuerySegments(parts: $parts, config: $config);
        if (empty($querySegments) === false) {
            $path .= '?'.implode('&', $querySegments);
        }

        return $path;

    }//end derivePathAndQuery()

    /**
     * Collects query-string segments from the composed URL and `config['query']`.
     *
     * @param array $parts  The parse_url() parts of the composed URL.
     * @param array $config The normalised request configuration.
     *
     * @return array<int, string> The query-string segments, in order.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
     */
    private function collectQuerySegments(array $parts, array $config): array
    {
        $querySegments = [];
        if (isset($parts['query']) === true && (string) $parts['query'] !== '') {
            $querySegments[] = (string) $parts['query'];
        }

        if (isset($config['query']) === true) {
            if (is_array($config['query']) === true && empty($config['query']) === false) {
                $querySegments[] = http_build_query($config['query']);
            }

            if (is_string($config['query']) === true && $config['query'] !== '') {
                $querySegments[] = $config['query'];
            }
        }

        return $querySegments;

    }//end collectQuerySegments()

    /**
     * Normalises the configured headers to the broker's `array<string, string>` shape.
     *
     * The auth header is broker-controlled — any caller-supplied value for it
     * is discarded server-side by the broker itself.
     *
     * @param array $config The normalised request configuration.
     *
     * @return array<string, string> The header map for the broker.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
     */
    private function deriveHeaders(array $config): array
    {
        $headers    = [];
        $configured = ($config['headers'] ?? []);
        if (is_array($configured) === false) {
            return $headers;
        }

        foreach ($configured as $name => $value) {
            if (is_array($value) === true) {
                $flattened = [];
                array_walk_recursive(
                    $value,
                    function ($item) use (&$flattened) {
                        if (is_scalar($item) === true) {
                            $flattened[] = (string) $item;
                        }
                    }
                );
                $headers[(string) $name] = implode(', ', $flattened);
                continue;
            }

            if (is_scalar($value) === true) {
                $headers[(string) $name] = (string) $value;
            }
        }

        return $headers;

    }//end deriveHeaders()

    /**
     * Derives the raw request body: `config['body']` verbatim, or JSON-encoded `config['json']`.
     *
     * When the body comes from `config['json']` and no Content-Type header is
     * configured, `application/json` is added (mirroring Guzzle's `json`
     * option behaviour).
     *
     * @param array                 $config  The normalised request configuration.
     * @param array<string, string> $headers The broker header map (modified in place for Content-Type).
     *
     * @return string|null The raw body, or null when the request has none.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
     */
    private function deriveBody(array $config, array &$headers): ?string
    {
        if (isset($config['body']) === true && is_string($config['body']) === true && $config['body'] !== '') {
            return $config['body'];
        }

        if (array_key_exists('json', $config) === true) {
            $encoded = json_encode($config['json']);
            if ($encoded !== false) {
                $hasContentType = false;
                foreach (array_keys($headers) as $name) {
                    if (strtolower((string) $name) === 'content-type') {
                        $hasContentType = true;
                        break;
                    }
                }

                if ($hasContentType === false) {
                    $headers['Content-Type'] = 'application/json';
                }

                return $encoded;
            }
        }

        return null;

    }//end deriveBody()

    /**
     * Adapts the broker's `array{status, headers, body}` return to a PSR-7 response.
     *
     * Upstream non-2xx statuses returned by the broker are COMPLETED calls
     * (the broker's own contract) and flow through exactly like non-2xx
     * Guzzle responses do — including rate-limit header tracking.
     *
     * @param array $result The broker return value.
     *
     * @return Response The PSR-7 response.
     *
     * @spec openspec/changes/source-broker-credentials/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
     */
    private function adaptBrokerResponse(array $result): Response
    {
        $status  = (int) ($result['status'] ?? 502);
        $headers = ($result['headers'] ?? []);
        if (is_array($headers) === false) {
            $headers = [];
        }

        $body = (string) ($result['body'] ?? '');

        return new Response(status: $status, headers: $headers, body: $body);

    }//end adaptBrokerResponse()
}//end class
