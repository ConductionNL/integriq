<?php

/**
 * Relational database data-infra adapter.
 *
 * A PostgreSQL, MySQL, MariaDB or SQL Server database as an integriq source,
 * read and written only through the statements an administrator declares on
 * the source.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Adapter\DataInfra;

use OCA\Integriq\Service\Adapter\AbstractCategoryAdapterProvider;
use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseConnectionFactoryInterface;
use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseConnectionSettings;
use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseSourceException;
use OCA\Integriq\Service\Adapter\DataInfra\Database\DatabaseStatement;
use OCA\Integriq\Service\BrokeredCallConfigurationException;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use OCP\IAppConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The `data-infra-database` adapter.
 *
 * The source carries a `database` block (`driver`, `host`, `port`, `dbname`,
 * `user`, `charset`, `timeout`) and its `statements`. The password is a
 * credential reference under `configuration.authentication.password`, resolved
 * through the credential broker for the length of one call (design D1). Every
 * call opens one connection and closes it.
 *
 * Nothing runs that the source does not declare: a statement name the source
 * does not carry is refused before a connection is opened (design D2).
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 */
class DatabaseAdapter extends AbstractCategoryAdapterProvider {

	/**
	 * The source `type` this adapter serves.
	 */
	public const SOURCE_TYPE = 'database';

	/**
	 * The drivers this adapter connects with.
	 */
	public const DRIVERS = ['pdo_pgsql', 'pdo_mysql', 'pdo_sqlsrv'];

	/**
	 * The statement timeout when neither the statement nor the source sets one.
	 */
	public const DEFAULT_TIMEOUT = 30;

	/**
	 * The longest timeout a statement may ask for.
	 */
	public const MAX_TIMEOUT = 300;

	/**
	 * The rows per page when neither the call nor the statement sets one.
	 */
	public const DEFAULT_PAGE_SIZE = 500;

	/**
	 * The most rows one page may return.
	 */
	public const MAX_PAGE_SIZE = 5000;

	/**
	 * The most tables and views schema discovery describes.
	 */
	public const MAX_DESCRIBED_TABLES = 500;

	/**
	 * Constructor.
	 *
	 * @param CredentialBrokerService            $credentialBroker OpenRegister's credential broker.
	 * @param IAppConfig                         $appConfig        App config.
	 * @param LoggerInterface                    $logger           Logger.
	 * @param IL10N                              $l10n             Translator for the label.
	 * @param DatabaseConnectionFactoryInterface $connections      Opens the connections.
	 * @param BrokeredCallService                $credentials      Resolves the source's credential reference.
	 */
	public function __construct(
		CredentialBrokerService $credentialBroker,
		IAppConfig $appConfig,
		LoggerInterface $logger,
		private readonly IL10N $l10n,
		private readonly DatabaseConnectionFactoryInterface $connections,
		private readonly BrokeredCallService $credentials,
	) {
		parent::__construct(credentialBroker: $credentialBroker, appConfig: $appConfig, logger: $logger);
	}//end __construct()

