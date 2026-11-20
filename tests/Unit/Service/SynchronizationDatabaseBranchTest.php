<?php

/**
 * The database branch of the synchronization engine.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-synchronization-reads-from-a-database-page-by-page-req-dbsf-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use Exception;
use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseSourceException;
use OCA\Integriq\Service\Adapter\DataInfra\DatabaseAdapter;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A synchronization with source type `database` reads its statement page by
 * page; one with target type `database` writes through its write statement.
 */
#[CoversClass(SynchronizationService::class)]
class SynchronizationDatabaseBranchTest extends TestCase {

	/** The organisation of both the synchronization and its source. */
	private const ORG = '0a0a0a0a-0000-4000-8000-00000000000a';

	/**
	 * The adapter double.
	 *
	 * @var DatabaseAdapter&MockObject
	 */
	private $adapter;

	private SynchronizationService $service;

	/**
	 * The stored database source.
	 *
	 * @return array<string,mixed>
	 */
	private static function source(): array {
		return ['type' => 'database', 'name' => 'Zaken', 'statements' => [['name' => 'zaken-sinds', 'sql' => 'SELECT 1']]];
	}

	/**
	 * Build the engine with the adapter in its container.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$entity = new ObjectEntity();
		$entity->setUuid('db-1');
		$entity->setObject(self::source());
		$entity->setOrganisation(self::ORG);

		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('find')->willReturn($entity);

		$this->adapter = $this->createMock(DatabaseAdapter::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === DatabaseAdapter::class) ? $this->adapter : null);

		$this->service = new SynchronizationService(
			callService: $this->createMock(CallService::class),
			mappingService: $this->createMock(MappingService::class),
			containerInterface: $container,
			orObjectService: $objects,
			objectService: $this->createMock(ObjectService::class),
			logger: $this->createMock(LoggerInterface::class),
			synchronizationLogService: $this->createMock(SynchronizationLogService::class),
			appConfig: $this->createMock(IAppConfig::class),
			approvalGate: $this->createMock(SynchronizationApprovalGate::class)
		);
	}

	/**
	 * A database synchronization on zaken-sinds.
	 *
	 * @param array<string,mixed> $sourceConfig The source config.
	 *
	 * @return array<string,mixed>
	 */
	private static function synchronization(array $sourceConfig = ['statement' => 'zaken-sinds', 'pageSize' => 500]): array {
		return [
			'id' => 'sync-1',
			'sourceType' => 'database',
			'sourceId' => 'db-1',
			'sourceConfig' => $sourceConfig,
			'targetType' => 'database',
			'targetId' => 'db-1',
			'targetConfig' => ['statement' => 'zaak-opslaan', 'idField' => 'zaaknummer'],
			'@self' => ['organisation' => self::ORG],
		];
	}

	/**
	 * The adapter answers a 1,200-row table in pages on the id.
	 *
	 * @return void
	 */
	private function tableOf(int $count): void {
		$this->adapter->method('read')->willReturnCallback(
			static function (array $source, string $statement, array $parameters, $after, ?int $limit) use ($count): array {
				$from = (int) ($after ?? 0);
				$rows = [];
				for ($id = ($from + 1); $id <= min($count, ($from + $limit)); $id++) {
					$rows[] = ['id' => $id, 'zaaknummer' => sprintf('Z-%04d', $id)];
				}

				$next = null;
				if (count($rows) === $limit) {
					$next = $rows[(count($rows) - 1)]['id'];
				}

				return ['rows' => $rows, 'next' => $next, 'pageSize' => $limit, 'durationMs' => 1];
			}
		);
	}

	/**
	 * A table of 1,200 rows arrives in three pages, every row handed on.
	 *
	 * @return void
	 */
	public function testATableOf1200RowsArrivesInThreePages(): void {
		$this->tableOf(count: 1200);
		$this->adapter->expects($this->exactly(3))->method('read')
			->with(self::source(), 'zaken-sinds', [], $this->anything(), 500);

		$objects = $this->service->getAllObjectsFromSource(synchronization: self::synchronization(), isTest: false, data: null, fetchInfo: $fetchInfo);

		self::assertCount(1200, $objects);
		self::assertSame(['id' => 1200, 'zaaknummer' => 'Z-1200'], $objects[1199]);
		self::assertSame(['complete' => true, 'pagesFetched' => 3, 'failureReason' => null], $fetchInfo);
	}//end testATableOf1200RowsArrivesInThreePages()

	/**
	 * A test run reads one page and hands on one row.
	 *
	 * @return void
	 */
	public function testATestRunReadsOnePage(): void {
		$this->tableOf(count: 1200);
		$this->adapter->expects($this->once())->method('read');

		$objects = $this->service->getAllObjectsFromSource(synchronization: self::synchronization(), isTest: true);

		self::assertSame([['id' => 1, 'zaaknummer' => 'Z-0001']], $objects);
	}//end testATestRunReadsOnePage()

