<?php

/**
 * Add endpoint input/output mapping columns.
 *
 * Adds the input_mapping and output_mapping columns to the endpoints table
 * and sets a default value for the existing conditions column.
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
 * Adds input/output mapping columns and a default for the conditions column on endpoints.
 */
class Version1Date20241230141628 extends SimpleMigrationStep {
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
	 * Adds the endpoint mapping columns and the conditions default.
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

		if ($schema->hasTable(tableName: 'openconnector_endpoints') === true) {
			$table = $schema->getTable(tableName: 'openconnector_endpoints');
			$table->addColumn('input_mapping', Types::STRING)->setNotnull(false)->setDefault(null);
			$table->addColumn('output_mapping', Types::STRING)->setNotnull(false)->setDefault(null);
			$table->getColumn('conditions')->setDefault('[]');
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
