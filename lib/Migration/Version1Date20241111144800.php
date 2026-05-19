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
use OCP\IDBConnection;

/**
 * This migration changes the following:
 * - Renaming of SynchronizationContract sourceId & sourceHash to originId and originHash,
 * creating the new columns and transferring old data to the new fields.
 * - Removal of old indexes related to sourceId and sourceHash
 * - Addition of new indexes for originId and synchronization_id fields
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20241111144800 extends SimpleMigrationStep {

	private IDBConnection $connection;

	public function __construct(IDBConnection $connection) {
		$this->connection = $connection;
	}

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
		$table = $schema->getTable('openconnector_synchronization_contracts');

		// Step 1: Add new columns for 'origin_id' and 'origin_hash'
		if ($table->hasColumn('origin_id') === false) {
			$table->addColumn('origin_id', Types::STRING, [
				'length' => 255,
				'notnull' => true,
			]);
		}
		if ($table->hasColumn('origin_hash') === false) {
			$table->addColumn('origin_hash', Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
		}

		// Step 4: Adjust indexes in preparation for data migration
		if ($table->hasIndex('openconnector_sync_contracts_source_id_index') === true) {
			$table->dropIndex('openconnector_sync_contracts_source_id_index');
		}
		if ($table->hasIndex('openconnector_sync_contracts_origin_id_index') === false) {
			$table->addIndex(['origin_id'], 'openconnector_sync_contracts_origin_id_index');
		}

		if ($table->hasIndex('openconnector_sync_contracts_sync_source_index') === true) {
			$table->dropIndex('openconnector_sync_contracts_sync_source_index');
		}
		if ($table->hasIndex('openconnector_sync_contracts_sync_origin_index') === false) {
			$table->addIndex(['synchronization_id', 'origin_id'], 'openconnector_sync_contracts_sync_origin_index');
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/**
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();
		$table = $schema->getTable('openconnector_synchronization_contracts');

		// Step 2: Copy data from old columns to new columns
		if ($table->hasColumn('origin_id') === true && $table->hasColumn('origin_hash') === true
			&& $table->hasColumn('source_id') === true && $table->hasColumn('source_hash') === true
		) {
			$this->connection->executeQuery("
				UPDATE oc_openconnector_synchronization_contracts
				SET origin_id = source_id, origin_hash = source_hash
				WHERE source_id IS NOT NULL
			");
		}

		if ($table->hasColumn('source_id') === true) {
			$table->dropColumn('source_id');
		}
		if ($table->hasColumn('source_hash') === true) {
			$table->dropColumn('source_hash');
		}
	}
}
