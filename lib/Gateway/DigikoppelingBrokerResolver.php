<?php

/**
 * Which Digikoppeling broker this instance runs over.
 *
 * @category Service
 * @package  OCA\Integriq\Gateway
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Gateway;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The broker is configuration, so moving from one to another is a save and not
 * a release. The previous choice stays in the audit trail, and an unconfigured
 * broker fails before a call is attempted rather than after one timed out.
 *
 * @spec openspec/changes/statutory-gateways-and-frameworks/specs/statutory-gateways/spec.md#requirement-the-digikoppeling-broker-is-chosen-per-instance-req-sg-002
 */
class DigikoppelingBrokerResolver {
	/**
	 * App id for app-config reads and writes.
	 */
	public const APP_ID = 'integriq';

	/**
	 * App-config key holding the selected broker id.
	 */
	public const SELECTED_KEY = 'digikoppeling.broker';

	/**
	 * App-config key holding the configured brokers.
	 */
	public const BROKERS_KEY = 'digikoppeling.brokers';

	/**
	 * App-config key holding the audit trail of broker changes.
	 */
	public const AUDIT_KEY = 'digikoppeling.broker_audit';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App configuration store.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every configured broker, keyed by id.
	 *
	 * @return array<string,array<string,mixed>> The brokers.
	 */
	public function available(): array {
		$raw = $this->appConfig->getValueString(self::APP_ID, self::BROKERS_KEY, '{}');
		$decoded = json_decode($raw, true);

		return (is_array($decoded) === true ? $decoded : []);
	}//end available()

	/**
	 * The broker a call runs over.
	 *
	 * @return array<string,mixed> The broker's configuration.
	 *
	 * @throws BrokerConfigurationException When no usable broker is configured.
	 */
	public function resolve(): array {
		$selected = $this->appConfig->getValueString(self::APP_ID, self::SELECTED_KEY, '');
		$available = $this->available();

		if ($selected === '') {
			throw new BrokerConfigurationException(
				sprintf(
					'No Digikoppeling broker is selected. Set "%s" to one of: %s. Nothing was sent.',
					self::SELECTED_KEY,
					($available === [] ? '(no brokers configured)' : implode(', ', array_keys($available)))
				)
			);
		}

		if (isset($available[$selected]) === false) {
			throw new BrokerConfigurationException(
				sprintf(
					'The selected Digikoppeling broker "%s" is not configured. Configured brokers: %s. Nothing was sent.',
					$selected,
					($available === [] ? '(none)' : implode(', ', array_keys($available)))
				)
			);
		}

		$broker = $available[$selected];
		if ((string)($broker['endpoint'] ?? '') === '') {
			throw new BrokerConfigurationException(
				sprintf('The Digikoppeling broker "%s" has no endpoint configured. Nothing was sent.', $selected)
			);
		}

		return (['id' => $selected] + $broker);
	}//end resolve()

	/**
	 * Select a broker, keeping the previous choice in the audit trail.
	 *
	 * @param string $brokerId The broker to run over.
	 * @param string $userId Who chose it.
	 *
	 * @return array<int,array<string,mixed>> The audit trail after the change.
	 *
	 * @throws BrokerConfigurationException When the broker is not configured.
	 */
	public function select(string $brokerId, string $userId = ''): array {
		$available = $this->available();
		if (isset($available[$brokerId]) === false) {
			throw new BrokerConfigurationException(
				sprintf(
					'"%s" is not a configured Digikoppeling broker. Configured brokers: %s.',
					$brokerId,
					($available === [] ? '(none)' : implode(', ', array_keys($available)))
				)
			);
		}

		$previous = $this->appConfig->getValueString(self::APP_ID, self::SELECTED_KEY, '');
		$audit = $this->audit();

		$from = $previous;
		if ($from === '') {
			$from = null;
		}

		$audit[] = [
			'from' => $from,
			'to' => $brokerId,
			'by' => $userId,
			'at' => gmdate('c'),
		];

		$this->appConfig->setValueString(self::APP_ID, self::SELECTED_KEY, $brokerId);
		$encoded = json_encode($audit);
		if ($encoded === false) {
			$encoded = '[]';
		}

		$this->appConfig->setValueString(self::APP_ID, self::AUDIT_KEY, $encoded);

		$this->logger->info('digikoppeling.broker.changed', ['from' => $previous, 'to' => $brokerId, 'by' => $userId]);

		return $audit;
	}//end select()

	/**
	 * Every broker change this instance has made.
	 *
	 * @return array<int,array<string,mixed>> The audit trail.
	 */
	public function audit(): array {
		$raw = $this->appConfig->getValueString(self::APP_ID, self::AUDIT_KEY, '[]');
		$decoded = json_decode($raw, true);

		return (is_array($decoded) === true ? $decoded : []);
	}//end audit()
}//end class
