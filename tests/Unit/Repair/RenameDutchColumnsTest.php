<?php

/**
 * Tests for RenameDutchColumns: a column follows the name its schema declares.
 *
 * The database is an in-memory model of the three things the step reads
 * (registers, schema properties, information_schema) and the two statements
 * it writes (RENAME COLUMN, UPDATE ... SET). Statements are applied to the
 * model, so a second run sees what the first one left.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\RenameDutchColumns;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The direction of every move comes from the table's schema.
 */
class RenameDutchColumnsTest extends TestCase {

	/**
	 * The shard table of the ROD messages (register 7, schema 41).
	 *
	 * @var string
	 */
	private const ROD = 'oc_openregister_table_7_41';

	/**
	 * The shard table of the iWMO/iJW messages (register 7, schema 42).
	 *
	 * @var string
	 */
	private const IWMO = 'oc_openregister_table_7_42';

	/**
	 * Schema id => its stored `properties` JSON, or null when unreadable.
	 *
	 * @var array<int, string|null>
	 */
	private array $schemas = [];

	/**
	 * Table => list of rows (column => value). Every row holds every column.
	 *
	 * @var array<string, array{columns: list<string>, rows: list<array<string, mixed>>}>
	 */
	private array $tables = [];

	/**
	 * The statements the step executed.
	 *
	 * @var list<string>
	 */
	private array $statements = [];

	/**
	 * An empty model.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->schemas = [];
		$this->tables = [];
		$this->statements = [];

	}//end setUp()

	/**
	 * A wire name the schema declares is not renamed. Before the fix the step
	 * renamed rod_message.kenmerk to reference and every retour lost its key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rename-dutch-columns-follows-the-schema/specs/register-vocabulary/spec.md#scenario-a-wire-name-the-schema-declares-stays
	 */
	public function testAWireNameTheSchemaDeclaresStays(): void {
		$this->schemas[41] = '{"kenmerk":{"type":"string"},"berichtsoort":{"type":"string"}}';
		$this->tables[self::ROD] = ['columns' => ['id', 'kenmerk', 'berichtsoort'], 'rows' => [['id' => 1, 'kenmerk' => 'K-1', 'berichtsoort' => 'x']]];

		$this->runStep();

		$this->assertSame([], $this->statements);
		$this->assertSame(['id', 'kenmerk', 'berichtsoort'], $this->tables[self::ROD]['columns']);

	}//end testAWireNameTheSchemaDeclaresStays()

	/**
	 * A column the old step renamed comes back under the declared name, with its data.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rename-dutch-columns-follows-the-schema/specs/register-vocabulary/spec.md#scenario-a-column-the-old-step-renamed-is-restored
	 */
	public function testAColumnTheOldStepRenamedIsRestored(): void {
		$this->schemas[41] = '{"kenmerk":{"type":"string"}}';
		$this->tables[self::ROD] = ['columns' => ['id', 'reference'], 'rows' => [['id' => 1, 'reference' => 'K-1']]];

		$this->runStep();

		$this->assertSame(['id', 'kenmerk'], $this->tables[self::ROD]['columns']);
		$this->assertSame('K-1', $this->tables[self::ROD]['rows'][0]['kenmerk']);

	}//end testAColumnTheOldStepRenamedIsRestored()

	/**
	 * When MagicMapper re-added the declared column, it is back-filled and nothing is dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rename-dutch-columns-follows-the-schema/specs/register-vocabulary/spec.md#scenario-a-column-the-old-step-renamed-is-restored
	 */
	public function testAReAddedDeclaredColumnIsBackFilled(): void {
		$this->schemas[41] = '{"kenmerk":{"type":"string"}}';
		$this->tables[self::ROD] = [
			'columns' => ['id', 'reference', 'kenmerk'],
			'rows' => [
				['id' => 1, 'reference' => 'K-1', 'kenmerk' => null],
				['id' => 2, 'reference' => 'K-old', 'kenmerk' => 'K-2'],
			],
		];

		$this->runStep();

		$this->assertSame(['id', 'reference', 'kenmerk'], $this->tables[self::ROD]['columns'], 'no column is dropped');
		$this->assertSame('K-1', $this->tables[self::ROD]['rows'][0]['kenmerk']);
		$this->assertSame('K-2', $this->tables[self::ROD]['rows'][1]['kenmerk'], 'a stored value is never overwritten');

	}//end testAReAddedDeclaredColumnIsBackFilled()

	/**
	 * A schema that declares the English name still gets its Dutch column moved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rename-dutch-columns-follows-the-schema/specs/register-vocabulary/spec.md#scenario-a-schema-that-declares-the-english-name-still-moves
	 */
	public function testASchemaThatDeclaresTheEnglishNameStillMoves(): void {
		$this->schemas[42] = '{"reference":{"type":"string"}}';
		$this->tables[self::IWMO] = ['columns' => ['id', 'kenmerk'], 'rows' => [['id' => 1, 'kenmerk' => 'I-1']]];

		$this->runStep();

		$this->assertSame(['id', 'reference'], $this->tables[self::IWMO]['columns']);
		$this->assertSame('I-1', $this->tables[self::IWMO]['rows'][0]['reference']);

	}//end testASchemaThatDeclaresTheEnglishNameStillMoves()

