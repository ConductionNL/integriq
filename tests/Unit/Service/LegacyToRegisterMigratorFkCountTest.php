<?php

/**
 * The FK rewrite pass reports the rows each UPDATE changed.
 *
 * The statement's IResult carries the affected-row count; the tally per
 * entity must be that count, on both SQL dialects, and 0 when the UPDATE fails.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/openconnector-storage-migration/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\Migration\LegacyToRegisterMigrator;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Row counts of LegacyToRegisterMigrator's FK rewrite pass.
 *
 * @covers \OCA\Integriq\Service\Migration\LegacyToRegisterMigrator
 */
class LegacyToRegisterMigratorFkCountTest extends TestCase {

	/**
	 * Affected rows the next UPDATE reports, per source column; a missing column throws.
	 *
	 * @var array<string, int>
	 */
	private array $rows = [];

	/**
	 * Build a migrator whose prepared UPDATEs report $this->rows.
	 *
	 * @param string $platform The JSON dialect, pgsql or mysql.
	 *
	 * @return LegacyToRegisterMigrator
	 */
	private function migrator(string $platform): LegacyToRegisterMigrator {
		$db = $this->createMock(IDBConnection::class);
		$db->method('prepare')->willReturnCallback(fn (string $sql) => $this->statement());

		$migrator = new LegacyToRegisterMigrator(
			db: $db,
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			secureRandom: $this->createMock(ISecureRandom::class),
		);

		$pks = [];
		foreach (LegacyToRegisterMigrator::FK_REWRITES as $index => $fk) {
			$pks[$fk['fromSchema']] = (100 + $index);
			$pks[$fk['toSchema']] = (200 + $index);
		}

		(new ReflectionProperty(LegacyToRegisterMigrator::class, 'schemaPks'))->setValue($migrator, $pks);
		(new ReflectionProperty(LegacyToRegisterMigrator::class, 'platform'))->setValue($migrator, $platform);

		return $migrator;
	}//end migrator()

	/**
	 * A prepared UPDATE whose result reports the rows configured for its jsonFkField.
	 *
	 * @return IPreparedStatement
	 */
	private function statement(): IPreparedStatement {
		$column = '';
		$stmt   = $this->createMock(IPreparedStatement::class);
		$stmt->method('bindValue')->willReturnCallback(
			function ($param, $value) use (&$column) {
				if ($param === 'jsonFkField') {
					$column = $value;
				}

				return true;
			}
		);
		$stmt->method('execute')->willReturnCallback(
			function () use (&$column) {
				if (array_key_exists($column, $this->rows) === false) {
					throw new RuntimeException('update refused');
				}

				$result = $this->createMock(IResult::class);
				$result->method('rowCount')->willReturn($this->rows[$column]);
				return $result;
			}
		);
		return $stmt;
	}//end statement()

	/**
	 * Run the FK pass over one result row per entity in FK_REWRITES.
	 *
	 * @param string $platform The JSON dialect.
	 *
	 * @return array<string, int> fkRewrites per entity slug.
	 */
	private function tally(string $platform): array {
		$results = [];
		foreach (LegacyToRegisterMigrator::FK_REWRITES as $fk) {
			$results[$fk['fromSchema']] = ['slug' => $fk['fromSchema']];
		}

		$results = array_values($results);
		$method  = new ReflectionMethod(LegacyToRegisterMigrator::class, 'runFkRewritePass');
		$args    = [&$results];
		$method->invokeArgs($this->migrator(platform: $platform), $args);

		$out = [];
		foreach ($results as $row) {
			$out[$row['slug']] = $row['fkRewrites'] ?? 0;
		}

		return $out;
	}//end tally()

	/**
	 * The expected tally: each entity sums the rows of its own FK columns.
	 *
	 * @return array<string, int>
	 */
	private function expected(): array {
		$out = [];
		foreach (LegacyToRegisterMigrator::FK_REWRITES as $fk) {
			$camel = lcfirst(str_replace('_', '', ucwords($fk['legacyCol'], '_')));
			$out[$fk['fromSchema']] = (($out[$fk['fromSchema']] ?? 0) + ($this->rows[$camel] ?? 0));
		}

		return $out;
	}//end expected()

	/**
	 * Give every FK column a distinct row count, 0 included.
	 *
	 * @return void
	 */
	private function distinctCounts(): void {
		$this->rows = [];
		foreach (LegacyToRegisterMigrator::FK_REWRITES as $index => $fk) {
			$camel = lcfirst(str_replace('_', '', ucwords($fk['legacyCol'], '_')));
			$this->rows[$camel] = ($index * 7);
		}
	}//end distinctCounts()

	/**
	 * Postgres: the tally is the affected-row count, not 1 per statement.
	 *
	 * @return void
	 */
	public function testPostgresTallyIsTheAffectedRowCount(): void {
		$this->distinctCounts();
		$this->assertSame($this->expected(), $this->tally(platform: 'pgsql'));
	}//end testPostgresTallyIsTheAffectedRowCount()

	/**
	 * MySQL: the tally is the affected-row count, not 1 per statement.
	 *
	 * @return void
	 */
	public function testMysqlTallyIsTheAffectedRowCount(): void {
		$this->distinctCounts();
		$this->assertSame($this->expected(), $this->tally(platform: 'mysql'));
	}//end testMysqlTallyIsTheAffectedRowCount()

	/**
	 * A failed UPDATE adds nothing, and an UPDATE that touched no rows adds 0.
	 *
	 * @return void
	 */
	public function testFailedAndEmptyUpdatesAddNothing(): void {
		$this->rows = [];
		$tally      = $this->tally(platform: 'mysql');
		$this->assertSame(array_fill_keys(array_keys($tally), 0), $tally);
	}//end testFailedAndEmptyUpdatesAddNothing()

	/**
	 * A single rewrite returns its own count, and 0 when a schema PK is unknown.
	 *
	 * @return void
	 */
	public function testOneRewriteReturnsItsCount(): void {
		$fk = LegacyToRegisterMigrator::FK_REWRITES[0];
		$this->rows = [lcfirst(str_replace('_', '', ucwords($fk['legacyCol'], '_'))) => 42];
		$method     = new ReflectionMethod(LegacyToRegisterMigrator::class, 'applyOneFkRewrite');

		$this->assertSame(42, $method->invoke($this->migrator(platform: 'pgsql'), $fk));

		$unknown = array_merge($fk, ['toSchema' => 'no-such-schema']);
		$this->assertSame(0, $method->invoke($this->migrator(platform: 'pgsql'), $unknown));
	}//end testOneRewriteReturnsItsCount()
}//end class
