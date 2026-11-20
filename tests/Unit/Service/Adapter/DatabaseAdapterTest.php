<?php

/**
 * The database adapter runs only declared statements, binds by name and pages by key.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Adapter;

use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseConnectionFactoryInterface;
use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseConnectionInterface;
use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseConnectionSettings;
use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseSourceException;
use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseStatement;
use OCA\Integriq\Service\Adapter\DataInfra\DatabaseAdapter;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A fake database: a table of rows that answers a keyset read the way
 * `WHERE id > :after ORDER BY id LIMIT :limit` would, and records every call.
 */
final class FakeDatabaseConnection implements DatabaseConnectionInterface {

	/** @var array<int,array{sql:string,parameters:array<string,mixed>,types:array<string,string>,maxRows:int}> */
	public array $reads = [];

	/** @var array<int,array{sql:string,parameters:array<string,mixed>}> */
	public array $writes = [];

	public int $closed = 0;

	/**
	 * Constructor.
	 *
	 * @param array<int,array<string,mixed>> $rows The table, ordered by id.
	 */
	public function __construct(private readonly array $rows) {
	}

	public function fetchRows(string $sql, array $parameters, array $types, int $maxRows): array {
		$this->reads[] = ['sql' => $sql, 'parameters' => $parameters, 'types' => $types, 'maxRows' => $maxRows];
		$after = ($parameters['after'] ?? null);
		$matching = array_values(array_filter($this->rows, static fn (array $row): bool => $after === null || $row['id'] > $after));

		// Return more than asked when the SQL has no LIMIT, so the cap is the adapter's.
		$limit = ($parameters['limit'] ?? count($matching));

		return array_slice($matching, 0, min($limit, $maxRows));
	}

	public function execute(string $sql, array $parameters, array $types): int {
		$this->writes[] = ['sql' => $sql, 'parameters' => $parameters];

		return 1;
	}

	public function describe(int $maxTables): array {
		return [['name' => 'zaken', 'kind' => 'table', 'columns' => [['name' => 'id', 'type' => 'integer', 'nullable' => false]]]];
	}

	public function close(): void {
		$this->closed++;
	}
}//end class

/**
 * Opens the fake connection and records the settings it was given.
 */
final class FakeDatabaseConnectionFactory implements DatabaseConnectionFactoryInterface {

	/** @var array<int,DatabaseConnectionSettings> */
	public array $opened = [];

	/**
	 * Constructor.
	 *
	 * @param FakeDatabaseConnection $connection The connection every open returns.
	 * @param array<int,string>      $drivers    The drivers this server has.
	 */
	public function __construct(
		public readonly FakeDatabaseConnection $connection,
		private readonly array $drivers = ['pdo_pgsql', 'pdo_mysql'],
	) {
	}

	public function open(DatabaseConnectionSettings $settings): DatabaseConnectionInterface {
		$this->opened[] = $settings;

		return $this->connection;
	}

	public function driverAvailable(string $driver): bool {
		return in_array($driver, $this->drivers, true);
	}
}//end class

#[CoversClass(DatabaseAdapter::class)]
#[UsesClass(DatabaseStatement::class)]
#[UsesClass(DatabaseConnectionSettings::class)]
class DatabaseAdapterTest extends TestCase {

	/**
	 * The credential reference resolver.
	 *
	 * @var BrokeredCallService&MockObject
	 */
	private $credentials;

	private FakeDatabaseConnectionFactory $factory;

	private DatabaseAdapter $adapter;

	/**
	 * A 1,200-row table of zaken.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function zaken(int $count): array {
		$rows = [];
		for ($id = 1; $id <= $count; $id++) {
			$rows[] = ['id' => $id, 'zaaknummer' => sprintf('Z-%04d', $id)];
		}

		return $rows;
	}

	/**
	 * Build the adapter over a fake database.
	 *
	 * @param array<int,string> $drivers The drivers this server has.
	 *
	 * @return void
	 */
	private function build(array $drivers = ['pdo_pgsql', 'pdo_mysql'], int $rows = 1200): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->credentials = $this->createMock(BrokeredCallService::class);
		$this->factory = new FakeDatabaseConnectionFactory(connection: new FakeDatabaseConnection(rows: self::zaken($rows)), drivers: $drivers);

