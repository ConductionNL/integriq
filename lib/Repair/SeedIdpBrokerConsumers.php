<?php

/**
 * Integriq SeedIdpBrokerConsumers.
 *
 * Seeds the `portaliq` consumer of the government identity broker, disabled,
 * with no secret and no return address, so enabling it is one command:
 *
 *   occ integriq:idp:consumer portaliq --return-url=<address> --secret-ref=<credential>
 *
 * It never overwrites. An entry that already exists, in either form, is the
 * administrator's and stays exactly as it is; a disabled seed written over a
 * live consumer would sign every resident out of the portal on the next
 * upgrade.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
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
 *
 * @psalm-suppress UnusedClass Nextcloud instantiates repair steps from the
 *  `<repair-steps>` block in appinfo/info.xml, which psalm does not read.
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Auth\Idp\IdpBrokerConfig;
use OCA\Integriq\Auth\Idp\IdpConsumer;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Seeds the disabled portaliq consumer once.
 *
 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
 */
class SeedIdpBrokerConsumers implements IRepairStep {

	/**
	 * The consumers seeded, disabled.
	 *
	 * @var array<int,string>
	 */
	public const SEEDED = ['portaliq'];

	/**
	 * Constructor.
	 *
	 * @param IdpBrokerConfig $config The broker's settings.
	 */
	public function __construct(
		private readonly IdpBrokerConfig $config,
	) {

	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function getName(): string {
		return 'Seed the disabled identity broker consumers';

	}//end getName()

	/**
	 * Seed each missing consumer, disabled.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/identity-broker-browser-login/specs/digid-eherkenning-auth-adapter/spec.md#requirement-a-consuming-app-is-registered-with-its-return-addresses-req-idp-003
	 */
	public function run(IOutput $output): void {
		foreach (self::SEEDED as $consumer) {
			if ($this->config->hasConsumer(consumer: $consumer) === true) {
				continue;
			}

			$this->config->saveConsumer(consumer: new IdpConsumer(id: $consumer, enabled: false));
			$output->info('Seeded the disabled identity broker consumer ' . $consumer . '.');
		}

	}//end run()

}//end class
