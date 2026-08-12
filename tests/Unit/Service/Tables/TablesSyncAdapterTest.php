<?php

/**
 * Unit tests for TablesSyncAdapter.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Tables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/tables-bridge/tasks.md#task-11-unit-tests--coercion-contract-mapping-adapter-stubbed-client
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Tables;

use OCA\OpenConnector\Exception\TablesFeatureDisabledException;
use OCA\OpenConnector\Exception\TablesNotFoundException;
use OCA\OpenConnector\Service\Tables\TablesClientInterface;
use OCA\OpenConnector\Service\Tables\TablesColumnCoercer;
use OCA\OpenConnector\Service\Tables\TablesSyncAdapter;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the column-cache, mapping-resolution, coercion, pagination, and
 * feature-detection layer, exercised against a stubbed TablesClientInterface
 * (no real Tables app required — proposal.md Risk 3).
 *
 * @spec openspec/changes/tables-bridge/specs/tables-bridge/spec.md
 */
class TablesSyncAdapterTest extends TestCase {

	/**
	 * @var TablesClientInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $client;

	/**
	 * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $appManager;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * @var TablesSyncAdapter
	 */
	private TablesSyncAdapter $adapter;

	/**
	 * @var ObjectEntity
	 */
	private ObjectEntity $source;

	/**
	 * The Amount(id 7,number,2 decimals)/Status(id 8,selection) column fixture
	 * used by REQ-001/REQ-003 scenarios.
	 *
	 * @var array
	 */
	private array $columns = [
		[
			'id' => 7,
			'title' => 'Amount',
			'type' => 'number',
			'subtype' => null,
			'mandatory' => true,
			'constraints' => ['numberDecimals' => 2],
		],
		[
			'id' => 8,
			'title' => 'Status',
			'type' => 'selection',
			'subtype' => 'selection',
			'mandatory' => false,
			'constraints' => ['selectionOptions' => ['open', 'paid', 'overdue']],
		],
	];

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->client = $this->createMock(TablesClientInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->adapter = new TablesSyncAdapter($this->client, $this->appManager, $this->logger, new TablesColumnCoercer());
		$this->source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://nc.example.test'], 'source-uuid-1');

	}//end setUp()

	/**
	 * isEnabled() reflects IAppManager::isEnabledForUser() only (REQ-004).
	 *
	 * @return void
	 */
	public function testIsEnabledReflectsAppManager(): void {
		$this->appManager->method('isEnabledForUser')->with('tables')->willReturn(true);

		$this->assertTrue($this->adapter->isEnabled());

	}//end testIsEnabledReflectsAppManager()

	/**
	 * assertEnabled() throws when Tables is disabled — the abort signal for
	 * both the discovery endpoints (409) and a configured run (REQ-004).
	 *
	 * @return void
	 */
	public function testAssertEnabledThrowsWhenDisabled(): void {
		$this->appManager->method('isEnabledForUser')->willReturn(false);

		$this->expectException(TablesFeatureDisabledException::class);

		$this->adapter->assertEnabled();

	}//end testAssertEnabledThrowsWhenDisabled()

	/**
	 * fetchAllRows() flattens each row to `['id' => ..., ...data]` and stops
	 * once a page returns fewer rows than requested (REQ-002).
	 *
	 * @return void
	 */
	public function testFetchAllRowsFlattensAndStopsOnShortPage(): void {
		$this->client->method('listRows')->willReturnCallback(
			function ($source, $tableId, $viewId, $page, $pageSize) {
				if ($page === 1) {
					return [
						['id' => 1, 'tableId' => 42, 'data' => ['7' => '10']],
						['id' => 2, 'tableId' => 42, 'data' => ['7' => '20']],
					];
				}

				return [];
			}
		);

		$rows = $this->adapter->fetchAllRows(source: $this->source, tableId: 42, viewId: null, pageSize: 2);

		$this->assertCount(2, $rows);
		$this->assertSame(['id' => '1', '7' => '10'], $rows[0]);
		$this->assertSame(['id' => '2', '7' => '20'], $rows[1]);

	}//end testFetchAllRowsFlattensAndStopsOnShortPage()

