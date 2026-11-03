<?php

/**
 * OCC command: integriq:synchronization-to-flow.
 *
 * Task 3.1 of `flow-native-synchronization`: prints the generated flow document
 * for one Synchronization, or the reasons that synchronization cannot be
 * migrated yet.
 *
 * The command WRITES NOTHING. It creates no flow, enables nothing, and touches
 * no SynchronizationContract — it renders the document so a human can read it
 * before anything is created from it, which is the whole point of a migration
 * that ships "disabled until reviewed".
 *
 * Usage:
 *   occ integriq:synchronization-to-flow <synchronization>
 *   occ integriq:synchronization-to-flow <synchronization> --json
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
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Command;

use OCA\Integriq\Exception\SynchronizationNotMigratableException;
use OCA\Integriq\Service\SynchronizationFlowGeneratorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Renders a Synchronization as a disabled, reviewable flow document.
 *
 * @spec openspec/changes/flow-native-synchronization/tasks.md#3-migration--deprecation
 */
class SynchronizationToFlow extends Command {
	use GeneratedFlowOutput;

	/**
	 * The exception the generator refuses with.
	 */
	private const REFUSAL = SynchronizationNotMigratableException::class;

	/**
	 * Constructor.
	 *
	 * @param SynchronizationFlowGeneratorInterface $generator The migration generator.
	 */
	public function __construct(
		private readonly SynchronizationFlowGeneratorInterface $generator,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Configure the command name, description, argument and options.
	 *
	 * @return void
	 *
	 * @spec exclude Symfony console wiring — framework metadata, no domain behavior.
	 */
	protected function configure(): void {
		$this->setName(name: 'integriq:synchronization-to-flow')
			->setDescription(
				'Render a synchronization as a generated (disabled) flow document, or explain why it cannot be'
			)
			->addArgument(
				'synchronization',
				InputArgument::REQUIRED,
				'The synchronization to render: its uuid, slug or reference'
			);

		$this->addJsonOption();

	}//end configure()

	/**
	 * Generate the flow document for the referenced entity.
	 *
	 * @param InputInterface $input The console input.
	 *
	 * @return array<string, mixed> The flow document.
	 *
	 * @throws SynchronizationNotMigratableException When the entity cannot be migrated.
	 */
	protected function generateFlow(InputInterface $input): array {
		return $this->generator->generateFor(reference: (string)$input->getArgument('synchronization'));
	}//end generateFlow()
}//end class