	/**
	 * A failure after the first page returns what was read and marks the fetch incomplete.
	 *
	 * @return void
	 */
	public function testALaterFailureMarksTheFetchIncomplete(): void {
		$this->adapter->method('read')->willReturnOnConsecutiveCalls(
			['rows' => array_fill(0, 2, ['id' => 1]), 'next' => 2, 'pageSize' => 2, 'durationMs' => 1],
			$this->throwException(new DatabaseSourceException('The database refused the statement: canceling statement due to statement timeout'))
		);

		$objects = $this->service->getAllObjectsFromSource(synchronization: self::synchronization(['statement' => 'zaken-sinds', 'pageSize' => 2]), isTest: false, data: null, fetchInfo: $fetchInfo);

		self::assertCount(2, $objects);
		self::assertFalse($fetchInfo['complete']);
		self::assertSame(1, $fetchInfo['pagesFetched']);
		self::assertStringContainsString('statement timeout', (string) $fetchInfo['failureReason']);
	}//end testALaterFailureMarksTheFetchIncomplete()

	/**
	 * A failing first page fails the run; no statement named fails it too.
	 *
	 * @return void
	 */
	public function testAFailingFirstPageOrNoStatementFailsTheRun(): void {
		$this->adapter->method('read')->willThrowException(new DatabaseSourceException('The source declares no statement "zaken-sinds".'));

		try {
			$this->service->getAllObjectsFromSource(synchronization: self::synchronization());
			self::fail('a failing first page must fail the run');
		} catch (Exception $exception) {
			self::assertSame('The database source could not be read: The source declares no statement "zaken-sinds".', $exception->getMessage());
		}

		$this->expectExceptionMessage('A database synchronization needs sourceConfig.statement, the read statement to run.');
		$this->service->getAllObjectsFromSource(synchronization: self::synchronization([]));
	}//end testAFailingFirstPageOrNoStatementFailsTheRun()

	/**
	 * A key that does not advance stops the read instead of looping.
	 *
	 * @return void
	 */
	public function testAKeyThatDoesNotAdvanceStopsTheRead(): void {
		$this->adapter->expects($this->exactly(2))->method('read')->willReturn(['rows' => [['id' => 7]], 'next' => 7, 'pageSize' => 1, 'durationMs' => 1]);

		$objects = $this->service->getAllObjectsFromSource(synchronization: self::synchronization(), isTest: false, data: null, fetchInfo: $fetchInfo);

		self::assertCount(2, $objects);
		self::assertFalse($fetchInfo['complete']);
		self::assertSame('The page key did not advance; reading stopped.', $fetchInfo['failureReason']);
	}//end testAKeyThatDoesNotAdvanceStopsTheRead()

	/**
	 * A database target writes the mapped object through its write statement.
	 *
	 * @return void
	 */
	public function testADatabaseTargetWritesThroughItsStatement(): void {
		$object = ['zaaknummer' => 'Z-0042', 'omschrijving' => 'Bouwvergunning'];
		$this->adapter->expects($this->once())->method('write')
			->with(self::source(), 'zaak-opslaan', ['zaaknummer' => 'Z-0042', 'omschrijving' => 'Bouwvergunning', 'targetId' => null])
			->willReturn(['affected' => 1, 'durationMs' => 2]);

		$contract = $this->service->updateTarget(
			synchronizationContract: ['synchronizationId' => 'sync-1'],
			targetObject: $object,
			action: 'save',
			synchronization: self::synchronization()
		);

		self::assertSame('Z-0042', $contract['targetId']);
		self::assertSame(md5(serialize(['zaaknummer' => 'Z-0042', 'omschrijving' => 'Bouwvergunning'])), $contract['targetHash']);
	}//end testADatabaseTargetWritesThroughItsStatement()

	/**
	 * A delete without a delete statement clears the contract and runs nothing.
	 *
	 * @return void
	 */
	public function testADeleteWithoutADeleteStatementOnlyClearsTheContract(): void {
		$this->adapter->expects($this->never())->method('write');

		$object = [];
		$contract = $this->service->updateTarget(
			synchronizationContract: ['synchronizationId' => 'sync-1', 'targetId' => 'Z-0042', 'targetHash' => 'h'],
			targetObject: $object,
			action: 'delete',
			synchronization: self::synchronization()
		);

		self::assertNull($contract['targetId']);
		self::assertNull($contract['targetHash']);
	}//end testADeleteWithoutADeleteStatementOnlyClearsTheContract()
}//end class
