<?php

/**
 * Golden test pinning TablesSyncAdapter's row writes and row reads.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Tables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Tables;

use OCA\Integriq\Service\Tables\TablesClientInterface;
use OCA\Integriq\Service\Tables\TablesColumnCoercer;
use OCA\Integriq\Service\Tables\TablesSyncAdapter;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Pins the payload written (or the skip and its log line) for every mapping case, and the
 * rows read across pages.
 */
class TablesSyncAdapterWriteGoldenTest extends TestCase {

	/** @var array<int, array> Client calls: [method, ...arguments]. */
	private array $calls = [];

	/** @var array<int, array> Log lines: [level, message, context]. */
	private array $logs = [];

	/**
	 * An adapter over a recording client.
	 *
	 * @param array $pages The rows each listRows() page returns, in order.
	 *
	 * @return TablesSyncAdapter
	 */
	private function adapter(array $pages = []): TablesSyncAdapter {
		$this->calls = [];
		$this->logs = [];
		$client = $this->createMock(TablesClientInterface::class);
		$client->method('listColumns')->willReturn(
			[
				['id' => 7, 'title' => 'Amount', 'type' => 'number', 'subtype' => null, 'mandatory' => true, 'constraints' => ['numberDecimals' => 2]],
				['id' => 8, 'title' => 'Status', 'type' => 'selection', 'subtype' => 'selection', 'mandatory' => false, 'constraints' => ['selectionOptions' => ['open', 'paid']]],
				['id' => 9, 'title' => 'Twice', 'type' => 'text', 'subtype' => 'line', 'mandatory' => false, 'constraints' => []],
				['id' => 10, 'title' => 'Twice', 'type' => 'text', 'subtype' => 'line', 'mandatory' => false, 'constraints' => []],
				['id' => 11, 'title' => 'Note', 'type' => 'text', 'subtype' => 'line', 'mandatory' => false, 'constraints' => []],
			]
		);
		$client->method('createRow')->willReturnCallback(
			function (...$arguments): array {
				$this->calls[] = array_merge(['createRow'], array_slice($arguments, 1));
				return ['id' => 100];
			}
		);
		$client->method('updateRow')->willReturnCallback(
			function (...$arguments): array {
				$this->calls[] = array_merge(['updateRow'], array_slice($arguments, 1));
				return ['id' => 5];
			}
		);
		$client->method('listRows')->willReturnCallback(
			function (...$arguments) use (&$pages): array {
				$this->calls[] = array_merge(['listRows'], array_slice($arguments, 1));
				return (array_shift($pages) ?? []);
			}
		);
		$logs = &$this->logs;
		$logger = new class($logs) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array $logs The shared log line list.
			 */
			public function __construct(private array &$logs) {
			}

			/**
			 * Record one log line.
			 *
			 * @param mixed              $level   The level.
			 * @param string|\Stringable $message The message.
			 * @param array              $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->logs[] = [$level, (string)$message, $context];
			}
		};

		return new TablesSyncAdapter($client, $this->createMock(IAppManager::class), $logger, new TablesColumnCoercer());

	}//end adapter()

	/**
	 * Every write outcome.
	 *
	 * @return void
	 */
	public function testWrites(): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://nc.example.test'], 'source-uuid-1');
		$object = ['total' => '19.991', 'state' => 'paid', 'note' => 'n'];

		$adapter = $this->adapter();
		$this->assertSame(
			['id' => '100'],
			$adapter->writeRow($source, 42, '', $object, [['column' => 'Amount', 'value' => 'total'], ['column' => ''], ['value' => 'x'], ['column' => 5], ['column' => 'Note'], ['column' => 'Status', 'value' => 'state']])
		);
		$this->assertSame([['createRow', 42, ['7' => 19.99, '11' => '', '8' => 'paid']]], $this->calls);
		$this->assertSame([], $this->logs);

		$adapter = $this->adapter();
		$this->assertSame(['id' => '5'], $adapter->writeRow($source, 42, '5', $object, [['column' => 'Note', 'value' => 'note']]));
		$this->assertSame([['updateRow', 5, ['11' => 'n']]], $this->calls);

		$adapter = $this->adapter();
		$this->assertNull($adapter->writeRow($source, 42, null, $object, [['column' => 'Note', 'value' => 'note'], ['column' => 'Missing', 'value' => 'note']]));
		$this->assertSame([['warning', 'TablesSyncAdapter: column mapping title does not resolve to any column — skipping row write', ['column' => 'Missing', 'tableId' => 42]]], $this->logs);
		$this->assertSame([], $this->calls);

		$adapter = $this->adapter();
		$this->assertNull($adapter->writeRow($source, 42, null, $object, [['column' => 'Twice', 'value' => 'note']]));
		$this->assertSame([['error', 'TablesSyncAdapter: ambiguous column title in mapping — refusing to guess, skipping row write', ['column' => 'Twice', 'matchCount' => 2, 'tableId' => 42]]], $this->logs);

		$adapter = $this->adapter();
		$this->assertNull($adapter->writeRow($source, 42, null, $object, [['column' => 'Status', 'value' => 'note']]));
		$this->assertSame('warning', $this->logs[0][0]);
		$this->assertSame('TablesSyncAdapter: value coercion failed for column — skipping row write', $this->logs[0][1]);
		$this->assertSame(['column', 'tableId', 'reason'], array_keys($this->logs[0][2]));
		$this->assertSame(['Status', 42], [$this->logs[0][2]['column'], $this->logs[0][2]['tableId']]);

	}//end testWrites()

	/**
	 * Rows read across pages, stopping on an empty page, a short page or a repeated first page.
	 *
	 * @return void
	 */
	public function testReads(): void {
		$source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://nc.example.test'], 'source-uuid-1');
		$page = static fn (int $first, int $count): array => array_map(static fn (int $id): array => ['id' => $id, 'data' => ['7' => $id * 2]], range($first, ($first + $count - 1)));

		$adapter = $this->adapter([$page(1, 2), $page(3, 2), $page(5, 1)]);
		$this->assertSame(
			[['id' => '1', 7 => 2], ['id' => '2', 7 => 4], ['id' => '3', 7 => 6], ['id' => '4', 7 => 8], ['id' => '5', 7 => 10]],
			$adapter->fetchAllRows($source, 42, 3, 2)
		);
		$this->assertSame([['listRows', 42, 3, 1, 2], ['listRows', 42, 3, 2, 2], ['listRows', 42, 3, 3, 2]], $this->calls);

		$adapter = $this->adapter([$page(1, 2), $page(1, 2)]);
		$this->assertCount(2, $adapter->fetchAllRows($source, 42, null, 2));
		$this->assertSame([['warning', 'TablesSyncAdapter: rows endpoint did not appear to honour pagination; stopping', ['tableId' => 42, 'page' => 2]]], $this->logs);

		$adapter = $this->adapter([$page(1, 2), []]);
		$this->assertCount(2, $adapter->fetchAllRows($source, 42, null, 2));
		$this->assertCount(2, $this->calls);

	}//end testReads()
}//end class
