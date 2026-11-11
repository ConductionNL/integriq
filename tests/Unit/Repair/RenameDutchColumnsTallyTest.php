<?php

/**
 * Characterisation tests for what RenameDutchColumns writes and reports
 * across several shard tables.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\RenameDutchColumns;
use OCP\DB\Exception;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Pins the statements, in order, and the summary line: renames, restores,
 * back-fills, a refused statement left uncounted and an unreadable schema.
 */
class RenameDutchColumnsTallyTest extends TestCase {

	/**
	 * Table name => columns.
	 *
	 * @var array<string, list<string>>
	 */
	private array $tables = [
		'oc_openregister_table_7_41' => ['id', 'kenmerk', 'taal', 'language', 'nummer'],
		'oc_openregister_table_7_42' => ['id', 'reference', 'zaak_id'],
		'oc_openregister_table_7_43' => ['id', 'kanaal'],
	];

	/**
	 * Schema id => properties JSON (missing = unreadable).
	 *
	 * @var array<int, string>
	 */
	private array $schemas = [
		41 => '{"reference":{},"language":{},"number":{}}',
		42 => '{"kenmerk":{},"caseId":{}}',
	];

	/**
	 * Every statement run.
	 *
	 * @var list<string>
	 */
	private array $statements = [];

	/**
	 * Every info line.
	 *
	 * @var list<string>
	 */
	private array $info = [];

	/**
	 * A fake database over the tables and schemas; the rename of `nummer` fails.
	 *
	 * @return IDBConnection
	 */
	private function db(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(
			new class {
				/**
				 * Quote an identifier.
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
				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn([7]);
				$result->method('fetchOne')->willReturn($this->schemas[(int)($params[0] ?? 0)] ?? false);
				return $result;
			}
		);
		$db->method('prepare')->willReturnCallback(
			function (string $sql): IPreparedStatement {
				$bound = [];
				$rows = null;
				$statement = $this->createMock(IPreparedStatement::class);
				$statement->method('bindValue')->willReturnCallback(
					function (string $param, mixed $value) use (&$bound): bool {
						$bound[$param] = $value;
						return true;
					}
				);
				$statement->method('fetch')->willReturnCallback(
					function () use ($sql, &$bound, &$rows): mixed {
						if ($rows === null) {
							$rows = (str_contains($sql, 'information_schema.tables') === true)
								? array_map(static fn (string $t): array => ['table_name' => $t], array_keys($this->tables))
								: array_map(static fn (string $c): array => ['column_name' => $c], $this->tables[(string)$bound['table']] ?? []);
						}

						return (array_shift($rows) ?? false);
					}
				);
				return $statement;
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->statements[] = $sql;
				if (str_contains($sql, '"nummer"') === true) {
					throw new Exception('locked');
				}

				return 1;
			}
		);

		return $db;

	}//end db()

	/**
	 * Every move is written in map order per table, and the summary counts
	 * only the statements that ran.
	 *
	 * @return void
	 */
	public function testTheStatementsAndTheSummary(): void {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $line): void {
				$this->info[] = $line;
			}
		);

		(new RenameDutchColumns(db: $this->db(), logger: new NullLogger()))->run($output);

		$this->assertSame(
			[
				'ALTER TABLE "oc_openregister_table_7_41" RENAME COLUMN "kenmerk" TO "reference"',
				'ALTER TABLE "oc_openregister_table_7_41" RENAME COLUMN "nummer" TO "number"',
				'UPDATE "oc_openregister_table_7_41" SET "language" = "taal" WHERE "language" IS NULL AND "taal" IS NOT NULL',
				'ALTER TABLE "oc_openregister_table_7_42" RENAME COLUMN "reference" TO "kenmerk"',
				'ALTER TABLE "oc_openregister_table_7_42" RENAME COLUMN "zaak_id" TO "case_id"',
			],
			$this->statements
		);
		$this->assertSame(
			['RenameDutchColumns: 2 renamed, 1 restored to the declared Dutch name, 1 back-filled, 0 refused, 1 skipped (schema unreadable), across 3 shard table(s).'],
			$this->info
		);

	}//end testTheStatementsAndTheSummary()

}//end class
