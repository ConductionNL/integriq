<?php

/**
 * Contract of the LtiLaunchService.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Lti
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

namespace OCA\Integriq\Service\Lti;

use OCA\Integriq\Exception\LtiValidationException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Public operations of {@see LtiLaunchService} that other components call.
 */
interface LtiLaunchServiceInterface {
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
	public function initiateLogin(string $deploymentUuid, array $params, string $launchUrl): array;

	/**
	 * Validate a posted launch `id_token` and, on success, mint a short-lived
	 * single-use launch reference for the consuming app's redirect target.
	 *
	 * @param string $idToken The posted `id_token` JWS (compact serialization).
	 * @param string $deploymentUuid The `lti_deployment` route parameter.
	 * @param string|null $cookieState The `state` value round-tripped via the login's `SameSite=None` cookie.
	 * @param string|null $presentedState The `state` value posted alongside the `id_token`.
	 *
	 * @return array{launchReference: string, redirectUrl: string}
	 *
	 * @throws LtiValidationException On any validation failure (HTTP 400/401, no partial-trust fallback).
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function validateLaunch(
		string $idToken,
		string $deploymentUuid,
		?string $cookieState,
		?string $presentedState,
	): array;

	/**
	 * Resolve a previously-validated launch reference to its claims (single use).
	 *
	 * @param string $launchReference The reference minted by {@see validateLaunch()}.
	 *
	 * @return array|null The cached `{claims, deploymentUuid}` payload, or null if unknown/expired/already consumed.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function consumeLaunchReference(string $launchReference): ?array;

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
	public function resolveResourceMapping(string $deploymentUuid, string $resourceLinkId): ?array;

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
	): array;

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
	): array;

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
	public function verifyDeepLinkingResponse(string $idToken): array;

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
	public function verifyIdTokenSignature(string $idToken, string $registrationType): array;

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
	public function validateTiming(array $payload): void;
}
