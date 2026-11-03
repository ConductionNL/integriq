<?php

/**
 * The console output every to-flow command shares.
 *
 * A using command provides generateFlow(), and names the exception its
 * generator refuses with in REFUSAL. execute() prints the refusal and its
 * reasons, or the generated flow document, as text or as bare JSON.
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
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

trait GeneratedFlowOutput {

	/**
	 * Add the --json option.
	 *
	 * @return void
	 */
	private function addJsonOption(): void {
		$this->addOption(
			name: 'json',
			shortcut: null,
			mode: InputOption::VALUE_NONE,
			description: 'Emit the flow document as JSON and nothing else'
		);
	}//end addJsonOption()

	/**
	 * Generate the flow and print it, or print why it cannot be generated.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return integer The exit code.
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);

		try {
			$flow = $this->generateFlow(input: $input);
		} catch (Throwable $refusal) {
			if (is_a($refusal, self::REFUSAL) === false) {
				throw $refusal;
			}

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
}//end trait
