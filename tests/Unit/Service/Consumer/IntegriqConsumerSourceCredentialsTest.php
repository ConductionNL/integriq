<?php

/**
 * Unit tests for IntegriqConsumerSource over a consumer's credentials list.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/access-consumer-credentials/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Consumer;

use OCA\Integriq\Service\Consumer\ConsumerCredentials;
use OCA\Integriq\Service\Consumer\IntegriqConsumerSource;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Two keys at once, an expired key, the throttled last used write, and the
 * single stored key still working.
 *
 * @covers \OCA\Integriq\Service\Consumer\IntegriqConsumerSource
 * @uses   \OCA\Integriq\Service\Consumer\ConsumerCredentials
 * @uses   \OCA\Integriq\Service\Helper\RandomUuid
 */
final class IntegriqConsumerSourceCredentialsTest extends TestCase {

	/**
	 * The stored consumers' data, by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * Saves made, as [uuid, data, silent].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>, 2: bool}>
	 */
	private array $saves = [];

	/**
	 * Whether a save throws.
	 *
	 * @var bool
	 */
	private bool $saveFails = false;

	private ConsumerCredentials $credentials;

	protected function setUp(): void {
		$config = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $a, string $k, string $d = ''): string => ($config[$k] ?? $d));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $a, string $k, string $v) use (&$config): bool {
				$config[$k] = $v;
				return true;
			}
		);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(fn (int $length): string => str_repeat('p', $length));
		$this->credentials = new ConsumerCredentials($appConfig, $random);

		$this->stored = [
			'c-partner' => [
				'name' => 'partner',
				'authorizationType' => 'apiKey',
				'authorizationConfiguration' => ['apiKey' => null],
				ConsumerCredentials::FIELD => [
					$this->entry(key: 'old-key', id: 'cred-old', lastUsedAt: '2026-11-11T08:00:00+00:00'),
					$this->entry(key: 'new-key', id: 'cred-new', lastUsedAt: (new \DateTimeImmutable())->format(DATE_ATOM)),
					$this->entry(key: 'gone-key', id: 'cred-gone', expiresAt: '2020-01-01T00:00:00+00:00'),
				],
			],
			'c-legacy' => ['name' => 'legacy', 'authorizationType' => 'apikey', 'authorizationConfiguration' => ['apiKey' => 'plain-key']],
		];
	}

	public function testBothKeysOfARotatingConsumerPass(): void {
		$source = $this->source();

		$this->assertSame('c-partner', $source->findByApiKey('old-key')?->uuid);
		$this->assertSame('c-partner', $source->findByApiKey('new-key')?->uuid);
	}

	public function testAnExpiredKeyOrAnUnknownKeyIsRefused(): void {
		$source = $this->source();

		$this->assertNull($source->findByApiKey('gone-key'));
		$this->assertNull($source->findByApiKey('nope'));
		$this->assertSame([], $this->saves);
	}

	public function testUsingAKeyRecordsWhenOnTheRawObjectAndKeepsTheOtherEntries(): void {
		$this->source()->findByApiKey('old-key');

		$this->assertCount(1, $this->saves);
		[$uuid, $data, $silent] = $this->saves[0];
		$this->assertSame('c-partner', $uuid);
		$this->assertTrue($silent);
		$this->assertNotSame('2026-11-11T08:00:00+00:00', $data[ConsumerCredentials::FIELD][0]['lastUsedAt']);
		$this->assertSame($this->stored['c-partner'][ConsumerCredentials::FIELD][1], $data[ConsumerCredentials::FIELD][1]);
		$this->assertSame($this->stored['c-partner'][ConsumerCredentials::FIELD][0]['keyHash'], $data[ConsumerCredentials::FIELD][0]['keyHash']);
	}

	public function testAKeyUsedWithinTheMinuteIsNotWrittenAgain(): void {
		$this->source()->findByApiKey('new-key');

		$this->assertSame([], $this->saves);
	}

	public function testAFailedLastUsedWriteStillLetsTheCallThrough(): void {
		$this->saveFails = true;

		$this->assertSame('c-partner', $this->source()->findByApiKey('old-key')?->uuid);
	}

	public function testAListChangedSinceTheReadIsNotOverwritten(): void {
		$objects = $this->objects(rawOverride: ['name' => 'partner', ConsumerCredentials::FIELD => [['id' => 'someone-else']]]);

		$this->assertSame('c-partner', (new IntegriqConsumerSource($objects, $this->credentials))->findByApiKey('old-key')?->uuid);
		$this->assertSame([], $this->saves);
	}

	public function testTheSingleStoredKeyStillPasses(): void {
		$this->assertSame('c-legacy', $this->source()->findByApiKey('plain-key')?->uuid);
		$this->assertSame('c-legacy', (new IntegriqConsumerSource($this->objects()))->findByApiKey('plain-key')?->uuid);
	}

	public function testWithoutTheCredentialRulesOnlyTheSingleKeyIsChecked(): void {
		$this->assertNull((new IntegriqConsumerSource($this->objects()))->findByApiKey('old-key'));
	}

	/**
	 * A stored API key entry.
	 *
	 * @param string      $key        The key.
	 * @param string      $id         The entry id.
	 * @param string|null $lastUsedAt When it was last used.
	 * @param string|null $expiresAt  When it expires.
	 *
	 * @return array<string, mixed>
	 */
	private function entry(string $key, string $id, ?string $lastUsedAt = null, ?string $expiresAt = null): array {
		$entry = $this->credentials->apiKeyEntry(key: $key, label: $id, expiresAt: $expiresAt);
		$entry['id'] = $id;
		$entry['lastUsedAt'] = $lastUsedAt;

		return $entry;
	}

	/**
	 * The source with the credential rules.
	 *
	 * @return IntegriqConsumerSource
	 */
	private function source(): IntegriqConsumerSource {
		return new IntegriqConsumerSource($this->objects(), $this->credentials);
	}

	/**
	 * An object service over the stored consumers.
	 *
	 * @param array<string, mixed>|null $rawOverride What a raw read answers instead of the stored data.
	 *
	 * @return ObjectService
	 */
	private function objects(?array $rawOverride = null): ObjectService {
		$entity = static function (string $uuid, array $data): ObjectEntity {
			$object = new ObjectEntity();
			$object->setUuid($uuid);
			$object->setObject($data);
			return $object;
		};
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function () use ($entity): array {
				$all = [];
				foreach ($this->stored as $uuid => $data) {
					$all[] = $entity($uuid, $data);
				}
				return ['results' => $all];
			}
		);
		$objects->method('find')->willReturnCallback(
			function ($id, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = true) use ($entity, $rawOverride): ObjectEntity {
				$this->assertFalse($_render);
				$this->assertSame(['integriq', 'consumer'], [$register, $schema]);
				return $entity((string)$id, ($rawOverride ?? $this->stored[$id]));
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true, bool $silent = false): ObjectEntity {
				if ($this->saveFails === true) {
					throw new RuntimeException('database down');
				}
				$this->assertFalse($_rbac);
				$this->saves[] = [(string)$uuid, $object, $silent];
				return new ObjectEntity();
			}
		);

		return $objects;
	}
}
