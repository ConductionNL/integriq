<?php

/**
 * Integriq EudiIssuerKeyService.
 *
 * Owns the EUDI wallet credential issuer's own ES256 (P-256) signing-key
 * lifecycle: generation, rotation (archiving the previous public key so
 * already-issued credentials remain verifiable), and signing of every JWT
 * this adapter mints (dc+sd-jwt credentials, OAuth Status List Tokens).
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTime;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\Integriq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Generates, rotates, publishes, and signs with the EUDI issuer's own key.
 *
 * Custody (design.md D-KEY): mirrors scholiq's `KeyManagementService` shape
 * — private key encrypted at rest via `OCP\Security\ICrypto::encrypt()`
 * before persistence, public key stored plain with a SHA-256 `kid`
 * fingerprint, rotation archives the previous public key (capped at 32,
 * oldest-pruned-first) rather than discarding it. A NEW class (different
 * app, different Nextcloud container), the identical pattern — not a
 * reuse of scholiq's class.
 *
 * Storage: one `IAppConfig` JSON blob per organisation
 * (`eudi_issuer_key_<organisationId>`), `sensitive: true` so the admin UI
 * never surfaces the raw value. Organisation scoping is resolved by the
 * caller (controller/service) via {@see OrganisationBridgeService} and
 * passed in as `$organisationId` (null/empty falls back to a single
 * `default` scope — never an unencrypted key, never a hard failure).
 *
 * This service is also the SOLE place that ever holds decrypted private
 * key material in memory (mirrors {@see WebhookSignatureService}'s
 * "single owner of crypto" framing) — every JWT this adapter mints
 * (dc+sd-jwt credentials, OAuth Status List Tokens) is signed via
 * {@see signJwt()} rather than callers handling key material themselves.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
 */
class EudiIssuerKeyService {

	/**
	 * Signing algorithm for the issuer key (OpenID4VCI/EUDI convention: ES256/P-256).
	 *
	 * @var string
	 */
	public const ALGORITHM = 'ES256';

	/**
	 * Maximum number of archived (retired) public keys retained per organisation,
	 * oldest pruned first once exceeded.
	 *
	 * @var integer
	 */
	public const MAX_ARCHIVED_KEYS = 32;

	/**
	 * App-config key prefix (one key per organisation scope).
	 *
	 * @var string
	 */
	private const CONFIG_KEY_PREFIX = 'eudi_issuer_key_';

	/**
	 * Fallback organisation scope used when OpenRegister / the active
	 * organisation is unavailable (design.md D-KEY organisation scoping).
	 *
	 * @var string
	 */
	public const DEFAULT_ORGANISATION_SCOPE = 'default';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config storage for the (encrypted) key material.
	 * @param ICrypto $crypto Nextcloud's symmetric encryption service for the private key.
	 * @param LoggerInterface $logger Logger for key lifecycle events (never logs key material).
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ICrypto $crypto,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Resolve the app-config key for an organisation scope.
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 *
	 * @return string
	 */
	private function configKey(?string $organisationId): string {
		$scope = ($organisationId ?? self::DEFAULT_ORGANISATION_SCOPE);
		if ($scope === '') {
			$scope = self::DEFAULT_ORGANISATION_SCOPE;
		}

		return self::CONFIG_KEY_PREFIX . $scope;
	}//end configKey()

