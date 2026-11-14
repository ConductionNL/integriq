<?php

/**
 * OCC command: integriq:synchronization:run.
 *
 * Runs a synchronization through SynchronizationService::synchronize(), the
 * call the Run button makes. `--test` is the engine's own test mode, which
 * writes no objects. Exits 0 when the run completed, 1 when it threw, 2 when
 * the synchronization was not found.
 *
 * @category Command
 * @package  OCA\Integriq\Command\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-sources-synchronizations-and-jobs-can-be-listed-run-and-tested-with-occ-req-iac-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Command\Integration;

use OCA\Integriq\Service\SynchronizationServiceInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs, or test-runs, one synchronization.
 */
class SynchronizationRun extends IntegrationCommand {

	/**
	 * Constructor.
	 *
	 * @param IntegrationObjectLookup         $lookup          Finds the synchronization.
	 * @param SynchronizationServiceInterface $synchronization Runs it.
	 *
	 * @return void
	 */
	public function __construct(
		IntegrationObjectLookup $lookup,
		private readonly SynchronizationServiceInterface $synchronization,
	) {
		parent::__construct(lookup: $lookup);

	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec exclude Symfony console wiring, no domain behaviour.
	 */
	protected function configure(): void {
		$this->setName(name: 'integriq:synchronization:run')
			->setDescription(description: 'Run a synchronization, or with --test show what it would do without writing')
			->addArgument(name: 'synchronization', mode: InputArgument::REQUIRED, description: 'The synchronization uuid or slug')
			->addOption(name: 'test', shortcut: null, mode: InputOption::VALUE_NONE, description: 'Run in test mode: no object is written')
			->addOption(name: 'force', shortcut: null, mode: InputOption::VALUE_NONE, description: 'Process every object, also unchanged ones');
		$this->addOutputOption();

	}//end configure()

	/**
	 * Run the synchronization and print its result.
	 *
	 * The deletion-ratio guard's override is deliberately not offered: it is a
	 * person's decision on the screen, not a flag in a script.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int 0 when the run completed, 1 when it failed, 2 when not found.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-sources-synchronizations-and-jobs-can-be-listed-run-and-tested-with-occ-req-iac-001
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$synchronization = $this->resolveOrReport(
			output: $output,
			schema: 'synchronization',
			reference: (string) $input->getArgument('synchronization')
		);
		if ($synchronization === null) {
			return self::EXIT_NOT_FOUND;
		}

		$test = ($input->getOption('test') === true);
		try {
			$result = $this->synchronization->synchronize(
				synchronization: $synchronization,
				isTest: $test,
				force: ($input->getOption('force') === true)
			);
		} catch (Throwable $e) {
			$this->printResult(input: $input, output: $output, result: ['passed' => false, 'test' => $test, 'error' => $e->getMessage()]);
			return self::EXIT_FAILED;
		}

		$this->printResult(input: $input, output: $output, result: ['passed' => true, 'test' => $test, 'result' => $result]);

		return self::EXIT_OK;

	}//end execute()

}//end class
