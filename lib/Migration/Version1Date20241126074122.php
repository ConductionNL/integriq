<?php

/**
 * Add conditions and follow_ups columns to Synchronizations.
 *
 * Adds two columns to the Synchronizations table:
 * - conditions for json logic.
 * - follow_ups for follow up synchronizations.
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
use OCA\OpenRegister\Db\Schema;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds conditions and follow_ups JSON columns to the Synchronizations table.
 */
class Version1Date20241126074122 extends SimpleMigrationStep {
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
	 * Adds the conditions and follow_ups columns to the synchronizations table.
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
