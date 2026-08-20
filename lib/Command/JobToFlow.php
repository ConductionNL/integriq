<?php

/**
 * OCC command: openconnector:job-to-flow.
 *
 * Task 3.3 of `flow-native-synchronization`: prints the generated
 * `trigger-schedule` flow document for one Job, or the reasons that job cannot
 * be migrated yet.
 *
 * The command WRITES NOTHING. It creates no flow, enables nothing, and leaves
 * the job exactly as it found it — it renders the document so a human can read
 * it before anything is created from it, which is the whole point of a
 * migration that ships "disabled until reviewed".
 *
 * Usage:
 *   occ openconnector:job-to-flow <job>
 *   occ openconnector:job-to-flow <job> --json
 *
 * @category Command
 * @package  OCA\OpenConnector\Command
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
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Command;

use OCA\OpenConnector\Exception\EntityNotMigratableException;
use OCA\OpenConnector\Service\JobToFlowGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders a Job as a disabled, reviewable trigger-schedule flow document.
 *
 * @spec openspec/changes/flow-native-synchronization/tasks.md#3-migration--deprecation
 */
class JobToFlow extends Command {

	/**
	 * Constructor.
	 *
	 * @param JobToFlowGenerator $generator The migration generator.
	 */
	public function __construct(
		private readonly JobToFlowGenerator $generator,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Configure the command name, description, argument and options.
	 *
	 * @return void
	 *
	 * @spec exclude Symfony console wiring — framework metadata, no domain behavior.
	 */
	protected function configure(): void {
		$this->setName(name: 'openconnector:job-to-flow')
			->setDescription(
				'Render a job as a generated (disabled) trigger-schedule flow document, or explain why it cannot be'
			)
			->addArgument(
				'job',
				InputArgument::REQUIRED,
				'The job to render: its uuid, slug or reference'
			)
			->addOption(
				'json',
				null,
				InputOption::VALUE_NONE,
				'Emit the flow document as JSON and nothing else'
			);

	}//end configure()

	/**
	 * Render the document, or report the refusal.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return integer 0 when a document was rendered; 1 when the job was refused.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);

		try {
			$flow = $this->generator->generateFor(reference: (string)$input->getArgument('job'));
		} catch (EntityNotMigratableException $refusal) {
			$io->error($refusal->getMessage());
			$io->listing($refusal->getReasons());

			return Command::FAILURE;
		}

		$encoded = (string)json_encode($flow, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

		if ($input->getOption('json') === true) {
			$output->writeln($encoded);

			return Command::SUCCESS;
		}

		$io->success(sprintf('Generated a DISABLED flow "%s" — nothing was created.', (string)$flow['name']));
		$output->writeln($encoded);

		return Command::SUCCESS;

	}//end execute()
}//end class
