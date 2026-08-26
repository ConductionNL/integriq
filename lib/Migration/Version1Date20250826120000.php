<?php

/**
 * Log size columns migration.
 *
 * Migration step to add size columns to all log tables for tracking log entry sizes.
 * This helps with storage management and automated cleanup based on log sizes.
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
 * Migration step to add size columns to log tables
 *
 * This migration adds a 'size' column to all log tables to track the byte size
 * of each log entry. This enables better storage management and cleanup policies
 * based on log sizes rather than just age.
 *
 * Tables affected:
 * - openconnector_call_logs
 * - openconnector_job_logs
 * - openconnector_synchronization_logs
 * - openconnector_synchronization_contract_logs
 *
 * @psalm-api
 * @package   OCA\Integriq\Migration
 * @category  Migration
 * @author    Integriq Team
 * @copyright 2025 Integriq
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   1.0.0
 * @link      https://github.com/ConductionNL/integriq
 */
class Version1Date20250826120000 extends SimpleMigrationStep {
	/**
	 * Pre-schema change callback
	 *
	 * @param IOutput $output Migration output interface
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
	 * @param array<string, mixed> $options Migration options
	 *
	 * @return void
	 *
	 * @psalm-param IOutput $output
	 * @psalm-param Closure(): ISchemaWrapper $schemaClosure
	 * @psalm-param array<string, mixed> $options
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// No pre-schema changes needed.
	}//end preSchemaChange()

	/**
	 * Main schema change callback
	 *
	 * This method adds the 'size' column to all log tables. The size column
	 * stores the byte size of each log entry, calculated from the serialized
	 * object representation.
	 *
	 * @param IOutput $output Migration output interface
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
	 * @param array<string, mixed> $options Migration options
	 *
	 * @return ISchemaWrapper|null The modified schema wrapper
	 *
	 * @psalm-param    IOutput $output
	 * @psalm-param    Closure(): ISchemaWrapper $schemaClosure
	 * @psalm-param    array<string, mixed> $options
	 * @psalm-return   ISchemaWrapper|null
	 * @phpstan-param  IOutput $output
	 * @phpstan-param  Closure(): ISchemaWrapper $schemaClosure
	 * @phpstan-param  array<string, mixed> $options
	 * @phpstan-return ISchemaWrapper|null
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		// List of log tables that need the size column.
		$logTables = [
			'openconnector_call_logs',
			'openconnector_job_logs',
			'openconnector_synchronization_logs',
			'openconnector_synchronization_contract_logs',
		];

		$tablesUpdated = 0;

		// Add size column to each log table.
		foreach ($logTables as $tableName) {
			if ($schema->hasTable($tableName) === false) {
				$output->warning("Table {$tableName} not found, skipping");
				continue;
			}

			$table = $schema->getTable($tableName);

			// Check if the size column already exists.
			if ($table->hasColumn('size') === true) {
				$output->info("'size' column already exists in {$tableName} table, skipping");
				continue;
			}

			// Add the size column with default value of 4096 bytes (4KB).
			$table->addColumn(
				'size',
				Types::INTEGER,
				[
					'notnull' => true,
					'default' => 4096,
					'comment' => 'Size of the log entry in bytes',
				]
			);

			$tablesUpdated++;
			$output->info("Added 'size' column to {$tableName} table");
		}//end foreach

		if ($tablesUpdated > 0) {
			$output->info("Successfully added 'size' column to {$tablesUpdated} log tables");
		}

		if ($tablesUpdated === 0) {
			$output->info('No tables were modified - all size columns already exist');
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Post-schema change callback
	 *
	 * After adding the size columns, this method could be used to populate
	 * the size values for existing log entries. Currently, it just outputs
	 * completion information.
	 *
	 * @param IOutput $output Migration output interface
	 * @param Closure(): ISchemaWrapper $schemaClosure Schema closure
	 * @param array<string, mixed> $options Migration options
	 *
	 * @return void
	 *
	 * @psalm-param IOutput $output
	 * @psalm-param Closure(): ISchemaWrapper $schemaClosure
	 * @psalm-param array<string, mixed> $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$output->info('Log size columns migration completed successfully');
		$output->info('All new log entries will automatically calculate and store their size');
		$output->info('Existing log entries will use the default size value (4096 bytes) until updated');
	}//end postSchemaChange()
}//end class
