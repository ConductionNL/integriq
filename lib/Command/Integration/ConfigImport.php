<?php

/**
 * OCC command: integriq:config:import.
 *
 * Reads a configuration directory, prints the preview of creates, updates,
 * collisions, unresolved references and sources whose credentials need
 * re-entry, and writes nothing unless `--confirm` is given. With `--confirm`
 * it imports through ConfigurationService::importConfiguration(), the path
 * the screen's import uses. Exits 0 on a preview or an import, 1 when the
 * directory is unreadable or the import failed.
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
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-directory-imports-with-a-preview-and-only-when-confirmed-req-iac-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Command\Integration;

use OCA\Integriq\Service\ConfigurationDirectoryCodec;
use OCA\Integriq\Service\ConfigurationImportPreviewServiceInterface;
use OCA\Integriq\Service\ConfigurationServiceInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Previews, and on confirmation imports, a configuration directory.
 */
class ConfigImport extends Command {

	/**
	 * The preview sections, in the order they are printed, with their headings.
	 *
	 * @var array<string, string>
	 */
	private const SECTIONS = [
		'creates' => 'Creates',
		'updates' => 'Updates',
		'collisions' => 'Collisions',
		'unresolvedReferences' => 'Unresolved references',
		'credentialsNeedingReentry' => 'Credentials to enter again',
	];

	/**
	 * Constructor.
	 *
	 * @param ConfigurationServiceInterface              $configurations The import the screen uses.
	 * @param ConfigurationImportPreviewServiceInterface $preview        The non-mutating preview the screen shows.
	 * @param ConfigurationDirectoryCodec                $codec          Joins the files into the document.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ConfigurationServiceInterface $configurations,
		private readonly ConfigurationImportPreviewServiceInterface $preview,
		private readonly ConfigurationDirectoryCodec $codec,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Configure the command.
	 *
	 * @return void
	 *
	 * @spec exclude Symfony console wiring, no domain behaviour.
	 */
	protected function configure(): void {
		$this->setName(name: 'integriq:config:import')
			->setDescription(description: 'Preview a configuration directory, and import it with --confirm')
			->addArgument(name: 'directory', mode: InputArgument::REQUIRED, description: 'The directory integriq:config:export wrote')
			->addOption(name: 'confirm', shortcut: null, mode: InputOption::VALUE_NONE, description: 'Write the import; without it nothing is written')
			->addOption(
				name: 'output',
				shortcut: null,
				mode: InputOption::VALUE_REQUIRED,
				description: 'Output format: plain or json',
				default: 'plain'
			);

	}//end configure()

	/**
	 * Preview the directory, and import it when confirmed.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int 0 on a preview or an import, 1 on a failure.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-directory-imports-with-a-preview-and-only-when-confirmed-req-iac-003
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$directory = (string) $input->getArgument('directory');
		$confirmed = ($input->getOption('confirm') === true);

		try {
			$document = $this->codec->join(files: $this->codec->readDirectory(directory: $directory));
			$preview  = $this->preview->preview(oas: $document);
		} catch (\Throwable $e) {
			$output->writeln(sprintf('<error>%s cannot be imported: %s</error>', $directory, $e->getMessage()));
			return IntegrationCommand::EXIT_FAILED;
		}

		$written = null;
		if ($confirmed === true) {
			try {
				$written = $this->writtenSlugs(imported: $this->configurations->importConfiguration(oas: $document));
			} catch (\Throwable $e) {
				$output->writeln(sprintf('<error>The import failed: %s</error>', $e->getMessage()));
				return IntegrationCommand::EXIT_FAILED;
			}
		}

		if ((string) $input->getOption('output') === 'json') {
			$result = ['confirmed' => $confirmed, 'preview' => $preview, 'written' => $written];
			$output->writeln((string) json_encode($result, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
			return IntegrationCommand::EXIT_OK;
		}

		$this->printPreview(output: $output, preview: $preview);
		if ($written === null) {
			$output->writeln('Nothing was written. Run again with --confirm to import.');
			return IntegrationCommand::EXIT_OK;
		}

		foreach ($written as $component => $slugs) {
			$list = '-';
			if ($slugs !== []) {
				$list = implode(', ', $slugs);
			}

			$output->writeln(sprintf('Imported %s: %s', $component, $list));
		}

		return IntegrationCommand::EXIT_OK;

	}//end execute()

	/**
	 * Print each preview section with one line per entry.
	 *
	 * @param OutputInterface      $output  The console output.
	 * @param array<string, mixed> $preview The preview.
	 *
	 * @return void
	 */
	private function printPreview(OutputInterface $output, array $preview): void {
		foreach (self::SECTIONS as $key => $heading) {
			$entries = (array) ($preview[$key] ?? []);
			$output->writeln(sprintf('%s: %d', $heading, count($entries)));
			foreach ($entries as $entry) {
				$output->writeln('  '.$this->describe(entry: $entry));
			}
		}

	}//end printPreview()

	/**
	 * One preview entry as a line: its type and slug, and its other fields as JSON.
	 *
	 * @param mixed $entry The entry.
	 *
	 * @return string
	 */
	private function describe(mixed $entry): string {
		if (is_array($entry) === false) {
			return (string) json_encode($entry, JSON_UNESCAPED_SLASHES);
		}

		$line = trim(((string) ($entry['type'] ?? '')).' '.((string) ($entry['slug'] ?? '')));
		unset($entry['type'], $entry['slug']);
		if ($entry !== []) {
			$line .= ' '.json_encode($entry, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		}

		return $line;

	}//end describe()

	/**
	 * The slugs importConfiguration() wrote, per component.
	 *
	 * @param array<string, mixed> $imported Its result.
	 *
	 * @return array<string, list<string>>
	 */
	private function writtenSlugs(array $imported): array {
		$written = [];
		foreach ($imported as $component => $entities) {
			$written[(string) $component] = array_map('strval', array_keys((array) $entities));
		}

		return $written;

	}//end writtenSlugs()

}//end class
