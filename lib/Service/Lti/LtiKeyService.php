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
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * Generates, rotates, and publishes LTI signing keys.
 *
 * Custody note (integriq#2213): the private half of every signing key is
 * encrypted at rest with Nextcloud's `OCP\Security\ICrypto` (the instance
 * secret) before it reaches the registration object, and is decrypted only
 * in {@see getActiveKeyEntry()}, the one read path that hands key material to
 * signing code. A stored value carries the {@see ENCRYPTED_PREFIX} marker; a
 * value without it is a row written before this change (plain base64 PEM). It
 * still reads back unchanged so existing registrations keep signing, and the
 * next write of the keys through this service (generate, rotate or the
 * retirement sweep) seals it.
 *
 * The broker move ADR-064 asks for (a `credentialRef` resolved at signing
 * time) is deferred: `CredentialBrokerService::resolveInjectable()` guards on
 * the SESSION user, and a platform launch or an AGS token request runs in a
 * learner's session, not the key owner's. Moving the keys needs a scope
 * decision on who owns them and a migration of existing rows.
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
	 * Marker on a `privateKeySecret` that holds ICrypto ciphertext rather than
	 * a legacy plain base64 PEM.
	 *
	 * @var string
	 */
	public const ENCRYPTED_PREFIX = 'icrypto:';

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $orObjectService OR ObjectService used to read/write registrations.
	 * @param LoggerInterface $logger Logger for rotation/retirement outcomes (never logs key material).
	 * @param ICrypto $crypto Encrypts the private key at rest and decrypts it for signing.
	 */
	public function __construct(
		private readonly OrObjectService $orObjectService,
		private readonly LoggerInterface $logger,
		private readonly ICrypto $crypto,
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
				register: 'integriq',
				schema: $registrationType,
				_rbac: false,
				_multitenancy: false,
				// `signingKeys` is writeOnly (register.d/99-lti-*-secrets-writeonly.json)
				// and the rendered read strips writeOnly unconditionally, also under
				// `_rbac: false` (openregister#460). Unrendered, the keys are there.
				_render: false
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
	 * @return array The new key entry (`kid`, `algorithm`, `publicJwk`, `privateKeySecret`, `status`; no `rotatedAt` until rotated).
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
			// A base64-encoded PEM private key, encrypted at rest (#2213) and
			// never logged. getActiveKeyEntry() decrypts it back to base64(PEM),
			// the shape AuthenticationService::getRSJWK() (REQ-LTI-008/009
			// outbound calls) and this app's own signing consume unmodified.
			'privateKeySecret' => $this->sealSecret(plainSecret: base64_encode($pem)),
			'status' => 'active',
			// No `rotatedAt` while active: the lti_tool/lti_platform schemas declare
			// it as a date-time string ("unset while active") and refuse null
			// (#2261). rotateKey() stamps it when the key is superseded.
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
	 * Encrypt a base64(PEM) private key for storage.
	 *
	 * @param string $plainSecret The base64(PEM) private key.
	 *
	 * @return string The marked ICrypto ciphertext.
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
	 */
	private function sealSecret(string $plainSecret): string {
		return self::ENCRYPTED_PREFIX . $this->crypto->encrypt($plainSecret);
	}//end sealSecret()

	/**
	 * Encrypt every signing-key entry still holding a legacy plain secret.
	 *
	 * Runs before each write of a registration's `signingKeys`, so a row
	 * written before encryption is sealed the next time this service saves it.
	 *
	 * @param array $signingKeys The registration's signingKeys[] entries.
	 *
	 * @return array The same entries with every private key encrypted.
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
	 */
	private function sealLegacyEntries(array $signingKeys): array {
		foreach ($signingKeys as $index => $entry) {
			$secret = (string)($entry['privateKeySecret'] ?? '');
			if ($secret === '' || str_starts_with($secret, self::ENCRYPTED_PREFIX) === true) {
				continue;
			}

			$signingKeys[$index]['privateKeySecret'] = $this->sealSecret(plainSecret: $secret);
		}

		return $signingKeys;
	}//end sealLegacyEntries()

	/**
	 * Decrypt a stored private key back to base64(PEM).
	 *
	 * A value without the {@see ENCRYPTED_PREFIX} marker is a legacy plain row
	 * and is returned unchanged, so registrations written before encryption
	 * keep signing until their next write seals them.
	 *
	 * @param string $storedSecret The stored `privateKeySecret`.
	 *
	 * @return string The base64(PEM) private key.
	 *
	 * @throws LtiValidationException When the ciphertext cannot be decrypted (for example after the instance secret changed).
	 *
	 * @spec openspec/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
	 */
	private function openSecret(string $storedSecret): string {
		if (str_starts_with($storedSecret, self::ENCRYPTED_PREFIX) === false) {
			return $storedSecret;
		}

		try {
			return $this->crypto->decrypt(substr($storedSecret, strlen(self::ENCRYPTED_PREFIX)));
		} catch (Throwable $exception) {
			// Class only: the message of a crypto failure is never widened with key material.
			$this->logger->error('LtiKeyService: stored signing key could not be decrypted (' . $exception::class . ')');
			throw new LtiValidationException(
				message: 'Stored signing key material could not be decrypted',
				details: [],
				httpStatus: 500
			);
		}

	}//end openSecret()

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

		$data['signingKeys'] = $this->sealLegacyEntries(signingKeys: $signingKeys);
		$this->orObjectService->saveObject(
			object: $data,
			register: 'integriq',
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

		$data['signingKeys'] = $this->sealLegacyEntries(signingKeys: $signingKeys);
		$this->orObjectService->saveObject(
			object: $data,
			register: 'integriq',
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
			register: 'integriq',
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
	public function getActiveKeyEntry(string $registrationType, string $registrationUuid): ?array {
		$registration = $this->findRegistration(registrationType: $registrationType, registrationUuid: $registrationUuid);
		$signingKeys = ($registration->getObject()['signingKeys'] ?? []);

		foreach ($signingKeys as $entry) {
			if (($entry['status'] ?? null) !== 'active') {
				continue;
			}

			if (is_string($entry['privateKeySecret'] ?? null) === true) {
				$entry['privateKeySecret'] = $this->openSecret(storedSecret: $entry['privateKeySecret']);
			}

			return $entry;
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
						'register' => 'integriq',
						'schema' => $registrationType,
					],
				],
				_rbac: false,
				_multitenancy: false
			);
			$registrations = ($matches['results'] ?? $matches);

			foreach ($registrations as $listed) {
				// The list is rendered, so its rows carry no writeOnly `signingKeys`:
				// read each registration again past the render boundary.
				$registration = $this->findRegistration(registrationType: $registrationType, registrationUuid: $listed->getUuid());
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
					$data['signingKeys'] = $this->sealLegacyEntries(signingKeys: $signingKeys);
					$this->orObjectService->saveObject(
						object: $data,
						register: 'integriq',
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
