<?php

/**
 * Unit tests for EudiStatusListService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-publishes-single-bit-revocation-only-req-eudi-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTime;
use OCA\Integriq\Service\EudiIssuerKeyService;
use OCA\Integriq\Service\EudiStatusListService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for status-list bit assignment, revocation bit-flip idempotency,
 * bitstring encode/decode round-trip, and the near-expiry refresh sweep.
 *
 * @spec openspec/changes/eudi-wallet-credential-issuance/specs/eudi-wallet-credential-issuance/spec.md#requirement-status-list-publishes-single-bit-revocation-only-req-eudi-008
 */
class EudiStatusListServiceTest extends TestCase {

	/**
	 * In-memory "database" of status-list rows keyed by uuid.
	 *
	 * @var array<string, array>
	 */
	private array $rows = [];

	/**
	 * Build an EudiStatusListService backed by an in-memory OR ObjectService
	 * mock and a real EudiIssuerKeyService (in-memory app-config + fake
	 * reversible ICrypto — see EudiIssuerKeyServiceTest for the same pattern).
	 *
	 * @return EudiStatusListService
	 */
	private function makeService(): EudiStatusListService {
		$objectService = $this->createMock(ObjectService::class);

		$objectService->method('find')->willReturnCallback(
			function ($id, ...$rest) {
				if (isset($this->rows[$id]) === false) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('not found');
				}

				$entity = new ObjectEntity();
				$entity->setUuid($id);
				$entity->setObject($this->rows[$id]);
				return $entity;
			}
		);

		$objectService->method('findAll')->willReturnCallback(
			function ($config = []) {
				$filters = ($config['filters'] ?? []);
				$orgId = ($filters['organisationId'] ?? null);
				$results = [];
				foreach ($this->rows as $uuid => $data) {
					if ($orgId !== null && ($data['organisationId'] ?? null) !== $orgId) {
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
				if ($uuid === null) {
					$uuid = bin2hex(random_bytes(8));
				}

				$this->rows[$uuid] = $object;

				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setObject($object);
				return $entity;
			}
		);

		$appConfigStore = [];
		$appConfig = $this->createMock(\OCP\IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use (&$appConfigStore) {
				return ($appConfigStore[$app . '.' . $key] ?? $default);
			}
		);
		$appConfig->method('setValueString')->willReturnCallback(
			static function (string $app, string $key, string $value, bool $lazy = false, bool $sensitive = false) use (&$appConfigStore) {
				$appConfigStore[$app . '.' . $key] = $value;
				return true;
			}
		);

		$crypto = $this->createMock(\OCP\Security\ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $p): string => 'enc:' . base64_encode($p));
		$crypto->method('decrypt')->willReturnCallback(static fn (string $c): string => base64_decode(substr($c, strlen('enc:'))));

		$keyService = new EudiIssuerKeyService($appConfig, $crypto, new NullLogger());

		return new EudiStatusListService($objectService, $keyService, new NullLogger());
	}//end makeService()

	/**
	 * assignIndex() lazily creates a status list for a new organisation and
	 * assigns sequential indices.
	 *
	 * @return void
	 */
	public function testAssignIndexCreatesListAndAssignsSequentialIndices(): void {
		$service = $this->makeService();

		$first = $service->assignIndex('org-1');
		$second = $service->assignIndex('org-1');

		$this->assertSame(0, $first['index']);
		$this->assertSame(1, $second['index']);
		$this->assertSame($first['statusListId'], $second['statusListId']);

	}//end testAssignIndexCreatesListAndAssignsSequentialIndices()

	/**
	 * revokeIndex() flips the bit 0 -> 1 exactly once; a second call is idempotent.
	 *
	 * @return void
	 */
	public function testRevokeIndexFlipsBitAndIsIdempotent(): void {
		$service = $this->makeService();
		$assignment = $service->assignIndex('org-1');

		$flipped = $service->revokeIndex($assignment['statusListId'], $assignment['index']);
		$this->assertTrue($flipped, 'first revocation must actually flip the bit');

		$again = $service->revokeIndex($assignment['statusListId'], $assignment['index']);
		$this->assertFalse($again, 'revoking an already-revoked bit must be a idempotent no-op');

		$row = $this->rows[$assignment['statusListId']];
		$this->assertSame(1, $row['bitstring'][$assignment['index']]);

	}//end testRevokeIndexFlipsBitAndIsIdempotent()

