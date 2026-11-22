<?php

/**
 * Characterisation tests for MigrateEolSchemaSlugs' two database reads.
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the queries, their parameters, the filtering of their answers and
 * the logged fallback when the database refuses.
 */
class MigrateEolSchemaSlugsReadsTest extends TestCase {

	/**
	 * Every query, as [sql, params].
	 *
	 * @var list<array{0:string,1:array}>
	 */
	private array $queries = [];

	/**
	 * Every warning, as [message, context].
	 *
	 * @var list<array{0:string,1:array}>
	 */
	private array $warnings = [];

	/**
	 * Call a private method of a step whose database answers with $answer
	 * (an IResult method name => value) or throws.
	 *
	 * @param string     $method The private method.
	 * @param array|null $answer The result answers, or null to throw.
	 *
	 * @return mixed
	 */
	private function call(string $method, ?array $answer): mixed {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use ($answer): IResult {
				$this->queries[] = [$sql, $params];
				if ($answer === null) {
					throw new Exception('db down');
				}

				$result = $this->createMock(IResult::class);
				foreach ($answer as $name => $value) {
					$result->method($name)->willReturn($value);
				}

				return $result;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);
		$reflection = new \ReflectionMethod(MigrateEolSchemaSlugs::class, $method);
		$reflection->setAccessible(true);

		return $reflection->invoke(new MigrateEolSchemaSlugs($db, $logger));

	}//end call()

	/**
	 * The slugs read are the old and new ones of the map, scoped to this
	 * app; empty, non-string and repeated answers are dropped.
	 *
	 * @return void
	 */
	public function testExistingSlugsQueriesTheMapAndKeepsRealSlugs(): void {
		$rows = [['slug' => 'endOfLifeProduct'], ['slug' => ''], ['slug' => null], [], ['slug' => 7], ['slug' => 'eol_product'], ['slug' => 'endOfLifeProduct']];

		$this->assertSame(['endOfLifeProduct', 'eol_product'], $this->call('existingSlugs', ['fetchAll' => $rows]));

		$slugs = [];
		foreach (MigrateEolSchemaSlugs::SLUG_MAP as $old => $new) {
			$slugs[] = $old;
			$slugs[] = $new;
		}

		$this->assertSame(
			[[
				'SELECT slug FROM `*PREFIX*openregister_schemas` WHERE slug IN (' . implode(',', array_fill(0, count($slugs), '?')) . ') AND (application = ? OR application LIKE ?)',
				array_merge($slugs, ['integriq', 'integriq.%']),
			]],
			$this->queries
		);
		$this->assertSame([], $this->warnings);

	}//end testExistingSlugsQueriesTheMapAndKeepsRealSlugs()

	/**
	 * A refused read plans nothing and says why.
	 *
	 * @return void
	 */
	public function testARefusedReadYieldsNothingAndIsLogged(): void {
		$this->assertSame([], $this->call('existingSlugs', null));
		$this->assertNull($this->call('synchronizationTable', null));

		$this->assertSame(
			[
				['MigrateEolSchemaSlugs: could not read schema slugs; skipping.', ['exception' => 'db down']],
				['MigrateEolSchemaSlugs: could not resolve the synchronization table; skipping.', ['exception' => 'db down']],
			],
			$this->warnings
		);

	}//end testARefusedReadYieldsNothingAndIsLogged()

	/**
	 * The synchronization table is composed from both ids, and refused when
	 * either is missing or zero or the row is absent.
	 *
	 * @return void
	 */
	public function testTheSynchronizationTableIsComposedFromBothIds(): void {
		$this->assertSame('openregister_table_3_14', $this->call('synchronizationTable', ['fetch' => ['register_id' => '3', 'schema_id' => 14]]));
		$this->assertSame(
			[
				'SELECT r.id AS register_id, s.id AS schema_id FROM `*PREFIX*openregister_registers` r, `*PREFIX*openregister_schemas` s WHERE r.slug = ? AND s.slug = ? AND s.application = ?',
				['integriq', 'synchronization', 'integriq'],
			],
			$this->queries[0]
		);

		foreach ([false, [], ['register_id' => 3], ['register_id' => 0, 'schema_id' => 4], ['register_id' => 'x', 'schema_id' => 4]] as $row) {
			$this->assertNull($this->call('synchronizationTable', ['fetch' => $row]), json_encode($row));
		}

		$this->assertSame([], $this->warnings);

	}//end testTheSynchronizationTableIsComposedFromBothIds()

}//end class
