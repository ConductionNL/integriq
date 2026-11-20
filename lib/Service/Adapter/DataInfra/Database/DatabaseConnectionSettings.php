<?php

/**
 * How to reach one database: the settings a source declares, and the password the broker resolved.
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

use SensitiveParameter;

/**
 * How to reach one database, for the length of one adapter call.
 *
 * Built by the adapter from the source's `database` block and the password the
 * credential broker resolved. Never stored.
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 */
final class DatabaseConnectionSettings {

	/**
	 * Constructor.
	 *
	 * @param string   $driver         The DBAL driver: pdo_pgsql, pdo_mysql or pdo_sqlsrv.
	 * @param string   $host           The database host.
	 * @param int|null $port           The port, or null for the driver's default.
	 * @param string   $dbname         The database name.
	 * @param string   $user           The account.
	 * @param string   $password       The account's password, resolved by the broker.
	 * @param int      $timeoutSeconds The connect and statement timeout.
	 * @param string   $charset        The client character set, or '' for the driver's default.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function __construct(
		public readonly string $driver,
		public readonly string $host,
		public readonly ?int $port,
		public readonly string $dbname,
		public readonly string $user,
		#[SensitiveParameter]
		public readonly string $password,
		public readonly int $timeoutSeconds,
		public readonly string $charset = '',
	) {
	}//end __construct()
}//end class
