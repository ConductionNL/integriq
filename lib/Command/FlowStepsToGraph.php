<?php

/**
 * OCC command: integriq:flow:steps-to-graph.
 *
 * Task 2 of `retire-integriq-flow-schema`: drives the steps-to-graph
 * migration over the live `flow` objects — dry run by default, `--apply` to
 * write, `--rollback` to remove the written graphs again.
 *
 * The dry run is the default on purpose: a migration whose blast radius is
 * unmeasured is a migration whose rollback is unplanned, so the first
 * invocation ALWAYS answers "how many flows, which refused, why" without
 * touching anything.
 *
 * Usage:
 *   occ integriq:flow:steps-to-graph              # dry run: report only
 *   occ integriq:flow:steps-to-graph --apply      # write nodes/edges in place
 *   occ integriq:flow:steps-to-graph --rollback           # dry run of the rollback
 *   occ integriq:flow:steps-to-graph --rollback --apply   # remove nodes/edges again
 *
 * @category Command
 * @package  OCA\Integriq\Command
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

namespace OCA\Integriq\Command;

use OCA\Integriq\Service\FlowGraphMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migrates (or rolls back) live flow objects between steps and graph shape.
 *
 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
 */
class FlowStepsToGraph extends Command {

	/**
	 * Constructor.
	 *
	 * @param FlowGraphMigrationService $migration The shared migration behaviour.
	 */
	public function __construct(
		private readonly FlowGraphMigrationService $migration,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Configure the command name, description and options.
	 *
	 * @return void
	 *
	 * @spec exclude Symfony console wiring — framework metadata, no domain behavior.
	 */
	protected function configure(): void {
		$this->setName(name: 'integriq:flow:steps-to-graph')
			->setDescription(
				'Translate live flow objects from steps[] to the OpenRegister nodes/edges graph (dry run unless --apply)'
			)
			->addOption(
				'apply',
				null,
				InputOption::VALUE_NONE,
				'Write the changes; without it the command only reports what would happen'
			)
			->addOption(
				'rollback',
				null,
				InputOption::VALUE_NONE,
				'Remove the written nodes/edges again, leaving steps[] as the only shape'
			);

	}//end configure()

	/**
	 * Run the migration (or its rollback) and print one row per flow.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return integer 0 when every flow migrated or was already done; 1 when any flow was refused.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$apply = ($input->getOption('apply') === true);
		$report = $this->reportFor(input: $input, apply: $apply);

		if ($apply === false) {
			$io->note('Dry run — nothing was written. Re-run with --apply to write.');
		}

		$refusals = 0;
		foreach ($report as $row) {
			$line = sprintf('[%s] %s (%s)', $row['action'], $row['name'], $row['id']);
			$output->writeln($line);

			if ($row['action'] === FlowGraphMigrationService::REFUSED) {
				$refusals++;
				foreach ($row['reasons'] as $reason) {
					$output->writeln('    - ' . $reason);
				}
			}
		}

		$io->success(sprintf('%d flow(s) inspected, %d refused.', count($report), $refusals));

		if ($refusals > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()

	/**
	 * Run the direction the flags asked for.
	 *
	 * @param InputInterface $input Console input.
	 * @param bool $apply Whether to write.
	 *
	 * @return array<int, array{id: string, name: string, action: string, reasons: array<int, string>}> One row per flow.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#2-the-step-to-graph-migration
	 */
	private function reportFor(InputInterface $input, bool $apply): array {
		if ($input->getOption('rollback') === true) {
			return $this->migration->rollback(apply: $apply);
		}

		return $this->migration->migrate(apply: $apply);

	}//end reportFor()
}//end class
