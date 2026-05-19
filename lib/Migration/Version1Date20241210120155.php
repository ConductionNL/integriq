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
 * - Adding 1 new column for the table Synchronization: currentPage
 * - Adding 1 new column for the table SynchronizationContractLogs: message
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20241210120155 extends SimpleMigrationStep {

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

		// Synchronizations table
		if ($schema->hasTable('openconnector_synchronizations') === true) {
			$table = $schema->getTable('openconnector_synchronizations');

			if ($table->hasColumn('current_page') === false) {
				$table->addColumn('current_page', Types::INTEGER, [
					'notnull' => false,
					'default' => 1
				]);
			}
		}

		// SynchronizationContractLogs table
		if ($schema->hasTable('openconnector_synchronization_contract_logs') === true) {
			$table = $schema->getTable('openconnector_synchronization_contract_logs');

			if ($table->hasColumn('message') === false) {
				$table->addColumn('message', Types::STRING, [
					'length' => 255,
					'notnull' => false,
				])->setDefault(null);
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
