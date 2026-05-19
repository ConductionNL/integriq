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
 * FIXME Auto-generated migration step: Please modify to your needs!
 *
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 */
class Version0Date20240826193657 extends SimpleMigrationStep {

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

		if (!$schema->hasTable('openconnector_endpoints')) {
			$table = $schema->createTable('openconnector_endpoints');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$table->addColumn('reference', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('version', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
			$table->addColumn('endpoint', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('endpoint_array', Types::JSON, ['notnull' => false]);
			$table->addColumn('endpoint_regex', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('method', Types::STRING, ['notnull' => true, 'length' => 10, 'default' => '']);
			$table->addColumn('target_type', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('target_id', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '']);
			$table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['uuid'], 'openconnector_endpoints_uuid_index');
			$table->addIndex(['endpoint'], 'openconnector_endpoints_endpoint_index');
			$table->addIndex(['endpoint_regex'], 'openconnector_endpoints_endpoint_regex_index');
		}

		if (!$schema->hasTable('openconnector_jobs')) {
			$table = $schema->createTable('openconnector_jobs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('version', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$table->addColumn('job_class', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('arguments', Types::TEXT, ['notnull' => false]);
			$table->addColumn('interval', Types::INTEGER, ['notnull' => true, 'default' => 3600]);
			$table->addColumn('execution_time', Types::INTEGER, ['notnull' => true, 'default' => 3600]);
			$table->addColumn('time_sensitive', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->addColumn('allow_parallel_runs', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('is_enabled', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->addColumn('single_run', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('schedule_after', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('job_list_id', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('last_run', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('next_run', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('log_retention', Types::INTEGER, ['notnull' => true, 'default' => 3600]);
			$table->addColumn('error_retention', Types::INTEGER, ['notnull' => true, 'default' => 86400]);
			$table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['uuid'], 'openconnector_jobs_uuid_index');
		}

		if (!$schema->hasTable('openconnector_mappings')) {
			$table = $schema->createTable('openconnector_mappings');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('version', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
			$table->addColumn('reference', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('name', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$table->addColumn('mapping', Types::TEXT, ['notnull' => false]);
			$table->addColumn('unset', Types::TEXT, ['notnull' => false]);
			$table->addColumn('cast', Types::TEXT, ['notnull' => false]);
			$table->addColumn('pass_through', Types::BOOLEAN, ['notnull' => false]);
			$table->addColumn('date_created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
			$table->addColumn('date_modified', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['uuid'], 'openconnector_mappings_uuid_index');
		}

		if (!$schema->hasTable('openconnector_sources')) {
			$table = $schema->createTable('openconnector_sources');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('version', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			$table->addColumn('reference', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('location', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('is_enabled', Types::BOOLEAN, ['notnull' => true, 'default' => true]);
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 50]);
			$table->addColumn('authorization_header', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('auth', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('authentication_config', Types::TEXT, ['notnull' => false]);
			$table->addColumn('authorization_passthrough_method', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('locale', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('accept', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('jwt', Types::TEXT, ['notnull' => false]);
			$table->addColumn('jwt_id', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('secret', Types::TEXT, ['notnull' => false]);
			$table->addColumn('username', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('password', Types::TEXT, ['notnull' => false]);
			$table->addColumn('apikey', Types::TEXT, ['notnull' => false]);
			$table->addColumn('documentation', Types::TEXT, ['notnull' => false]);
			$table->addColumn('logging_config', Types::TEXT, ['notnull' => false]);
			$table->addColumn('oas', Types::TEXT, ['notnull' => false]);
			$table->addColumn('paths', Types::TEXT, ['notnull' => false]);
			$table->addColumn('headers', Types::TEXT, ['notnull' => false]);
			$table->addColumn('translation_config', Types::TEXT, ['notnull' => false]);
			$table->addColumn('configuration', Types::TEXT, ['notnull' => false]);
			$table->addColumn('endpoints_config', Types::TEXT, ['notnull' => false]);
			$table->addColumn('status', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('last_call', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('last_sync', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('object_count', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('test', Types::BOOLEAN, ['notnull' => false]);
			$table->addColumn('logRetention', Types::INTEGER, ['notnull' => true, 'default' => 3600]);
			$table->addColumn('errorRetention', Types::INTEGER, ['notnull' => true, 'default' => 86400]);
			$table->addColumn('date_created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
			$table->addColumn('date_modified', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['uuid'], 'openconnector_sources_uuid_index');
		}

		if (!$schema->hasTable('openconnector_synchronizations')) {
			$table = $schema->createTable('openconnector_synchronizations');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('version', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('description', Types::TEXT, ['notnull' => false]);
			// Source
			$table->addColumn('source_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('source_type', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('source_hash', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('source_target_mapping', Types::TEXT, ['notnull' => false]);
			$table->addColumn('source_config', Types::JSON, ['notnull' => false]);
			$table->addColumn('source_last_changed', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('source_last_checked', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('source_last_synced', Types::DATETIME, ['notnull' => false]);
			// Target
			$table->addColumn('target_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('target_type', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('target_hash', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('target_source_mapping', Types::TEXT, ['notnull' => false]);
			$table->addColumn('target_config', Types::JSON, ['notnull' => false]);
			$table->addColumn('target_last_changed', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('target_last_checked', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('target_last_synced', Types::DATETIME, ['notnull' => false]);
			// General
			$table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
			$table->addColumn('updated', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['uuid'], 'openconnector_synchronizations_uuid_index');
			$table->addIndex(['source_id'], 'openconnector_synchronizations_source_id_index');
			$table->addIndex(['target_id'], 'openconnector_synchronizations_target_id_index');
		}

        if (!$schema->hasTable('openconnector_synchronization_contracts')) {
            $table = $schema->createTable('openconnector_synchronization_contracts');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
            $table->addColumn('version', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => '0.0.1']);
            $table->addColumn('synchronization_id', Types::STRING, ['notnull' => true, 'length' => 255]);
            // Source
            $table->addColumn('source_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('source_hash', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('source_last_changed', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('source_last_checked', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('source_last_synced', Types::DATETIME, ['notnull' => false]);
            // Target
            $table->addColumn('target_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('target_hash', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('target_last_changed', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('target_last_checked', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('target_last_synced', Types::DATETIME, ['notnull' => false]);
            // General
            $table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
            $table->addColumn('updated', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['uuid'], 'openconnector_sync_contracts_uuid_index');
            $table->addIndex(['synchronization_id'], 'openconnector_sync_contracts_sync_index');
            $table->addIndex(['source_id'], 'openconnector_sync_contracts_source_id_index');
            $table->addIndex(['target_id'], 'openconnector_sync_contracts_target_id_index');
            $table->addIndex(['synchronization_id', 'source_id'], 'openconnector_sync_contracts_sync_origin_index');
            $table->addIndex(['synchronization_id', 'target_id'], 'openconnector_sync_contracts_sync_target_index');
        }

        if (!$schema->hasTable('openconnector_consumers')) {
            $table = $schema->createTable('openconnector_consumers');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]); // The id of the consumer
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]); // The uuid of the consumer
            $table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]); // The name of the consumer
            $table->addColumn('description', Types::TEXT, ['notnull' => false]); // The description of the consumer
            $table->addColumn('domains', Types::JSON, ['notnull' => false]); // The domains the consumer is allowed to run from
            $table->addColumn('ips', Types::JSON, ['notnull' => false]); // The ips the consumer is allowed to run from
            $table->addColumn('authorization_type', Types::STRING, ['notnull' => false, 'length' => 255]); // The authorization type of the consumer, should be one of the following: 'none', 'basic', 'bearer', 'apiKey', 'oauth2', 'jwt'. Keep in mind that the consumer needs to be able to handle the authorization type.
            $table->addColumn('authorization_configuration', Types::TEXT, ['notnull' => false]); // The authorization configuration of the consumer
            $table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']); // the date and time the consumer was created
            $table->addColumn('updated', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']); // the date and time the consumer was updated
            $table->setPrimaryKey(['id']);
            $table->addIndex(['uuid'], 'openconnector_consumers_uuid_index');
        }

        if (!$schema->hasTable('openconnector_call_logs')) {
            $table = $schema->createTable('openconnector_call_logs');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
            $table->addColumn('status_code', Types::INTEGER, ['notnull' => false, 'length' => 3]);
            $table->addColumn('status_message', Types::STRING, ['notnull' => false, 'length' => 256]);
            $table->addColumn('request', Types::JSON, ['notnull' => false]);
            $table->addColumn('response', Types::JSON, ['notnull' => false]);
            $table->addColumn('source_id', Types::INTEGER, ['notnull' => true]);
            $table->addColumn('action_id', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('synchronization_id', Types::INTEGER, ['notnull' => false]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('session_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
            $table->addColumn('expires', Types::DATETIME, ['notnull' => false]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['uuid'], 'openconnector_call_logs_uuid_index');
            $table->addIndex(['source_id'], 'openconnector_call_logs_source_id_index');
            $table->addIndex(['action_id'], 'openconnector_call_logs_action_id_index');
            $table->addIndex(['synchronization_id'], 'openconnector_call_logs_sync_id_index');
            $table->addIndex(['status_code'], 'openconnector_call_logs_status_code_index');
        }

        if (!$schema->hasTable('openconnector_job_logs')) {
            $table = $schema->createTable('openconnector_job_logs');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
            $table->addColumn('level', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => 'INFO']);
            $table->addColumn('message', Types::STRING, ['notnull' => true, 'length' => 255, 'default' => 'success']);
            $table->addColumn('job_id', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('job_list_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('job_class', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('arguments', Types::JSON, ['notnull' => false]);
            $table->addColumn('execution_time', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('session_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('stack_trace', Types::JSON, ['notnull' => false]);
            $table->addColumn('expires', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('last_run', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('next_run', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['uuid'], 'openconnector_job_logs_uuid_index');
            $table->addIndex(['job_id'], 'openconnector_job_logs_job_id_index');
            $table->addIndex(['job_list_id'], 'openconnector_job_logs_job_list_id_index');
            $table->addIndex(['user_id'], 'openconnector_job_logs_user_id_index');
        }

        if (!$schema->hasTable('openconnector_synchronization_contract_logs')) {
            $table = $schema->createTable('openconnector_synchronization_contract_logs');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
            $table->addColumn('synchronization_id', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('synchronization_contract_id', Types::STRING, ['notnull' => true, 'length' => 255]);
            $table->addColumn('source', Types::JSON, ['notnull' => false]);
            $table->addColumn('target', Types::JSON, ['notnull' => false]);
            $table->addColumn('user_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('session_id', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->addColumn('expires', Types::DATETIME, ['notnull' => false]);
            $table->addColumn('created', Types::DATETIME, ['notnull' => true, 'default' => 'CURRENT_TIMESTAMP']);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['uuid'], 'openconnector_sync_contract_logs_uuid_index');
            $table->addIndex(['synchronization_id'], 'openconnector_sync_contract_logs_sync_index');
            $table->addIndex(['synchronization_contract_id'], 'openconnector_sync_contract_logs_contract_index');
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
