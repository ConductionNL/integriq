<?php

/**
 * Add rate-limit columns to the Source table.
 *
 * This migration changes the following:
 * - Adding 4 new columns for the table Source: rateLimitLimit, rateLimitRemaining,
 *   rateLimitReset and rateLimitWindow.
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
 * Adds rate-limit tracking columns to the openconnector_sources table.
 */
class Version1Date20241121160300 extends SimpleMigrationStep {
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
	 * Adds the rate-limit columns to the sources table when missing.
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
		// Sources table.
		$table = $schema->getTable('openconnector_sources');

		if ($table->hasColumn('rate_limit_limit') === false) {
			$table->addColumn(
				'rate_limit_limit',
				Types::INTEGER,
				[
					'notnull' => false,
					'default' => null,
				]
			);
		}

		if ($table->hasColumn('rate_limit_remaining') === false) {
			$table->addColumn(
				'rate_limit_remaining',
				Types::INTEGER,
				[
					'notnull' => false,
					'default' => null,
				]
			);
		}

		if ($table->hasColumn('rate_limit_reset') === false) {
			$table->addColumn(
				'rate_limit_reset',
				Types::INTEGER,
				[
					'notnull' => false,
					'default' => null,
				]
			);
		}

		if ($table->hasColumn('rate_limit_window') === false) {
			$table->addColumn(
				'rate_limit_window',
				Types::INTEGER,
				[
					'notnull' => false,
					'default' => null,
				]
			);
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
