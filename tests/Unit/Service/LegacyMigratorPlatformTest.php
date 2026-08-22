<?php

/**
 * Which JSON dialect the legacy migrator picks, per database provider.
 *
 * ocon#1183. The migrator chose between `jsonb_set` (Postgres) and `JSON_SET`
 * (MySQL) with `instanceof AbstractMySQLPlatform`, which matched MySQL and
 * MariaDB together. `getDatabaseProvider()` reports them as two constants, so
 * the replacement has to name MariaDB — and that is the assertion this file
 * exists for.
 *
 * The pre-existing `LegacyToRegisterMigratorTest` cannot cover it: it mocks
 * `getDatabasePlatform()` with a bare `stdClass` AND `getDatabaseProvider()`
 * with `'sqlite'`, so it lands on the fallback either way and would go green
 * whichever branch the code took.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/openconnector-storage-migration/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\Migration\LegacyToRegisterMigrator;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Provider → JSON dialect mapping in LegacyToRegisterMigrator.
 */
class LegacyMigratorPlatformTest extends TestCase {

	/**
	 * Warnings the migrator logged during the last call.
	 *
	 * @var string[]
	 */
	private array $warnings = [];

	/**
	 * Resolve a provider through the migrator's platform mapping.
	 *
	 * @param string $provider One of IDBConnection::PLATFORM_*.
	 *
	 * @return string The chosen dialect.
	 */
	private function platformFor(string $provider): string {
		$this->warnings = [];

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		$migrator = new LegacyToRegisterMigrator(
			$this->createMock(IDBConnection::class),
			$this->createMock(IAppConfig::class),
			$logger
		);

		$method = new ReflectionMethod(LegacyToRegisterMigrator::class, 'platformFor');
		$method->setAccessible(true);

		return $method->invoke($migrator, $provider);
	}//end platformFor()

	/**
	 * PostgreSQL gets the Postgres dialect.
	 *
	 * @return void
	 */
	public function testPostgresGetsPgsql(): void {
		$this->assertSame('pgsql', $this->platformFor(IDBConnection::PLATFORM_POSTGRES));
		$this->assertSame([], $this->warnings);

	}//end testPostgresGetsPgsql()

	/**
	 * MySQL gets the MySQL dialect, without a warning.
	 *
	 * @return void
	 */
	public function testMysqlGetsMysql(): void {
		$this->assertSame('mysql', $this->platformFor(IDBConnection::PLATFORM_MYSQL));
		$this->assertSame([], $this->warnings);

	}//end testMysqlGetsMysql()

	/**
	 * MariaDB is RECOGNISED, not merely defaulted onto the same answer.
	 *
	 * The dialect assertion alone cannot tell "matched the MariaDB branch"
	 * from "fell through the fallback, which happens to return the same
	 * string" — so the absence of the warning is what actually distinguishes
	 * them, and is the whole point of the test.
	 *
	 * @return void
	 */
	public function testMariadbIsRecognisedRatherThanDefaulted(): void {
		$this->assertSame('mysql', $this->platformFor(IDBConnection::PLATFORM_MARIADB));
		$this->assertSame([], $this->warnings, 'MariaDB should not be reported as an unknown platform');

	}//end testMariadbIsRecognisedRatherThanDefaulted()

	/**
	 * A genuinely unrecognised provider warns and defaults to MySQL.
	 *
	 * The counterpart to the MariaDB test: without this, "no warning" would be
	 * satisfiable by a mapping that never warns at all.
	 *
	 * @return void
	 */
	public function testAnUnknownProviderWarnsAndDefaults(): void {
		$this->assertSame('mysql', $this->platformFor(IDBConnection::PLATFORM_SQLITE));
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('unknown DB platform', $this->warnings[0]);

	}//end testAnUnknownProviderWarnsAndDefaults()
}//end class