	/**
	 * Load the stored key-set blob for an organisation scope.
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 *
	 * @return array{active: array|null, archived: array[]}
	 */
	private function loadKeySet(?string $organisationId): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, $this->configKey(organisationId: $organisationId), '');
		if ($raw === '') {
			return ['active' => null, 'archived' => []];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false) {
			return ['active' => null, 'archived' => []];
		}

		return [
			'active' => ($decoded['active'] ?? null),
			'archived' => ($decoded['archived'] ?? []),
		];

	}//end loadKeySet()

	/**
	 * Persist a key-set blob for an organisation scope.
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 * @param array $keySet The key-set blob (`active`/`archived`).
	 *
	 * @return void
	 */
	private function saveKeySet(?string $organisationId, array $keySet): void {
		$this->appConfig->setValueString(
			Application::APP_ID,
			$this->configKey(organisationId: $organisationId),
			json_encode($keySet),
			sensitive: true
		);

	}//end saveKeySet()

	/**
	 * Generate a fresh ES256/P-256 keypair.
	 *
	 * @return array{kid: string, publicKeyPem: string, encryptedPrivateKey: string, createdAt: string}
	 *
	 * @throws RuntimeException When OpenSSL key generation or export fails.
	 */
	private function generateKeypair(): array {
		$resource = openssl_pkey_new(
			[
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name' => 'prime256v1',
			]
		);
		if ($resource === false) {
			throw new RuntimeException('Unable to generate an EUDI issuer signing key (openssl_pkey_new failed)');
		}

		$exportedPrivate = openssl_pkey_export($resource, $privatePem);
		if ($exportedPrivate === false || is_string($privatePem) === false) {
			throw new RuntimeException('Unable to export the generated EUDI issuer private key');
		}

		$details = openssl_pkey_get_details($resource);
		if ($details === false || isset($details['key']) === false) {
			throw new RuntimeException('Unable to derive the EUDI issuer public key');
		}

		$publicPem = $details['key'];
		$kid = hash('sha256', $publicPem);

		return [
			'kid' => $kid,
			'publicKeyPem' => $publicPem,
			'encryptedPrivateKey' => $this->crypto->encrypt($privatePem),
			'createdAt' => (new DateTime())->format('c'),
		];

	}//end generateKeypair()

	/**
	 * Generate the first issuer signing key for an organisation.
	 *
	 * Fails loudly (thrown exception, no partial key persisted) if key
	 * generation fails. Silently replaces any existing key if called again
	 * without going through {@see rotateKey()} — callers that want rotation
	 * semantics (archiving the previous key) MUST call rotateKey() instead.
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 *
	 * @return array{kid: string, publicKeyPem: string, algorithm: string} Public material only.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
	 */
	public function generateKey(?string $organisationId = null): array {
		$keySet = $this->loadKeySet(organisationId: $organisationId);
		$keypair = $this->generateKeypair();
		$keySet['active'] = $keypair;
		$this->saveKeySet(organisationId: $organisationId, keySet: $keySet);

		$this->logger->info(
			'EudiIssuerKeyService: generated issuer signing key',
			['organisationId' => ($organisationId ?? self::DEFAULT_ORGANISATION_SCOPE), 'kid' => $keypair['kid']]
		);

		return [
			'kid' => $keypair['kid'],
			'publicKeyPem' => $keypair['publicKeyPem'],
			'algorithm' => self::ALGORITHM,
		];

	}//end generateKey()

	/**
	 * Rotate an organisation's issuer signing key.
	 *
	 * Archives the current active public key (capped at
	 * {@see MAX_ARCHIVED_KEYS}, oldest pruned first) so credentials already
	 * issued and status-list tokens already published under it remain
	 * verifiable, then generates a new active key. The rotated-out private
	 * key is discarded, never archived.
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 *
	 * @return array{kid: string, publicKeyPem: string, algorithm: string} Public material only.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
	 */
	public function rotateKey(?string $organisationId = null): array {
		$keySet = $this->loadKeySet(organisationId: $organisationId);

		if ($keySet['active'] !== null) {
			$archivedEntry = [
				'kid' => $keySet['active']['kid'],
				'publicKeyPem' => $keySet['active']['publicKeyPem'],
				'createdAt' => $keySet['active']['createdAt'],
				'archivedAt' => (new DateTime())->format('c'),
			];

			$archived = $keySet['archived'];
			$archived[] = $archivedEntry;

			// Prune oldest-first once the cap is exceeded.
			if (count($archived) > self::MAX_ARCHIVED_KEYS) {
				$archived = array_slice($archived, (count($archived) - self::MAX_ARCHIVED_KEYS));
			}

			$keySet['archived'] = $archived;
		}

		$keypair = $this->generateKeypair();
		$previousKid = ($keySet['active']['kid'] ?? null);
		$keySet['active'] = $keypair;
		$this->saveKeySet(organisationId: $organisationId, keySet: $keySet);

		$this->logger->info(
			'EudiIssuerKeyService: rotated issuer signing key',
			[
				'organisationId' => ($organisationId ?? self::DEFAULT_ORGANISATION_SCOPE),
				'newKid' => $keypair['kid'],
				'previousKid' => $previousKid,
			]
		);

		return [
			'kid' => $keypair['kid'],
			'publicKeyPem' => $keypair['publicKeyPem'],
			'algorithm' => self::ALGORITHM,
		];

	}//end rotateKey()

	/**
	 * Resolve the active signing key (including decrypted private material).
	 *
	 * Internal use only (signing) — never exposed on a controller response.
	 * Generates a fresh key on first use so callers never have to
	 * pre-provision a key before this adapter can sign anything.
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 *
	 * @return array{kid: string, publicKeyPem: string, privateKeyPem: string}
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
	 */
	public function resolveActiveKey(?string $organisationId = null): array {
		$keySet = $this->loadKeySet(organisationId: $organisationId);

		if ($keySet['active'] === null) {
			$this->generateKey(organisationId: $organisationId);
			$keySet = $this->loadKeySet(organisationId: $organisationId);
		}

		$active = $keySet['active'];

		return [
			'kid' => $active['kid'],
			'publicKeyPem' => $active['publicKeyPem'],
			'privateKeyPem' => $this->crypto->decrypt($active['encryptedPrivateKey']),
		];

	}//end resolveActiveKey()

	/**
	 * Resolve a public key (active or archived) by its `kid` fingerprint.
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 * @param string $kid The `kid` fingerprint to resolve.
	 *
	 * @return string|null The public key PEM, or null when not found.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
	 */
	public function resolvePublicKeyByFingerprint(?string $organisationId, string $kid): ?string {
		$keySet = $this->loadKeySet(organisationId: $organisationId);

		if (($keySet['active']['kid'] ?? null) === $kid) {
			return $keySet['active']['publicKeyPem'];
		}

		foreach ($keySet['archived'] as $entry) {
			if (($entry['kid'] ?? null) === $kid) {
				return $entry['publicKeyPem'];
			}
		}

		return null;
	}//end resolvePublicKeyByFingerprint()

	/**
	 * Derive a public JWK from a PEM public key.
	 *
	 * Uses the same secure temp-file pattern already established by
	 * `LtiKeyService::derivePublicJwk()` / `AuthorizationService::getJWK()`.
	 *
	 * @param string $publicKeyPem The PEM public key.
	 * @param string $kid The key's `kid`.
	 *
	 * @return array The public JWK.
	 *
	 * @throws RuntimeException When a secure temp file cannot be allocated.
	 */
	private function derivePublicJwk(string $publicKeyPem, string $kid): array {
		$filename = tempnam(sys_get_temp_dir(), 'oc-eudi-key-');
		if ($filename === false) {
			throw new RuntimeException('Could not allocate temp file for EUDI issuer key');
		}

		@chmod($filename, 0600);
		file_put_contents($filename, $publicKeyPem);
		@chmod($filename, 0600);

		try {
			$jwk = JWKFactory::createFromKeyFile(
				$filename,
				null,
				['kid' => $kid, 'alg' => self::ALGORITHM, 'use' => 'sig']
			);
		} finally {
			if (file_exists($filename) === true) {
				@unlink($filename);
			}
		}

		return $jwk->toPublic()->jsonSerialize();
	}//end derivePublicJwk()

	/**
	 * Build the publishable JWKS document for an organisation (active + archived).
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 *
	 * @return array A `{"keys": [...]}` JWKS document of public JWKs only.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-metadata-endpoint-req-eudi-003
	 */
	public function getJwks(?string $organisationId = null): array {
		$keySet = $this->loadKeySet(organisationId: $organisationId);
		if ($keySet['active'] === null) {
			// Ensure metadata always has at least one key to publish.
			$this->generateKey(organisationId: $organisationId);
			$keySet = $this->loadKeySet(organisationId: $organisationId);
		}

		$keys = [];
		$active = $keySet['active'];
		$keys[] = $this->derivePublicJwk(publicKeyPem: $active['publicKeyPem'], kid: $active['kid']);

		foreach ($keySet['archived'] as $entry) {
			$keys[] = $this->derivePublicJwk(publicKeyPem: $entry['publicKeyPem'], kid: $entry['kid']);
		}

		return ['keys' => $keys];
	}//end getJwks()

	/**
	 * Get the active key's public JWK only (used by the credential issuer
	 * metadata endpoint's `credential_configurations_supported` binding).
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 *
	 * @return array The active public JWK.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-metadata-endpoint-req-eudi-003
	 */
	public function getActivePublicJwk(?string $organisationId = null): array {
		$active = $this->resolveActiveKey(organisationId: $organisationId);

		return $this->derivePublicJwk(publicKeyPem: $active['publicKeyPem'], kid: $active['kid']);
	}//end getActivePublicJwk()

	/**
	 * Sign a JWT payload with the organisation's active issuer key.
	 *
	 * The SOLE code path in this adapter that touches decrypted private key
	 * material outside {@see resolveActiveKey()} itself — every credential
	 * (dc+sd-jwt) and every OAuth Status List Token this adapter mints is
	 * signed here.
	 *
	 * @param string|null $organisationId The organisation uuid, or null for the default scope.
	 * @param array $payload The JWT payload (claims) to sign.
	 * @param array $extraHeaderClaims Additional protected header claims (e.g. `typ`).
	 *
	 * @return string The compact-serialized signed JWT.
	 *
	 * @spec openspec/specs/eudi-wallet-credential-issuance/spec.md#requirement-credential-endpoint-verifies-proof-of-possession-and-dispatches-by-format-req-eudi-007
	 */
	public function signJwt(?string $organisationId, array $payload, array $extraHeaderClaims = []): string {
		$active = $this->resolveActiveKey(organisationId: $organisationId);

		$filename = tempnam(sys_get_temp_dir(), 'oc-eudi-sign-');
		if ($filename === false) {
			throw new RuntimeException('Could not allocate temp file for EUDI issuer signing key');
		}

		@chmod($filename, 0600);
		file_put_contents($filename, $active['privateKeyPem']);
		@chmod($filename, 0600);

		try {
			$jwk = JWKFactory::createFromKeyFile(
				$filename,
				null,
				['kid' => $active['kid'], 'alg' => self::ALGORITHM, 'use' => 'sig']
			);
		} finally {
			if (file_exists($filename) === true) {
				@unlink($filename);
			}
		}

		$algorithmManager = new AlgorithmManager([new ES256()]);
		$jwsBuilder = new JWSBuilder($algorithmManager);

		$protectedHeader = array_merge(
			['alg' => self::ALGORITHM, 'kid' => $active['kid']],
			$extraHeaderClaims
		);

		$jws = $jwsBuilder->create()
			->withPayload(json_encode($payload, JSON_UNESCAPED_SLASHES))
			->addSignature($jwk, $protectedHeader)
			->build();

		$serializer = new CompactSerializer();

		return $serializer->serialize($jws, 0);
	}//end signJwt()
}//end class
