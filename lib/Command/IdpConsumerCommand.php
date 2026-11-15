<?php

/**
 * Integriq IdpConsumerCommand.
 *
 * Registers one app that may start a government login at integriq:
 *
 *   occ integriq:idp:consumer portaliq \
 *     --return-url=https://portal.example.nl/index.php/apps/portaliq/portal/api/session/broker/callback \
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
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Command;

use OCA\Integriq\Auth\Idp\IdpBrokerConfigInterface;
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
 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
 */
class IdpConsumerCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param IdpBrokerConfigInterface $config The broker's settings.
	 */
	public function __construct(
		private readonly IdpBrokerConfigInterface $config,
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * Declare the command.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
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
	 * @spec openspec/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$request = new IdpConsumerRequest(input: $input, output: $output);
		$request->id = trim((string)$input->getArgument('consumer'));
		if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $request->id) !== 1) {
			return $this->refused(output: $output, message: 'A consumer id is lowercase letters, digits, dashes and underscores.');
		}

		$consumer = $this->consumerFor(request: $request);
		if ($consumer === null) {
			return 1;
		}

		$this->config->saveConsumer(consumer: $consumer);
		$this->report(request: $request, consumer: $consumer);

		return 0;

	}//end execute()

	/**
	 * The consumer the run registers, or null when it refused (and said why).
	 *
	 * @param IdpConsumerRequest $request The run; takes the settled return addresses and secret reference.
	 *
	 * @return IdpConsumer|null The consumer to save.
	 */
	private function consumerFor(IdpConsumerRequest $request): ?IdpConsumer {
		// A consumer nobody registered reads as an empty, disabled one, so
		// every option below falls back to the same place.
		$existing = ($this->config->consumer(consumer: $request->id) ?? new IdpConsumer(id: $request->id, enabled: false));

		$returnUrls = $this->returnUrls(input: $request->input, output: $request->output, existing: $existing);
		if ($returnUrls === null) {
			return null;
		}

		$request->returnUrls = $returnUrls;
		$request->secretRef = $this->option(input: $request->input, name: 'secret-ref', fallback: $existing->getSecretRef());
		if ($this->refusesLegacy(request: $request, existing: $existing) === true) {
			return null;
		}

		return $this->built(request: $request, existing: $existing);

	}//end consumerFor()

	/**
	 * Refuse to move an inline-secret consumer without a secret reference.
	 *
	 * @param IdpConsumerRequest $request  The run, with its settled secret reference.
	 * @param IdpConsumer        $existing The stored consumer.
	 *
	 * @return bool True when the run was refused (and said why).
	 */
	private function refusesLegacy(IdpConsumerRequest $request, IdpConsumer $existing): bool {
		if ($existing->isLegacy() === false || $request->secretRef !== '') {
			return false;
		}

		// The inline secret is not carried into the new form: it would
		// put a plaintext secret back into app config under a new shape.
		$this->refused(
			output: $request->output,
			message: $request->id . ' holds its secret inline. Give --secret-ref with a credential broker reference to move it.'
		);
		return true;

	}//end refusesLegacy()

	/**
	 * The consumer from the run's settled values and the stored organisation as fallback.
	 *
	 * @param IdpConsumerRequest $request  The run.
	 * @param IdpConsumer        $existing The stored (or empty) consumer.
	 *
	 * @return IdpConsumer The consumer.
	 */
	private function built(IdpConsumerRequest $request, IdpConsumer $existing): IdpConsumer {
		return new IdpConsumer(
			id: $request->id,
			enabled: ($request->input->getOption('disable') !== true),
			returnUrls: $request->returnUrls,
			secretRef: $request->secretRef,
			secretOrganisation: $this->option(
				input: $request->input,
				name: 'secret-organisation',
				fallback: $existing->getSecretOrganisation()
			)
		);

	}//end built()

	/**
	 * Say what was saved, and warn when an enabled consumer still cannot sign anybody in.
	 *
	 * @param IdpConsumerRequest $request  The run.
	 * @param IdpConsumer        $consumer The saved consumer.
	 *
	 * @return void
	 */
	private function report(IdpConsumerRequest $request, IdpConsumer $consumer): void {
		$state = 'disabled';
		if ($consumer->isEnabled() === true) {
			$state = 'enabled';
		}

		$request->output->writeln($request->id . ' is ' . $state . ' with ' . count($request->returnUrls) . ' return address(es).');
		if ($consumer->isEnabled() === true && ($request->returnUrls === [] || $request->secretRef === '')) {
			$request->output->writeln('<comment>' . $request->id . ' cannot sign anybody in until it has a return address and a secret reference.</comment>');
		}

	}//end report()

	/**
	 * Say why the run stops.
	 *
	 * @param OutputInterface $output  The output.
	 * @param string          $message Why.
	 *
	 * @return int The failure exit code.
	 */
	private function refused(OutputInterface $output, string $message): int {
		$output->writeln('<error>' . $message . '</error>');
		return 1;

	}//end refused()

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
