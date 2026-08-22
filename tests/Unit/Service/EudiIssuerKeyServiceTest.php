<?php

/**
 * Unit tests for EudiIssuerKeyService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\EudiIssuerKeyService;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for issuer key generation, rotation/archiving, fingerprint
 * resolution, JWKS publish, and JWT signing.
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-issuer-signing-key-lifecycle-under-beheer-authenticatie-req-eudi-002
 */
class EudiIssuerKeyServiceTest extends TestCase {

	/**
	 * In-memory app-config store keyed by "app.key".
	 *
	 * @var array<string, string>
	 */
	private array $configStore = [];

	/**
	 * Build an EudiIssuerKeyService backed by an in-memory app-config store
	 * and a reversible (real, not canned) base64 "encryption" — exercises
	 * the actual encrypt-before-persist / decrypt-before-sign code paths.
	 *
	 * @return EudiIssuerKeyService
	 */
	private function makeService(): EudiIssuerKeyService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') {
				return ($this->configStore[$app . '.' . $key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false) {
				$this->configStore[$app . '.' . $key] = $value;
				return true;
			}
		);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(
			static fn (string $plaintext): string => 'enc:' . base64_encode($plaintext)
		);
		$crypto->method('decrypt')->willReturnCallback(
			static function (string $ciphertext): string {
				return base64_decode(substr($ciphertext, strlen('enc:')));
			}
		);

