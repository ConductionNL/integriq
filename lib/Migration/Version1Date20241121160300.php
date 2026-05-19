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
 * - Adding 4 new columns for the table Source: rateLimitLimit, rateLimitRemaining, rateLimitReset & rateLimitWindow
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20241121160300 extends SimpleMigrationStep {

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
		// Sources table
		$table = $schema->getTable('openconnector_sources');

		if ($table->hasColumn('rate_limit_limit') === false) {
			$table->addColumn('rate_limit_limit', Types::INTEGER, [
				'notnull' => false,
				'default' => null
			]);
		}

		if ($table->hasColumn('rate_limit_remaining') === false) {
			$table->addColumn('rate_limit_remaining', Types::INTEGER, [
				'notnull' => false,
				'default' => null
			]);
		}

		if ($table->hasColumn('rate_limit_reset') === false) {
			$table->addColumn('rate_limit_reset', Types::INTEGER, [
				'notnull' => false,
				'default' => null
			]);
		}

		if ($table->hasColumn('rate_limit_window') === false) {
			$table->addColumn('rate_limit_window', Types::INTEGER, [
				'notnull' => false,
				'default' => null
			]);
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
