<?php

/**
 * Integriq ExtensionsList command.
 *
 * `occ integriq:extensions`: what each extension app contributed, and what
 * was refused.
 *
 * @category Command
 * @package  OCA\Integriq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Command;

use OCA\Integriq\Intake\IntakeChannelRegistry;
use OCA\Integriq\Service\Catalog\ContributedTemplates;
use OCA\Integriq\Service\DigitalPost\DigitalPostProviderRegistry;
use OCA\Integriq\Service\Extension\ConnectorExtensionCollector;
use OCA\Integriq\Service\Payment\PaymentProviderRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds every registry, so the collect event runs, and lists each
 * contribution with its app, seam, id and whether it was refused. The first
 * thing to run when an extension's channel does not appear.
 *
 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
 */
class ExtensionsList extends Command {

	/**
	 * Constructor.
	 *
	 * @param ConnectorExtensionCollector $collector   Holds the record of every contribution.
	 * @param IntakeChannelRegistry       $intake      The intake channel registry.
	 * @param DigitalPostProviderRegistry $digitalPost The digital post provider registry.
	 * @param PaymentProviderRegistry     $payments    The payment provider registry.
	 * @param ContributedTemplates|null   $templates   The contributed connector templates, read so they are listed too.
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
	 */
	public function __construct(
		private readonly ConnectorExtensionCollector $collector,
		private readonly IntakeChannelRegistry $intake,
		private readonly DigitalPostProviderRegistry $digitalPost,
		private readonly PaymentProviderRegistry $payments,
		private readonly ?ContributedTemplates $templates = null,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and the --json option.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
	 */
	protected function configure(): void {
		$this->setName(name: 'integriq:extensions')
			->setDescription(description: 'List what each extension app contributed to integriq, and what was refused')
			->addOption(name: 'json', mode: InputOption::VALUE_NONE, description: 'Print the list as JSON');
	}//end configure()

	/**
	 * Build the registries and print the record.
	 *
	 * @param InputInterface  $input  The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return int 0, or 1 when a contribution was refused.
	 *
	 * @spec openspec/changes/platform-connector-extension-kit/specs/connector-extension-kit/spec.md#requirement-an-administrator-sees-what-each-extension-contributed-req-cxk-003
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		// Using each registry once makes it ask the extension apps.
		$this->intake->getChannelIds();
		$this->digitalPost->ids();
		$this->payments->ids();
		$this->templates?->cards();

		$record  = $this->collector->record();
		$refused = count(array_filter($record, static fn (array $entry): bool => $entry['refused'] === true));

		if ($input->getOption('json') === true) {
			$output->writeln((string)json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		} else if ($record === []) {
			$output->writeln('No extension app contributed anything.');
		} else {
			foreach ($record as $entry) {
				$state = 'registered';
				if ($entry['refused'] === true) {
					$state = 'REFUSED: ' . $entry['reason'];
				}

				$output->writeln(sprintf('%s  %s  %s  %s  (%s)', $entry['appId'], $entry['seam'], $entry['id'], $state, $entry['class']));
			}
		}

		if ($refused > 0) {
			return 1;
		}

		return 0;
	}//end execute()
}//end class
