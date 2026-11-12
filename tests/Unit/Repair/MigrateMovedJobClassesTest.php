<?php

/**
 * Unit tests for the MigrateMovedJobClasses repair step.
 *
 * The step rewrites the stored names of the job classes that moved into the
 * Service component: the `jobClass` of job objects and the `class` of queued
 * background jobs in `oc_jobs`.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec exclude Class-move plumbing; the step rewrites stored class-name
 *       strings and adds no domain behaviour.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\MigrateMovedJobClasses;
use OCA\Integriq\Service\Job\EventAction;
use OCA\Integriq\Service\Job\ExchangeJobAction;
use OCA\Integriq\Service\Job\FlowAction;
use OCA\Integriq\Service\Job\PingAction;
use OCA\Integriq\Service\Job\SynchronizationAction;
use OCA\Integriq\Service\Synchronization\FetchFilesJob;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Tests the moved-class rewrite on job objects and on queued background jobs.
 *
 * @covers \OCA\Integriq\Repair\MigrateMovedJobClasses
 * @uses   \OCA\Integriq\Repair\MigrateStoredJobClasses
 * @uses   \OCA\Integriq\Service\Job\MovedJobClasses
 * @uses   \OCA\Integriq\Service\SystemWrite
 */
class MigrateMovedJobClassesTest extends TestCase {
	/**
	 * Every old name with the name it must be rewritten to.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function movedNames(): array {
		return [
			'event action' => ['OCA\\Integriq\\Action\\EventAction', EventAction::class],
			'exchange job action' => ['OCA\\Integriq\\Action\\ExchangeJobAction', ExchangeJobAction::class],
			'flow action' => ['OCA\\Integriq\\Action\\FlowAction', FlowAction::class],
			'ping action' => ['OCA\\Integriq\\Action\\PingAction', PingAction::class],
			'synchronization action' => ['OCA\\Integriq\\Action\\SynchronizationAction', SynchronizationAction::class],
			'fetch files job' => ['OCA\\Integriq\\BackgroundJob\\FetchFilesJob', FetchFilesJob::class],
		];
	}//end movedNames()

	/**
	 * A job store whose saves are read back by the next findAll(), so a second
	 * run sees what the first one wrote.
	 *
	 * @param array<string, array<string, mixed>> $rows Job bodies by uuid, updated on save
	 *
	 * @return MockObject
	 */
	private function makeStore(array &$rows): MockObject {
		$store = ObjectServiceMockBuilder::make($this);
		$store->method('findAll')->willReturnCallback(
			function () use (&$rows): array {
				$results = [];
				foreach ($rows as $uuid => $body) {
					$results[] = ObjectServiceMockBuilder::objectEntity($this, $body, $uuid);
				}

				return ['results' => $results, 'total' => count($results)];
			}
		);
		$store->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null) use (&$rows) {
				$this->assertSame('integriq', $register);
				$this->assertSame('job', $schema);
				$this->assertArrayHasKey((string)$uuid, $rows, 'must update in place, never create a job');
				$rows[(string)$uuid] = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, (string)$uuid);
			}
		);

		return $store;
	}//end makeStore()

	/**
	 * A connection that records each update of `oc_jobs` as [table, set, where].
	 *
	 * @param array<int, array<string, mixed>> $updates Receives one entry per executed update
	 * @param Throwable|null $failure Thrown by every executeStatement() when set
	 *
	 * @return IDBConnection
	 */
	private function makeConnection(array &$updates, ?Throwable $failure = null): IDBConnection {
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('getQueryBuilder')->willReturnCallback(
			function () use (&$updates, $failure): IQueryBuilder {
				$update = ['table' => null, 'set' => [], 'where' => []];
				$query = $this->createMock(IQueryBuilder::class);
				$expr = $this->createMock(IExpressionBuilder::class);
				$expr->method('eq')->willReturnCallback(static fn ($column, $value): string => $column . ' = ' . $value);
				$query->method('expr')->willReturn($expr);
				$query->method('createNamedParameter')->willReturnCallback(static fn ($value): string => "'" . $value . "'");
				$query->method('update')->willReturnCallback(
					static function ($table) use (&$update, $query) {
						$update['table'] = $table;
						return $query;
					}
				);
				$query->method('set')->willReturnCallback(
					static function ($column, $value) use (&$update, $query) {
						$update['set'][$column] = $value;
						return $query;
					}
				);
				$query->method('where')->willReturnCallback(
					static function (...$predicates) use (&$update, $query) {
						$update['where'] = $predicates;
						return $query;
					}
				);
				$query->method('executeStatement')->willReturnCallback(
					static function () use (&$update, &$updates, $failure): int {
						if ($failure !== null) {
							throw $failure;
						}

						$updates[] = $update;
						return 1;
					}
				);
				return $query;
			}
		);

		return $connection;
	}//end makeConnection()

	/**
	 * Build the step over a job store and a connection.
	 *
	 * @param MockObject $store The OR object service double
	 * @param IDBConnection $connection The connection double
	 *
	 * @return MigrateMovedJobClasses
	 */
	private function makeStep(MockObject $store, IDBConnection $connection): MigrateMovedJobClasses {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($store) {
				if ($id === OrObjectService::class) {
					return $store;
				}

				throw new RuntimeException('unexpected service: ' . $id);
			}
		);

		return new MigrateMovedJobClasses($container, new NullLogger(), $connection);
	}//end makeStep()

	/**
	 * An old name stored on a job object becomes the current name, on the same
	 * object, with its other properties kept.
	 *
	 * @param string $oldName The stored name
	 * @param string $newName The expected name
	 *
	 * @return void
	 *
	 * @dataProvider movedNames
	 */
	public function testRewritesTheOldNameOnAJobObject(string $oldName, string $newName): void {
		$rows = ['job-1' => ['name' => 'Nightly', 'jobClass' => $oldName, 'arguments' => ['x' => 1]]];
		$updates = [];

		$this->makeStep($this->makeStore($rows), $this->makeConnection($updates))->run($this->createMock(IOutput::class));

		$this->assertSame(['name' => 'Nightly', 'jobClass' => $newName, 'arguments' => ['x' => 1]], $rows['job-1']);
	}//end testRewritesTheOldNameOnAJobObject()

	/**
	 * A second run over what the first run wrote changes nothing.
	 *
	 * @return void
	 */
	public function testASecondRunIsANoOp(): void {
		$rows = [];
		foreach (self::movedNames() as $label => [$oldName]) {
			$rows[$label] = ['jobClass' => $oldName];
		}

		$updates = [];
		$this->makeStep($this->makeStore($rows), $this->makeConnection($updates))->run($this->createMock(IOutput::class));
		$afterFirst = $rows;
		$this->assertSame(
			array_map(static fn (array $pair): array => ['jobClass' => $pair[1]], self::movedNames()),
			$afterFirst
		);

		$store = $this->makeStore($rows);
		$store->expects($this->never())->method('saveObject');
		$this->makeStep($store, $this->makeConnection($updates))->run($this->createMock(IOutput::class));
		$this->assertSame($afterFirst, $rows);
	}//end testASecondRunIsANoOp()

	/**
	 * Values that are not an old name of a moved class are left as they are:
	 * current names, a third-party class, the openconnector namespace (the
	 * previous step's job), a retired class, an empty or absent value.
	 *
	 * @return void
	 */
	public function testUnknownValuesAreLeftAlone(): void {
		$rows = [
			'current' => ['jobClass' => SynchronizationAction::class],
			'third-party' => ['jobClass' => 'OCA\\SomeOtherApp\\Action\\SynchronizationAction'],
			'openconnector' => ['jobClass' => 'OCA\\OpenConnector\\Action\\SynchronizationAction'],
			'retired' => ['jobClass' => 'OCA\\Integriq\\Cron\\FetchFilesJob'],
			'prefix' => ['jobClass' => 'OCA\\Integriq\\Action\\SynchronizationActionV2'],
			'empty' => ['jobClass' => ''],
			'absent' => ['name' => 'no class'],
		];
		$before = $rows;
		$updates = [];

		$store = $this->makeStore($rows);
		$store->expects($this->never())->method('saveObject');
		$this->makeStep($store, $this->makeConnection($updates))->run($this->createMock(IOutput::class));

		$this->assertSame($before, $rows);
	}//end testUnknownValuesAreLeftAlone()

	/**
	 * Queued background jobs are renamed in `oc_jobs`, one exact-match update
	 * per moved class, and nothing else is touched there.
	 *
	 * @return void
	 */
	public function testRenamesQueuedBackgroundJobs(): void {
		$rows = [];
		$updates = [];

		$this->makeStep($this->makeStore($rows), $this->makeConnection($updates))->run($this->createMock(IOutput::class));

		$expected = [];
		foreach (self::movedNames() as [$oldName, $newName]) {
			$expected[] = [
				'table' => 'jobs',
				'set' => ['class' => "'" . $newName . "'"],
				'where' => ["class = '" . $oldName . "'"],
			];
		}

		$this->assertSame($expected, $updates);
	}//end testRenamesQueuedBackgroundJobs()

	/**
	 * A failing `oc_jobs` update is logged, never thrown: the step also runs
	 * under `<install>`, and the job objects are still rewritten.
	 *
	 * @return void
	 */
	public function testAFailingQueueUpdateDoesNotThrow(): void {
		$rows = ['job-1' => ['jobClass' => 'OCA\\Integriq\\Action\\PingAction']];
		$updates = [];

		$this->makeStep($this->makeStore($rows), $this->makeConnection($updates, new RuntimeException('db down')))
			->run($this->createMock(IOutput::class));

		$this->assertSame([], $updates);
		$this->assertSame(PingAction::class, $rows['job-1']['jobClass']);
	}//end testAFailingQueueUpdateDoesNotThrow()
}//end class
