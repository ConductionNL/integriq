<?php

/**
 * Tests for the schema application-id migration.
 *
 * @category  Test
 * @package   OCA\Integriq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Repair;

use OCA\Integriq\Repair\MigrateSchemaApplicationId;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Integriq\Repair\MigrateSchemaApplicationId
 *
 * @spec exclude No canonical spec covers the `openconnector` -> `integriq` schema
 *  application-id migration. Pointing this at an existing spec would report
 *  conformance to a requirement that says nothing about it.
 */
final class MigrateSchemaApplicationIdTest extends TestCase {

	/**
	 * A connection whose two SELECTs return the given slug sets, in order.
	 *
	 * The step reads the NEW application id first, then the OLD one.
	 *
	 * @param array<int, string> $underNew Slugs already on the new app id.
	 * @param array<int, string> $underOld Slugs still on the old app id.
	 * @param array<int, array<int, string>> $written Captured UPDATE params.
	 *
	 * @return IDBConnection&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function db(array $underNew, array $underOld, array &$written = []) {
		$mk = function (array $slugs) {
			$r = $this->createMock(IResult::class);
			$r->method('fetchAll')->willReturn(array_map(static fn (string $s): array => ['slug' => $s], $slugs));
			return $r;
		};

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnOnConsecutiveCalls($mk($underNew), $mk($underOld));
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params) use (&$written): int {
				$written[] = $params;
				return 1;
			}
		);

		return $db;
	}//end db()

	/**
	 * A schema with no twin under the new application id is moved.
	 *
	 * @return void
	 */
	public function testMovesASchemaThatHasNoTwin(): void {
		$written = [];
		$db = $this->db([], ['case', 'task'], $written);

		$step = new MigrateSchemaApplicationId($db, $this->createMock(LoggerInterface::class));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('2 schema(s) moved'));

		$step->run($output);

		self::assertSame(
			[['integriq', 'openconnector', 'case'], ['integriq', 'openconnector', 'task']],
			$written
		);

	}//end testMovesASchemaThatHasNoTwin()

	/**
	 * A slug that ALREADY exists under the new application id is refused.
	 *
	 * Re-pointing it would leave two rows sharing (application, slug), and
	 * findByApplicationAndSlug() caps at one row — so one would silently win
	 * every lookup and the other's objects would become unreachable.
	 *
	 * @return void
	 */
	public function testRefusesASlugThatWouldCollide(): void {
		$written = [];
		$db = $this->db(['case'], ['case', 'task'], $written);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(self::stringContains('refusing to create a duplicate'));

		$step = new MigrateSchemaApplicationId($db, $logger);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('1 schema(s) moved to integriq, 1 refused'));

		$step->run($output);

		self::assertSame([['integriq', 'openconnector', 'task']], $written);

	}//end testRefusesASlugThatWouldCollide()

	/**
	 * The collision check is case-insensitive, as the lookup is.
	 *
	 * SchemaMapper::findByApplicationAndSlug() compares `lower(slug)`, so a
	 * twin differing only in case still collides.
	 *
	 * @return void
	 */
	public function testTheCollisionCheckIsCaseInsensitive(): void {
		$written = [];
		$db = $this->db(['Case'], ['case'], $written);

		$step = new MigrateSchemaApplicationId($db, $this->createMock(LoggerInterface::class));
		$step->run($this->createMock(IOutput::class));

		self::assertSame([], $written);

	}//end testTheCollisionCheckIsCaseInsensitive()

	/**
	 * Nothing on the old application id is a no-op, and says so.
	 *
	 * @return void
	 */
	public function testNothingOnTheOldAppIdIsANoOp(): void {
		$written = [];
		$db = $this->db([], [], $written);
		$db->expects(self::never())->method('executeStatement');

		$step = new MigrateSchemaApplicationId($db, $this->createMock(LoggerInterface::class));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('nothing to do'));

		$step->run($output);

	}//end testNothingOnTheOldAppIdIsANoOp()

	/**
	 * A failed READ must not read as "no schemas are claimed".
	 *
	 * That distinction is the whole safety of the step: an empty list says
	 * every move is safe, while a failed read says nothing at all. Treating
	 * them alike would move every schema on top of an existing twin.
	 *
	 * @return void
	 */
	public function testAFailedReadIsNotTreatedAsAnEmptyResult(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willThrowException(new \OCP\DB\Exception('read failed'));
		$db->expects(self::never())->method('executeStatement');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(self::stringContains('could not read schemas'));

		$step = new MigrateSchemaApplicationId($db, $logger);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('nothing done'));

		$step->run($output);

	}//end testAFailedReadIsNotTreatedAsAnEmptyResult()

	/**
	 * A failing UPDATE is logged and counted as not moved — never thrown.
	 *
	 * @return void
	 */
	public function testAFailingUpdateIsLoggedNotThrown(): void {
		$mk = function (array $slugs) {
			$r = $this->createMock(IResult::class);
			$r->method('fetchAll')->willReturn(array_map(static fn (string $s): array => ['slug' => $s], $slugs));
			return $r;
		};
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnOnConsecutiveCalls($mk([]), $mk(['case']));
		$db->method('executeStatement')->willThrowException(new \OCP\DB\Exception('write refused'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(self::stringContains('could not move schema'));

		$step = new MigrateSchemaApplicationId($db, $logger);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('0 schema(s) moved'));

		$step->run($output);

	}//end testAFailingUpdateIsLoggedNotThrown()

	/**
	 * The shipped ids are a repair step's, and they actually differ.
	 *
	 * @return void
	 */
	public function testShippedIdsAreWellFormed(): void {
		self::assertNotSame(MigrateSchemaApplicationId::OLD_APP_ID, MigrateSchemaApplicationId::NEW_APP_ID);
		self::assertMatchesRegularExpression('/^[a-z][a-z0-9_-]*$/', MigrateSchemaApplicationId::OLD_APP_ID);
		self::assertMatchesRegularExpression('/^[a-z][a-z0-9_-]*$/', MigrateSchemaApplicationId::NEW_APP_ID);
		self::assertTrue(
			(new ReflectionClass(MigrateSchemaApplicationId::class))->implementsInterface(IRepairStep::class)
		);
		self::assertNotSame('', (new MigrateSchemaApplicationId(
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class)
		))->getName());

	}//end testShippedIdsAreWellFormed()
}//end class