	/**
	 * fetchAllRows() stops (does not loop forever) when the upstream ignores
	 * pagination and keeps returning the same first row.
	 *
	 * @return void
	 */
	public function testFetchAllRowsStopsOnNonPaginatingUpstream(): void {
		$this->client->method('listRows')->willReturn(
			[
				['id' => 1, 'tableId' => 42, 'data' => ['7' => '10']],
				['id' => 2, 'tableId' => 42, 'data' => ['7' => '20']],
			]
		);

		$rows = $this->adapter->fetchAllRows(source: $this->source, tableId: 42, viewId: null, pageSize: 2);

		// Two pages max: page 1 returns the batch, page 2 repeats the same
		// first row id and the loop bails instead of looping forever.
		$this->assertCount(2, $rows);

	}//end testFetchAllRowsStopsOnNonPaginatingUpstream()

	/**
	 * writeRow() resolves a title-keyed mapping to the numeric columnId and
	 * coerces per the column's declared type (REQ-001 scenario, REQ-003).
	 *
	 * @return void
	 */
	public function testWriteRowResolvesTitleToColumnIdAndCoerces(): void {
		$this->client->method('listColumns')->willReturn($this->columns);
		$this->client->expects($this->once())->method('createRow')
			->with($this->source, 42, ['7' => 19.99])
			->willReturn(['id' => 100, 'tableId' => 42, 'data' => ['7' => 19.99]]);

		$result = $this->adapter->writeRow(
			target: $this->source,
			tableId: 42,
			existingRowId: null,
			mappedObject: ['invoice' => ['total' => '19.99']],
			columnMapping: [['column' => 'Amount', 'value' => 'invoice.total']]
		);

		$this->assertSame(['id' => '100'], $result);

	}//end testWriteRowResolvesTitleToColumnIdAndCoerces()

	/**
	 * An existing targetId routes the write through updateRow(), not createRow().
	 *
	 * @return void
	 */
	public function testWriteRowWithExistingIdCallsUpdateRow(): void {
		$this->client->method('listColumns')->willReturn($this->columns);
		$this->client->expects($this->never())->method('createRow');
		$this->client->expects($this->once())->method('updateRow')
			->with($this->source, 100, ['7' => 25.0])
			->willReturn(['id' => 100, 'tableId' => 42, 'data' => ['7' => 25.0]]);

		$result = $this->adapter->writeRow(
			target: $this->source,
			tableId: 42,
			existingRowId: '100',
			mappedObject: ['invoice' => ['total' => 25]],
			columnMapping: [['column' => 'Amount', 'value' => 'invoice.total']]
		);

		$this->assertSame(['id' => '100'], $result);

	}//end testWriteRowWithExistingIdCallsUpdateRow()

	/**
	 * An ambiguous column title (two columns share it) fails that row's write
	 * — never a first-match guess (REQ-001 scenario).
	 *
	 * @return void
	 */
	public function testWriteRowAmbiguousTitleSkipsRowNeverGuesses(): void {
		$duplicateTitleColumns = [
			['id' => 8, 'title' => 'Status', 'type' => 'text', 'subtype' => null, 'mandatory' => false, 'constraints' => []],
			['id' => 9, 'title' => 'Status', 'type' => 'text', 'subtype' => null, 'mandatory' => false, 'constraints' => []],
		];
		$this->client->method('listColumns')->willReturn($duplicateTitleColumns);
		$this->client->expects($this->never())->method('createRow');
		$this->client->expects($this->never())->method('updateRow');

		$result = $this->adapter->writeRow(
			target: $this->source,
			tableId: 42,
			existingRowId: null,
			mappedObject: ['status' => 'open'],
			columnMapping: [['column' => 'Status', 'value' => 'status']]
		);

		$this->assertNull($result);

	}//end testWriteRowAmbiguousTitleSkipsRowNeverGuesses()

