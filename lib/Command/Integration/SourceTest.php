<?php

/**
 * OCC command: integriq:source:test.
 *
 * Tests a source through the same SourceTestService the Test connection
 * button uses: no call log is written. Exits 0 when the source answered with
 * a status below 400, 1 when it failed, answered nothing or answered an error,
 * 2 when the source was not found.
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

use OCA\Integriq\Service\SourceTestServiceInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tests one source.
 */
class SourceTest extends IntegrationCommand {

	/**
	 * Constructor.
	 *
	 * @param IntegrationObjectLookup    $lookup      Finds the source.
	 * @param SourceTestServiceInterface $sourceTests Fires the test call.
	 *
	 * @return void
	 */
	public function __construct(
		IntegrationObjectLookup $lookup,
		private readonly SourceTestServiceInterface $sourceTests,
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
		$this->setName(name: 'integriq:source:test')
			->setDescription(description: 'Test a source the way the Test connection button does')
			->addArgument(name: 'source', mode: InputArgument::REQUIRED, description: 'The source uuid or slug')
			->addOption(
				name: 'endpoint',
				shortcut: null,
				mode: InputOption::VALUE_REQUIRED,
				description: 'The endpoint path, empty for the source root',
				default: ''
			)
			->addOption(name: 'method', shortcut: null, mode: InputOption::VALUE_REQUIRED, description: 'The HTTP method', default: 'GET');
		$this->addOutputOption();

	}//end configure()

	/**
	 * Test the source and print the outcome, status and duration.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int 0 on a status below 400, 1 on a failed test, 2 when not found.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-sources-synchronizations-and-jobs-can-be-listed-run-and-tested-with-occ-req-iac-001
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$source = $this->resolveOrReport(output: $output, schema: 'source', reference: (string) $input->getArgument('source'));
		if ($source === null) {
			return self::EXIT_NOT_FOUND;
		}

		$started = hrtime(true);
		$test    = $this->sourceTests->run(
			source: $source,
			endpoint: (string) $input->getOption('endpoint'),
			method: strtoupper((string) $input->getOption('method'))
		);
		$duration = (int) round((hrtime(true) - $started) / 1000000);

		$status = $test['statusCode'] ?? null;
		$passed = ($test['outcome'] !== SourceTestServiceInterface::OUTCOME_FAILED
			&& $test['outcome'] !== SourceTestServiceInterface::OUTCOME_NO_RESPONSE
			&& is_int($status) === true
			&& $status < 400);

		$this->printResult(
			input: $input,
			output: $output,
			result: [
				'passed' => $passed,
				'outcome' => $test['outcome'],
				'statusCode' => $status,
				'statusMessage' => ($test['statusMessage'] ?? ''),
				'durationMs' => $duration,
				'error' => ($test['error'] ?? ''),
			]
		);

		if ($passed === true) {
			return self::EXIT_OK;
		}

		return self::EXIT_FAILED;

	}//end execute()

}//end class
