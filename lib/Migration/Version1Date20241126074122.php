<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenConnector\Migration;

use Closure;
use OCA\OpenRegister\Db\Schema;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds two columns to the Synchronizations table:
 * - conditions for json logic
 * - follow_ups for follow up synchronizations
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20241126074122 extends SimpleMigrationStep {

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
		if ($schema->hasTable(tableName: 'openconnector_synchronizations') === true) {
			$table = $schema->getTable(tableName: 'openconnector_synchronizations');
			if ($table->hasColumn(name: 'conditions') === false) {
				$table->addColumn(name: 'conditions', typeName: Types::JSON)
					->setDefault(default: '{}')
					->setNotnull(notnull:false);
			}
			if ($table->hasColumn(name: 'follow_ups') === false) {

				$table->addColumn(name: 'follow_ups', typeName: Types::JSON)
					->setDefault(default: '{}')
					->setNotnull(notnull:false);
			}
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