	/**
	 * A non-numeric value against a `number` column fails only that row —
	 * signalled by a null return, never an exception (REQ-003).
	 *
	 * @return void
	 */
	public function testWriteRowNonNumericValueSkipsRow(): void {
		$this->client->method('listColumns')->willReturn($this->columns);
		$this->client->expects($this->never())->method('createRow');

		$result = $this->adapter->writeRow(
			target: $this->source,
			tableId: 42,
			existingRowId: null,
			mappedObject: ['invoice' => ['total' => 'not-a-number']],
			columnMapping: [['column' => 'Amount', 'value' => 'invoice.total']]
		);

		$this->assertNull($result);

	}//end testWriteRowNonNumericValueSkipsRow()

	/**
	 * A selection value with no matching option fails only that row (REQ-003).
	 *
	 * @return void
	 */
	public function testWriteRowUnmatchedSelectionSkipsRow(): void {
		$this->client->method('listColumns')->willReturn($this->columns);
		$this->client->expects($this->never())->method('createRow');

		$result = $this->adapter->writeRow(
			target: $this->source,
			tableId: 42,
			existingRowId: null,
			mappedObject: ['status' => 'cancelled'],
			columnMapping: [['column' => 'Status', 'value' => 'status']]
		);

		$this->assertNull($result);

	}//end testWriteRowUnmatchedSelectionSkipsRow()

	/**
	 * A well-formed selection value matching an option is written unchanged.
	 *
	 * @return void
	 */
	public function testWriteRowMatchedSelectionWrites(): void {
		$this->client->method('listColumns')->willReturn($this->columns);
		$this->client->expects($this->once())->method('createRow')
			->with($this->source, 42, ['8' => 'paid'])
			->willReturn(['id' => 101, 'tableId' => 42, 'data' => ['8' => 'paid']]);

		$result = $this->adapter->writeRow(
			target: $this->source,
			tableId: 42,
			existingRowId: null,
			mappedObject: ['status' => 'paid'],
			columnMapping: [['column' => 'Status', 'value' => 'status']]
		);

		$this->assertSame(['id' => '101'], $result);

	}//end testWriteRowMatchedSelectionWrites()

	/**
	 * Column metadata is fetched at most once per adapter instance (one run),
	 * even across multiple writeRow() calls against the same table (Decision 5).
	 *
	 * @return void
	 */
	public function testColumnsAreCachedAcrossMultipleWrites(): void {
		$this->client->expects($this->once())->method('listColumns')->willReturn($this->columns);
		$this->client->method('createRow')->willReturn(['id' => 1, 'tableId' => 42, 'data' => []]);

		$this->adapter->writeRow(
			target: $this->source,
			tableId: 42,
			existingRowId: null,
			mappedObject: ['invoice' => ['total' => 1]],
			columnMapping: [['column' => 'Amount', 'value' => 'invoice.total']]
		);
		$this->adapter->writeRow(
			target: $this->source,
			tableId: 42,
			existingRowId: null,
			mappedObject: ['invoice' => ['total' => 2]],
			columnMapping: [['column' => 'Amount', 'value' => 'invoice.total']]
		);

	}//end testColumnsAreCachedAcrossMultipleWrites()

	/**
	 * deleteRow() tolerates an upstream 404 (row already gone) as a no-op.
	 *
	 * @return void
	 */
	public function testDeleteRowTolerates404(): void {
		$this->client->method('deleteRow')->willThrowException(new TablesNotFoundException('gone'));

		$result = $this->adapter->deleteRow(target: $this->source, rowId: '999');

		$this->assertFalse($result);

	}//end testDeleteRowTolerates404()

	/**
	 * deleteRow() returns true and calls through on a normal delete.
	 *
	 * @return void
	 */
	public function testDeleteRowReturnsTrueOnSuccess(): void {
		$this->client->expects($this->once())->method('deleteRow')->with($this->source, 42);

		$result = $this->adapter->deleteRow(target: $this->source, rowId: '42');

		$this->assertTrue($result);

	}//end testDeleteRowReturnsTrueOnSuccess()
}//end class
