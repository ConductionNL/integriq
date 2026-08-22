<?php

/**
 * OCC command: integriq:rule-to-flow.
 *
 * Task 3.3 of `flow-native-synchronization`: prints the generated
 * `trigger-object` + `switch` flow document for one Rule as it runs on one
 * Endpoint, or the reasons that rule cannot be migrated yet.
 *
 * The endpoint is a REQUIRED argument rather than a guess: a Rule carries no
 * register, schema or HTTP method of its own, and `openregister.trigger-object`
 * refuses a partial trigger. A rule attached to two endpoints has two possible
 * object scopes, and picking one silently would be a migration nobody could
 * review.
 *
 * The command WRITES NOTHING. It creates no flow, enables nothing, and leaves
 * the rule attached to its endpoint.
 *
 * Usage:
 *   occ integriq:rule-to-flow <rule> <endpoint>
 *   occ integriq:rule-to-flow <rule> <endpoint> --json
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
use OCA\Integriq\Service\RuleToFlowGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders a Rule as a disabled, reviewable trigger-object flow document.
 *
 * @spec openspec/changes/flow-native-synchronization/tasks.md#3-migration--deprecation
 */
class RuleToFlow extends Command {

	/**
	 * Constructor.
	 *
	 * @param RuleToFlowGenerator $generator The migration generator.
	 */
	public function __construct(
		private readonly RuleToFlowGenerator $generator,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Configure the command name, description, arguments and options.
	 *
	 * @return void
	 *
	 * @spec exclude Symfony console wiring — framework metadata, no domain behavior.
	 */
	protected function configure(): void {
		$this->setName(name: 'integriq:rule-to-flow')
			->setDescription(
				'Render a rule as a generated (disabled) trigger-object flow document, or explain why it cannot be'
			)
			->addArgument(
				'rule',
				InputArgument::REQUIRED,
				'The rule to render: its uuid, slug or reference'
			)
			->addArgument(
				'endpoint',
				InputArgument::REQUIRED,
				'The endpoint the rule runs on, whose register, schema and method scope the object trigger'
			)
			->addOption(
				'json',
				null,
				InputOption::VALUE_NONE,
				'Emit the flow document as JSON and nothing else'
			);

	}//end configure()

	/**
	 * Render the document, or report the refusal.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return integer 0 when a document was rendered; 1 when the rule was refused.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);

		try {
			$flow = $this->generator->generateFor(
				ruleReference: (string)$input->getArgument('rule'),
				endpointReference: (string)$input->getArgument('endpoint')
			);
		} catch (EntityNotMigratableException $refusal) {
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
}//end class