	/**
	 * The adapter's id in the integration registry.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function getId(): string {
		return 'data-infra-database';
	}//end getId()

	/**
	 * The adapter's name in the store.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function getLabel(): string {
		return $this->l10n->t('Relational database (PostgreSQL, MySQL, MariaDB, SQL Server)');
	}//end getLabel()

	/**
	 * The adapter's icon.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function getIcon(): string {
		return 'DatabaseOutline';
	}//end getIcon()

	/**
	 * The app the adapter needs besides integriq: none.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	/**
	 * What the adapter can do, in the category's vocabulary.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function getCapabilities(): array {
		return ['read', 'write', 'schema-discover', 'query'];
	}//end getCapabilities()

	/**
	 * Credentials are per source, so the adapter is usable once this server
	 * has a driver for it.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function isEnabled(): bool {
		return $this->availableDrivers() !== [];
	}//end isEnabled()

	/**
	 * Which drivers this server can use; a missing extension is named.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function health(): array {
		$drivers = [];
		$missing = [];
		foreach (self::DRIVERS as $driver) {
			$drivers[$driver] = 'available';
			if ($this->connections->driverAvailable(driver: $driver) === false) {
				$drivers[$driver] = 'unavailable';
				$missing[] = $driver;
			}
		}

		$message = null;
		if ($missing !== []) {
			$message = sprintf('The PHP extension %s is not installed, so those databases cannot be connected.', implode(', ', $missing));
		}

		$status = 'ok';
		if ($missing === self::DRIVERS) {
			$status = 'unavailable';
		}

		return [
			'status' => $status,
			'authStatus' => 'per-source',
			'message' => $message,
			'drivers' => $drivers,
		];
	}//end health()

	/**
	 * The statements a source declares, checked.
	 *
	 * @param array<string,mixed> $source The source object.
	 *
	 * @return array<string,DatabaseStatement> By name.
	 *
	 * @throws DatabaseSourceException When a declaration is unusable or a name repeats.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 *
	 * DatabaseStatement::fromDeclaration() is the statement's named constructor.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function statements(array $source): array {
		$declared = ($source['statements'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		$statements = [];
		foreach ($declared as $declaration) {
			if (is_array($declaration) === false) {
				continue;
			}

			$statement = DatabaseStatement::fromDeclaration(declaration: $declaration);
			if (isset($statements[$statement->name]) === true) {
				throw new DatabaseSourceException(message: sprintf('The source declares statement "%s" twice.', $statement->name));
			}

			$statements[$statement->name] = $statement;
		}

		return $statements;
	}//end statements()

	/**
	 * Read one page of a declared read statement.
	 *
	 * @param array<string,mixed> $source     The source object.
	 * @param string              $statement  The statement's name.
	 * @param array<string,mixed> $parameters The values, by parameter name.
	 * @param string|int|null     $after      The last key of the previous page, or null for the first.
	 * @param int|null            $limit      The page size, capped.
	 *
	 * @return array{rows:array<int,array<string,mixed>>,next:string|int|null,pageSize:int,durationMs:int}
	 *         The rows, the key the next page reads after (null when this was the last page),
	 *         the page size used and how long the statement took.
	 *
	 * @throws DatabaseSourceException When the statement is not declared, not a read, or the database fails it.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-synchronization-reads-from-a-database-page-by-page-req-dbsf-002
	 */
	public function read(array $source, string $statement, array $parameters = [], string|int|null $after = null, ?int $limit = null): array {
		$declared = $this->declared(source: $source, name: $statement, kind: DatabaseStatement::KIND_READ);
		$pageSize = $this->pageSize(statement: $declared, requested: $limit);
		[$values, $types] = $declared->bind(values: $parameters, after: $after, limit: $pageSize);

		$connection = $this->connections->open(settings: $this->settings(source: $source, statement: $declared));
		$started = hrtime(true);
		try {
			$rows = $connection->fetchRows(sql: $declared->sql, parameters: $values, types: $types, maxRows: $pageSize);
		} finally {
			$connection->close();
		}

		$durationMs = (int) round((hrtime(true) - $started) / 1e6);

		$next = null;
		if ($declared->pageKey !== null && count($rows) >= $pageSize) {
			$last = $rows[(count($rows) - 1)];
			if (array_key_exists($declared->pageKey, $last) === false) {
				throw new DatabaseSourceException(
					message: sprintf('Statement "%s" pages on %s, but its rows carry no %s column.', $declared->name, $declared->pageKey, $declared->pageKey)
				);
			}

			$next = $last[$declared->pageKey];
			if (is_int($next) === false) {
				$next = (string) $next;
			}
		}

		return ['rows' => $rows, 'next' => $next, 'pageSize' => $pageSize, 'durationMs' => $durationMs];
	}//end read()

	/**
	 * Run a declared write statement.
	 *
	 * @param array<string,mixed> $source     The source object.
	 * @param string              $statement  The statement's name.
	 * @param array<string,mixed> $parameters The values, by parameter name; others are ignored.
	 *
	 * @return array{affected:int,durationMs:int}
	 *
	 * @throws DatabaseSourceException When the statement is not declared, not a write, or the database fails it.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function write(array $source, string $statement, array $parameters = []): array {
		$declared = $this->declared(source: $source, name: $statement, kind: DatabaseStatement::KIND_WRITE);
		[$values, $types] = $declared->bind(values: $parameters, after: null, limit: 0);

		$connection = $this->connections->open(settings: $this->settings(source: $source, statement: $declared));
		$started = hrtime(true);
		try {
			$affected = $connection->execute(sql: $declared->sql, parameters: $values, types: $types);
		} finally {
			$connection->close();
		}

		return ['affected' => $affected, 'durationMs' => (int) round((hrtime(true) - $started) / 1e6)];
	}//end write()

	/**
	 * The tables and views of the source's database, with their columns.
	 *
	 * @param array<string,mixed> $source The source object.
	 *
	 * @return array{tables:array<int,array<string,mixed>>,durationMs:int}
	 *
	 * @throws DatabaseSourceException When the database cannot be reached or read.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function discover(array $source): array {
		$connection = $this->connections->open(settings: $this->settings(source: $source, statement: null));
		$started = hrtime(true);
		try {
			$tables = $connection->describe(maxTables: self::MAX_DESCRIBED_TABLES);
		} finally {
			$connection->close();
		}

		return ['tables' => $tables, 'durationMs' => (int) round((hrtime(true) - $started) / 1e6)];
	}//end discover()

	/**
	 * The declared statement of that name and kind; refused before any connection.
	 *
	 * @param array<string,mixed> $source The source object.
	 * @param string              $name   The statement's name.
	 * @param string              $kind   The kind the call needs.
	 *
	 * @return DatabaseStatement
	 *
	 * @throws DatabaseSourceException When the source is no database source or declares no such statement.
	 */
	private function declared(array $source, string $name, string $kind): DatabaseStatement {
		if ((string) ($source['type'] ?? '') !== self::SOURCE_TYPE) {
			throw new DatabaseSourceException(message: 'This source is not a database source.');
		}

		$statements = $this->statements(source: $source);
		if (isset($statements[$name]) === false) {
			throw new DatabaseSourceException(message: sprintf('The source declares no statement "%s".', $name));
		}

		if ($statements[$name]->kind !== $kind) {
			throw new DatabaseSourceException(
				message: sprintf('Statement "%s" is a %s statement, not a %s statement.', $name, $statements[$name]->kind, $kind)
			);
		}

		return $statements[$name];
	}//end declared()

