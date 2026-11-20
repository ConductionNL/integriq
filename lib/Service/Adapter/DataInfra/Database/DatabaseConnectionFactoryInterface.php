<?php

/**
 * Opens database connections and says which drivers this server can open.
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
 * Opens database connections, and says which drivers this server can open.
 *
 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
 */
interface DatabaseConnectionFactoryInterface {

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
	public function open(DatabaseConnectionSettings $settings): DatabaseConnectionInterface;

	/**
	 * Whether this server has the PHP extension a driver needs.
	 *
	 * @param string $driver The DBAL driver name.
	 *
	 * @return bool True when the extension is loaded.
	 *
	 * @spec openspec/changes/sources-database-adapter/specs/data-infra-connectors/spec.md#requirement-a-relational-database-is-a-source-with-declared-statements-req-dbsf-001
	 */
	public function driverAvailable(string $driver): bool;
}//end interface
