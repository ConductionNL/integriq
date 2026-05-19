<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenConnector\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * This migration changes the following:
 * - Adding 1 new column for the table Consumers: reference
 * - Adding 1 new column for the table Jobs: reference
 * - Adding 1 new column for the table Synchronizations: reference
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20250107163601 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/**
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();

		if ($schema->hasTable(tableName: 'openconnector_jobs') === true) {
			$table = $schema->getTable(tableName: 'openconnector_jobs');
			$table->addColumn('reference', Types::STRING, ['notnull' => false, 'length' => 255]);
		}
		if ($schema->hasTable(tableName: 'openconnector_synchronizations') === true) {
			$table = $schema->getTable(tableName: 'openconnector_synchronizations');
			$table->addColumn('reference', Types::STRING, ['notnull' => false, 'length' => 255]);
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}
}