	/**
	 * A second run changes nothing, in either direction.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rename-dutch-columns-follows-the-schema/specs/register-vocabulary/spec.md#scenario-a-column-the-old-step-renamed-is-restored
	 */
	public function testASecondRunChangesNothing(): void {
		$this->schemas[41] = '{"kenmerk":{"type":"string"}}';
		$this->schemas[42] = '{"reference":{"type":"string"}}';
		$this->tables[self::ROD] = ['columns' => ['id', 'reference'], 'rows' => [['id' => 1, 'reference' => 'K-1']]];
		$this->tables[self::IWMO] = ['columns' => ['id', 'kenmerk'], 'rows' => [['id' => 1, 'kenmerk' => 'I-1']]];

		$this->runStep();
		$this->assertCount(2, $this->statements);

		$this->statements = [];
		$this->runStep();

		$this->assertSame([], $this->statements);

	}//end testASecondRunChangesNothing()

	/**
	 * A table whose schema cannot be read is left as it is.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rename-dutch-columns-follows-the-schema/specs/register-vocabulary/spec.md#scenario-a-schema-that-cannot-be-read-leaves-the-table-alone
	 */
	public function testAnUnreadableSchemaLeavesTheTableAlone(): void {
		$this->schemas[42] = null;
		$this->tables[self::IWMO] = ['columns' => ['id', 'kenmerk'], 'rows' => [['id' => 1, 'kenmerk' => 'I-1']]];

		$this->runStep();

		$this->assertSame([], $this->statements);

	}//end testAnUnreadableSchemaLeavesTheTableAlone()

	/**
	 * Run the step over the model.
	 *
	 * @return void
	 */
	private function runStep(): void {
		(new RenameDutchColumns(db: $this->db(), logger: new NullLogger()))->run($this->createMock(IOutput::class));

	}//end runStep()

	/**
	 * The database model.
	 *
	 * @return IDBConnection
	 */
	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);

		$db->method('getDatabasePlatform')->willReturn(
			new class {
				/**
				 * Quote like PostgreSQL.
				 *
				 * @param string $identifier The identifier.
				 *
				 * @return string
				 */
				public function quoteSingleIdentifier(string $identifier): string {
					return '"' . $identifier . '"';
				}
			}
		);

		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []): IResult {
				if (str_contains($sql, 'openregister_registers') === true) {
					return $this->queryResult(rows: [], column: [7]);
				}

				$id = (int)($params[0] ?? 0);
				return $this->queryResult(rows: [], column: [], one: ($this->schemas[$id] ?? false));
			}
		);

		$db->method('prepare')->willReturnCallback(
			fn (string $sql): IPreparedStatement => $this->statement(sql: $sql)
		);

		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->statements[] = $sql;
				$this->apply(sql: $sql);
				return 1;
			}
		);

		return $db;

	}//end db()

	/**
	 * A prepared information_schema query.
	 *
	 * @param string $sql The query.
	 *
	 * @return IPreparedStatement
	 */
	private function statement(string $sql): IPreparedStatement {
		$bound = [];
		$rows = [];
		$statement = $this->createMock(IPreparedStatement::class);
		$statement->method('bindValue')->willReturnCallback(
			function (string $param, mixed $value) use (&$bound): bool {
				$bound[$param] = $value;
				return true;
			}
		);
		$statement->method('execute')->willReturnCallback(
			function () use ($sql, &$bound, &$rows): IResult {
				if (str_contains($sql, 'information_schema.tables') === true) {
					foreach (array_keys($this->tables) as $name) {
						$rows[] = ['table_name' => $name];
					}
				} else {
					foreach (($this->tables[(string)$bound['table']]['columns'] ?? []) as $column) {
						$rows[] = ['column_name' => $column];
					}
				}

				return $this->queryResult(rows: [], column: []);
			}
		);
		$statement->method('fetch')->willReturnCallback(
			function () use (&$rows): mixed {
				return (array_shift($rows) ?? false);
			}
		);

		return $statement;

	}//end statement()

	/**
	 * A query result.
	 *
	 * @param list<array<string, mixed>> $rows   Rows for fetch().
	 * @param list<mixed>                $column Values for fetchAll(FETCH_COLUMN).
	 * @param mixed                      $one    The value for fetchOne().
	 *
	 * @return IResult
	 */
	private function queryResult(array $rows, array $column, mixed $one = false): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($column);
		$result->method('fetchOne')->willReturn($one);
		$result->method('fetch')->willReturnCallback(
			function () use (&$rows): mixed {
				return (array_shift($rows) ?? false);
			}
		);

		return $result;

	}//end queryResult()

	/**
	 * Apply a RENAME COLUMN or an UPDATE ... SET to the model.
	 *
	 * @param string $sql The statement.
	 *
	 * @return void
	 */
	private function apply(string $sql): void {
		if (preg_match('/^ALTER TABLE "([^"]+)" RENAME COLUMN "([^"]+)" TO "([^"]+)"$/', $sql, $m) === 1) {
			[, $table, $from, $to] = $m;
			$this->tables[$table]['columns'] = array_map(static fn (string $c): string => ($c === $from ? $to : $c), $this->tables[$table]['columns']);
			foreach ($this->tables[$table]['rows'] as $i => $row) {
				$row[$to] = $row[$from];
				unset($row[$from]);
				$this->tables[$table]['rows'][$i] = $row;
			}

			return;
		}

		if (preg_match('/^UPDATE "([^"]+)" SET "([^"]+)" = "([^"]+)" WHERE "\2" IS NULL AND "\3" IS NOT NULL$/', $sql, $m) === 1) {
			[, $table, $to, $from] = $m;
			foreach ($this->tables[$table]['rows'] as $i => $row) {
				if ($row[$to] === null && $row[$from] !== null) {
					$this->tables[$table]['rows'][$i][$to] = $row[$from];
				}
			}

			return;
		}

		$this->fail('Unexpected statement: ' . $sql);

	}//end apply()
}//end class
