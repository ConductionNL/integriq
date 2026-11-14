<?php

/**
 * OCC command: integriq:config:export.
 *
 * Exports a configuration through the same ConfigurationService export the
 * screen uses and writes it as a directory of stable files, one per entity,
 * for a git working tree. Exits 0 when written, 1 when the export or the
 * write failed, 2 when the configuration holds nothing to export.
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
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-configuration-exports-to-a-directory-of-stable-files-req-iac-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Command\Integration;

use OCA\Integriq\Service\ConfigurationServiceInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Exports one configuration into a directory.
 *
 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-configuration-exports-to-a-directory-of-stable-files-req-iac-002
 */
class ConfigExport extends Command {

	/**
	 * Constructor.
	 *
	 * @param ConfigurationServiceInterface $configurations The export the screen uses.
	 * @param ConfigurationDirectoryCodec   $codec          Splits the export into files.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ConfigurationServiceInterface $configurations,
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
		$this->setName(name: 'integriq:config:export')
			->setDescription(description: 'Export a configuration as a directory of files, one per entity')
			->addArgument(name: 'configuration', mode: InputArgument::REQUIRED, description: 'The configuration id')
			->addArgument(name: 'directory', mode: InputArgument::REQUIRED, description: 'The directory to write, e.g. a git working tree');

	}//end configure()

	/**
	 * Export, split and write; print the files that changed and the entities without a slug.
	 *
	 * @param InputInterface  $input  The console input.
	 * @param OutputInterface $output The console output.
	 *
	 * @return int 0 when written, 1 on a failure, 2 when the configuration holds nothing.
	 *
	 * @spec openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-configuration-exports-to-a-directory-of-stable-files-req-iac-002
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$configuration = (string) $input->getArgument('configuration');
		$directory     = (string) $input->getArgument('directory');

		try {
			$split = $this->codec->split(
				document: $this->configurations->exportConfiguration(configurationId: $configuration),
				configuration: $configuration
			);
		} catch (\Throwable $e) {
			$output->writeln(sprintf('<error>The export failed: %s</error>', $e->getMessage()));
			return IntegrationCommand::EXIT_FAILED;
		}

		if (count($split['files']) === 1) {
			$output->writeln(sprintf('<error>Configuration "%s" holds nothing to export.</error>', $configuration));
			return IntegrationCommand::EXIT_NOT_FOUND;
		}

		foreach ($split['warnings'] as $warning) {
			$output->writeln('<comment>'.$warning.'</comment>');
		}

		try {
			$changed = $this->codec->writeDirectory(directory: $directory, files: $split['files']);
		} catch (\Throwable $e) {
			$output->writeln(sprintf('<error>Writing %s failed: %s</error>', $directory, $e->getMessage()));
			return IntegrationCommand::EXIT_FAILED;
		}

		$output->writeln(sprintf('%d files in %s, %d changed.', count($split['files']), $directory, count($changed)));
		foreach ($changed as $path) {
			$output->writeln('  '.$path);
		}

		return IntegrationCommand::EXIT_OK;

	}//end execute()

}//end class
