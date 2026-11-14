<?php

/**
 * Integriq Integration List Command.
 *
 * The three list commands: `integriq:source:list`,
 * `integriq:synchronization:list` and `integriq:job:list`, one class each
 * over this base.
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

use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists the objects of one schema.
 */
abstract class IntegrationListCommand extends IntegrationCommand {

	/**
	 * The schema listed.
	 *
	 * @return string The schema.
	 */
	abstract protected function schema(): string;

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec exclude Symfony console wiring, no domain behaviour.
	 */
	protected function configure(): void {
		$this->setName(name: 'integriq:'.$this->schema().':list')
			->setDescription(description: 'List every '.$this->schema().' with its slug and uuid');
		$this->addOutputOption();

	}//end configure()

	/**
	 * Print the objects.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int The exit code, always 0.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-sources-synchronizations-and-jobs-can-be-listed-run-and-tested-with-occ-req-iac-001
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$rows = [];
		foreach ($this->lookup->list(schema: $this->schema()) as $object) {
			$data   = (array) $object->getObject();
			$rows[] = [
				'uuid' => (string) ($object->getUuid() ?? ($data['id'] ?? '')),
				'slug' => (string) ($data['slug'] ?? ''),
				'name' => (string) ($data['name'] ?? ''),
			];
		}

		usort($rows, static fn (array $a, array $b): int => strcmp($a['slug'].$a['uuid'], $b['slug'].$b['uuid']));

		if ($this->wantsJson(input: $input) === true) {
			$output->writeln((string) json_encode($rows, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
			return self::EXIT_OK;
		}

		$table = new Table(output: $output);
		$table->setHeaders(headers: ['uuid', 'slug', 'name']);
		$table->setRows(rows: array_map(static fn (array $row): array => array_values($row), $rows));
		$table->render();

		return self::EXIT_OK;

	}//end execute()

}//end class
