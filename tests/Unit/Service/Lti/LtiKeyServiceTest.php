<?php

/**
 * Unit tests for LtiKeyService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Lti
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/lti-13-platform/specs/lti-platform/spec.md#requirement-own-signing-key-lifecycle-with-rotation-and-a-per-registration-jwks-publish-endpoint-req-lti-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Lti;

use DateTime;
use OCA\Integriq\Exception\LtiValidationException;
use OCA\Integriq\Service\Lti\LtiKeyService;
use OCA\Integriq\Tests\Unit\Service\Lti\Support\AesTestCrypto;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

/**
 * Tests for signing-key generation, rotation, grace-window JWKS content, and retirement.
 */
class LtiKeyServiceTest extends TestCase {

	/**
	 * In-memory "database" of registration objects keyed by uuid, used by
	 * the ObjectService mock's find/findAll/saveObject callbacks.
	 *
	 * @var array<string, array>
	 */
	private array $registrations = [];

	/**
	 * Build an LtiKeyService backed by an in-memory registration store.
	 *
	 * @return LtiKeyService
	 */
	private function makeService(): LtiKeyService {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('find')->willReturnCallback(
			function ($id, $_extend = [], $files = false, $register = null, $schema = null, $_rbac = true, $_multitenancy = true) {
				if (isset($this->registrations[$id]) === false) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('not found');
				}

				$entity = new ObjectEntity();
				$entity->setUuid($id);
				$entity->setObject($this->registrations[$id]);
				return $entity;
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function ($config = []) {
				$schema = ($config['filters']['schema'] ?? null);
				$results = [];
				foreach ($this->registrations as $uuid => $data) {
					if (($data['@schema'] ?? null) !== $schema) {
						continue;
					}

					$entity = new ObjectEntity();
					$entity->setUuid($uuid);
					$entity->setObject($data);
					$results[] = $entity;
				}

				return ['results' => $results];
			}
		);

		$objectService->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null, $uuid = null) {
				$this->registrations[$uuid] = $object;

				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($object);
				return $entity;
			}
		);

		return new LtiKeyService($objectService, new NullLogger(), new AesTestCrypto());
	}//end makeService()

	/**
	 * Seed a registration row.
	 *
	 * @param string $uuid The registration uuid.
	 * @param string $schema `lti_platform` or `lti_tool`.
	 * @param array $extra Extra fields (e.g. pre-existing signingKeys).
	 *
	 * @return void
	 */
	private function seedRegistration(string $uuid, string $schema, array $extra = []): void {
		$this->registrations[$uuid] = array_merge(['@schema' => $schema, 'signingKeys' => []], $extra);

	}//end seedRegistration()

	/**
	 * generateKey() produces an active key with public JWK and no leaked private material.
	 *
	 * @return void
	 */
	public function testGenerateKeyProducesActiveKeyRedacted(): void {
		$service = $this->makeService();
		$this->seedRegistration('plat-1', 'lti_platform');

		$entry = $service->generateKey('lti_platform', 'plat-1', 'RS256');

		$this->assertSame('active', $entry['status']);
		$this->assertSame('RS256', $entry['algorithm']);
		$this->assertNotEmpty($entry['kid']);
		$this->assertArrayHasKey('publicJwk', $entry);
		$this->assertArrayNotHasKey('privateKeySecret', $entry, 'generateKey() must never return the private key');

		// The persisted row DOES carry the private material (custody per ADR-007).
		$stored = $this->registrations['plat-1']['signingKeys'][0];
		$this->assertArrayHasKey('privateKeySecret', $stored);
		$this->assertNotEmpty($stored['privateKeySecret']);

		// Public JWK must not contain private RSA components.
		foreach (['d', 'p', 'q', 'dp', 'dq', 'qi'] as $privateComponent) {
			$this->assertArrayNotHasKey($privateComponent, $entry['publicJwk'], "publicJwk must not leak RSA component '$privateComponent'");
		}

	}//end testGenerateKeyProducesActiveKeyRedacted()

	/**
	 * generateKey() refuses to run when an active key already exists.
	 *
	 * @return void
	 */
	public function testGenerateKeyRejectsWhenActiveKeyExists(): void {
		$service = $this->makeService();
		$this->seedRegistration('plat-1', 'lti_platform', ['signingKeys' => [['kid' => 'k1', 'algorithm' => 'RS256', 'status' => 'active']]]);

		$this->expectException(BadRequestException::class);
		$service->generateKey('lti_platform', 'plat-1');

	}//end testGenerateKeyRejectsWhenActiveKeyExists()

	/**
	 * rotateKey() refuses to run when there is no active key.
	 *
	 * @return void
	 */
	public function testRotateKeyRejectsWhenNoActiveKey(): void {
		$service = $this->makeService();
		$this->seedRegistration('plat-1', 'lti_platform');

		$this->expectException(BadRequestException::class);
		$service->rotateKey('lti_platform', 'plat-1');

	}//end testRotateKeyRejectsWhenNoActiveKey()

	/**
	 * Rotation moves the current active key to previous (stamped rotatedAt)
	 * and generates a new active key; both remain in the publishable JWKS
	 * (grace window). New signatures always come from the new active key —
	 * verified indirectly by the new kid differing from the old one.
	 *
	 * @return void
	 */
	public function testRotateKeyKeepsPreviousPublishedDuringGraceWindow(): void {
		$service = $this->makeService();
		$this->seedRegistration('plat-1', 'lti_platform');

		$first = $service->generateKey('lti_platform', 'plat-1');
		$second = $service->rotateKey('lti_platform', 'plat-1');

		$this->assertNotSame($first['kid'], $second['kid']);
		$this->assertSame('active', $second['status']);

		$jwks = $service->getPublishableJwks('lti_platform', 'plat-1');
		$kids = array_column($jwks['keys'], 'kid');

		$this->assertContains($first['kid'], $kids, 'the rotated-out (previous) key must remain published during the grace window');
		$this->assertContains($second['kid'], $kids, 'the new active key must be published');
		$this->assertCount(2, $jwks['keys']);

		$activeEntry = $service->getActiveKeyEntry('lti_platform', 'plat-1');
		$this->assertSame($second['kid'], $activeEntry['kid'], 'new outbound signatures must use the new active key');

	}//end testRotateKeyKeepsPreviousPublishedDuringGraceWindow()

	/**
	 * A retired key (grace window elapsed) is dropped from the published JWKS.
	 *
	 * @return void
	 */
	public function testRetiredKeyIsRemovedFromPublishedSet(): void {
		$service = $this->makeService();
		$eightDaysAgo = (new DateTime('-8 days'))->format('c');

		$this->seedRegistration(
			'plat-1',
			'lti_platform',
			[
				'signingKeys' => [
					['kid' => 'old', 'algorithm' => 'RS256', 'publicJwk' => ['kty' => 'RSA', 'kid' => 'old'], 'status' => 'previous', 'rotatedAt' => $eightDaysAgo],
					['kid' => 'new', 'algorithm' => 'RS256', 'publicJwk' => ['kty' => 'RSA', 'kid' => 'new'], 'status' => 'active', 'rotatedAt' => null],
				],
			]
		);

		$retired = $service->retireExpiredKeys();
		$this->assertSame(1, $retired);

		$jwks = $service->getPublishableJwks('lti_platform', 'plat-1');
		$kids = array_column($jwks['keys'], 'kid');

		$this->assertNotContains('old', $kids, 'a retired key MUST NOT appear in the published JWKS');
		$this->assertContains('new', $kids);

	}//end testRetiredKeyIsRemovedFromPublishedSet()

	/**
	 * A previous key still within its 7-day grace window is NOT retired.
	 *
	 * @return void
	 */
	public function testPreviousKeyWithinGraceWindowIsNotRetired(): void {
		$service = $this->makeService();
		$oneDayAgo = (new DateTime('-1 day'))->format('c');

		$this->seedRegistration(
			'plat-1',
			'lti_platform',
			[
				'signingKeys' => [
					['kid' => 'recent', 'algorithm' => 'RS256', 'publicJwk' => ['kty' => 'RSA'], 'status' => 'previous', 'rotatedAt' => $oneDayAgo],
				],
			]
		);

		$retired = $service->retireExpiredKeys();
		$this->assertSame(0, $retired);

		$jwks = $service->getPublishableJwks('lti_platform', 'plat-1');
		$this->assertCount(1, $jwks['keys']);

	}//end testPreviousKeyWithinGraceWindowIsNotRetired()

	/**
	 * generateKey() rejects an unsupported algorithm.
	 *
	 * @return void
	 */
	public function testGenerateKeyRejectsUnsupportedAlgorithm(): void {
		$service = $this->makeService();
		$this->seedRegistration('plat-1', 'lti_platform');

		$this->expectException(BadRequestException::class);
		$service->generateKey('lti_platform', 'plat-1', 'HS256');

	}//end testGenerateKeyRejectsUnsupportedAlgorithm()

	// =========================================================================
	// REQ-LTI-011 — registration trust gate: approve()/suspend()
	// =========================================================================

	/**
	 * A newly-seeded registration with no `status` field defaults to
	 * `pending` and `approve()` transitions it to `approved`.
	 *
	 * @return void
	 */
	public function testApproveTransitionsPendingRegistrationToApproved(): void {
		$service = $this->makeService();
		$this->seedRegistration('plat-approve-1', 'lti_platform');

		$result = $service->approve('lti_platform', 'plat-approve-1');

		$this->assertSame('approved', $result['status']);
		$this->assertSame('plat-approve-1', $result['registrationUuid']);
		$this->assertSame('approved', $this->registrations['plat-approve-1']['status']);

	}//end testApproveTransitionsPendingRegistrationToApproved()

	/**
	 * `suspend()` transitions an approved registration to `suspended`.
	 *
	 * @return void
	 */
	public function testSuspendTransitionsApprovedRegistrationToSuspended(): void {
		$service = $this->makeService();
		$this->seedRegistration('plat-suspend-1', 'lti_platform', ['status' => 'approved']);

		$result = $service->suspend('lti_platform', 'plat-suspend-1');

		$this->assertSame('suspended', $result['status']);
		$this->assertSame('suspended', $this->registrations['plat-suspend-1']['status']);

	}//end testSuspendTransitionsApprovedRegistrationToSuspended()

	/**
	 * A suspended registration can be re-approved (reversible — design.md).
	 *
	 * @return void
	 */
	public function testSuspendedRegistrationCanBeReApproved(): void {
		$service = $this->makeService();
		$this->seedRegistration('plat-reapprove-1', 'lti_platform', ['status' => 'suspended']);

		$result = $service->approve('lti_platform', 'plat-reapprove-1');

		$this->assertSame('approved', $result['status']);

	}//end testSuspendedRegistrationCanBeReApproved()

	/**
	 * `approve()` on an unknown registration uuid throws rather than
	 * silently no-op-ing.
	 *
	 * @return void
	 */
	public function testApproveUnknownRegistrationThrows(): void {
		$service = $this->makeService();

		$this->expectException(LtiValidationException::class);
		$service->approve('lti_platform', 'does-not-exist');

	}//end testApproveUnknownRegistrationThrows()

	/**
	 * `approve()`/`suspend()` work identically for `lti_tool` registrations
	 * — the trust gate is role-agnostic (design.md D1).
	 *
	 * @return void
	 */
	public function testApproveAndSuspendWorkForLtiToolToo(): void {
		$service = $this->makeService();
		$this->seedRegistration('tool-approve-1', 'lti_tool');

		$approved = $service->approve('lti_tool', 'tool-approve-1');
		$this->assertSame('approved', $approved['status']);

		$suspended = $service->suspend('lti_tool', 'tool-approve-1');
		$this->assertSame('suspended', $suspended['status']);

	}//end testApproveAndSuspendWorkForLtiToolToo()

	/**
	 * The private half of a generated key is not a readable PEM at rest
	 * (integriq#2213). Before the fix `privateKeySecret` held base64(PEM),
	 * so a database dump carried every registration's signing key in clear.
	 *
	 * @return void
	 */
	public function testStoredPrivateKeyIsNotReadablePemAtRest(): void {
		$service = $this->makeService();
		$this->seedRegistration('plat-rest-1', 'lti_platform');

		$service->generateKey('lti_platform', 'plat-rest-1', 'RS256');

		$stored = (string)$this->registrations['plat-rest-1']['signingKeys'][0]['privateKeySecret'];
		$this->assertNotSame('', $stored);
		$this->assertStringNotContainsString('-----BEGIN', $stored, 'the stored key must not be a raw PEM');
		$this->assertStringNotContainsString(
			'-----BEGIN',
			(string)base64_decode($stored),
			'the stored key must not be a base64-encoded PEM'
		);

	}//end testStoredPrivateKeyIsNotReadablePemAtRest()

	/**
	 * The active key read back for signing still signs, and the signature
	 * verifies against the key the registration publishes.
	 *
	 * @return void
	 */
	public function testActiveKeyEntryFromEncryptedStorageStillSigns(): void {
		$service = $this->makeService();
		$this->seedRegistration('tool-rest-1', 'lti_tool');
		$generated = $service->generateKey('lti_tool', 'tool-rest-1', 'RS256');

		$entry = $service->getActiveKeyEntry('lti_tool', 'tool-rest-1');
		$this->assertNotNull($entry);
		$this->assertSame($generated['kid'], $entry['kid']);

		$this->assertSignsAndMatchesPublishedKey(
			privateKeySecret: (string)$entry['privateKeySecret'],
			publicJwk: $generated['publicJwk']
		);

	}//end testActiveKeyEntryFromEncryptedStorageStillSigns()

	/**
	 * A row written before encryption (plain base64 PEM) still signs, and the
	 * next write through this service stores it encrypted.
	 *
	 * @return void
	 */
	public function testLegacyPlaintextKeyStillSignsAndIsEncryptedOnNextWrite(): void {
		$resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		openssl_pkey_export($resource, $pem);
		$details = openssl_pkey_get_details($resource);
		$legacySecret = base64_encode($pem);
		$legacyJwk = ['kty' => 'RSA', 'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=')];

		$service = $this->makeService();
		$this->seedRegistration(
			'plat-legacy-1',
			'lti_platform',
			['signingKeys' => [['kid' => 'legacy-kid', 'algorithm' => 'RS256', 'publicJwk' => $legacyJwk, 'privateKeySecret' => $legacySecret, 'status' => 'active', 'rotatedAt' => null]]]
		);

		$entry = $service->getActiveKeyEntry('lti_platform', 'plat-legacy-1');
		$this->assertNotNull($entry);
		$this->assertSame($legacySecret, $entry['privateKeySecret'], 'a legacy row reads back unchanged');
		$this->assertSignsAndMatchesPublishedKey(privateKeySecret: (string)$entry['privateKeySecret'], publicJwk: $legacyJwk);

		// Rotation is the next write: the legacy key becomes `previous` and is sealed.
		$service->rotateKey('lti_platform', 'plat-legacy-1');
		foreach ($this->registrations['plat-legacy-1']['signingKeys'] as $stored) {
			$this->assertStringNotContainsString('-----BEGIN', (string)base64_decode((string)$stored['privateKeySecret']));
		}

		$this->assertSame('previous', $this->registrations['plat-legacy-1']['signingKeys'][0]['status']);

	}//end testLegacyPlaintextKeyStillSignsAndIsEncryptedOnNextWrite()

	/**
	 * A stored key that cannot be decrypted (the instance secret changed)
	 * fails closed with a validation error, never an unsigned launch.
	 *
	 * @return void
	 */
	public function testUndecryptableKeyFailsClosed(): void {
		$service = $this->makeService();
		$this->seedRegistration('plat-bad-1', 'lti_platform');
		$service->generateKey('lti_platform', 'plat-bad-1', 'RS256');

		// Ciphertext whose HMAC does not verify, as after a changed instance secret.
		$this->registrations['plat-bad-1']['signingKeys'][0]['privateKeySecret'] = LtiKeyService::ENCRYPTED_PREFIX . 'deadbeef|00|00|3';

		$this->expectException(LtiValidationException::class);
		$service->getActiveKeyEntry('lti_platform', 'plat-bad-1');

	}//end testUndecryptableKeyFailsClosed()

	/**
	 * Sign with a stored base64(PEM) key and verify with its public half,
	 * whose modulus must be the one the registration publishes.
	 *
	 * @param string $privateKeySecret The base64(PEM) private key as signing code receives it.
	 * @param array  $publicJwk        The published public JWK.
	 *
	 * @return void
	 */
	private function assertSignsAndMatchesPublishedKey(string $privateKeySecret, array $publicJwk): void {
		$pem = base64_decode($privateKeySecret, true);
		$this->assertIsString($pem);
		$privateKey = openssl_pkey_get_private($pem);
		$this->assertNotFalse($privateKey, 'the key handed to signing code must parse as a private key');

		$details = openssl_pkey_get_details($privateKey);
		$this->assertSame(
			$publicJwk['n'],
			rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
			'the signing key must be the key the registration publishes'
		);

		$this->assertTrue(openssl_sign('launch', $signature, $privateKey, OPENSSL_ALGO_SHA256));
		$this->assertSame(1, openssl_verify('launch', $signature, $details['key'], OPENSSL_ALGO_SHA256));

	}//end assertSignsAndMatchesPublishedKey()
}//end class