		return new EudiIssuerKeyService($appConfig, $crypto, new NullLogger());
	}//end makeService()

	/**
	 * A generated key's private half is stored encrypted, never the raw PEM,
	 * and the response returned to the caller contains no private material.
	 *
	 * @return void
	 */
	public function testGeneratedKeyIsEncryptedAtRestAndResponseHasNoPrivateMaterial(): void {
		$service = $this->makeService();

		$entry = $service->generateKey('org-1');

		$this->assertArrayHasKey('kid', $entry);
		$this->assertArrayHasKey('publicKeyPem', $entry);
		$this->assertArrayNotHasKey('privateKeyPem', $entry, 'generateKey() must never return private key material');
		$this->assertArrayNotHasKey('encryptedPrivateKey', $entry);

		// The raw stored blob's public key half is legitimately plain PEM
		// (design.md D-KEY), but the PRIVATE key PEM header must never
		// appear verbatim — only through the (fake, but real-shaped)
		// ICrypto-encrypted ciphertext.
		$stored = $this->configStore['integriq.eudi_issuer_key_org-1'];
		$this->assertStringNotContainsString('-----BEGIN PRIVATE KEY-----', $stored, 'stored blob must not contain a raw private-key PEM header');
		$this->assertStringContainsString('enc:', $stored, 'stored blob must carry the ICrypto-encrypted ciphertext');

	}//end testGeneratedKeyIsEncryptedAtRestAndResponseHasNoPrivateMaterial()

	/**
	 * resolveActiveKey() decrypts the private key correctly (round-trip).
	 *
	 * @return void
	 */
	public function testResolveActiveKeyDecryptsPrivateKeyRoundTrip(): void {
		$service = $this->makeService();
		$service->generateKey('org-1');

		$active = $service->resolveActiveKey('org-1');

		$this->assertStringContainsString('-----BEGIN PRIVATE KEY-----', $active['privateKeyPem']);

	}//end testResolveActiveKeyDecryptsPrivateKeyRoundTrip()

	/**
	 * resolveActiveKey() lazily generates a key on first use when none exists yet.
	 *
	 * @return void
	 */
	public function testResolveActiveKeyLazilyGeneratesWhenMissing(): void {
		$service = $this->makeService();

		$active = $service->resolveActiveKey('org-never-provisioned');

		$this->assertNotEmpty($active['kid']);
		$this->assertNotEmpty($active['privateKeyPem']);

	}//end testResolveActiveKeyLazilyGeneratesWhenMissing()

	/**
	 * Rotation archives the previous public key (resolvable by kid) and
	 * discards its private key — a new active key is generated.
	 *
	 * @return void
	 */
	public function testRotateKeyArchivesPreviousPublicKeyOnly(): void {
		$service = $this->makeService();
		$first = $service->generateKey('org-1');

		$second = $service->rotateKey('org-1');

		$this->assertNotSame($first['kid'], $second['kid']);

		// The old key's public material remains resolvable...
		$archivedPublic = $service->resolvePublicKeyByFingerprint('org-1', $first['kid']);
		$this->assertSame($first['publicKeyPem'], $archivedPublic);

		// ...but the archived entry itself never carries the encrypted private key.
		$stored = json_decode($this->configStore['integriq.eudi_issuer_key_org-1'], true);
		$archivedRow = $stored['archived'][0];
		$this->assertArrayNotHasKey('encryptedPrivateKey', $archivedRow, 'archived key MUST NOT retain private key material');

		// New signatures resolve to the NEW active key.
		$active = $service->resolveActiveKey('org-1');
		$this->assertSame($second['kid'], $active['kid']);

	}//end testRotateKeyArchivesPreviousPublicKeyOnly()

	/**
	 * Rotation caps the archive at MAX_ARCHIVED_KEYS, pruning oldest first.
	 *
	 * @return void
	 */
	public function testRotationPrunesArchiveOldestFirst(): void {
		$service = $this->makeService();
		$service->generateKey('org-1');

		$kids = [];
		for ($i = 0; $i < (EudiIssuerKeyService::MAX_ARCHIVED_KEYS + 5); $i++) {
			$kids[] = $service->rotateKey('org-1')['kid'];
		}

		$stored = json_decode($this->configStore['integriq.eudi_issuer_key_org-1'], true);
		$this->assertCount(EudiIssuerKeyService::MAX_ARCHIVED_KEYS, $stored['archived']);

		// The oldest archived entries (from the earliest rotations) must have been pruned.
		$archivedKids = array_column($stored['archived'], 'kid');
		$this->assertNotContains($kids[0], $archivedKids, 'the oldest archived key must have been pruned');
		$this->assertContains($kids[count($kids) - 2], $archivedKids, 'a recent archived key must be retained');

	}//end testRotationPrunesArchiveOldestFirst()

	/**
	 * Different organisations never share a key (separate app-config scope).
	 *
	 * @return void
	 */
	public function testOrganisationsAreScopedIndependently(): void {
		$service = $this->makeService();

		$orgA = $service->generateKey('org-a');
		$orgB = $service->generateKey('org-b');

		$this->assertNotSame($orgA['kid'], $orgB['kid']);
		$this->assertNull($service->resolvePublicKeyByFingerprint('org-a', $orgB['kid']));

	}//end testOrganisationsAreScopedIndependently()

	/**
	 * A null organisation id (organisation-bridge unavailable) falls back to
	 * the single default-organisation scope rather than an unencrypted key
	 * or a hard failure.
	 *
	 * @return void
	 */
	public function testNullOrganisationFallsBackToDefaultScope(): void {
		$service = $this->makeService();

		$entry = $service->generateKey(null);

		$this->assertArrayHasKey('integriq.eudi_issuer_key_' . EudiIssuerKeyService::DEFAULT_ORGANISATION_SCOPE, $this->configStore);
		$active = $service->resolveActiveKey(null);
		$this->assertSame($entry['kid'], $active['kid']);

	}//end testNullOrganisationFallsBackToDefaultScope()

	/**
	 * getJwks() publishes both the active and archived public keys as JWKs,
	 * never any private key material.
	 *
	 * @return void
	 */
	public function testGetJwksPublishesActiveAndArchivedPublicKeysOnly(): void {
		$service = $this->makeService();
		$first = $service->generateKey('org-1');
		$second = $service->rotateKey('org-1');

		$jwks = $service->getJwks('org-1');
		$kids = array_column($jwks['keys'], 'kid');

		$this->assertContains($first['kid'], $kids);
		$this->assertContains($second['kid'], $kids);

		foreach ($jwks['keys'] as $jwk) {
			foreach (['d', 'privateKeyPem', 'encryptedPrivateKey'] as $privateField) {
				$this->assertArrayNotHasKey($privateField, $jwk, "JWKS entry must not leak '$privateField'");
			}
		}

	}//end testGetJwksPublishesActiveAndArchivedPublicKeysOnly()

	/**
	 * signJwt() produces a compact JWS that verifies against the active
	 * key's own public JWK.
	 *
	 * @return void
	 */
	public function testSignJwtProducesAVerifiableCompactJws(): void {
		$service = $this->makeService();
		$service->generateKey('org-1');

		$jwt = $service->signJwt('org-1', ['sub' => 'test-subject', 'iat' => time()], ['typ' => 'test+jwt']);

		$parts = explode('.', $jwt);
		$this->assertCount(3, $parts, 'a compact JWS has exactly 3 dot-separated parts');

		$padded = str_pad($parts[0], ((int)ceil(strlen($parts[0]) / 4) * 4), '=');
		$header = json_decode(base64_decode(strtr($padded, '-_', '+/')), true);
		$this->assertSame('ES256', $header['alg']);
		$this->assertSame('test+jwt', $header['typ']);

	}//end testSignJwtProducesAVerifiableCompactJws()
}//end class
