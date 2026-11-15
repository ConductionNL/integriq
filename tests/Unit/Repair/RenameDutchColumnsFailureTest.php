<?php

/**
 * Unit tests for RenameDutchColumns when the database refuses a step.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Repair
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

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\RenameDutchColumns;
use OCP\DB\Exception;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each read or write the step depends on can fail. A failure is logged as a
 * warning and leaves the data as it was: no registers or no table list means
 * nothing to do, an unreadable schema or column list skips that table, and a
 * failed statement is not counted.
 *
 * @covers \OCA\Integriq\Repair\RenameDutchColumns
 */
final class RenameDutchColumnsFailureTest extends TestCase {

	private const SHARD = 'oc_openregister_table_7_41';

	/**
	 * The database double.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The output double.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Every warning logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db     = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
		$this->logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);
		$this->db->method('getDatabasePlatform')->willReturn(
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
	}//end setUp()

	/**
	 * Run the step.
	 *
	 * @return void
	 */
	private function runStep(): void {
		(new RenameDutchColumns(db: $this->db, logger: $this->logger))->run($this->output);
	}//end runStep()

	/**
	 * A result answering these values.
	 *
	 * @param array<int, mixed> $column  The fetchAll column.
	 * @param mixed             $one     The fetchOne value.
	 *
	 * @return IResult
	 */
	private function queryResult(array $column = [], mixed $one = false): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($column);
		$result->method('fetchOne')->willReturn($one);
		return $result;
	}//end queryResult()

	/**
	 * A prepared statement answering these rows, or failing to execute.
	 *
	 * @param array<int, array<string, string>>|null $rows The rows, or null to fail.
	 *
	 * @return IPreparedStatement
	 */
	private function statement(?array $rows): IPreparedStatement {
		$statement = $this->createMock(IPreparedStatement::class);
		if ($rows === null) {
			$statement->method('execute')->willThrowException(new Exception('no information_schema'));
			return $statement;
		}

		$statement->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		return $statement;
	}//end statement()

	/**
	 * Answer the register query with register 7 and this schema for the shard.
	 *
	 * @param string|null $schema The schema properties JSON, or null to fail the read.
	 *
	 * @return void
	 */
	private function givenRegisterAndSchema(?string $schema): void {
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use ($schema): IResult {
				if (str_contains($sql, 'openregister_registers') === true) {
					$this->assertSame(['integriq%'], $params);
					return $this->queryResult(column: ['7']);
				}

				$this->assertSame(['41'], $params);
				if ($schema === null) {
					throw new Exception('schemas gone');
				}

				return $this->queryResult(one: $schema);
			}
		);
	}//end givenRegisterAndSchema()

	/**
	 * The step has a readable name.
	 *
	 * @return void
	 */
	public function testTheStepHasAName(): void {
		$this->assertSame(
			'Move openconnector data from the Dutch columns to the English ones',
			(new RenameDutchColumns(db: $this->db, logger: $this->logger))->getName()
		);
	}//end testTheStepHasAName()

	/**
	 * When the registers cannot be read, nothing is listed or changed.
	 *
	 * @return void
	 */
	public function testUnreadableRegistersMeanNothingToDo(): void {
		$this->db->method('executeQuery')->willThrowException(new Exception('registers gone'));
		$this->db->expects($this->never())->method('prepare');
		$this->db->expects($this->never())->method('executeStatement');
		$this->output->expects($this->once())->method('info')
			->with('RenameDutchColumns: no openconnector shard tables on this install; nothing to do.');

		$this->runStep();

		$this->assertSame([['RenameDutchColumns: could not resolve the registers; skipping.', ['exception' => 'registers gone']]], $this->warnings);
	}//end testUnreadableRegistersMeanNothingToDo()

	/**
	 * When the tables cannot be listed, nothing is changed.
	 *
	 * @return void
	 */
	public function testAnUnlistableSchemaMeansNothingToDo(): void {
		$this->givenRegisterAndSchema(schema: '{}');
		$this->db->method('prepare')->willReturn($this->statement(rows: null));
		$this->db->expects($this->never())->method('executeStatement');
		$this->output->expects($this->once())->method('info')
			->with('RenameDutchColumns: no openconnector shard tables on this install; nothing to do.');

		$this->runStep();

		$this->assertSame([['RenameDutchColumns: could not list tables; skipping.', ['exception' => 'no information_schema']]], $this->warnings);
	}//end testAnUnlistableSchemaMeansNothingToDo()

	/**
	 * Only shards of the app's registers are taken; an unreadable schema skips the table.
	 *
	 * @return void
	 */
	public function testOnlyShardsOfTheRegistersAreTakenAndAnUnreadableSchemaSkips(): void {
		$this->givenRegisterAndSchema(schema: null);
		$this->db->expects($this->once())->method('prepare')->willReturn(
			$this->statement(
				rows: [
					['table_name' => 'oc_openregister_table_8_41'],
					['table_name' => 'oc_openregister_table_7_41_old'],
					['table_name' => ''],
					['table_name' => self::SHARD],
					['table_name' => self::SHARD],
				]
			)
		);
		$this->db->expects($this->never())->method('executeStatement');
		$this->output->expects($this->once())->method('info')->with(
			'RenameDutchColumns: 0 renamed, 0 restored to the declared Dutch name, 0 back-filled, 0 refused, 1 skipped (schema unreadable), across 1 shard table(s).'
		);

		$this->runStep();

		$this->assertSame(
			[['RenameDutchColumns: could not read the schema of a table; skipping it.', ['table' => self::SHARD, 'exception' => 'schemas gone']]],
			$this->warnings
		);
	}//end testOnlyShardsOfTheRegistersAreTakenAndAnUnreadableSchemaSkips()

	/**
	 * A schema that is not a JSON object skips the table.
	 *
	 * @return void
	 */
	public function testASchemaThatIsNotAnObjectSkipsTheTable(): void {
		$this->givenRegisterAndSchema(schema: '"kenmerk"');
		$this->db->method('prepare')->willReturn($this->statement(rows: [['table_name' => self::SHARD]]));
		$this->db->expects($this->never())->method('executeStatement');
		$this->output->expects($this->once())->method('info')->with($this->stringContains('1 skipped (schema unreadable), across 1 shard table(s).'));

		$this->runStep();

		$this->assertSame([], $this->warnings);
	}//end testASchemaThatIsNotAnObjectSkipsTheTable()

	/**
	 * When a table's columns cannot be read, the table is left alone.
	 *
	 * @return void
	 */
	public function testUnreadableColumnsLeaveTheTableAlone(): void {
		$this->givenRegisterAndSchema(schema: '{"reference":{}}');
		$this->db->method('prepare')->willReturnOnConsecutiveCalls(
			$this->statement(rows: [['table_name' => self::SHARD]]),
			$this->statement(rows: null)
		);
		$this->db->expects($this->never())->method('executeStatement');
		$this->output->expects($this->once())->method('info')->with(
			'RenameDutchColumns: 0 renamed, 0 restored to the declared Dutch name, 0 back-filled, 0 refused, 0 skipped (schema unreadable), across 1 shard table(s).'
		);

		$this->runStep();

		$this->assertSame(
			[['RenameDutchColumns: could not read columns; skipping table.', ['table' => self::SHARD, 'exception' => 'no information_schema']]],
			$this->warnings
		);
	}//end testUnreadableColumnsLeaveTheTableAlone()

	/**
	 * A failed rename or back-fill is logged with its statement and not counted.
	 *
	 * @return void
	 */
	public function testAFailedStatementIsLoggedAndNotCounted(): void {
		$this->givenRegisterAndSchema(schema: '{"reference":{},"channel":{}}');
		$this->db->method('prepare')->willReturnOnConsecutiveCalls(
			$this->statement(rows: [['table_name' => self::SHARD]]),
			$this->statement(rows: [['column_name' => 'kenmerk'], ['column_name' => ''], ['column_name' => 'kanaal'], ['column_name' => 'channel']])
		);
		$this->db->expects($this->exactly(2))->method('executeStatement')->willThrowException(new Exception('locked'));
		$this->output->expects($this->once())->method('info')->with(
			'RenameDutchColumns: 0 renamed, 0 restored to the declared Dutch name, 0 back-filled, 0 refused, 0 skipped (schema unreadable), across 1 shard table(s).'
		);

		$this->runStep();

		$this->assertSame(
			[
				[
					'RenameDutchColumns: statement failed; leaving the column as it was.',
					['sql' => 'UPDATE "' . self::SHARD . '" SET "channel" = "kanaal" WHERE "channel" IS NULL AND "kanaal" IS NOT NULL', 'exception' => 'locked'],
				],
				[
					'RenameDutchColumns: statement failed; leaving the column as it was.',
					['sql' => 'ALTER TABLE "' . self::SHARD . '" RENAME COLUMN "kenmerk" TO "reference"', 'exception' => 'locked'],
				],
			],
			$this->warnings
		);
	}//end testAFailedStatementIsLoggedAndNotCounted()
}//end class
