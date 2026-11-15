<?php

/**
 * OCC command: integriq:job-to-flow.
 *
 * Task 3.3 of `flow-native-synchronization`: prints the generated
 * `trigger-schedule` flow document for one Job, or the reasons that job cannot
 * be migrated yet.
 *
 * The command WRITES NOTHING. It creates no flow, enables nothing, and leaves
 * the job exactly as it found it — it renders the document so a human can read
 * it before anything is created from it, which is the whole point of a
 * migration that ships "disabled until reviewed".
 *
 * Usage:
 *   occ integriq:job-to-flow <job>
 *   occ integriq:job-to-flow <job> --json
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

use OCA\Integriq\Exception\EntityNotMigratableException;
use OCA\Integriq\Service\JobToFlowGeneratorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Renders a Job as a disabled, reviewable trigger-schedule flow document.
 *
 * @spec openspec/changes/flow-native-synchronization/tasks.md#3-migration--deprecation
 */
class JobToFlow extends Command {
	use GeneratedFlowOutput;

	/**
	 * The exception the generator refuses with.
	 */
	private const REFUSAL = EntityNotMigratableException::class;

	/**
	 * Constructor.
	 *
	 * @param JobToFlowGeneratorInterface $generator The migration generator.
	 */
	public function __construct(
		private readonly JobToFlowGeneratorInterface $generator,
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
		$this->setName(name: 'integriq:job-to-flow')
			->setDescription(
				'Render a job as a generated (disabled) trigger-schedule flow document, or explain why it cannot be'
			)
			->addArgument(
				'job',
				InputArgument::REQUIRED,
				'The job to render: its uuid, slug or reference'
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
	 * @throws EntityNotMigratableException When the entity cannot be migrated.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	protected function generateFlow(InputInterface $input): array {
		return $this->generator->generateFor(reference: (string)$input->getArgument('job'));
	}//end generateFlow()
}//end class
