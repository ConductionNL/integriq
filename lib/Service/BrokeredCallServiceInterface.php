<?php

/**
 * Contract of the BrokeredCallService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Exception\BrokeredCallConfigurationException;

/**
 * Public operations of {@see BrokeredCallService} that other components call.
 */
interface BrokeredCallServiceInterface {
	/**
	 * The app id the broker authorises against the credential's allowedApps.
	 *
	 * @var string
	 */
	public const APP_ID = 'openconnector';

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
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
	 */
	public function hasCredentialRef(array $config): bool;

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
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
	 */
	public function hasInjectableCredentials(array $sourceData): bool;

	/**
	 * Whether a configuration's TLS client identity (`cert` / `ssl_key`) is a credential placeholder.
	 *
	 * A client certificate and its key are secrets as much as a client secret is, so they
	 * may be kept in the credential broker too (integriq#2102). Either key may hold a
	 * `{"credentialRef": {...}}` placeholder, or Guzzle's `[path, passphrase]` pair with a
	 * placeholder in either position.
	 *
	 * @param array $config A source `configuration` or a merged call configuration.
	 *
	 * @return boolean Whether `cert` or `ssl_key` carries a placeholder.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
	 */
	public function hasInjectableTlsIdentity(array $config): bool;

	/**
	 * Resolve a configuration's TLS client identity placeholders to their PEM material.
	 *
	 * Interim custody for mTLS sources (integriq#2102, option 2): the certificate and key
	 * are read from the broker through the same inject-only resolver as an authentication
	 * placeholder, and the engine writes them to its own TLS context. The material enters
	 * this process, which is weaker than a broker that presents the certificate itself
	 * (openregister#2720 case 2), and stronger than keeping it on the source in clear.
	 *
	 * @param array $config A merged call configuration.
	 *
	 * @return array The configuration with `cert` / `ssl_key` placeholders replaced.
	 *
	 * @throws BrokeredCallConfigurationException On any resolution failure (mapped to a 409 CallLog).
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
	 */
	public function hydrateInjectableTlsIdentity(array $config): array;

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
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
	 */
	public function hydrateInjectableCredentials(array $sourceData): array;

	/**
	 * Resolve one credential reference to its secret, for a caller that is not a source.
	 *
	 * The same inject-only lookup {@see hydrateInjectableCredentials()} runs for a
	 * placeholder under a source's authentication: the broker's owner and
	 * allowedApps guards apply, and a host-locked proxy credential is refused.
	 *
	 * @param array $ref The inner reference, `{credentialId}` or `{credentialName}`.
	 *
	 * @return string The secret.
	 *
	 * @throws BrokeredCallConfigurationException On any resolution failure.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-broker-credentials-are-a-credential-reference-resolved-at-publish-req-ebsc-003
	 */
	public function resolveCredentialRef(array $ref): string;

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
	 * @param array $config The merged call configuration (CallService Phase 7 output).
	 * @param array $sourceData The raw source data array.
	 * @param boolean $asynchronous Whether the engine was asked to dispatch asynchronously.
	 *
	 * @return array{credentialId: string, actingUserId: string|null} The resolved dispatch identity.
	 *
	 * @throws BrokeredCallConfigurationException On any hard config error (mapped to a 409 CallLog).
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-credentialref-source-authentication-contract-req-sbc-001
	 */
	public function prepare(array $config, array $sourceData, bool $asynchronous): array;

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
	 * @param string $credentialId The resolved `brokeredcredential` UUID.
	 * @param string|null $actingUserId Acting user for sessionless (cron) calls, or null when a session exists.
	 * @param string $method The HTTP method.
	 * @param string $url The composed URL (`location` + `endpoint`); only its path+query is forwarded.
	 * @param array $config The normalised request configuration (headers / query / body / json).
	 *
	 * @return Response The PSR-7 response for the engine's CallLog pipeline.
	 *
	 * @spec openspec/specs/http-call-engine/spec.md#requirement-brokered-dispatch-through-credentialbrokerservice-req-sbc-002
	 */
	public function dispatch(
		string $credentialId,
		?string $actingUserId,
		string $method,
		string $url,
		array $config,
	): Response;
}
