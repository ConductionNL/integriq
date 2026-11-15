<?php

/**
 * Integriq WarnSupersededIdpBrokerKeys repair step.
 *
 * The identity provider broker kept three single-tenant JSON maps in app
 * config: the SP entity ids, the HMAC salts and the trust aliases. Each now
 * lives on a per-organisation identity provider registration. This step logs
 * one warning per key that still holds a value, naming the key and what
 * replaces it, and never the value: the salts map held plaintext salts.
 * It changes nothing and never throws.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

use OCA\Integriq\Auth\Idp\IdpBrokerConfig;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Warns about superseded identity provider broker keys that still hold a value.
 *
 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
 */
class WarnSupersededIdpBrokerKeys implements IRepairStep {

	/**
	 * Each superseded key and what replaces it.
	 *
	 * @var array<string,string>
	 */
	public const SUPERSEDED = [
		IdpBrokerConfig::KEY_ENTITY_IDS => 'the spEntityId of each identity provider registration',
		IdpBrokerConfig::KEY_SALTS => 'a salt credential in Keepiq, referenced from each identity provider registration',
		IdpBrokerConfig::KEY_TRUST_ALIASES => 'the assuranceMapping of each identity provider registration',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig The app config holding the keys.
	 * @param LoggerInterface $logger    Receives one warning per key that holds a value.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
	 */
	public function getName(): string {
		return 'Warn about identity provider broker settings that registrations replace';

	}//end getName()

	/**
	 * Log one warning per superseded key that still holds a value.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-idp-broker-config/specs/idp-broker-configuration/spec.md#requirement-each-organisation-has-its-own-registration-per-provider-req-idpc-002
	 */
	public function run(IOutput $output): void {
		foreach (self::SUPERSEDED as $key => $replacement) {
			if ($this->holdsValue(key: $key) === false) {
				continue;
			}

			$warning = sprintf(
				'The app setting %s is no longer read: %s replaces it. Move its contents there, then delete the setting.',
				$key,
				$replacement
			);
			$output->warning($warning);
			$this->logger->warning('[integriq] ' . $warning, ['key' => $key]);
		}

	}//end run()

	/**
	 * Whether a key holds a value. An empty JSON map or list is no value.
	 *
	 * @param string $key The app config key.
	 *
	 * @return bool
	 */
	private function holdsValue(string $key): bool {
		try {
			$value = trim($this->appConfig->getValueString(IdpBrokerConfig::APP_ID, $key, ''));
		} catch (Throwable $exception) {
			// A key stored with another type still holds something worth a warning.
			$this->logger->info('[integriq] ' . $key . ' could not be read as text (' . $exception::class . ')');
			return true;
		}

		return in_array($value, ['', '{}', '[]', 'null'], true) === false;

	}//end holdsValue()
}//end class
