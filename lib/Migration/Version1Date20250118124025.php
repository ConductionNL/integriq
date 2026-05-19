<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\OpenConnector\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * FIXME Auto-generated migration step: Please modify to your needs!
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20250118124025 extends SimpleMigrationStep {

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

		if (!$schema->hasTable('openconnector_synchronization_logs')) {
			$table = $schema->createTable('openconnector_synchronization_logs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('message', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('synchronization_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('result', Types::JSON, ['notnull' => false]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('session_id', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('test', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('force', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('execution_time', Types::INTEGER, ['notnull' => true, 'default' => 3600]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
			$table->addColumn('expires', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['uuid'], 'openconnector_sync_logs_uuid_index');
			$table->addIndex(['synchronization_id'], 'openconnector_sync_logs_sync_id_index');
			$table->addIndex(['user_id'], 'openconnector_sync_logs_user_id_index');
			$table->addIndex(['created'], 'openconnector_sync_logs_created_index');
		}

		if ($schema->hasTable(tableName: 'openconnector_synchronization_contracts') === true) {
			$table = $schema->getTable(tableName: 'openconnector_synchronization_contracts');
			$table->addColumn('target_last_action', Types::STRING, ['notnull' => false, 'length' => 6]); // 6 chars is enough for 'create', 'update', 'delete'
		}

		if ($schema->hasTable(tableName: 'openconnector_synchronization_contract_logs') === true) {
			$table = $schema->getTable(tableName: 'openconnector_synchronization_contract_logs'); 
			$table->addColumn('synchronization_log_id', Types::STRING, ['notnull' => false, 'length' => 36]); // synchronization_log_id
			$table->addColumn('target_result', Types::STRING, ['notnull' => false, 'length' => 6]); // target_result
			$table->addColumn('test', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('force', Types::BOOLEAN, ['notnull' => true, 'default' => false]);

			$table->addIndex(['synchronization_log_id'], 'openconnector_sync_logs_sync_index');
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
