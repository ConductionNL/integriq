<?php

/**
 * Integriq OpenFormulierenDrain command.
 *
 * `occ integriq:openformulieren:drain`: pushes every staged `received` and
 * `failed` Open Formulieren submission through the inbound submit path.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Command;

use OCA\Integriq\Service\OpenFormulieren\SubmissionDrain;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Drain the Open Formulieren staging schema into cases; a dry run by default.
 *
 * @spec openspec/changes/open-formulieren-submits-into-its-case/tasks.md
 */
class OpenFormulierenDrain extends Command {

	/**
	 * Constructor.
	 *
	 * @param SubmissionDrain $drain The drain.
	 */
	public function __construct(
		private readonly SubmissionDrain $drain,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and options.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'integriq:openformulieren:drain')
			->setDescription(
				description: 'Report (dry-run) the staged Open Formulieren submissions without a case; --apply submits them into their cases'
			)
			->addOption(
				name: 'apply',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Submit and mark them. Without it nothing is written.'
			)
			->addOption(
				name: 'limit',
				shortcut: null,
				mode: InputOption::VALUE_REQUIRED,
				description: 'Maximum number of submissions per status',
				default: '1000'
			);
	}//end configure()

	/**
	 * Run the drain and print each submission's outcome.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int 0 when nothing failed; 1 otherwise.
	 *
	 * @spec openspec/changes/open-formulieren-submits-into-its-case/tasks.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io    = new SymfonyStyle($input, $output);
		$limit = (string)$input->getOption('limit');
		if (preg_match('/^\d+$/', $limit) !== 1 || (int)$limit < 1 || (int)$limit > 100000) {
			$io->error(sprintf('limit must be an integer in [1, 100000], got "%s"', $limit));
			return Command::FAILURE;
		}

		$apply = ((bool)$input->getOption('apply') === true);
		try {
			$report = $this->drain->run(apply: $apply, limit: (int)$limit);
		} catch (Throwable $exception) {
			$io->error('The drain could not run: ' . $exception->getMessage());
			return Command::FAILURE;
		}

		foreach ($report['rows'] as $row) {
			$io->writeln(sprintf('%s %s: %s', $row['formSlug'], $row['uuid'], $row['outcome']));
		}

		if ($apply === false) {
			$io->note(sprintf('Dry run: %d submission(s) wait for a case. Run with --apply to submit them.', $report['pending']));
			return Command::SUCCESS;
		}

		$io->success(sprintf('%d submission(s) became a case, %d failed; %d were pending.', $report['drained'], $report['failed'], $report['pending']));
		if ($report['failed'] === 0) {
			return Command::SUCCESS;
		}

		return Command::FAILURE;
	}//end execute()
}//end class
