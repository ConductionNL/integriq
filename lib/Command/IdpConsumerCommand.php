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
 * @SuppressWarnings(PHPMD.StaticAccess) IdpConsumer::isAcceptableReturnUrl is a pure check with no state to inject.
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

		// A consumer nobody registered reads as an empty, disabled one, so
		// every option below falls back to the same place.
		$existing = ($this->config->consumer(consumer: $id) ?? new IdpConsumer(id: $id, enabled: false));

		$returnUrls = $this->returnUrls(input: $input, output: $output, existing: $existing);
		if ($returnUrls === null) {
			return 1;
		}

		$secretRef = $this->option(input: $input, name: 'secret-ref', fallback: $existing->getSecretRef());
		if ($existing->isLegacy() === true && $secretRef === '') {
			// The inline secret is not carried into the new form: it would
			// put a plaintext secret back into app config under a new shape.
			$output->writeln(
				'<error>' . $id . ' holds its secret inline. Give --secret-ref with a credential broker reference to move it.</error>'
			);
			return 1;
		}

		$consumer = new IdpConsumer(
			id: $id,
			enabled: ($input->getOption('disable') !== true),
			returnUrls: $returnUrls,
			secretRef: $secretRef,
			secretOrganisation: $this->option(
				input: $input,
				name: 'secret-organisation',
				fallback: $existing->getSecretOrganisation()
			)
		);
		$this->config->saveConsumer(consumer: $consumer);

		$state = 'disabled';
		if ($consumer->isEnabled() === true) {
			$state = 'enabled';
		}

		$output->writeln($id . ' is ' . $state . ' with ' . count($returnUrls) . ' return address(es).');
		if ($consumer->isEnabled() === true && ($returnUrls === [] || $secretRef === '')) {
			$output->writeln('<comment>' . $id . ' cannot sign anybody in until it has a return address and a secret reference.</comment>');
		}

		return 0;

	}//end execute()

	/**
	 * The return addresses to store: the ones asked for, or the ones already there.
	 *
	 * @param InputInterface $input The input.
	 * @param OutputInterface $output The output, for a refused address.
	 * @param IdpConsumer $existing The consumer as it is now.
	 *
	 * @return array<int,string>|null The addresses, or null when one of them is refused.
	 */
	private function returnUrls(InputInterface $input, OutputInterface $output, IdpConsumer $existing): ?array {
		$asked = array_map('strval', (array)$input->getOption('return-url'));
		if ($asked === []) {
			return $existing->getReturnUrls();
		}

		foreach ($asked as $url) {
			if (IdpConsumer::isAcceptableReturnUrl(url: $url) === false) {
				$output->writeln('<error>' . $url . ' is not an https address with a host, so it is not registered.</error>');
				return null;
			}
		}

		return array_values(array_unique($asked));

	}//end returnUrls()

	/**
	 * One string option, or the fallback when it was not given.
	 *
	 * @param InputInterface $input The input.
	 * @param string $name The option name.
	 * @param string $fallback The value already stored.
	 *
	 * @return string The value.
	 */
	private function option(InputInterface $input, string $name, string $fallback): string {
		$value = trim((string)($input->getOption($name) ?? ''));
		if ($value === '') {
			return $fallback;
		}

		return $value;

	}//end option()

}//end class
