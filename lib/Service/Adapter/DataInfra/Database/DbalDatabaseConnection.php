<?php

/**
 * One open DBAL connection behind the database seam.
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
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Throwable;

/**
 * One open DBAL connection: runs declared statements with bound values, and
 * describes the tables and views.
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 */
class DbalDatabaseConnection implements DatabaseConnectionInterface {

	/**
	 * The schemas of the database's own catalogue, left out of discovery.
	 */
	private const SYSTEM_SCHEMAS = ['information_schema', 'pg_catalog', 'pg_toast', 'sys', 'mysql', 'performance_schema'];

	/**
	 * Constructor.
	 *
	 * @param Connection $connection The open connection.
	 */
	public function __construct(
		private readonly Connection $connection,
	) {
	}//end __construct()

	/**
	 * Run a read statement and return at most `$maxRows` rows.
	 *
	 * @param string               $sql        The declared SQL, with named placeholders.
	 * @param array<string,mixed>  $parameters The values, by placeholder name.
	 * @param array<string,string> $types      Per name: integer, boolean, null or string.
	 * @param int                  $maxRows    The row cap.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 *
	 * @throws DatabaseSourceException When the database refuses the statement.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function fetchRows(string $sql, array $parameters, array $types, int $maxRows): array {
		try {
			$result = $this->connection->executeQuery($sql, $parameters, self::dbalTypes(types: $types));
			$rows = [];
			$read = 0;
			while ($read < $maxRows) {
				$row = $result->fetchAssociative();
				if ($row === false) {
					break;
				}

				$rows[] = $row;
				$read++;
			}

			$result->free();
		} catch (Throwable $exception) {
			throw new DatabaseSourceException(message: 'The database refused the statement: ' . $exception->getMessage());
		}

		return $rows;
	}//end fetchRows()

	/**
	 * Run a write statement.
	 *
	 * @param string               $sql        The declared SQL, with named placeholders.
	 * @param array<string,mixed>  $parameters The values, by placeholder name.
	 * @param array<string,string> $types      Per name: integer, boolean, null or string.
	 *
	 * @return int The number of rows the database reports affected.
	 *
	 * @throws DatabaseSourceException When the database refuses the statement.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function execute(string $sql, array $parameters, array $types): int {
		try {
			return (int) $this->connection->executeStatement($sql, $parameters, self::dbalTypes(types: $types));
		} catch (Throwable $exception) {
			throw new DatabaseSourceException(message: 'The database refused the statement: ' . $exception->getMessage());
		}
	}//end execute()

	/**
	 * The tables and views with their columns.
	 *
	 * @param int $maxTables How many tables and views to describe at most.
	 *
	 * @return array<int,array{name:string,kind:string,columns:array<int,array{name:string,type:string,nullable:bool}>}>
	 *
	 * @throws DatabaseSourceException When the schema cannot be read.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function describe(int $maxTables): array {
		try {
			$manager = $this->connection->createSchemaManager();
			$names = [];
			foreach ($manager->listTableNames() as $table) {
				$names[(string) $table] = 'table';
			}

			foreach ($manager->listViews() as $view) {
				$name = (string) $view->getName();
				if (self::isSystemObject(name: $name) === false) {
					$names[$name] = 'view';
				}
			}

			ksort($names);
			$described = [];
			foreach (array_slice($names, 0, $maxTables, true) as $name => $kind) {
				$columns = [];
				foreach ($manager->listTableColumns((string) $name) as $column) {
					$columns[] = [
						'name' => $column->getName(),
						'type' => self::typeName(type: $column->getType()),
						'nullable' => ($column->getNotnull() === false),
					];
				}

				if ($columns === [] && $kind === 'view') {
					$columns = $this->viewColumns(name: (string) $name);
				}

				$described[] = ['name' => (string) $name, 'kind' => $kind, 'columns' => $columns];
			}
		} catch (Throwable $exception) {
			throw new DatabaseSourceException(message: 'The database schema could not be read: ' . $exception->getMessage());
		}//end try

		return $described;
	}//end describe()

	/**
	 * Close the connection.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function close(): void {
		$this->connection->close();
	}//end close()

	/**
	 * A view's columns, read from an empty select where the schema manager lists
	 * none (PostgreSQL's column listing covers tables only). Their types are not
	 * known this way.
	 *
	 * @param string $name The view, as the schema manager names it.
	 *
	 * @return array<int,array{name:string,type:string,nullable:bool}>
	 */
	private function viewColumns(string $name): array {
		try {
			$quoted = implode('.', array_map(fn (string $part): string => $this->connection->quoteIdentifier($part), explode('.', $name)));
			$result = $this->connection->executeQuery('SELECT * FROM ' . $quoted . ' WHERE 1 = 0');
			$columns = [];
			for ($index = 0; $index < $result->columnCount(); $index++) {
				$columns[] = ['name' => $result->getColumnName($index), 'type' => 'unknown', 'nullable' => true];
			}

			$result->free();
		} catch (Throwable) {
			return [];
		}

		return $columns;
	}//end viewColumns()

	/**
	 * Whether a name belongs to the database's own catalogue, such as
	 * PostgreSQL's information_schema views, which DBAL lists with the rest.
	 *
	 * @param string $name A table or view name, possibly schema-qualified.
	 *
	 * @return bool
	 */
	private static function isSystemObject(string $name): bool {
		$schema = strtolower((string) strstr($name, '.', true));

		return in_array($schema, self::SYSTEM_SCHEMAS, true);
	}//end isSystemObject()

	/**
	 * DBAL's binding types for the seam's type names.
	 *
	 * @param array<string,string> $types Per name: integer, boolean, null or string.
	 *
	 * @return array<string,ParameterType|int>
	 */
	private static function dbalTypes(array $types): array {
		$mapped = [];
		foreach ($types as $name => $type) {
			$mapped[$name] = match ($type) {
				'integer' => ParameterType::INTEGER,
				'boolean' => ParameterType::BOOLEAN,
				'null' => ParameterType::NULL,
				default => ParameterType::STRING,
			};
		}

		return $mapped;
	}//end dbalTypes()

	/**
	 * A column type's name, on DBAL 3 and 4 alike.
	 *
	 * @param Type $type The column type.
	 *
	 * @return string
	 */
	private static function typeName(Type $type): string {
		try {
			return Type::getTypeRegistry()->lookupName($type);
		} catch (Throwable) {
			$class = get_class($type);
			$short = substr($class, (int) strrpos($class, '\\') + 1);

			return strtolower((string) preg_replace('/Type$/', '', $short));
		}
	}//end typeName()
}//end class
