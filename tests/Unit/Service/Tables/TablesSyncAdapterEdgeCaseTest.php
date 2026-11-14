<?php

/**
 * Edge-case unit tests for TablesSyncAdapter: per-user feature detection,
 * the editor discovery helpers and the column cache key.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Tables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Tables;

use OCA\Integriq\Exception\TablesFeatureDisabledException;
use OCA\Integriq\Service\Tables\TablesClientInterface;
use OCA\Integriq\Service\Tables\TablesColumnCoercer;
use OCA\Integriq\Service\Tables\TablesSyncAdapter;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\IUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the adapter paths the main adapter test leaves out.
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
 */
#[CoversClass(TablesSyncAdapter::class)]
#[UsesClass(TablesFeatureDisabledException::class)]
class TablesSyncAdapterEdgeCaseTest extends TestCase {

	/**
	 * @var TablesClientInterface&MockObject
	 */
	private $client;

	/**
	 * @var IAppManager&MockObject
	 */
	private $appManager;

	/**
	 * @var TablesSyncAdapter
	 */
	private TablesSyncAdapter $adapter;

	/**
	 * One column of a table, as the client returns it.
	 *
	 * @var array
	 */
	private array $columns = [
		['id' => 7, 'title' => 'Amount', 'type' => 'number', 'subtype' => null, 'mandatory' => true, 'constraints' => []],
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
		$this->adapter = new TablesSyncAdapter(
			$this->client,
			$this->appManager,
			$this->createMock(LoggerInterface::class),
			new TablesColumnCoercer()
		);

	}//end setUp()

	/**
	 * A source entity with the given uuid and id.
	 *
	 * @param string $uuid The uuid ('' for none).
	 * @param int $id The database id.
	 *
	 * @return ObjectEntity
	 */
	private function source(string $uuid, int $id): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid($uuid);
		$source->setId($id);

		return $source;

	}//end source()

	/**
	 * With a user, isEnabled() asks the app manager about that user.
	 *
	 * @return void
	 */
	public function testIsEnabledForAGivenUserPassesTheUser(): void {
		$user = $this->createMock(IUser::class);
		$this->appManager->expects($this->once())->method('isEnabledForUser')
			->with('tables', $user)
			->willReturn(false);

		$this->assertFalse($this->adapter->isEnabled(user: $user));

	}//end testIsEnabledForAGivenUserPassesTheUser()

	/**
	 * assertEnabled() for a user refuses when Tables is disabled for that user.
	 *
	 * @return void
	 */
	public function testAssertEnabledForAUserRefusesWhenDisabledForThem(): void {
		$user = $this->createMock(IUser::class);
		$this->appManager->method('isEnabledForUser')->with('tables', $user)->willReturn(false);

		$this->expectException(TablesFeatureDisabledException::class);
		$this->adapter->assertEnabled(user: $user);

	}//end testAssertEnabledForAUserRefusesWhenDisabledForThem()

	/**
	 * assertEnabled() returns quietly when Tables is enabled.
	 *
	 * @return void
	 */
	public function testAssertEnabledPassesWhenEnabled(): void {
		$this->appManager->expects($this->once())->method('isEnabledForUser')->with('tables')->willReturn(true);

		$this->adapter->assertEnabled();

	}//end testAssertEnabledPassesWhenEnabled()

	/**
	 * The editor's table picker gets the client's table list unchanged.
	 *
	 * @return void
	 */
	public function testListTablesForEditorReturnsClientTables(): void {
		$source = $this->source('source-uuid-1', 1);
		$tables = [['id' => 42, 'title' => 'Vendor Invoices', 'ownerType' => 'user']];
		$this->client->expects($this->once())->method('listTables')->with($source)->willReturn($tables);

		$this->assertSame($tables, $this->adapter->listTablesForEditor(source: $source));

	}//end testListTablesForEditorReturnsClientTables()

	/**
	 * The editor's column list is fetched once per source and table.
	 *
	 * @return void
	 */
	public function testListColumnsForEditorIsCachedPerSourceAndTable(): void {
		$source = $this->source('source-uuid-1', 1);
		$this->client->expects($this->exactly(2))->method('listColumns')
			->willReturnCallback(
				fn (ObjectEntity $calledSource, int $tableId): array => ($tableId === 42 ? $this->columns : [])
			);

		$this->assertSame($this->columns, $this->adapter->listColumnsForEditor(source: $source, tableId: 42));
		$this->assertSame($this->columns, $this->adapter->listColumnsForEditor(source: $source, tableId: 42));
		$this->assertSame([], $this->adapter->listColumnsForEditor(source: $source, tableId: 43));

	}//end testListColumnsForEditorIsCachedPerSourceAndTable()

	/**
	 * Sources without a uuid are cached by database id, so they never share columns.
	 *
	 * @return void
	 */
	public function testSourcesWithoutUuidAreCachedById(): void {
		$first = $this->source('', 11);
		$second = $this->source('', 12);
		$this->client->expects($this->exactly(2))->method('listColumns')
			->willReturnCallback(
				fn (ObjectEntity $calledSource, int $tableId): array => [['id' => $calledSource->getId()]]
			);

		$this->assertSame([['id' => 11]], $this->adapter->listColumnsForEditor(source: $first, tableId: 42));
		$this->assertSame([['id' => 12]], $this->adapter->listColumnsForEditor(source: $second, tableId: 42));
		$this->assertSame([['id' => 11]], $this->adapter->listColumnsForEditor(source: $first, tableId: 42));

	}//end testSourcesWithoutUuidAreCachedById()
}//end class
