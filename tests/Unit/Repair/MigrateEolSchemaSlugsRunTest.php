<?php

/**
 * MigrateEolSchemaSlugs::run() end to end over a scripted database: what it
 * renames, what it refuses, which stored synchronization references it
 * re-points, and that a failing statement is logged without aborting the
 * repair.
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

use OCA\Integriq\Repair\MigrateEolSchemaSlugs;
use OCP\DB\Exception;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Integriq\Repair\MigrateEolSchemaSlugs
 */
class MigrateEolSchemaSlugsRunTest extends TestCase {

	/**
	 * Every statement executed, as [sql, params].
	 *
	 * @var list<array{0:string,1:array}>
	 */
	private array $statements = [];

	/**
	 * Every warning, as [message, context].
	 *
	 * @var list<array{0:string,1:array}>
	 */
	private array $warnings = [];

	/**
	 * Every info line written to the output.
	 *
	 * @var list<string>
	 */
	private array $info = [];

	/**
	 * Run the step over a database that holds $slugs and, unless null, a
	 * synchronization register/schema id pair.
	 *
	 * @param array<int, mixed>          $slugs      The slug rows' values, or null to fail the read.
	 * @param array<string, mixed>|false $syncIds    The synchronization id row, false for none.
	 * @param callable|null              $onExecute  Decides a statement's answer: rows affected or throw.
	 *
	 * @return void
	 */
	private function runStep(?array $slugs, array|false $syncIds, ?callable $onExecute = null): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql) use ($slugs, $syncIds): IResult {
				$result = $this->createMock(IResult::class);
				if (str_contains($sql, 'SELECT slug FROM') === true) {
					if ($slugs === null) {
						throw new Exception('schemas unreadable');
					}

					$result->method('fetchAll')->willReturn(array_map(static fn ($slug): array => ['slug' => $slug], $slugs));
					return $result;
				}

				$result->method('fetch')->willReturn($syncIds);
				return $result;
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params = []) use ($onExecute): int {
				$this->statements[] = [$sql, $params];

				return ($onExecute === null) ? 1 : $onExecute($sql, $params);
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->info[] = $message;
			}
		);

		(new MigrateEolSchemaSlugs($db, $logger))->run($output);

	}//end runStep()

	/**
	 * The SQL of every statement executed.
	 *
	 * @return list<string>
	 */
	private function statementSql(): array {
		return array_column($this->statements, 0);

	}//end statementSql()

	/**
	 * An install with both old slugs renames both, scoped to this app, then
	 * re-points both source and target references of both slugs.
	 *
	 * @return void
	 */
	public function testAnOldInstallIsRenamedAndItsReferencesRepointed(): void {
		$this->runStep(['eolProduct', 'eolCycle'], ['register_id' => 3, 'schema_id' => 17]);

		$scope = ['integriq', 'integriq.%'];
		$this->assertSame(
			[
				[
					'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE slug = ? AND (application = ? OR application LIKE ?)',
					['eol_product', 'eolProduct', ...$scope],
				],
				[
					'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE slug = ? AND (application = ? OR application LIKE ?)',
					['eol_cycle', 'eolCycle', ...$scope],
				],
				['UPDATE `*PREFIX*openregister_table_3_17` SET source_id = ? WHERE source_id = ?', ['integriq/eol_product', 'integriq/eolProduct']],
				['UPDATE `*PREFIX*openregister_table_3_17` SET target_id = ? WHERE target_id = ?', ['integriq/eol_product', 'integriq/eolProduct']],
				['UPDATE `*PREFIX*openregister_table_3_17` SET source_id = ? WHERE source_id = ?', ['integriq/eol_cycle', 'integriq/eolCycle']],
				['UPDATE `*PREFIX*openregister_table_3_17` SET target_id = ? WHERE target_id = ?', ['integriq/eol_cycle', 'integriq/eolCycle']],
			],
			$this->statements
		);
		$this->assertSame([], $this->warnings);
		$this->assertSame(
			['MigrateEolSchemaSlugs: 2 schema slug(s) renamed, 0 refused, 4 stored reference(s) re-pointed.'],
			$this->info
		);

	}//end testAnOldInstallIsRenamedAndItsReferencesRepointed()

	/**
	 * A pair where both slugs exist is refused and logged; the other pair is
	 * still renamed.
	 *
	 * @return void
	 */
	public function testACollidingPairIsRefusedAndTheOtherStillRenamed(): void {
		$this->runStep(['eolProduct', 'eol_product', 'eolCycle'], false);

		$this->assertSame(
			[['UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE slug = ? AND (application = ? OR application LIKE ?)', ['eol_cycle', 'eolCycle', 'integriq', 'integriq.%']]],
			$this->statements
		);
		$this->assertSame(
			[
				[
					"MigrateEolSchemaSlugs: both 'eolProduct' and 'eol_product' exist, so the lower id would silently win every lookup; renaming neither.",
					['old' => 'eolProduct'],
				],
			],
			$this->warnings
		);
		$this->assertSame(
			['MigrateEolSchemaSlugs: 1 schema slug(s) renamed, 1 refused, 0 stored reference(s) re-pointed.'],
			$this->info
		);

	}//end testACollidingPairIsRefusedAndTheOtherStillRenamed()

	/**
	 * A migrated install renames nothing; without a synchronization table no
	 * reference is touched either.
	 *
	 * @return void
	 */
	public function testAMigratedInstallWithoutSynchronizationsChangesNothing(): void {
		$this->runStep(['eol_product', 'eol_cycle'], false);

		$this->assertSame([], $this->statements);
		$this->assertSame(['MigrateEolSchemaSlugs: 0 schema slug(s) renamed, 0 refused, 0 stored reference(s) re-pointed.'], $this->info);

	}//end testAMigratedInstallWithoutSynchronizationsChangesNothing()

	/**
	 * A synchronization id row with a missing id resolves no table.
	 *
	 * @return void
	 */
	public function testAnIncompleteSynchronizationIdRowResolvesNoTable(): void {
		$this->runStep([], ['register_id' => 3, 'schema_id' => 0]);

		$this->assertSame([], $this->statements);

	}//end testAnIncompleteSynchronizationIdRowResolvesNoTable()

	/**
	 * Re-pointing reports the rows actually changed, not the statements run.
	 *
	 * @return void
	 */
	public function testTheRepointCountIsTheRowsChanged(): void {
		$this->runStep(
			[],
			['register_id' => 1, 'schema_id' => 2],
			static fn (string $sql, array $params): int => ($params === ['integriq/eol_cycle', 'integriq/eolCycle'] && str_contains($sql, 'target_id') === true) ? 5 : 0
		);

		$this->assertCount(4, $this->statements);
		$this->assertSame(['MigrateEolSchemaSlugs: 0 schema slug(s) renamed, 0 refused, 5 stored reference(s) re-pointed.'], $this->info);

	}//end testTheRepointCountIsTheRowsChanged()

	/**
	 * A failed rename is logged and not counted; a failed re-point is logged
	 * and the remaining columns are still tried.
	 *
	 * @return void
	 */
	public function testFailingStatementsAreLoggedAndTheRunCarriesOn(): void {
		$this->runStep(
			['eolProduct', 'eolCycle'],
			['register_id' => 3, 'schema_id' => 17],
			static function (string $sql, array $params): int {
				if ($params[1] === 'eolProduct' || ($params === ['integriq/eol_product', 'integriq/eolProduct'] && str_contains($sql, 'SET source_id') === true)) {
					throw new Exception('locked');
				}

				return 1;
			}
		);

		$this->assertCount(6, $this->statements);
		$this->assertSame(
			[
				[
					'MigrateEolSchemaSlugs: schema slug rename failed.',
					['old' => 'eolProduct', 'new' => 'eol_product', 'exception' => 'locked'],
				],
				[
					'MigrateEolSchemaSlugs: could not re-point a stored reference.',
					['table' => 'openregister_table_3_17', 'column' => 'source_id', 'old' => 'integriq/eolProduct', 'exception' => 'locked'],
				],
			],
			$this->warnings
		);
		$this->assertSame(['MigrateEolSchemaSlugs: 1 schema slug(s) renamed, 0 refused, 3 stored reference(s) re-pointed.'], $this->info);

	}//end testFailingStatementsAreLoggedAndTheRunCarriesOn()

	/**
	 * Unreadable schemas plan no rename and say so; the references are still
	 * checked.
	 *
	 * @return void
	 */
	public function testUnreadableSchemasRenameNothing(): void {
		$this->runStep(null, false);

		$this->assertSame([], $this->statements);
		$this->assertSame(
			[['MigrateEolSchemaSlugs: could not read schema slugs; skipping.', ['exception' => 'schemas unreadable']]],
			$this->warnings
		);
		$this->assertSame(['MigrateEolSchemaSlugs: 0 schema slug(s) renamed, 0 refused, 0 stored reference(s) re-pointed.'], $this->info);

	}//end testUnreadableSchemasRenameNothing()
}//end class