	/**
	 * The compressed `lst` bitstring round-trips through encode/decode.
	 *
	 * @return void
	 */
	public function testBitstringEncodeDecodeRoundTrips(): void {
		$bitstring = [0 => 0, 1 => 1, 2 => 0, 3 => 1, 10 => 1];

		$reflection = new \ReflectionMethod(EudiStatusListService::class, 'encodeStatusList');
		$reflection->setAccessible(true);
		$lst = $reflection->invoke(null, $bitstring, 1);

		$decoded = EudiStatusListService::decodeStatusList($lst, 1);

		foreach ($bitstring as $index => $value) {
			$this->assertSame($value, ($decoded[$index] ?? 0), "bit $index must round-trip");
		}

	}//end testBitstringEncodeDecodeRoundTrips()

	/**
	 * signAndCache() produces a JWT whose payload carries the current
	 * bitstring and a future exp, verifiable against the issuer's active key.
	 *
	 * @return void
	 */
	public function testSignAndCacheProducesAValidStatusListToken(): void {
		$service = $this->makeService();
		$assignment = $service->assignIndex('org-1');
		$service->revokeIndex($assignment['statusListId'], $assignment['index']);

		$token = $service->getPublishedToken($assignment['statusListId']);
		$this->assertNotNull($token);

		$parts = explode('.', $token);
		$this->assertCount(3, $parts);

		$padded = str_pad($parts[1], ((int)ceil(strlen($parts[1]) / 4) * 4), '=');
		$payload = json_decode(base64_decode(strtr($padded, '-_', '+/')), true);

		$this->assertArrayHasKey('status_list', $payload);
		$this->assertGreaterThan(time(), $payload['exp']);

		$decodedBits = EudiStatusListService::decodeStatusList($payload['status_list']['lst'], 1);
		$this->assertSame(1, ($decodedBits[$assignment['index']] ?? 0));

	}//end testSignAndCacheProducesAValidStatusListToken()

	/**
	 * refreshNearExpiry() re-signs a row whose token exp is within the
	 * refresh threshold, leaving the bitstring contents unchanged.
	 *
	 * @return void
	 */
	public function testRefreshNearExpiryResignsNearExpiryRows(): void {
		$service = $this->makeService();
		$assignment = $service->assignIndex('org-1');
		$service->revokeIndex($assignment['statusListId'], $assignment['index']);

		$oldToken = $this->rows[$assignment['statusListId']]['currentToken'];

		// Force the cached token to look near-expiry (< 25% of 24h remaining).
		$nearExpiry = (new DateTime('+1 hour'))->format('c');
		$this->rows[$assignment['statusListId']]['currentTokenExp'] = $nearExpiry;

		$refreshed = $service->refreshNearExpiry();
		$this->assertSame(1, $refreshed);

		$newToken = $this->rows[$assignment['statusListId']]['currentToken'];
		$this->assertNotSame($oldToken, $newToken, 're-signing must produce a fresh token');

		// Bitstring contents must be unchanged by the refresh.
		$this->assertSame(1, $this->rows[$assignment['statusListId']]['bitstring'][$assignment['index']]);

	}//end testRefreshNearExpiryResignsNearExpiryRows()

	/**
	 * refreshNearExpiry() does NOT re-sign a row whose token is far from expiry.
	 *
	 * @return void
	 */
	public function testRefreshNearExpiryLeavesFreshTokensAlone(): void {
		$service = $this->makeService();
		$assignment = $service->assignIndex('org-1');
		$service->getPublishedToken($assignment['statusListId']);

		$refreshed = $service->refreshNearExpiry();
		$this->assertSame(0, $refreshed, 'a freshly-signed 24h token must not be refreshed again immediately');

	}//end testRefreshNearExpiryLeavesFreshTokensAlone()
}//end class