		$this->adapter = new DatabaseAdapter(
			credentialBroker: $this->createMock(CredentialBrokerService::class),
			appConfig: $this->createMock(IAppConfig::class),
			logger: $this->createMock(LoggerInterface::class),
			l10n: $l10n,
			connections: $this->factory,
			credentials: $this->credentials
		);
	}

	/**
	 * A database source declaring one keyset read and one write.
	 *
	 * @param array<string,mixed> $overrides Fields to replace.
	 *
	 * @return array<string,mixed>
	 */
	private static function source(array $overrides = []): array {
		return array_replace([
			'type' => 'database',
			'database' => ['driver' => 'pdo_pgsql', 'host' => 'db.example.nl', 'port' => '5432', 'dbname' => 'zaken', 'user' => 'integriq_read', 'timeout' => 20],
			'configuration' => ['authentication' => ['password' => ['credentialRef' => ['credentialName' => 'zaken-db']]]],
			'statements' => [
				['name' => 'zaken-sinds', 'kind' => 'read', 'sql' => 'SELECT id, zaaknummer FROM zaken WHERE id > :after ORDER BY id LIMIT :limit', 'pageKey' => 'id'],
				['name' => 'zaak-sluiten', 'kind' => 'write', 'sql' => 'UPDATE zaken SET status = :status WHERE id = :id', 'parameters' => [['name' => 'id', 'type' => 'integer'], 'status']],
			],
		], $overrides);
	}

	/**
	 * Have the broker resolve the reference to a password.
	 *
	 * @return void
	 */
	private function brokerResolves(string $password): void {
		$this->credentials->method('hydrateInjectableCredentials')->willReturnCallback(
			static function (array $source) use ($password): array {
				$source['configuration']['authentication']['password'] = $password;

				return $source;
			}
		);
	}

	/**
	 * The first page is read with the password from the broker and the source's settings.
	 *
	 * @return void
	 */
	public function testAReadConnectsWithTheBrokeredPasswordAndBindsByName(): void {
		$this->build();
		$this->brokerResolves(password: 's3cret');

		$page = $this->adapter->read(source: self::source(), statement: 'zaken-sinds');

		self::assertCount(500, $page['rows']);
		self::assertSame(['id' => 1, 'zaaknummer' => 'Z-0001'], $page['rows'][0]);
		self::assertSame(500, $page['next']);
		self::assertSame(500, $page['pageSize']);
		self::assertIsInt($page['durationMs']);

		$settings = $this->factory->opened[0];
		self::assertSame(['pdo_pgsql', 'db.example.nl', 5432, 'zaken', 'integriq_read', 's3cret', 20], [$settings->driver, $settings->host, $settings->port, $settings->dbname, $settings->user, $settings->password, $settings->timeoutSeconds]);

		$read = $this->factory->connection->reads[0];
		self::assertSame('SELECT id, zaaknummer FROM zaken WHERE id > :after ORDER BY id LIMIT :limit', $read['sql']);
		self::assertSame(['after' => 0, 'limit' => 500], $read['parameters']);
		self::assertSame(['after' => 'integer', 'limit' => 'integer'], $read['types']);
		self::assertSame(1, $this->factory->connection->closed, 'the connection lives for one call');
	}//end testAReadConnectsWithTheBrokeredPasswordAndBindsByName()

	/**
	 * Pages follow the key until one is shorter than the page size.
	 *
	 * @return void
	 */
	public function testPagesFollowTheKeyUntilAShortPage(): void {
		$this->build();
		$this->brokerResolves(password: 'pw');

		$after = null;
		$sizes = [];
		do {
			$page = $this->adapter->read(source: self::source(), statement: 'zaken-sinds', after: $after, limit: 500);
			$sizes[] = count($page['rows']);
			$after = $page['next'];
		} while ($after !== null);

		self::assertSame([500, 500, 200], $sizes);
		self::assertSame([0, 500, 1000], array_column(array_column($this->factory->connection->reads, 'parameters'), 'after'));
	}//end testPagesFollowTheKeyUntilAShortPage()

	/**
	 * The page size is capped, also when the SQL itself sets no limit.
	 *
	 * @return void
	 */
	public function testThePageSizeIsCapped(): void {
		$this->build(rows: 6000);
		$this->brokerResolves(password: 'pw');
		$source = self::source(['statements' => [['name' => 'alles', 'sql' => 'SELECT id FROM zaken']]]);

		$page = $this->adapter->read(source: $source, statement: 'alles', limit: 100000);

		self::assertCount(DatabaseAdapter::MAX_PAGE_SIZE, $page['rows']);
		self::assertSame(DatabaseAdapter::MAX_PAGE_SIZE, $this->factory->connection->reads[0]['maxRows']);
		self::assertNull($page['next'], 'a read without a page key is one page');
	}//end testThePageSizeIsCapped()

	/**
	 * A statement name the source does not declare is refused before any connection opens.
	 *
	 * @return void
	 */
	public function testAnUndeclaredStatementIsRefusedBeforeAConnectionOpens(): void {
		$this->build();
		$this->credentials->expects($this->never())->method('hydrateInjectableCredentials');

		try {
			$this->adapter->read(source: self::source(), statement: 'DROP TABLE zaken');
			self::fail('an undeclared statement must be refused');
		} catch (DatabaseSourceException $exception) {
			self::assertSame('The source declares no statement "DROP TABLE zaken".', $exception->getMessage());
		}

		self::assertSame([], $this->factory->opened);
	}//end testAnUndeclaredStatementIsRefusedBeforeAConnectionOpens()

	/**
	 * A write statement cannot be read, nor a read statement written.
	 *
	 * @return void
	 */
	public function testTheKindMustMatch(): void {
		$this->build();

		$this->expectException(DatabaseSourceException::class);
		$this->expectExceptionMessage('Statement "zaak-sluiten" is a write statement, not a read statement.');
		$this->adapter->read(source: self::source(), statement: 'zaak-sluiten');
	}//end testTheKindMustMatch()

	/**
	 * A write binds only its declared parameters.
	 *
	 * @return void
	 */
	public function testAWriteBindsOnlyItsDeclaredParameters(): void {
		$this->build();
		$this->brokerResolves(password: 'pw');

		$result = $this->adapter->write(source: self::source(), statement: 'zaak-sluiten', parameters: ['id' => '12', 'status' => 'gesloten', 'omschrijving' => "x'; --"]);

		self::assertSame(1, $result['affected']);
		self::assertSame([['sql' => 'UPDATE zaken SET status = :status WHERE id = :id', 'parameters' => ['status' => 'gesloten', 'id' => 12]]], $this->factory->connection->writes);
	}//end testAWriteBindsOnlyItsDeclaredParameters()

	/**
	 * A password stored in the source is refused, and nothing connects.
	 *
	 * @return void
	 */
	public function testAnInlinePasswordIsRefused(): void {
		$this->build();
		$source = self::source(['configuration' => ['authentication' => ['password' => 'plain-text']]]);

		try {
			$this->adapter->read(source: $source, statement: 'zaken-sinds');
			self::fail('an inline password must be refused');
		} catch (DatabaseSourceException $exception) {
			self::assertSame('Store the database password as a credential reference, not in the source.', $exception->getMessage());
		}

		self::assertSame([], $this->factory->opened);
	}//end testAnInlinePasswordIsRefused()

	/**
	 * A broker refusal is reported, and nothing connects.
	 *
	 * @return void
	 */
	public function testABrokerRefusalIsReported(): void {
		$this->build();
		$this->credentials->method('hydrateInjectableCredentials')->willThrowException(new BrokeredCallConfigurationException('the credential is not shared with integriq'));

		$this->expectException(DatabaseSourceException::class);
		$this->expectExceptionMessage('The database password could not be resolved: the credential is not shared with integriq');
		$this->adapter->read(source: self::source(), statement: 'zaken-sinds');
	}//end testABrokerRefusalIsReported()

	/**
	 * A driver whose extension is missing is refused and reported by health.
	 *
	 * @return void
	 */
	public function testAMissingDriverIsRefusedAndReportedUnavailable(): void {
		$this->build(drivers: ['pdo_pgsql']);
		$source = self::source(['database' => ['driver' => 'pdo_sqlsrv', 'host' => 'sql.example.nl', 'dbname' => 'zaken']]);

		try {
			$this->adapter->read(source: $source, statement: 'zaken-sinds');
			self::fail('a missing driver must be refused');
		} catch (DatabaseSourceException $exception) {
			self::assertSame('The PHP extension pdo_sqlsrv is not installed on this server.', $exception->getMessage());
		}

		$health = $this->adapter->health();
		self::assertSame('ok', $health['status']);
		self::assertSame(['pdo_pgsql' => 'available', 'pdo_mysql' => 'unavailable', 'pdo_sqlsrv' => 'unavailable'], $health['drivers']);
		self::assertSame('The PHP extension pdo_mysql, pdo_sqlsrv is not installed, so those databases cannot be connected.', $health['message']);
		self::assertTrue($this->adapter->isEnabled());

		$this->build(drivers: []);
		self::assertSame('unavailable', $this->adapter->health()['status']);
		self::assertFalse($this->adapter->isEnabled());
	}//end testAMissingDriverIsRefusedAndReportedUnavailable()

	/**
	 * Schema discovery lists the tables and columns, and the adapter declares its category contract.
	 *
	 * @return void
	 */
	public function testDiscoveryAndTheCategoryContract(): void {
		$this->build();
		$source = self::source(['configuration' => []]);

		$schema = $this->adapter->discover(source: $source);

		self::assertSame([['name' => 'zaken', 'kind' => 'table', 'columns' => [['name' => 'id', 'type' => 'integer', 'nullable' => false]]]], $schema['tables']);
		self::assertSame('', $this->factory->opened[0]->password, 'no reference, no password');
		self::assertSame('data-infra-database', $this->adapter->getId());
		self::assertSame(['read', 'write', 'schema-discover', 'query'], $this->adapter->getCapabilities());
	}//end testDiscoveryAndTheCategoryContract()

	/**
	 * Only a database source is served, and a repeated statement name is refused.
	 *
	 * @return void
	 */
	public function testOnlyAWellFormedDatabaseSourceIsServed(): void {
		$this->build();

		try {
			$this->adapter->read(source: self::source(['type' => 'api']), statement: 'zaken-sinds');
			self::fail('an api source must be refused');
		} catch (DatabaseSourceException $exception) {
			self::assertSame('This source is not a database source.', $exception->getMessage());
		}

		$this->expectExceptionMessage('The source declares statement "a" twice.');
		$this->adapter->statements(source: ['statements' => [['name' => 'a', 'sql' => 'SELECT 1'], ['name' => 'a', 'sql' => 'SELECT 2']]]);
	}//end testOnlyAWellFormedDatabaseSourceIsServed()
}//end class
