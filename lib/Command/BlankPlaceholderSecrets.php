<?php

/**
 * Report, and with --apply blank, stored secrets equal to the export placeholder.
 *
 * Dry-run by default: without --apply nothing is written.
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
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */

declare(strict_types=1);

namespace OCA\Integriq\Command;

use OCA\Integriq\Service\Security\PlaceholderSecretRepair;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * occ integriq:secrets:blank-placeholders [--apply] [--json] [--limit=N]
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 */
class BlankPlaceholderSecrets extends Command {

	/**
	 * Constructor.
	 *
	 * @param PlaceholderSecretRepair $repair The repair.
	 */
	public function __construct(
		private readonly PlaceholderSecretRepair $repair,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and options.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	protected function configure(): void {
		$this->setName(name: 'integriq:secrets:blank-placeholders')
			->setDescription(
				description: 'Report (dry-run) stored source and rule secrets equal to the export placeholder; --apply blanks them'
			)
			->addOption(
				name: 'apply',
				shortcut: null,
				mode: InputOption::VALUE_NONE,
				description: 'Blank the placeholder values. Without it nothing is written.'
			)
			->addOption(name: 'json', shortcut: null, mode: InputOption::VALUE_NONE, description: 'Emit the report as JSON')
			->addOption(
				name: 'limit',
				shortcut: null,
				mode: InputOption::VALUE_REQUIRED,
				description: 'Maximum number of objects per schema',
				default: '1000'
			);
	}//end configure()

	/**
	 * Run the repair and print the report.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return integer The exit code.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io    = new SymfonyStyle($input, $output);
		$limit = (string)$input->getOption('limit');
		if (preg_match('/^\d+$/', $limit) !== 1 || (int)$limit < 1 || (int)$limit > 100000) {
			$io->error(sprintf('limit must be an integer in [1, 100000], got "%s"', $limit));
			return Command::FAILURE;
		}

		$apply  = ((bool)$input->getOption('apply') === true);
		$report = $this->repair->run(apply: $apply, limit: (int)$limit);

		if ((bool)$input->getOption('json') === true) {
			$output->writeln((string)json_encode(array_merge(['apply' => $apply], $report), (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
			return $this->exitCode(report: $report);
		}

		foreach ($report['objects'] as $row) {
			$io->writeln(sprintf('%s %s (%s): %s', $row['schema'], $row['uuid'], $row['name'], implode(', ', $row['paths'])));
		}

		if ($apply === false) {
			$io->note(sprintf('Dry run: %d object(s) hold placeholder secrets. Run with --apply to blank them.', $report['found']));
			return Command::SUCCESS;
		}

		$io->success(sprintf('Blanked %d object(s), %d failed. Re-enter their secrets.', $report['blanked'], $report['failed']));
		return $this->exitCode(report: $report);
	}//end execute()

	/**
	 * Failure when any save failed.
	 *
	 * @param array{failed: int} $report The repair report.
	 *
	 * @return integer The exit code.
	 */
	private function exitCode(array $report): int {
		if ($report['failed'] === 0) {
			return Command::SUCCESS;
		}

		return Command::FAILURE;
	}//end exitCode()
}//end class
