<?php

/**
 * Which SQL dialect `SettingsService` emits, per database platform.
 *
 * ocon#1183. Two private methods pick SQL by inspecting the database platform,
 * and neither had any coverage — so the deprecated `getDatabasePlatform()` call
 * they use could not be replaced without guessing.
 *
 * These tests exist to make that replacement safe, and MariaDB is the reason.
 * `instanceof AbstractMySQLPlatform` catches MySQL and MariaDB in one branch;
 * `getDatabaseProvider()` separates them into `PLATFORM_MYSQL` and
 * `PLATFORM_MARIADB`. A translation that compares only against `PLATFORM_MYSQL`
 * sends MariaDB down the PostgreSQL path and emits `::interval` casts to a
 * server that has never heard of them — silently, because nothing here was
 * measured. So MariaDB is asserted explicitly rather than left to fall through
 * a default.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/logs-and-statistics/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Dialect selection in SettingsService.
 */
class SettingsServiceDialectTest extends TestCase {

	/**
	 * A SettingsService whose connection reports the given platform.
	 *
	 * @param string $provider One of IDBConnection::PLATFORM_*.
	 *
	 * @return SettingsService The service under test.
	 */
	private function serviceOn(string $provider): SettingsService {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn($provider);

		return new SettingsService($db, $this->createMock(IAppConfig::class), new NullLogger());
	}//end serviceOn()

	/**
	 * Call a private method on the service.
	 *
	 * @param SettingsService $service The service.
	 * @param string $method The method name.
	 * @param array<mixed> $args Positional arguments.
	 *
	 * @return mixed The return value.
	 */
	private function call(SettingsService $service, string $method, array $args) {
		$reflected = new ReflectionMethod(SettingsService::class, $method);
		$reflected->setAccessible(true);

		return $reflected->invokeArgs($service, $args);
	}//end call()

	/**
	 * The platforms that must produce MySQL-family interval syntax.
	 *
	 * @return array<string, array{0: string}> Named cases.
	 */
	public static function mysqlFamilyProvider(): array {
		return [
			'mysql' => [IDBConnection::PLATFORM_MYSQL],
			'mariadb' => [IDBConnection::PLATFORM_MARIADB],
		];

	}//end mysqlFamilyProvider()

	/**
	 * MySQL and MariaDB both get `DATE_ADD(…, INTERVAL ? MICROSECOND)`.
	 *
	 * @param string $provider The platform under test.
	 *
	 * @return void
	 *
	 * @dataProvider mysqlFamilyProvider
	 */
	public function testTheMysqlFamilyGetsDateAdd(string $provider): void {
		$sql = $this->call($this->serviceOn($provider), 'expiresExpression', ['created']);

		$this->assertSame('DATE_ADD(created, INTERVAL ? MICROSECOND)', $sql);

	}//end testTheMysqlFamilyGetsDateAdd()

	/**
	 * PostgreSQL gets the interval-cast form.
	 *
	 * @return void
	 */
	public function testPostgresGetsAnIntervalCast(): void {
		$sql = $this->call($this->serviceOn(IDBConnection::PLATFORM_POSTGRES), 'expiresExpression', ['created']);

		$this->assertSame("created + (? || ' microseconds')::interval", $sql);

	}//end testPostgresGetsAnIntervalCast()

	/**
	 * A platform that is neither falls back to the MySQL form, not the Postgres one.
	 *
	 * The fallback direction is load-bearing: `::interval` is PostgreSQL-only
	 * syntax, so defaulting the other way would emit SQL that no non-Postgres
	 * server can parse. `DATE_ADD` at least fails on a server that understands
	 * the rest of the statement.
	 *
	 * @return void
	 */
	public function testAnUnknownPlatformFallsBackToTheMysqlForm(): void {
		$sql = $this->call($this->serviceOn(IDBConnection::PLATFORM_SQLITE), 'expiresExpression', ['created']);

		$this->assertStringNotContainsString('::interval', $sql);
		$this->assertSame('DATE_ADD(created, INTERVAL ? MICROSECOND)', $sql);

	}//end testAnUnknownPlatformFallsBackToTheMysqlForm()

	/**
	 * The column expression is spliced verbatim, not quoted or rewritten.
	 *
	 * `rebase()` passes `COALESCE(created, NOW())` as the column, so anything
	 * that treats the argument as an identifier would break the caller.
	 *
	 * @return void
	 */
	public function testTheColumnExpressionIsSplicedVerbatim(): void {
		$sql = $this->call(
			$this->serviceOn(IDBConnection::PLATFORM_POSTGRES),
			'expiresExpression',
			['COALESCE(created, NOW())']
		);

		$this->assertStringStartsWith('COALESCE(created, NOW()) +', $sql);

	}//end testTheColumnExpressionIsSplicedVerbatim()

	/**
	 * PostgreSQL probes `information_schema`, not `SHOW COLUMNS`.
	 *
	 * `columnExists()` swallows every Throwable and returns false, so this
	 * asserts on the SQL that reached `prepare()` rather than on the return
	 * value — otherwise a test would pass just as well against a method that
	 * threw immediately and never queried anything.
	 *
	 * @return void
	 */
	public function testPostgresProbesInformationSchema(): void {
		$prepared = $this->captureColumnProbe(IDBConnection::PLATFORM_POSTGRES);

		$this->assertStringContainsString('information_schema.columns', $prepared);
		$this->assertStringNotContainsString('SHOW COLUMNS', $prepared);

	}//end testPostgresProbesInformationSchema()

	/**
	 * MySQL and MariaDB both probe with `SHOW COLUMNS`.
	 *
	 * @param string $provider The platform under test.
	 *
	 * @return void
	 *
	 * @dataProvider mysqlFamilyProvider
	 */
	public function testTheMysqlFamilyProbesWithShowColumns(string $provider): void {
		$prepared = $this->captureColumnProbe($provider);

		$this->assertStringContainsString('SHOW COLUMNS', $prepared);
		$this->assertStringNotContainsString('information_schema', $prepared);

	}//end testTheMysqlFamilyProbesWithShowColumns()

	/**
	 * Run `columnExists()` and return the SQL it handed to `prepare()`.
	 *
	 * @param string $provider One of IDBConnection::PLATFORM_*.
	 *
	 * @return string The prepared SQL.
	 */
	private function captureColumnProbe(string $provider): string {
		$prepared = '';

		$statement = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$statement->method('fetch')->willReturn(false);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn($provider);
		$db->method('quote')->willReturnCallback(static fn ($value): string => "'" . $value . "'");
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use (&$prepared, $statement) {
				$prepared = $sql;
				return $statement;
			}
		);

		$service = new SettingsService($db, $this->createMock(IAppConfig::class), new NullLogger());

		$this->call($service, 'columnExists', ['openconnector_event_messages', 'expires']);

		$this->assertNotSame('', $prepared, 'columnExists() should have prepared a statement');

		return $prepared;
	}//end captureColumnProbe()
}//end class
