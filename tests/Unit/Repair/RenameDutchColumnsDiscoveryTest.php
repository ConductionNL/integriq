<?php

/**
 * Characterisation tests for RenameDutchColumns' table discovery and its
 * reading of a table's declared columns.
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which shard tables belong to the registers and which column names a
 * table's schema declares, including every fallback.
 */
class RenameDutchColumnsDiscoveryTest extends TestCase {

	/**
	 * Every query or statement run.
	 *
	 * @var list<string>
	 */
	private array $sql = [];

	/**
	 * Every warning logged.
	 *
	 * @var list<string>
	 */
	private array $warnings = [];

	/**
	 * Call a private method on a step over a fake database.
	 *
	 * @param string $method     The private method.
	 * @param array  $args       Its arguments.
	 * @param array  $registers  The register ids the database answers.
	 * @param array  $tables     The table names information_schema lists.
	 * @param mixed  $properties The schema properties JSON, or an Exception.
	 *
	 * @return mixed
	 */
	private function call(string $method, array $args, array $registers = [], array $tables = [], mixed $properties = null): mixed {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use ($registers, $properties): IResult {
				$this->sql[] = $sql . ' ' . json_encode($params);
				if ($properties instanceof Exception && str_contains($sql, 'openregister_schemas') === true) {
					throw $properties;
				}

				$result = $this->createMock(IResult::class);
				$result->method('fetchAll')->willReturn($registers);
				$result->method('fetchOne')->willReturn($properties);
				return $result;
			}
		);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use ($tables): IPreparedStatement {
				$this->sql[] = $sql;
				$rows = array_map(static fn (string $name): array => ['table_name' => $name], $tables);
				$statement = $this->createMock(IPreparedStatement::class);
				$statement->method('fetch')->willReturnCallback(static function () use (&$rows): mixed {
					return (array_shift($rows) ?? false);
				});
				return $statement;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		$reflection = new \ReflectionMethod(RenameDutchColumns::class, $method);
		$reflection->setAccessible(true);

		return $reflection->invokeArgs(new RenameDutchColumns(db: $db, logger: $logger), $args);

	}//end call()

	/**
	 * Only a table named for one of the registers and ending in a schema id
	 * is a shard table, once each, in listing order.
	 *
	 * @return void
	 */
	public function testShardTablesAreTheRegistersNumberedTables(): void {
		$tables = [
			'oc_openregister_table_12_3',
			'oc_openregister_table_7_41',
			'oc_openregister_table_7_x',
			'oc_openregister_table_77_1',
			'x_openregister_table_7_5_openregister_table_12_9',
			'oc_openregister_table_7_41',
			'oc_openregister_table_8_1',
		];

		$this->assertSame(
			['oc_openregister_table_12_3', 'oc_openregister_table_7_41', 'x_openregister_table_7_5_openregister_table_12_9'],
			$this->call('shardTables', [], registers: [7, '12'], tables: $tables)
		);

	}//end testShardTablesAreTheRegistersNumberedTables()

	/**
	 * Without registers the tables are never listed; without tables none.
	 *
	 * @return void
	 */
	public function testNoRegistersOrNoTablesMeansNoShards(): void {
		$this->assertSame([], $this->call('shardTables', [], registers: [], tables: ['oc_openregister_table_7_41']));
		$this->assertCount(1, $this->sql);
		$this->assertSame([], $this->call('shardTables', [], registers: [7], tables: []));

	}//end testNoRegistersOrNoTablesMeansNoShards()

	/**
	 * The declared properties become snake_case column names; a table name
	 * without a schema id, an unreadable or non-object schema declare none.
	 *
	 * @return void
	 */
	public function testDeclaredColumnsFollowTheSchema(): void {
		$this->assertSame(
			['zaak_identificatie', 'kenmerk', 'url_v2'],
			$this->call('declaredColumns', ['oc_openregister_table_7_41'], properties: '{"zaakIdentificatie":{},"kenmerk":{},"url-v2":{}}')
		);
		$this->assertSame(['SELECT properties FROM `*PREFIX*openregister_schemas` WHERE id = ? ["41"]'], $this->sql);

		$this->assertNull($this->call('declaredColumns', ['oc_some_table'], properties: '{}'));
		$this->assertNull($this->call('declaredColumns', ['oc_openregister_table_7_41'], properties: '"text"'));
		$this->assertNull($this->call('declaredColumns', ['oc_openregister_table_7_41'], properties: false));
		$this->assertSame([], $this->call('declaredColumns', ['oc_openregister_table_7_41'], properties: '[]'));
		$this->assertNull($this->call('declaredColumns', ['oc_openregister_table_7_41'], properties: new Exception('db down')));
		$this->assertSame(['RenameDutchColumns: could not read the schema of a table; skipping it.'], $this->warnings);

	}//end testDeclaredColumnsFollowTheSchema()

}//end class