	/**
	 * The page size: the call's, else the statement's, else the default; capped.
	 *
	 * @param DatabaseStatement $statement The statement.
	 * @param int|null          $requested The call's page size.
	 *
	 * @return int
	 */
	private function pageSize(DatabaseStatement $statement, ?int $requested): int {
		$size = ($requested ?? $statement->pageSize ?? self::DEFAULT_PAGE_SIZE);

		return max(1, min($size, self::MAX_PAGE_SIZE));
	}//end pageSize()

	/**
	 * How to reach the source's database, with the password from the broker.
	 *
	 * @param array<string,mixed>    $source    The source object.
	 * @param DatabaseStatement|null $statement The statement, for its own timeout.
	 *
	 * @return DatabaseConnectionSettings
	 *
	 * @throws DatabaseSourceException When a setting is missing, the driver is unavailable or the password is inline.
	 */
	private function settings(array $source, ?DatabaseStatement $statement): DatabaseConnectionSettings {
		$database = ($source['database'] ?? []);
		if (is_array($database) === false) {
			$database = [];
		}

		$driver = (string) ($database['driver'] ?? '');
		if (in_array($driver, self::DRIVERS, true) === false) {
			throw new DatabaseSourceException(message: sprintf('The source names no supported driver (%s).', implode(', ', self::DRIVERS)));
		}

		if ($this->connections->driverAvailable(driver: $driver) === false) {
			throw new DatabaseSourceException(message: sprintf('The PHP extension %s is not installed on this server.', $driver));
		}

		$host = trim((string) ($database['host'] ?? ''));
		$dbname = trim((string) ($database['dbname'] ?? ''));
		if ($host === '' || $dbname === '') {
			throw new DatabaseSourceException(message: 'The source needs a database host and a database name.');
		}

		return new DatabaseConnectionSettings(
			driver: $driver,
			host: $host,
			port: self::port(database: $database),
			dbname: $dbname,
			user: trim((string) ($database['user'] ?? '')),
			password: $this->password(source: $source),
			timeoutSeconds: self::timeout(database: $database, statement: $statement),
			charset: trim((string) ($database['charset'] ?? ''))
		);
	}//end settings()

	/**
	 * The configured port, or null to use the driver's default.
	 *
	 * @param array<string,mixed> $database The source's database settings.
	 *
	 * @return int|null
	 */
	private static function port(array $database): ?int {
		if (isset($database['port']) === true && filter_var($database['port'], FILTER_VALIDATE_INT) !== false) {
			return (int) $database['port'];
		}

		return null;
	}//end port()

	/**
	 * The timeout in seconds: the statement's own, else the source's, else the default, capped.
	 *
	 * @param array<string,mixed>    $database  The source's database settings.
	 * @param DatabaseStatement|null $statement The statement, for its own timeout.
	 *
	 * @return int
	 */
	private static function timeout(array $database, ?DatabaseStatement $statement): int {
		$timeout = filter_var(($database['timeout'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if ($statement?->timeoutSeconds !== null) {
			$timeout = $statement->timeoutSeconds;
		}

		if (is_int($timeout) === false) {
			$timeout = self::DEFAULT_TIMEOUT;
		}

		return min($timeout, self::MAX_TIMEOUT);
	}//end timeout()

	/**
	 * The account's password, resolved from its credential reference.
	 *
	 * A password stored inline is refused: it would sit in the source object in
	 * plain text. No reference means no password, for an account that needs none.
	 *
	 * @param array<string,mixed> $source The source object.
	 *
	 * @return string
	 *
	 * @throws DatabaseSourceException When the password is inline or the broker refuses it.
	 */
	private function password(array $source): string {
		$password = ($source['configuration']['authentication']['password'] ?? null);
		if ($password === null || $password === '') {
			return '';
		}

		if (is_array($password) === false) {
			throw new DatabaseSourceException(message: 'Store the database password as a credential reference, not in the source.');
		}

		try {
			$hydrated = $this->credentials->hydrateInjectableCredentials(sourceData: $source);
		} catch (BrokeredCallConfigurationException $exception) {
			throw new DatabaseSourceException(message: 'The database password could not be resolved: ' . $exception->getMessage());
		}

		$resolved = ($hydrated['configuration']['authentication']['password'] ?? null);
		if (is_string($resolved) === false) {
			throw new DatabaseSourceException(message: 'The database password could not be resolved.');
		}

		return $resolved;
	}//end password()

	/**
	 * The drivers this server has an extension for.
	 *
	 * @return array<int,string>
	 */
	private function availableDrivers(): array {
		return array_values(array_filter(self::DRIVERS, fn (string $driver): bool => $this->connections->driverAvailable(driver: $driver)));
	}//end availableDrivers()
}//end class
