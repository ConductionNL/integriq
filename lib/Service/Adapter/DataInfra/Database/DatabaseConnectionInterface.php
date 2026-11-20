<?php

/**
 * One open connection to a source's database.
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

/**
 * One open connection to a source's database.
 *
 * The seam between the adapter and Doctrine DBAL: the adapter decides what may
 * run, this runs it. Values are always bound by name; nothing is concatenated
 * into SQL here or in the adapter.
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 */
interface DatabaseConnectionInterface {

	/**
	 * Run a read statement and return at most `$maxRows` rows.
	 *
	 * @param string                $sql        The declared SQL, with named placeholders.
	 * @param array<string,mixed>   $parameters The values, by placeholder name.
	 * @param array<string,string>  $types      Per name: `integer`, `boolean`, `null` or `string`.
	 * @param int                   $maxRows    The row cap.
	 *
	 * @return array<int,array<string,mixed>> The rows.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function fetchRows(string $sql, array $parameters, array $types, int $maxRows): array;

	/**
	 * Run a write statement.
	 *
	 * @param string               $sql        The declared SQL, with named placeholders.
	 * @param array<string,mixed>  $parameters The values, by placeholder name.
	 * @param array<string,string> $types      Per name: `integer`, `boolean`, `null` or `string`.
	 *
	 * @return int The number of rows the database reports affected.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function execute(string $sql, array $parameters, array $types): int;

	/**
	 * The tables and views with their columns.
	 *
	 * @param int $maxTables How many tables and views to describe at most.
	 *
	 * @return array<int,array{name:string,kind:string,columns:array<int,array{name:string,type:string,nullable:bool}>}>
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function describe(int $maxTables): array;

	/**
	 * Close the connection.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function close(): void;
}//end interface
