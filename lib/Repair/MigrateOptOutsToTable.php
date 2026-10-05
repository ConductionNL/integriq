<?php

/**
 * Integriq MigrateOptOutsToTable repair step.
 *
 * Copies the opt-outs stored in OpenRegister into integriq's own table, so
 * the decision whether to send keeps honouring every opt-out made before the
 * table existed. Idempotent: one row per opt-out, keyed on address, scope and
 * case, so a second run copies nothing.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Outbound\Identity\OptOutTableMigrator;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Moves the OpenRegister opt-outs into the opt-out table.
 *
 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
 */
class MigrateOptOutsToTable implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the migrator lazily, so the step loads without OpenRegister.
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {

	}//end __construct()

	/**
	 * The step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function getName(): string {
		return 'Copy the recipient opt-outs from OpenRegister into the opt-out table';

	}//end getName()

	/**
	 * Copy what is not copied yet.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/opt-outs-in-an-app-table-and-routing-rules-read-as-config/specs/outbound-sender-identity/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$migrator = $this->container->get(OptOutTableMigrator::class);
			$result = $migrator->migrate();
		} catch (Throwable $exception) {
			$output->warning(
				'MigrateOptOutsToTable: opt-outs not copied (' . $exception->getMessage() . '). '
				. 'Run occ maintenance:repair once OpenRegister is available.'
			);
			return;
		}

		$output->info(
			sprintf(
				'MigrateOptOutsToTable: %d read, %d copied, %d already present, %d skipped (no address).',
				$result['read'],
				$result['copied'],
				$result['present'],
				$result['skipped']
			)
		);

	}//end run()
}//end class
