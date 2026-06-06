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
 * Migration step to add configurations and slug columns to all necessary tables.
 *
 * @package OCA\OpenConnector\Migration
 * @category Migration
 * @author OpenConnector Team
 * @copyright 2024 OpenConnector
 * @license AGPL-3.0
 * @version 1.0.0
 * @link https://github.com/OpenConnector/openconnector
 */
class Version1Date20250515232835 extends SimpleMigrationStep {

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

		// List of tables that need the new columns
		$tables = [
			'openconnector_sources',
			'openconnector_endpoints',
			'openconnector_mappings',
			'openconnector_rules',
			'openconnector_jobs',
			'openconnector_synchronizations'
		];

		// Add configurations and slug columns to each table
		foreach ($tables as $tableName) {
			if ($schema->hasTable($tableName)) {
				$table = $schema->getTable($tableName);

				// Add configurations column if it doesn't exist
				if (!$table->hasColumn('configurations')) {
					$table->addColumn('configurations', Types::JSON)
						->setNotnull(false)
						->setDefault('[]');
				}

				// Add slug column if it doesn't exist
				if (!$table->hasColumn('slug')) {
					$table->addColumn('slug', Types::STRING, [
						'length' => 255,
						'notnull' => false,
						'default' => null
					]);

					// Add index for the slug column
					$table->addIndex(['slug'], 'idx_' . $tableName . '_slug');
					$table->addUniqueConstraint(['slug'], 'idx_' . $tableName . '_slug_unique');
				}
			}
		}

		// Add status column to synchronizations table if it doesn't exist
		if ($schema->hasTable('openconnector_synchronizations')) {
			$table = $schema->getTable('openconnector_synchronizations');
			if (!$table->hasColumn('status')) {
				$table->addColumn('status', Types::STRING, [
					'length' => 255,
					'notnull' => false,
					'default' => null
				]);
			}
		}

		// Add status column to jobs table if it doesn't exist
		if ($schema->hasTable('openconnector_jobs')) {
			$table = $schema->getTable('openconnector_jobs');
			if (!$table->hasColumn('status')) {
				$table->addColumn('status', Types::STRING, [
					'length' => 255,
					'notnull' => false,
					'default' => null
				]);
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
		// Get the database connection
		$connection = \OC::$server->get(\OCP\IDBConnection::class);
		
		// List of tables that need slug updates
		$tables = [
			'openconnector_sources' => 'name',
			'openconnector_endpoints' => 'name',
			'openconnector_mappings' => 'name',
			'openconnector_rules' => 'name',
			'openconnector_jobs' => 'name',
			'openconnector_synchronizations' => 'name'
		];

		// Update slugs for each table
		foreach ($tables as $tableName => $nameColumn) {
			// First, update any null or empty slugs using the name column
			$query = $connection->getQueryBuilder();
			$query->update($tableName)
				->set('slug', $query->createFunction('LOWER(REPLACE(REPLACE(REPLACE(' . $nameColumn . ', \' \', \'-\'), \'.\', \'-\'), \'_\', \'-\'))'))
				->where($query->expr()->orX(
					$query->expr()->isNull('slug'),
					$query->expr()->eq('slug', $query->createNamedParameter(''))
				));
			$query->executeQuery();

			// Then, ensure uniqueness across all tables
			$query = $connection->getQueryBuilder();
			$query->select('id', 'slug')
				->from($tableName)
				->orderBy('id', 'ASC');
			$result = $query->executeQuery();
			$slugs = [];
			$updates = [];

			while ($row = $result->fetch()) {
				$originalSlug = $row['slug'];
				$newSlug = $originalSlug;
				$counter = 1;

				// If slug is empty or null, use a default
				if (empty($originalSlug)) {
					$newSlug = 'item-' . $row['id'];
				} else {
					// Handle duplicate slugs
					while (isset($slugs[$newSlug])) {
						$newSlug = $originalSlug . '-' . $counter;
						$counter++;
					}
				}

				if ($newSlug !== $originalSlug) {
					$updates[] = [
						'id' => $row['id'],
						'slug' => $newSlug
					];
				}
				$slugs[$newSlug] = true;
			}
			$result->closeCursor();

			// Apply the updates
			foreach ($updates as $update) {
				$query = $connection->getQueryBuilder();
				$query->update($tableName)
					->set('slug', $query->createNamedParameter($update['slug']))
					->where($query->expr()->eq('id', $query->createNamedParameter($update['id'])));
				$query->executeQuery();
			}

			$output->info("Updated slugs for table: " . $tableName);
		}
	}
}
