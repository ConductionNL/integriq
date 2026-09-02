<?php

/**
 * Repair step: write the OpenRegister graph onto every legacy flow object.
 *
 * Task 2 of `retire-integriq-flow-schema`. Runs the same migration
 * `occ integriq:flow:steps-to-graph --apply` runs, on every upgrade, so an
 * instance is migrated without an operator remembering to. Safe to repeat by
 * construction: the migration is additive (`steps` stays), idempotent (an
 * object already carrying `nodes` is skipped, never overwritten), and a flow
 * the translator refuses is logged and left running on `FlowRunnerService`
 * exactly as before.
 *
 * Never throws — an escaping exception here aborts the upgrade, and "one flow
 * could not be translated" is a warning about one flow, not a reason to hold
 * the whole app hostage.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/retire-integriq-flow-schema/specs/flow-orchestration/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Service\FlowGraphMigrationService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies the steps-to-graph flow migration on install/upgrade.
 *
 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
 */
class MigrateFlowStepsToGraph implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the migration service lazily,
	 *                                      so the OpenRegister class_exists guard
	 *                                      can short-circuit before anything
	 *                                      referencing OR types is constructed.
	 * @param LoggerInterface $logger For refusals and non-fatal failures.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Human-readable name surfaced by `occ` during install / upgrade.
	 *
	 * @return string
	 *
	 * @spec exclude Repair-step display name for occ output — framework metadata, no domain behavior.
	 */
	public function getName(): string {
		return 'Write the OpenRegister nodes/edges graph onto legacy Integriq flow objects (retire-integriq-flow-schema)';
	}//end getName()

	/**
	 * Migrate every live flow, additively and idempotently.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	public function run(IOutput $output): void {
		if (class_exists('OCA\\OpenRegister\\Service\\ObjectService') === false) {
			// No OpenRegister, no register, no flows — nothing to migrate.
			return;
		}

		try {
			$migration = $this->container->get(FlowGraphMigrationService::class);
			$report = $migration->migrate(apply: true);
		} catch (Throwable $e) {
			// Non-fatal by contract: the legacy runner still executes steps[],
			// and the occ command re-runs the migration on demand.
			$this->logger->warning(
				'MigrateFlowStepsToGraph: migration pass failed, flows stay on steps[]: ' . $e->getMessage(),
				['exception' => $e]
			);

			return;
		}

		$migrated = 0;
		$refused = 0;
		foreach ($report as $row) {
			if ($row['action'] === FlowGraphMigrationService::MIGRATED) {
				$migrated++;
			}

			if ($row['action'] === FlowGraphMigrationService::REFUSED) {
				$refused++;
				$this->logger->warning(
					'MigrateFlowStepsToGraph: flow refused, staying on steps[]',
					['flowId' => $row['id'], 'name' => $row['name'], 'reasons' => $row['reasons']]
				);
			}
		}

		if (($migrated + $refused) > 0) {
			$output->info(sprintf(
				'Flow steps-to-graph migration: %d migrated, %d refused (see the log), %d total.',
				$migrated,
				$refused,
				count($report)
			));
		}

	}//end run()
}//end class
