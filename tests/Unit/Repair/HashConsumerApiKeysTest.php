<?php

/**
 * Unit tests for HashConsumerApiKeys: the plaintext key moves into a hashed credential.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/access-consumer-credentials/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\HashConsumerApiKeys;
use OCA\Integriq\Service\Consumer\ConsumerCredentials;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Hash first, then null the plaintext; a failure halfway keeps the consumer working.
 *
 * @covers \OCA\Integriq\Repair\HashConsumerApiKeys
 * @uses   \OCA\Integriq\Service\Consumer\ConsumerCredentials
 * @uses   \OCA\Integriq\Service\Helper\RandomUuid
 */
final class HashConsumerApiKeysTest extends TestCase {

	/**
	 * Stored consumers by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * Save number (1-based) that throws, or 0.
	 *
	 * @var int
	 */
	private int $failingSave = 0;

	/**
	 * Saves made.
	 *
	 * @var int
	 */
	private int $saveCount = 0;

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
	}

	public function testAPlaintextKeyBecomesOneHashedCredentialAndIsNulled(): void {
		$this->stored = ['c-1' => ['name' => 'portal', 'authorizationType' => 'apikey', 'authorizationConfiguration' => ['apiKey' => 'k-123', 'other' => 'kept']]];

		$this->repair()->run($this->createMock(IOutput::class));

		$data = $this->stored['c-1'];
		$this->assertNull($data['authorizationConfiguration']['apiKey']);
		$this->assertSame('kept', $data['authorizationConfiguration']['other']);
		$this->assertCount(1, $data[ConsumerCredentials::FIELD]);
		$entry = $data[ConsumerCredentials::FIELD][0];
		$this->assertSame('migrated', $entry['label']);
		$this->assertSame('apiKey', $entry['type']);
		$this->assertSame('k', $entry['keyPrefix']);
		$this->assertSame($this->credentials->hashKey(key: 'k-123'), $entry['keyHash']);
		$this->assertNotContains('k-123', $entry);
		$this->assertSame(2, $this->saveCount);
		$this->assertSame(0, $this->credentials->matchingApiKey(credentials: $data[ConsumerCredentials::FIELD], key: 'k-123', now: new \DateTimeImmutable()));
	}

	public function testAFailedHashSaveKeepsThePlaintextSoTheConsumerStillWorks(): void {
		$this->stored = ['c-1' => ['name' => 'portal', 'authorizationConfiguration' => ['apiKey' => 'k-123']]];
		$this->failingSave = 1;
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[integriq] consumer key hashing failed, plaintext kept',
			['uuid' => 'c-1', 'errorClass' => RuntimeException::class]
		);

		$this->repair(logger: $logger)->run($this->createMock(IOutput::class));

		$this->assertSame('k-123', $this->stored['c-1']['authorizationConfiguration']['apiKey']);
		$this->assertArrayNotHasKey(ConsumerCredentials::FIELD, $this->stored['c-1']);
	}

	public function testAFailedRemovalIsFinishedByTheNextRunWithoutADuplicateEntry(): void {
		$this->stored = ['c-1' => ['name' => 'portal', 'authorizationConfiguration' => ['apiKey' => 'k-123']]];
		$this->failingSave = 2;

		$this->repair()->run($this->createMock(IOutput::class));
		$this->assertSame('k-123', $this->stored['c-1']['authorizationConfiguration']['apiKey']);
		$this->assertCount(1, $this->stored['c-1'][ConsumerCredentials::FIELD]);

		$this->failingSave = 0;
		$this->repair()->run($this->createMock(IOutput::class));
		$this->assertNull($this->stored['c-1']['authorizationConfiguration']['apiKey']);
		$this->assertCount(1, $this->stored['c-1'][ConsumerCredentials::FIELD]);
	}

	public function testAConsumerWithoutAPlaintextKeyIsLeftAlone(): void {
		$this->stored = [
			'c-jwt' => ['name' => 'jwt', 'authorizationConfiguration' => ['publicKey' => 'pk']],
			'c-done' => ['name' => 'done', 'authorizationConfiguration' => ['apiKey' => null], ConsumerCredentials::FIELD => []],
			'c-int' => ['name' => 'int', 'authorizationConfiguration' => ['apiKey' => 123]],
		];
		$before = $this->stored;

		$this->repair()->run($this->createMock(IOutput::class));

		$this->assertSame($before, $this->stored);
		$this->assertSame(0, $this->saveCount);
	}

	public function testExistingCredentialsAreKept(): void {
		$existing = $this->credentials->apiKeyEntry(key: 'newer', label: 'newer');
		$this->stored = ['c-1' => ['name' => 'p', 'authorizationConfiguration' => ['apiKey' => 'k-123'], ConsumerCredentials::FIELD => [$existing]]];

		$this->repair()->run($this->createMock(IOutput::class));

		$this->assertSame($existing, $this->stored['c-1'][ConsumerCredentials::FIELD][0]);
		$this->assertSame('migrated', $this->stored['c-1'][ConsumerCredentials::FIELD][1]['label']);
	}

	public function testAnUnreadableConsumerListIsReportedNotThrown(): void {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('down'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Integriq: could not read consumers for key hashing: ' . RuntimeException::class);

		(new HashConsumerApiKeys($container, $this->credentials, $this->createMock(LoggerInterface::class)))->run($output);
	}

	/**
	 * The repair step over the fake store.
	 *
	 * @param LoggerInterface|null $logger The logger.
	 *
	 * @return HashConsumerApiKeys
	 */
	private function repair(?LoggerInterface $logger = null): HashConsumerApiKeys {
		$objects = $this->createMock(ObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (): array {
				$all = [];
				foreach (array_keys($this->stored) as $uuid) {
					$entity = new ObjectEntity();
					$entity->setUuid($uuid);
					$all[] = $entity;
				}
				return ['results' => $all];
			}
		);
		$objects->method('find')->willReturnCallback(
			function ($id, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true, bool $_render = true): ObjectEntity {
				$this->assertFalse($_render);
				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setObject($this->stored[$id]);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null): ObjectEntity {
				$this->saveCount++;
				if ($this->saveCount === $this->failingSave) {
					throw new RuntimeException('database down');
				}
				$this->assertSame(['integriq', 'consumer'], [$register, $schema]);
				$this->stored[(string)$uuid] = $object;
				return new ObjectEntity();
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($objects);

		return new HashConsumerApiKeys($container, $this->credentials, ($logger ?? $this->createMock(LoggerInterface::class)));
	}
}
