<?php

/**
 * Add current_page and message columns.
 *
 * This migration changes the following:
 * - Adding 1 new column for the table Synchronization: currentPage.
 * - Adding 1 new column for the table SynchronizationContractLogs: message.
 *
 * @category Migration
 * @package  OCA\Integriq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the current_page and message columns to the synchronization tables.
 */
class Version1Date20241210120155 extends SimpleMigrationStep {
	/**
	 * Pre-schema change callback.
	 *
	 * @param IOutput $output Migration output interface.
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return void
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}//end preSchemaChange()

	/**
	 * Adds the current_page and message columns when missing.
	 *
	 * @param IOutput $output Migration output interface.
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The modified schema wrapper.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		// Synchronizations table.
		if ($schema->hasTable('openconnector_synchronizations') === true) {
			$table = $schema->getTable('openconnector_synchronizations');

			if ($table->hasColumn('current_page') === false) {
				$table->addColumn(
					'current_page',
					Types::INTEGER,
					[
						'notnull' => false,
						'default' => 1,
					]
				);
			}
		}

		// SynchronizationContractLogs table.
		if ($schema->hasTable('openconnector_synchronization_contract_logs') === true) {
			$table = $schema->getTable('openconnector_synchronization_contract_logs');

			if ($table->hasColumn('message') === false) {
				$table->addColumn(
					'message',
					Types::STRING,
					[
						'length' => 255,
						'notnull' => false,
					]
				)->setDefault(null);
			}
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Post-schema change callback.
	 *
	 * @param IOutput $output Migration output interface.
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema closure.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return void
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
	}//end postSchemaChange()
}//end class
