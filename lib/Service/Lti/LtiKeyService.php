<?php

/**
 * Integriq LtiKeyService.
 *
 * Owns the per-registration signing-key lifecycle for LTI 1.3 / LTI
 * Advantage: generation, rotation (active -> previous -> retired) with a
 * grace window, and the publishable (active + previous) JWKS document.
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
 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Lti;

use DateTime;
use Jose\Component\KeyManagement\JWKFactory;
use OCA\Integriq\Exception\LtiValidationException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * Generates, rotates, and publishes LTI signing keys.
 *
 * Custody note (design.md D3): private key material is stored the same way
 * `AuthenticationService::fetchJWTToken`'s `secret` configuration is stored
 * today — plaintext-pending-encryption, per ADR-007's already-accepted,
 * fleet-wide status quo. This is a deliberate, documented divergence from
 * digikoppeling-adapter's fail-closed posture (see design.md D3 for the full
 * reasoning) — NOT an oversight.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
 */
class LtiKeyService {

	/**
	 * Registration schemas this service manages keys for.
	 *
	 * @var string[]
	 */
	public const REGISTRATION_TYPES = ['lti_platform', 'lti_tool'];

	/**
	 * Valid registration trust-gate statuses (REQ-LTI-011).
	 *
	 * @var string[]
	 */
	public const REGISTRATION_STATUSES = ['pending', 'approved', 'suspended'];

	/**
	 * Supported signing algorithms (REQ-LTI-002).
	 *
	 * @var string[]
	 */
	public const SUPPORTED_ALGORITHMS = ['RS256', 'PS256'];

	/**
	 * RSA key size in bits.
	 *
	 * @var integer
	 */
	private const KEY_SIZE_BITS = 2048;

