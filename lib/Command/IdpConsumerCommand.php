<?php

/**
 * Integriq IdpConsumerCommand.
 *
 * Registers one app that may start a government login at integriq:
 *
 *   occ integriq:idp:consumer portaliq \
 *     --return-url=https://portal.example.nl/portal/api/session/broker/callback \
 *     --secret-ref=<credential uuid in the OpenRegister credential broker>
 *
 * The secret itself never passes through this command or app config: the
 * command stores the broker reference, and the exchange reads the secret from
 * the broker when a code is redeemed.
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
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Command;

use OCA\Integriq\Auth\Idp\IdpBrokerConfig;
use OCA\Integriq\Auth\Idp\IdpConsumer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sets one consumer's return addresses, secret reference and enabled flag.
 *
 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
 */
class IdpConsumerCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param IdpBrokerConfig $config The broker's settings.
	 */
	public function __construct(
		private readonly IdpBrokerConfig $config,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Declare the command.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName(name: 'integriq:idp:consumer')
			->setDescription(description: 'Register an app that may start a DigiD, eHerkenning or eIDAS login, with its return addresses')
			->addArgument(name: 'consumer', mode: InputArgument::REQUIRED, description: 'The consumer id, for example portaliq')
			->addOption(
				name: 'return-url',
				mode: (InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
				description: 'An address the browser may be sent back to. Repeat for more. Replaces the list when given.'
			)
			->addOption(
				name: 'secret-ref',
				mode: InputOption::VALUE_REQUIRED,
				description: 'The credential broker reference that holds the exchange secret'
			)
			->addOption(
				name: 'secret-organisation',
				mode: InputOption::VALUE_REQUIRED,
				description: 'The organisation that owns that credential'
			)
			->addOption(name: 'disable', mode: InputOption::VALUE_NONE, description: 'Register the consumer switched off');

	}//end configure()

	/**
	 * Write the consumer.
	 *
	 * @param InputInterface $input The input.
	 * @param OutputInterface $output The output.
	 *
	 * @return integer 0 on success, 1 when the consumer cannot be written as asked.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$id = trim((string)$input->getArgument('consumer'));
		if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $id) !== 1) {
			$output->writeln('<error>A consumer id is lowercase letters, digits, dashes and underscores.</error>');
			return 1;
		}

		$existing = $this->config->consumer(consumer: $id);

		/** @var array<int,string> $asked */
		$asked = (array)$input->getOption('return-url');
		$returnUrls = [];
		if ($existing !== null) {
			$returnUrls = $existing->getReturnUrls();
		}

		if ($asked !== []) {
			$returnUrls = [];
			foreach ($asked as $url) {
				if (IdpConsumer::isAcceptableReturnUrl(url: (string)$url) === false) {
					$output->writeln('<error>' . $url . ' is not an https address with a host, so it is not registered.</error>');
					return 1;
				}

				$returnUrls[] = (string)$url;
			}
		}

		$secretRef = trim((string)($input->getOption('secret-ref') ?? ''));
		if ($secretRef === '' && $existing !== null) {
			$secretRef = $existing->getSecretRef();
		}

		$secretOrganisation = trim((string)($input->getOption('secret-organisation') ?? ''));
		if ($secretOrganisation === '' && $existing !== null) {
			$secretOrganisation = $existing->getSecretOrganisation();
		}

		if ($existing !== null && $existing->isLegacy() === true && $secretRef === '') {
			// The inline secret is not carried into the new form: it would
			// put a plaintext secret back into app config under a new shape.
			$output->writeln(
				'<error>' . $id . ' holds its secret inline. Give --secret-ref with a credential broker reference to move it.</error>'
			);
			return 1;
		}

		$enabled = ($input->getOption('disable') !== true);
		$consumer = new IdpConsumer(
			id: $id,
			enabled: $enabled,
			returnUrls: array_values(array_unique($returnUrls)),
			secretRef: $secretRef,
			secretOrganisation: $secretOrganisation
		);
		$this->config->saveConsumer(consumer: $consumer);

		$state = 'disabled';
		if ($enabled === true) {
			$state = 'enabled';
		}

		$output->writeln($id . ' is ' . $state . ' with ' . count($consumer->getReturnUrls()) . ' return address(es).');
		if ($enabled === true && ($consumer->getReturnUrls() === [] || $secretRef === '')) {
			$output->writeln('<comment>' . $id . ' cannot start a login until it has a return address and a secret reference.</comment>');
		}

		return 0;

	}//end execute()

}//end class
