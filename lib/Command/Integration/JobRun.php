<?php

/**
 * OCC command: integriq:job:run.
 *
 * Runs a job through JobService::executeJob(), the call the Run button makes.
 * Exits 0 when the job ran, 1 when it threw or its log entry has level ERROR,
 * 2 when the job was not found.
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

use OCA\Integriq\Service\JobServiceInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Runs one job.
 */
class JobRun extends IntegrationCommand {

	/**
	 * Constructor.
	 *
	 * @param IntegrationObjectLookup $lookup Finds the job.
	 * @param JobServiceInterface     $jobs   Runs it.
	 *
	 * @return void
	 */
	public function __construct(
		IntegrationObjectLookup $lookup,
		private readonly JobServiceInterface $jobs,
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
		$this->setName(name: 'integriq:job:run')
			->setDescription(description: 'Run a job the way the Run button does')
			->addArgument(name: 'job', mode: InputArgument::REQUIRED, description: 'The job uuid or slug')
			->addOption(name: 'force', shortcut: null, mode: InputOption::VALUE_NONE, description: 'Run it even when it is not due or is disabled');
		$this->addOutputOption();

	}//end configure()

	/**
	 * Run the job and print its log entry.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int 0 when the job ran, 1 when it failed, 2 when not found.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-sources-synchronizations-and-jobs-can-be-listed-run-and-tested-with-occ-req-iac-001
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$job = $this->resolveOrReport(output: $output, schema: 'job', reference: (string) $input->getArgument('job'));
		if ($job === null) {
			return self::EXIT_NOT_FOUND;
		}

		try {
			$log = $this->jobs->executeJob(job: $job, forceRun: ($input->getOption('force') === true));
		} catch (Throwable $e) {
			$this->printResult(input: $input, output: $output, result: ['passed' => false, 'error' => $e->getMessage()]);
			return self::EXIT_FAILED;
		}

		$entry  = [];
		if ($log !== null) {
			$entry = (array) $log->jsonSerialize();
		}

		$passed = (($entry['level'] ?? '') !== 'ERROR');
		$this->printResult(input: $input, output: $output, result: ['passed' => $passed, 'log' => $entry]);

		if ($passed === true) {
			return self::EXIT_OK;
		}

		return self::EXIT_FAILED;

	}//end execute()

}//end class
