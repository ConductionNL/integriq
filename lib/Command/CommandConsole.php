<?php

/**
 * Integriq CommandConsole: the console input and output the credential-custody
 * commands share — reading the --limit option and writing a JSON payload.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared console steps of the credential-custody commands.
 *
 * @spec openspec/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
 */
final class CommandConsole {
	/**
	 * Start a credential-custody run: styled output, the validated --limit and the --json mode.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return ConsoleRun|null The run, or null after reporting why --limit is invalid.
	 *
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
	 */
	public static function startRun(InputInterface $input, OutputInterface $output): ?ConsoleRun {
		$io = new SymfonyStyle($input, $output);

		$limit = self::readLimit(input: $input, io: $io);
		if ($limit === null) {
			return null;
		}

		$run = new ConsoleRun(io: $io, output: $output);
		$run->limit = $limit;
		$run->json = (bool)$input->getOption('json');

		return $run;
	}//end startRun()

	/**
	 * Read and validate the --limit option: an integer in [1, 100000].
	 *
	 * @param InputInterface $input Console input.
	 * @param SymfonyStyle   $io    Styled console I/O, for the error.
	 *
	 * @return integer|null The limit, or null after reporting why it is invalid.
	 *
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
	 */
	public static function readLimit(InputInterface $input, SymfonyStyle $io): ?int {
		$limitRaw = (string)$input->getOption('limit');
		if (preg_match('/^\d+$/', $limitRaw) !== 1) {
			$io->error(sprintf('limit must be an integer, got "%s"', $limitRaw));
			return null;
		}

		$limit = (int)$limitRaw;
		if ($limit < 1 || $limit > 100000) {
			$io->error(sprintf('limit must be in [1, 100000], got %d', $limit));
			return null;
		}

		return $limit;
	}//end readLimit()

	/**
	 * Write a machine-readable payload to the raw output.
	 *
	 * @param ConsoleRun           $run     The run whose output is written to.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
	 */
	public static function writeJson(ConsoleRun $run, array $payload): void {
		$run->output->writeln((string)json_encode($payload, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));
	}//end writeJson()
}//end class
