<?php

/**
 * Unit tests for the consolidated migration.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Migration;

use InvalidArgumentException;
use OCA\Integriq\Migration\Version2Date20260908000000;
use OCA\Integriq\Repair\MigrateLegacyStorage;
use OCA\Integriq\Service\Migration\LegacyToRegisterMigrator;
use OCP\App\IAppManager;
use OCP\DB\ISchemaWrapper;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests for Version2Date20260908000000.
 */
class ConsolidatedMigrationTest extends TestCase {
	/** @var array<int,string> */
	private array $statements = [];

	/** @var array<string,string> */
	private array $appConfigStore = [];

	private IDBConnection $db;

	private IAppConfig $appConfig;

	/**
	 * Make the drain's class_exists probe answer true in both environments.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		require_once __DIR__.'/../../stubs/openregister-service-stubs.php';
	}

	/**
	 * Wire the doubles shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->statements = [];
		$this->appConfigStore = [];

		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('executeStatement')->willReturnCallback(
			function (string $sql): int {
				$this->statements[] = $sql;
				return 0;
			}
		);

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->appConfigStore[$key] ?? $default
		);
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->appConfigStore[$key] = $value;
				return true;
			}
		);
	}

	/**
	 * Build the migration under test.
	 *
	 * @return Version2Date20260908000000
	 */
	private function migration(): Version2Date20260908000000 {
		return new Version2Date20260908000000(
			$this->appConfig,
			$this->db,
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(ContainerInterface::class)
		);
	}

