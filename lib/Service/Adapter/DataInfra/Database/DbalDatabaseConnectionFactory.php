<?php

/**
 * Doctrine DBAL behind the database seam: opens connections with a timeout.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Adapter\DataInfra\Database
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

namespace OCA\Integriq\Service\Adapter\DataInfra\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PDO;
use Throwable;

/**
 * Opens connections through Doctrine DBAL, the copy Nextcloud ships.
 *
 * DBAL gives one API over PostgreSQL, MySQL, MariaDB and SQL Server, a schema
 * manager for discovery and platform-aware binding (design D1). The timeout is
 * applied to connecting and, per platform, to every statement.
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 */
class DbalDatabaseConnectionFactory implements DatabaseConnectionFactoryInterface {

	/**
	 * The PHP extension each driver needs.
	 */
	private const EXTENSIONS = [
		'pdo_pgsql' => 'pdo_pgsql',
		'pdo_mysql' => 'pdo_mysql',
		'pdo_sqlsrv' => 'pdo_sqlsrv',
	];

	/**
	 * Whether this server has the PHP extension a driver needs.
	 *
	 * @param string $driver The DBAL driver name.
	 *
	 * @return bool True when the extension is loaded.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function driverAvailable(string $driver): bool {
		$extension = (self::EXTENSIONS[$driver] ?? null);

		return $extension !== null && extension_loaded($extension) === true;
	}//end driverAvailable()

	/**
	 * Open a connection with the timeout applied to connecting and to every statement.
	 *
	 * @param DatabaseConnectionSettings $settings Where and as whom.
	 *
	 * @return DatabaseConnectionInterface The open connection; the caller closes it.
	 *
	 * @throws DatabaseSourceException When the database cannot be reached or refuses the account.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function open(DatabaseConnectionSettings $settings): DatabaseConnectionInterface {
		$driverOptions = [PDO::ATTR_TIMEOUT => $settings->timeoutSeconds];
		if ($settings->driver === 'pdo_sqlsrv' && defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT') === true) {
			$driverOptions[constant('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')] = $settings->timeoutSeconds;
		}

		$params = [
			'driver' => $settings->driver,
			'host' => $settings->host,
			'dbname' => $settings->dbname,
			'user' => $settings->user,
			'password' => $settings->password,
			'driverOptions' => $driverOptions,
		];
		if ($settings->port !== null) {
			$params['port'] = $settings->port;
		}

		if ($settings->charset !== '') {
			$params['charset'] = $settings->charset;
		}

		try {
			$connection = DriverManager::getConnection($params);
			// Connects now, on DBAL 3 and 4 alike (connect() is protected on 4), so an
			// unreachable host or a refused account is reported here, not mid-statement.
			$connection->getNativeConnection();
		} catch (Throwable $exception) {
			throw new DatabaseSourceException(message: 
				sprintf(
					'The database at %s could not be reached: %s',
					$settings->host,
					self::withoutPassword(message: $exception->getMessage(), password: $settings->password)
				)
			);
		}

		$this->applyStatementTimeout(connection: $connection, settings: $settings);

		return new DbalDatabaseConnection(connection: $connection);
	}//end open()

	/**
	 * Make the server stop a statement that runs longer than the timeout.
	 *
	 * PostgreSQL takes `statement_timeout` in milliseconds; MySQL takes
	 * `max_execution_time` (milliseconds, SELECT only) and MariaDB
	 * `max_statement_time` (seconds). SQL Server's timeout is a driver option,
	 * set when connecting. A server that refuses the setting keeps running the
	 * statement under its own limits; the row cap still applies.
	 *
	 * @param Connection                 $connection The open connection.
	 * @param DatabaseConnectionSettings $settings   The settings.
	 *
	 * @return void
	 */
	private function applyStatementTimeout(Connection $connection, DatabaseConnectionSettings $settings): void {
		$milliseconds = ($settings->timeoutSeconds * 1000);
		$statements = match ($settings->driver) {
			'pdo_pgsql' => ['SET statement_timeout = ' . $milliseconds],
			'pdo_mysql' => ['SET SESSION max_execution_time = ' . $milliseconds, 'SET SESSION max_statement_time = ' . $settings->timeoutSeconds],
			default => [],
		};

		foreach ($statements as $statement) {
			try {
				$connection->executeStatement($statement);
			} catch (Throwable) {
				// MySQL knows only the first, MariaDB only the second.
				continue;
			}
		}
	}//end applyStatementTimeout()

	/**
	 * A driver message with the password taken out, should a driver echo it.
	 *
	 * @param string $message  The message.
	 * @param string $password The password.
	 *
	 * @return string
	 */
	private static function withoutPassword(string $message, #[\SensitiveParameter] string $password): string {
		if ($password === '') {
			return $message;
		}

		return str_replace($password, '***', $message);
	}//end withoutPassword()
}//end class