	/**
	 * Rotation grace window in seconds (7 days — design.md D3: longer than
	 * webhook-signing's 24h because a stale platform/tool-side JWKS cache on
	 * the far side is normal and outside our control).
	 *
	 * @var integer
	 */
	public const GRACE_WINDOW_SECONDS = 604800;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $orObjectService OR ObjectService used to read/write registrations.
	 * @param LoggerInterface $logger Logger for rotation/retirement outcomes (never logs key material).
	 */
	public function __construct(
		private readonly OrObjectService $orObjectService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Assert a registration type is one this service manages.
	 *
	 * @param string $registrationType The registration schema slug.
	 *
	 * @return void
	 *
	 * @throws BadRequestException When the type is not `lti_platform`/`lti_tool`.
	 */
	private function assertValidRegistrationType(string $registrationType): void {
		if (in_array(needle: $registrationType, haystack: self::REGISTRATION_TYPES, strict: true) === false) {
			throw new BadRequestException(
				message: 'Unknown LTI registration type: ' . $registrationType
			);
		}

	}//end assertValidRegistrationType()

	/**
	 * Load a registration object by uuid.
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 *
	 * @return ObjectEntity
	 *
	 * @throws LtiValidationException When the registration does not exist.
	 */
	private function findRegistration(string $registrationType, string $registrationUuid): ObjectEntity {
		$this->assertValidRegistrationType(registrationType: $registrationType);

		try {
			return $this->orObjectService->find(
				id: $registrationUuid,
				register: 'openconnector',
				schema: $registrationType,
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $exception) {
			throw new LtiValidationException(
				message: 'LTI registration not found',
				details: ['registrationType' => $registrationType, 'registrationUuid' => $registrationUuid],
				httpStatus: 404
			);
		}

	}//end findRegistration()

	/**
	 * Build a fresh signing-key entry (private material included).
	 *
	 * @param string $algorithm RS256 or PS256.
	 *
	 * @return array The new key entry (`kid`, `algorithm`, `publicJwk`, `privateKeySecret`, `status`, `rotatedAt`).
	 *
	 * @throws BadRequestException When the algorithm is not supported.
	 */
	private function createKeyEntry(string $algorithm): array {
		if (in_array(needle: $algorithm, haystack: self::SUPPORTED_ALGORITHMS, strict: true) === false) {
			throw new BadRequestException(message: 'Unsupported LTI signing algorithm: ' . $algorithm);
		}

		// Unpredictable kid — not derived from any secret material.
		$kid = bin2hex(random_bytes(16));

		$resource = openssl_pkey_new(
			[
				'private_key_bits' => self::KEY_SIZE_BITS,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);
		if ($resource === false) {
			throw new RuntimeException('Unable to generate an LTI signing key (openssl_pkey_new failed)');
		}

		$exported = openssl_pkey_export($resource, $pem);
		if ($exported === false || is_string($pem) === false) {
			throw new RuntimeException('Unable to export the generated LTI signing key');
		}

		return [
			'kid' => $kid,
			'algorithm' => $algorithm,
			'publicJwk' => $this->derivePublicJwk(pem: $pem, kid: $kid, algorithm: $algorithm),
			// Plaintext-pending-encryption per ADR-007 (design.md D3) — a
			// base64-encoded PEM private key, never logged. Stored as PEM
			// (not a JSON JWK) so it is directly consumable, unmodified, by
			// AuthenticationService::fetchJWTToken()'s existing getRSJWK()
			// path (REQ-LTI-008/009 Tool-role outbound calls reuse
			// fetchOAuthTokens() unmodified, which requires this exact
			// base64(PEM) shape) as well as this service's own signing.
			'privateKeySecret' => base64_encode($pem),
			'status' => 'active',
			'rotatedAt' => null,
		];

	}//end createKeyEntry()

	/**
	 * Derive the public JWK (kid/alg/use + public RSA components only) from a
	 * freshly generated PEM private key.
	 *
	 * Uses the same secure temp-file pattern (`tempnam` + `chmod 0600` +
	 * `try/finally` unlink) already established by
	 * `AuthenticationService::getRSJWK()` / `AuthorizationService::getJWK()`
	 * (#1012 fix) — the filename is unpredictable and the bytes are never
	 * left readable to other local users.
	 *
	 * @param string $pem The generated PEM private key.
	 * @param string $kid The key's `kid`.
	 * @param string $algorithm The key's algorithm.
	 *
	 * @return array The public JWK (via {@see \Jose\Component\Core\JWK::toPublic()}).
	 *
	 * @throws RuntimeException When a secure temp file cannot be allocated.
	 */
	private function derivePublicJwk(string $pem, string $kid, string $algorithm): array {
		$filename = tempnam(sys_get_temp_dir(), 'oc-lti-key-');
		if ($filename === false) {
			throw new RuntimeException('Could not allocate temp file for LTI key generation');
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

		return $jwk->toPublic()->jsonSerialize();
	}//end derivePublicJwk()

	/**
	 * Redact a signing-key entry for any response/log surface (drop the private key).
	 *
	 * @param array $entry A signingKeys[] entry.
	 *
	 * @return array The same entry minus `privateKeySecret`.
	 */
	private function redactEntry(array $entry): array {
		unset($entry['privateKeySecret']);
		return $entry;
	}//end redactEntry()

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
	public function generateKey(string $registrationType, string $registrationUuid, string $algorithm = 'RS256'): array {
		$registration = $this->findRegistration(registrationType: $registrationType, registrationUuid: $registrationUuid);
		$data = $registration->getObject();
		$signingKeys = ($data['signingKeys'] ?? []);

		foreach ($signingKeys as $entry) {
			if (($entry['status'] ?? null) === 'active') {
				throw new BadRequestException(
					message: 'This registration already has an active signing key; use rotate instead'
				);
			}
		}

		$newEntry = $this->createKeyEntry(algorithm: $algorithm);
		$signingKeys[] = $newEntry;

		$data['signingKeys'] = $signingKeys;
		$this->orObjectService->saveObject(
			object: $data,
			register: 'openconnector',
			schema: $registrationType,
			uuid: $registration->getUuid()
		);

		$this->logger->info(
			'LtiKeyService: generated signing key',
			['registrationType' => $registrationType, 'registrationUuid' => $registrationUuid, 'kid' => $newEntry['kid']]
		);

		return $this->redactEntry(entry: $newEntry);
	}//end generateKey()

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
	public function rotateKey(string $registrationType, string $registrationUuid, ?string $algorithm = null): array {
		$registration = $this->findRegistration(registrationType: $registrationType, registrationUuid: $registrationUuid);
		$data = $registration->getObject();
		$signingKeys = ($data['signingKeys'] ?? []);

		$activeIndex = null;
		foreach ($signingKeys as $index => $entry) {
			if (($entry['status'] ?? null) === 'active') {
				$activeIndex = $index;
				break;
			}
		}

		if ($activeIndex === null) {
			throw new BadRequestException(
				message: 'No active signing key to rotate; call generate first'
			);
		}

		$rotatedAlgorithm = ($algorithm ?? $signingKeys[$activeIndex]['algorithm'] ?? 'RS256');

		$signingKeys[$activeIndex]['status'] = 'previous';
		$signingKeys[$activeIndex]['rotatedAt'] = (new DateTime())->format('c');

		$newEntry = $this->createKeyEntry(algorithm: $rotatedAlgorithm);
		$signingKeys[] = $newEntry;

		$data['signingKeys'] = $signingKeys;
		$this->orObjectService->saveObject(
			object: $data,
			register: 'openconnector',
			schema: $registrationType,
			uuid: $registration->getUuid()
		);

		$this->logger->info(
			'LtiKeyService: rotated signing key',
			[
				'registrationType' => $registrationType,
				'registrationUuid' => $registrationUuid,
				'newKid' => $newEntry['kid'],
				'previousKid' => ($signingKeys[$activeIndex]['kid'] ?? null),
			]
		);

		return $this->redactEntry(entry: $newEntry);
	}//end rotateKey()

	/**
	 * Transition a registration's trust-gate `status` (REQ-LTI-011).
	 *
	 * Uses {@see findRegistration()} — the direct, ungated lookup this
	 * service already owns for key mutation — rather than
	 * {@see LtiRegistrationResolverService}'s gated lookups, so an admin can
	 * still find (and approve) a `pending` registration; the resolver's
	 * lookups are gated for *protocol* callers (login/launch/service-token),
	 * never for this admin-only mutation path.
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 * @param string $newStatus One of {@see REGISTRATION_STATUSES}.
	 *
	 * @return array `{registrationType, registrationUuid, status}` — this method never touches `signingKeys`,
	 *               so no key redaction applies; callers only ever receive the status-transition result.
	 *
	 * @throws LtiValidationException When the registration does not exist.
	 * @throws BadRequestException When `$newStatus` is not a recognised status.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	private function transitionStatus(string $registrationType, string $registrationUuid, string $newStatus): array {
		if (in_array(needle: $newStatus, haystack: self::REGISTRATION_STATUSES, strict: true) === false) {
			throw new BadRequestException(message: 'Unknown LTI registration status: ' . $newStatus);
		}

		$registration = $this->findRegistration(registrationType: $registrationType, registrationUuid: $registrationUuid);
		$data = $registration->getObject();
		$previousStatus = ($data['status'] ?? 'pending');
		$data['status'] = $newStatus;

		$this->orObjectService->saveObject(
			object: $data,
			register: 'openconnector',
			schema: $registrationType,
			uuid: $registration->getUuid()
		);

		$this->logger->info(
			'LtiKeyService: registration status transitioned',
			[
				'registrationType' => $registrationType,
				'registrationUuid' => $registrationUuid,
				'previousStatus' => $previousStatus,
				'newStatus' => $newStatus,
			]
		);

		return [
			'registrationType' => $registrationType,
			'registrationUuid' => $registration->getUuid(),
			'status' => $newStatus,
		];

	}//end transitionStatus()

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
	public function approve(string $registrationType, string $registrationUuid): array {
		return $this->transitionStatus(registrationType: $registrationType, registrationUuid: $registrationUuid, newStatus: 'approved');
	}//end approve()

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
	public function suspend(string $registrationType, string $registrationUuid): array {
		return $this->transitionStatus(registrationType: $registrationType, registrationUuid: $registrationUuid, newStatus: 'suspended');
	}//end suspend()

	/**
	 * Return the current `active` signing-key entry (private material included).
	 *
	 * Used internally by launch/service-token signing — never exposed on a
	 * controller response.
	 *
	 * @param string $registrationType `lti_platform` or `lti_tool`.
	 * @param string $registrationUuid The registration's UUID.
	 *
	 * @return array|null The active entry, or null when no active key exists.
	 *
	 * @throws LtiValidationException When the registration does not exist.
	 *
	 * @spec openspec/specs/lti-platform/spec.md
	 */
	public function getActiveKeyEntry(string $registrationType, string $registrationUuid): ?array {
		$registration = $this->findRegistration(registrationType: $registrationType, registrationUuid: $registrationUuid);
		$signingKeys = ($registration->getObject()['signingKeys'] ?? []);

		foreach ($signingKeys as $entry) {
			if (($entry['status'] ?? null) === 'active') {
				return $entry;
			}
		}

		return null;
	}//end getActiveKeyEntry()

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
	public function getPublishableJwks(string $registrationType, string $registrationUuid): array {
		$registration = $this->findRegistration(registrationType: $registrationType, registrationUuid: $registrationUuid);
		$signingKeys = ($registration->getObject()['signingKeys'] ?? []);

		$keys = [];
		foreach ($signingKeys as $entry) {
			if (in_array(needle: ($entry['status'] ?? null), haystack: ['active', 'previous'], strict: true) === true) {
				$keys[] = ($entry['publicJwk'] ?? null);
			}
		}

		return ['keys' => array_values(array_filter($keys))];
	}//end getPublishableJwks()

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
	public function retireExpiredKeys(): int {
		$now = new DateTime();
		$retiredCount = 0;

		foreach (self::REGISTRATION_TYPES as $registrationType) {
			$matches = $this->orObjectService->findAll(
				config: [
					'filters' => [
						'register' => 'openconnector',
						'schema' => $registrationType,
					],
				],
				_rbac: false,
				_multitenancy: false
			);
			$registrations = ($matches['results'] ?? $matches);

			foreach ($registrations as $registration) {
				$data = $registration->getObject();
				$signingKeys = ($data['signingKeys'] ?? []);
				$changed = false;

				foreach ($signingKeys as $index => $entry) {
					if (($entry['status'] ?? null) !== 'previous' || empty($entry['rotatedAt'] ?? null) === true) {
						continue;
					}

					$rotatedAt = new DateTime($entry['rotatedAt']);
					$graceEnd = clone $rotatedAt;
					$graceEnd->modify('+' . self::GRACE_WINDOW_SECONDS . ' seconds');

					if ($now >= $graceEnd) {
						$signingKeys[$index]['status'] = 'retired';
						$changed = true;
						$retiredCount++;
					}
				}//end foreach

				if ($changed === true) {
					$data['signingKeys'] = $signingKeys;
					$this->orObjectService->saveObject(
						object: $data,
						register: 'openconnector',
						schema: $registrationType,
						uuid: $registration->getUuid()
					);
				}
			}//end foreach
		}//end foreach

		if ($retiredCount > 0) {
			$this->logger->info('LtiKeyService: retirement sweep complete', ['retired' => $retiredCount]);
		}

		return $retiredCount;
	}//end retireExpiredKeys()
}//end class
