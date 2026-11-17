<?php

/**
 * Integriq Integration Command.
 *
 * What the list, run and test commands share: the `--output` option, the
 * three exit codes and the not-found message. 0 is success, 1 a failed run or
 * test, 2 an object that was not found, so a script can tell "it broke" from
 * "I named the wrong thing".
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

use OCA\OpenRegister\Db\ObjectEntity;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base of the integriq object commands.
 */
abstract class IntegrationCommand extends Command {

	/**
	 * The run or test succeeded.
	 */
	public const EXIT_OK = 0;

	/**
	 * The run or test failed.
	 */
	public const EXIT_FAILED = 1;

	/**
	 * The object was not found.
	 */
	public const EXIT_NOT_FOUND = 2;

	/**
	 * Constructor.
	 *
	 * @param IntegrationObjectLookup $lookup Finds the objects.
	 *
	 * @return void
	 */
	public function __construct(
		protected readonly IntegrationObjectLookup $lookup,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Add the `--output` option.
	 *
	 * @return void
	 *
	 * @spec exclude Symfony console wiring, no domain behaviour.
	 */
	protected function addOutputOption(): void {
		$this->addOption(
			name: 'output',
			shortcut: null,
			mode: InputOption::VALUE_REQUIRED,
			description: 'Output format: plain or json',
			default: 'plain'
		);

	}//end addOutputOption()

	/**
	 * Whether the caller asked for JSON.
	 *
	 * @param InputInterface $input The console input.
	 *
	 * @return bool True for `--output=json`.
	 */
	protected function wantsJson(InputInterface $input): bool {
		return ((string) $input->getOption('output') === 'json');

	}//end wantsJson()

	/**
	 * Print a result: one JSON document, or one `key: value` line per field.
	 *
	 * @param InputInterface       $input  The console input.
	 * @param OutputInterface      $output The console output.
	 * @param array<string, mixed> $result The result.
	 *
	 * @return void
	 */
	protected function printResult(InputInterface $input, OutputInterface $output, array $result): void {
		if ($this->wantsJson(input: $input) === true) {
			$output->writeln((string) json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
			return;
		}

		foreach ($result as $key => $value) {
			if (is_scalar($value) === false && $value !== null) {
				$value = json_encode($value, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			}

			$output->writeln($key.': '.var_export($value, true));
		}

	}//end printResult()

	/**
	 * Resolve the object, or say that nothing was found.
	 *
	 * @param OutputInterface $output    The console output.
	 * @param string          $schema    The schema.
	 * @param string          $reference The uuid or slug the operator gave.
	 *
	 * @return ObjectEntity|null The object, or null after printing the not-found message.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-sources-synchronizations-and-jobs-can-be-listed-run-and-tested-with-occ-req-iac-001
	 */
	protected function resolveOrReport(OutputInterface $output, string $schema, string $reference): ?ObjectEntity {
		$object = $this->lookup->resolve(schema: $schema, reference: $reference);
		if ($object === null) {
			$output->writeln(sprintf('<error>No %s found for "%s" (or the slug names more than one).</error>', $schema, $reference));
		}

		return $object;

	}//end resolveOrReport()

}//end class
