<?php

/**
 * Characterisation test for RecordOwnershipService::forObject.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Ownership
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Ownership;

use OCA\Integriq\Service\Ownership\OwnershipState;
use OCA\Integriq\Service\Ownership\RecordOwnershipService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

/**
 * Golden record of the ownership reads.
 */
class RecordOwnershipGoldenTest extends TestCase {

	/**
	 * Every read and log line, in order.
	 *
	 * @var list<mixed>
	 */
	private array $log = [];

	/**
	 * The state for one target over the given stored objects.
	 *
	 * @param string                    $targetId The target.
	 * @param array<string, mixed>|null $stored   Answers per schema; a schema mapped to `throw` throws.
	 *
	 * @return array<string, mixed>
	 */
	private function stateFor(string $targetId, array $stored): array {
		$this->log = [];
		$objects = $this->createMock(OrObjectService::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = []) use ($stored): array {
				$this->log[] = ['findAll' => $config];
				$answer = ($stored[$config['filters']['schema']] ?? []);
				if ($answer === 'throw') {
					throw new RuntimeException('store down');
				}

				return $answer;
			}
		);
		$log = &$this->log;
		$logger = new class($log) extends AbstractLogger {
			/**
			 * @param list<mixed> $log The shared log.
			 */
			public function __construct(private array &$log) {
			}

			/**
			 * @param mixed                $level   Level.
			 * @param string|\Stringable   $message Message.
			 * @param array<string, mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->log[] = ['log' => [$level, (string)$message, $context]];
			}
		};

		$state = (new RecordOwnershipService($objects, $logger))->forObject($targetId);
		$fields = (fn (): array => get_object_vars($this))->call($state);

		return ['calls' => $this->log, 'state' => $fields, 'array' => $state->toArray()];
	}//end stateFor()

	/**
	 * The recorded reads.
	 *
	 * @return void
	 */
	public function testGoldenRecord(): void {
		$entity = static function (array $data): ObjectEntity {
			$object = new ObjectEntity();
			$object->setObject($data);
			return $object;
		};
		$contract = ['targetId' => 't-1', 'synchronizationId' => 's-1', 'originId' => 'o-9', 'sourceLastSeen' => '2026-01-02T03:04:05+00:00', 'absentAtSource' => 1, 'endedAt' => '2026-02-01'];
		$sync = static fn (mixed $mode): array => ['uuid' => 's-1', 'id' => 4, 'name' => 'Zaken', 'sourceId' => 'src-1', 'sourceConfig' => ['ownershipMode' => $mode]];
		$record = [
			'no contract' => $this->stateFor('t-1', []),
			'contract read throws' => $this->stateFor('t-1', ['synchronization_contract' => 'throw']),
			'contract of another target' => $this->stateFor('t-1', ['synchronization_contract' => ['results' => [['targetId' => 't-2']]]]),
			'source owned' => $this->stateFor('t-1', ['synchronization_contract' => ['results' => [$entity($contract)]], 'synchronization' => ['results' => [$sync(null)]]]),
			'mirror declared' => $this->stateFor('t-1', ['synchronization_contract' => [['targetId' => 't-1', 'synchronizationId' => 's-1']], 'synchronization' => [$sync(OwnershipState::MODES[1] ?? 'source')]]),
			'local declared' => $this->stateFor('t-1', ['synchronization_contract' => [$contract], 'synchronization' => [$sync(OwnershipState::MODE_LOCAL)]]),
			'unknown mode' => $this->stateFor('t-1', ['synchronization_contract' => [$contract], 'synchronization' => [$sync('owned-by-mars')]]),
			'unknown non-scalar mode' => $this->stateFor('t-1', ['synchronization_contract' => [$contract], 'synchronization' => [['uuid' => 's-1', 'sourceConfig' => ['ownershipMode' => ['x']]]]]),
			'empty mode' => $this->stateFor('t-1', ['synchronization_contract' => [$contract], 'synchronization' => [['uuid' => 's-1', 'sourceConfig' => ['ownershipMode' => '']]]]),
			'config not an array' => $this->stateFor('t-1', ['synchronization_contract' => [$contract], 'synchronization' => [['uuid' => 's-1', 'sourceConfig' => 'x', 'name' => '']]]),
			'no synchronization id' => $this->stateFor('t-1', ['synchronization_contract' => [['targetId' => 't-1', 'sourceLastSeen' => 0]]]),
			'synchronization missing' => $this->stateFor('t-1', ['synchronization_contract' => [$contract], 'synchronization' => [['uuid' => 'other']]]),
			'synchronization throws' => $this->stateFor('t-1', ['synchronization_contract' => [$contract], 'synchronization' => 'throw']),
			'non-array entries' => $this->stateFor('t-1', ['synchronization_contract' => ['results' => ['x', 3, ['targetId' => 't-1', 'synchronizationId' => 's-1']]], 'synchronization' => ['results' => [7, $sync(null)]]]),
		];

		$path = __DIR__ . '/../../../fixtures/ownership/record-ownership.json';
		$actual = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		if (getenv('GOLDEN_WRITE') === '1') {
			file_put_contents($path, $actual);
		}

		$this->assertSame(file_get_contents($path), $actual);
	}//end testGoldenRecord()
}//end class
