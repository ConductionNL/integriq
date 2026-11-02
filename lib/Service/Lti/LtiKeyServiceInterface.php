<?php

/**
 * Contract of the LtiKeyService.
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
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * Public operations of {@see LtiKeyService} that other components call.
 */
interface LtiKeyServiceInterface {
	/**
	 * Generate the first signing key for a registration.
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 * @param string $algorithm RS256 (default) or PS256.
	 *
	 * @return array The new key entry, redacted (no private material).
	 *
	 * @throws BadRequestException When an active key already exists (call rotate() instead).
	 * @throws LtiValidationException When the registration does not exist.
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
	 */
	public function generateKey(string $registrationType, string $registrationUuid, string $algorithm = 'RS256'): array;

	/**
	 * Rotate a registration's signing key.
	 *
	 * Moves the current `active` key to `previous` (stamping `rotatedAt`,
	 * retained + still published for the grace window) and generates a new
	 * `active` key. New outbound signatures always use the new active key.
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 * @param string|null $algorithm Algorithm for the new key; defaults to the rotated key's algorithm.
	 *
	 * @return array The new key entry, redacted (no private material).
	 *
	 * @throws BadRequestException When there is no active key to rotate (call generate() first).
	 * @throws LtiValidationException When the registration does not exist.
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
	 */
	public function rotateKey(string $registrationType, string $registrationUuid, ?string $algorithm = null): array;

	/**
	 * Approve a registration — an admin-gated action that transitions
	 * `status` to `approved`, making the registration usable for
	 * login-initiation, launch validation, Platform-role launch initiation,
	 * and service-token issuance (REQ-LTI-011).
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 *
	 * @return array `{registrationType, registrationUuid, status}`.
	 *
	 * @throws LtiValidationException When the registration does not exist.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function approve(string $registrationType, string $registrationUuid): array;

	/**
	 * Suspend a registration — an admin-gated action that transitions
	 * `status` to `suspended`, rejecting every subsequent lookup identically
	 * to an unregistered issuer/client_id (REQ-LTI-011). Reversible by
	 * calling {@see approve()} again.
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 *
	 * @return array `{registrationType, registrationUuid, status}`.
	 *
	 * @throws LtiValidationException When the registration does not exist.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function suspend(string $registrationType, string $registrationUuid): array;

	/**
	 * Return the current `active` signing-key entry (private material included).
	 *
	 * Used internally by launch/service-token signing — never exposed on a
	 * controller response. The returned `privateKeySecret` is decrypted back
	 * to base64(PEM); the stored copy stays encrypted.
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 *
	 * @return array|null The active entry, or null when no active key exists.
	 *
	 * @throws LtiValidationException When the registration does not exist or its key cannot be decrypted.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function getActiveKeyEntry(string $registrationType, string $registrationUuid): ?array;

	/**
	 * Build the publishable JWKS document for a registration.
	 *
	 * `active` and `previous` (grace-window) public keys are included;
	 * `retired` keys MUST NOT appear (REQ-LTI-002).
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 *
	 * @return array A `{"keys": [...]}` JWKS document of public JWKs only.
	 *
	 * @throws LtiValidationException When the registration does not exist.
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
	 */
	public function getPublishableJwks(string $registrationType, string $registrationUuid): array;

	/**
	 * Sweep every `lti_platform`/`lti_tool` registration and retire
	 * `previous` keys whose grace window has elapsed.
	 *
	 * Mirrors `EventRetryJob`'s cron-registration pattern (a NC background
	 * job calls this from `run()`). Retired keys are dropped from
	 * {@see getPublishableJwks()} but kept in `signingKeys[]` for audit.
	 *
	 * @return integer Number of keys retired across both registration types.
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
	 */
	public function retireExpiredKeys(): int;
}
