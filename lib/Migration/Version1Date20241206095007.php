<?php

/**
 * Rename source retention columns to snake_case.
 *
 * Drops the camelCase logRetention and errorRetention columns from the sources
 * table and recreates them as log_retention and error_retention.
 *
 * @category Migration
 * @package  OCA\OpenConnector\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Renames the source retention columns from camelCase to snake_case.
 */
class Version1Date20241206095007 extends SimpleMigrationStep {
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
	 * Drops the camelCase columns and recreates them in snake_case.
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

		if ($schema->hasTable('openconnector_sources') === true) {
			$table = $schema->getTable('openconnector_sources');

			if ($table->hasColumn('logRetention') === true) {
				$table->dropColumn('logRetention');
				$table->addColumn('log_retention', Types::INTEGER)->setNotnull(false)->setDefault(3600);
			}

			if ($table->hasColumn('errorRetention') === true) {
				$table->dropColumn('errorRetention');
				$table->addColumn('error_retention', Types::INTEGER)->setNotnull(false)->setDefault(86400);
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
