<?php

/**
 * Integriq ConsoleRun: one run of a credential-custody console command.
 *
 * The styled and raw output a command writes to, how many sources it may
 * inspect and whether it answers in JSON.
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

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One command run: where it writes, its limit and its output mode.
 *
 * @spec openspec/specs/source-credential-custody/spec.md#requirement-authentication-config-audit
 */
final class ConsoleRun {
	/**
	 * Maximum sources to inspect.
	 *
	 * @var integer
	 */
	public int $limit = 1000;

	/**
	 * Whether to emit the machine-readable payload.
	 *
	 * @var boolean
	 */
	public bool $json = false;

	/**
	 * Constructor.
	 *
	 * @param SymfonyStyle    $io     Styled console I/O.
	 * @param OutputInterface $output Raw console output (for the --json payload).
	 */
	public function __construct(
		public readonly SymfonyStyle $io,
		public readonly OutputInterface $output,
	) {
	}//end __construct()
}//end class