	/**
	 * A schema double reporting the given tables as present.
	 *
	 * @param array<int,string> $present Unprefixed tables that exist.
	 *
	 * @return ISchemaWrapper
	 */
	private function schemaWith(array $present): ISchemaWrapper {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			static fn (string $name): bool => in_array($name, $present, true)
		);
		return $schema;
	}

	/**
	 * Read a private constant off the migration.
	 *
	 * @return array<int,string>
	 */
	private function legacyTables(): array {
		/** @var array<int,string> $tables */
		$tables = (new ReflectionClass(Version2Date20260908000000::class))->getConstant('LEGACY_TABLES');
		return $tables;
	}

	/**
	 * This app owns no tables, so the schema step declares nothing.
	 *
	 * @return void
	 */
	public function testChangeSchemaDeclaresNothing(): void {
		$result = $this->migration()->changeSchema(
			$this->createMock(IOutput::class),
			fn () => $this->schemaWith([]),
			[]
		);

		$this->assertNull($result);
	}

	/**
	 * A fresh install has no legacy table, so there is nothing to drain or drop
	 * and the flag is simply recorded as done.
	 *
	 * @return void
	 */
	public function testAFreshInstallDropsNothingAndRecordsTheFlag(): void {
		$this->migration()->postSchemaChange(
			$this->createMock(IOutput::class),
			fn () => $this->schemaWith([]),
			[]
		);

		$this->assertSame([], $this->statements, 'nothing may be dropped when no legacy table exists');
		$this->assertSame('true', $this->appConfigStore['storage_migrated'] ?? null);
	}

	/**
	 * An instance that already completed the cutover drops every legacy table
	 * it still carries, without re-running the drain.
	 *
	 * @return void
	 */
	public function testAnAlreadyMigratedInstanceDropsEveryLegacyTable(): void {
		$this->appConfigStore['storage_migrated'] = 'true';
		$tables = $this->legacyTables();

		$this->migration()->postSchemaChange(
			$this->createMock(IOutput::class),
			fn () => $this->schemaWith($tables),
			[]
		);

		$this->assertCount(count($tables), $this->statements);
		foreach ($tables as $table) {
			$this->assertContains(
				sprintf('DROP TABLE IF EXISTS *PREFIX*%s', $table),
				$this->statements
			);
		}
	}

	/**
	 * Only the tables that are actually present are dropped.
	 *
	 * @return void
	 */
	public function testOnlyPresentTablesAreDropped(): void {
		$this->appConfigStore['storage_migrated'] = 'true';
		$present = array_slice($this->legacyTables(), 0, 3);

		$this->migration()->postSchemaChange(
			$this->createMock(IOutput::class),
			fn () => $this->schemaWith($present),
			[]
		);

		$this->assertCount(3, $this->statements);
	}

	/**
	 * This is the assertion the whole change turns on. A drain that could not
	 * run must leave every legacy table in place, because the rows in them have
	 * not reached OpenRegister and dropping would destroy them.
	 *
	 * The container is made to throw, which drives the drain's "failed to
	 * resolve services" branch. Do NOT simplify this to a plain mock and rely
	 * on OpenRegister being absent: it is absent from a bare clone but PRESENT
	 * in CI, where the app runs inside a server checkout with openregister
	 * enabled. A plain mock then returns null past the class_exists guard and
	 * the test dies on a null call instead of asserting anything.
	 *
	 * @return void
	 */
	public function testAnIncompleteDrainDropsNothingAndLeavesTheFlagUnset(): void {
		$tables = $this->legacyTables();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('openregister unavailable'));

		$migration = new Version2Date20260908000000(
			$this->appConfig,
			$this->db,
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$container
		);

		$migration->postSchemaChange(
			$this->createMock(IOutput::class),
			fn () => $this->schemaWith($tables),
			[]
		);

		$this->assertSame([], $this->statements, 'a failed drain must not drop a single table');
		$this->assertArrayNotHasKey('storage_migrated', $this->appConfigStore);
	}

	/**
	 * One table failing to drop does not abandon the rest.
	 *
	 * @return void
	 */
	public function testAFailedDropIsLoggedAndTheRestStillRun(): void {
		$this->appConfigStore['storage_migrated'] = 'true';
		$tables = $this->legacyTables();
		$first = $tables[0];

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql) use ($first): int {
				if (str_contains($sql, $first)) {
					throw new \RuntimeException('table is locked');
				}

				$this->statements[] = $sql;
				return 0;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$migration = new Version2Date20260908000000(
			$this->appConfig,
			$db,
			$this->createMock(IAppManager::class),
			$logger,
			$this->createMock(ContainerInterface::class)
		);

		$migration->postSchemaChange(
			$this->createMock(IOutput::class),
			fn () => $this->schemaWith($tables),
			[]
		);

		$this->assertCount(count($tables) - 1, $this->statements);
	}

	/**
	 * The identifier guard refuses a name that is not a bare identifier.
	 *
	 * @return void
	 */
	public function testANonIdentifierTableNameIsRefused(): void {
		$migration = $this->migration();
		$drop = (new ReflectionClass($migration))->getMethod('dropLegacyTables');
		$drop->setAccessible(true);

		$this->expectException(InvalidArgumentException::class);
		$drop->invoke($migration, $this->createMock(IOutput::class), ['bad"; DROP TABLE x; --']);
	}

	/**
	 * Build a container whose two lookups return working doubles.
	 *
	 * @param array<int,array<string,mixed>> $result What migrateAll reports.
	 *
	 * @return ContainerInterface
	 */
	private function containerThatDrains(array $result): ContainerInterface {
		$configurationService = new class {
			/**
			 * @param string              $appId   Owning app id.
			 * @param array<string,mixed> $data    Decoded descriptor.
			 * @param string              $version App version.
			 *
			 * @return void
			 */
			public function importFromApp(string $appId, array $data, string $version): void {
			}
		};

		$migrator = $this->createMock(LegacyToRegisterMigrator::class);
		$migrator->method('migrateAll')->willReturn($result);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $id === LegacyToRegisterMigrator::class
				? $migrator
				: $configurationService
		);

		return $container;
	}

	/**
	 * A drain that copies every entity sets the flag and the tables then go.
	 *
	 * @return void
	 */
	public function testACleanDrainSetsTheFlagAndDropsTheTables(): void {
		$tables = $this->legacyTables();
		$result = array_map(
			static fn (string $t): array => ['slug' => $t, 'legacyCount' => 2, 'migratedCount' => 2, 'skipped' => 0],
			$tables
		);

		$migration = new Version2Date20260908000000(
			$this->appConfig,
			$this->db,
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$this->containerThatDrains($result)
		);

		$migration->postSchemaChange(
			$this->createMock(IOutput::class),
			fn () => $this->schemaWith($tables),
			[]
		);

		$this->assertSame('true', $this->appConfigStore['storage_migrated'] ?? null);
		$this->assertCount(count($tables), $this->statements);
	}

	/**
	 * One entity reporting a skip is enough to keep every table.
	 *
	 * This is the partial-drain case, and it is the one that would lose data if
	 * the drop were unconditional: the skipped rows are still only in the
	 * legacy table.
	 *
	 * @return void
	 */
	public function testASingleSkippedEntityKeepsEveryTable(): void {
		$tables = $this->legacyTables();
		$result = array_map(
			static fn (string $t): array => ['slug' => $t, 'legacyCount' => 2, 'migratedCount' => 2, 'skipped' => 0],
			$tables
		);
		$result[0]['skipped'] = 1;

		$migration = new Version2Date20260908000000(
			$this->appConfig,
			$this->db,
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$this->containerThatDrains($result)
		);

		$migration->postSchemaChange(
			$this->createMock(IOutput::class),
			fn () => $this->schemaWith($tables),
			[]
		);

		$this->assertArrayNotHasKey('storage_migrated', $this->appConfigStore);
		$this->assertSame([], $this->statements, 'a single skip must keep every table');
	}

	/**
	 * The migration and the repair step name the same fifteen tables.
	 *
	 * Two independently maintained lists. A table in one and not the other
	 * silently drops out of either the cutover check or the cleanup.
	 *
	 * @return void
	 */
	public function testTheTableListMatchesTheRepairStep(): void {
		$fromMigration = $this->legacyTables();
		$fromRepairStep = MigrateLegacyStorage::LEGACY_TABLES;

		sort($fromMigration);
		sort($fromRepairStep);

		$this->assertNotSame([], $fromMigration);
		$this->assertSame($fromRepairStep, $fromMigration);
	}
}
