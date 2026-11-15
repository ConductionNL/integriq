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

		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
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
			throw $this->noneSelected(available: $available);
		}

		if (isset($available[$selected]) === false) {
			throw $this->selectedUnknown(selected: $selected, available: $available);
		}

		return $this->withEndpoint(selected: $selected, broker: $available[$selected]);
	}//end resolve()

	/**
	 * The refusal for an instance that selected no broker.
	 *
	 * @param array<string,array<string,mixed>> $available The configured brokers.
	 *
	 * @return BrokerConfigurationException The refusal, naming the brokers to choose from.
	 */
	private function noneSelected(array $available): BrokerConfigurationException {
		return new BrokerConfigurationException(
			sprintf(
				'No Digikoppeling broker is selected. Set "%s" to one of: %s. Nothing was sent.',
				self::SELECTED_KEY,
				$this->known(available: $available, none: '(no brokers configured)')
			)
		);
	}//end noneSelected()

	/**
	 * The refusal for a selected broker that is not configured.
	 *
	 * @param string                            $selected  The selected broker.
	 * @param array<string,array<string,mixed>> $available The configured brokers.
	 *
	 * @return BrokerConfigurationException The refusal.
	 */
	private function selectedUnknown(string $selected, array $available): BrokerConfigurationException {
		return new BrokerConfigurationException(
			sprintf(
				'The selected Digikoppeling broker "%s" is not configured. Configured brokers: %s. Nothing was sent.',
				$selected,
				$this->known(available: $available, none: '(none)')
			)
		);
	}//end selectedUnknown()

	/**
	 * The selected broker with its id, once it has an endpoint.
	 *
	 * @param string              $selected The selected broker.
	 * @param array<string,mixed> $broker   Its configuration.
	 *
	 * @return array<string,mixed> `{id, endpoint, ...}`.
	 *
	 * @throws BrokerConfigurationException When it has no endpoint.
	 */
	private function withEndpoint(string $selected, array $broker): array {
		if ((string)($broker['endpoint'] ?? '') === '') {
			throw new BrokerConfigurationException(
				sprintf('The Digikoppeling broker "%s" has no endpoint configured. Nothing was sent.', $selected)
			);
		}

		return (['id' => $selected] + $broker);
	}//end withEndpoint()

	/**
	 * The configured broker ids for a refusal.
	 *
	 * @param array<string,array<string,mixed>> $available The configured brokers.
	 * @param string                            $none      What to say when there are none.
	 *
	 * @return string The ids, comma separated.
	 */
	private function known(array $available, string $none): string {
		if ($available === []) {
			return $none;
		}

		return implode(', ', array_keys($available));
	}//end known()

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
				sprintf('"%s" is not a configured Digikoppeling broker. Configured brokers: %s.', $brokerId, $this->known(available: $available, none: '(none)'))
			);
		}

		$previous = $this->appConfig->getValueString(self::APP_ID, self::SELECTED_KEY, '');
		$audit = $this->recordSelection(previous: $previous, change: ['to' => $brokerId, 'by' => $userId]);
		$this->logger->info('digikoppeling.broker.changed', ['from' => $previous, 'to' => $brokerId, 'by' => $userId]);

		return $audit;
	}//end select()

	/**
	 * Store the new selection and its line in the audit trail.
	 *
	 * @param string                       $previous The broker selected before, or empty.
	 * @param array{to:string,by:string}   $change   The broker selected now and who chose it.
	 *
	 * @return array<int,array<string,mixed>> The audit trail after the change.
	 */
	private function recordSelection(string $previous, array $change): array {
		$audit = $this->audit();
		$audit[] = $this->auditLine(previous: $previous, change: $change);

		$this->appConfig->setValueString(self::APP_ID, self::SELECTED_KEY, $change['to']);
		$this->storeAudit(audit: $audit);

		return $audit;
	}//end recordSelection()

	/**
	 * One line of the audit trail: from (null when nothing was selected), to, by and when.
	 *
	 * @param string                     $previous The broker selected before, or empty.
	 * @param array{to:string,by:string} $change   The broker selected now and who chose it.
	 *
	 * @return array<string,mixed> The line.
	 */
	private function auditLine(string $previous, array $change): array {
		$from = $previous;
		if ($from === '') {
			$from = null;
		}

		return [
			'from' => $from,
			'to' => $change['to'],
			'by' => $change['by'],
			'at' => gmdate('c'),
		];
	}//end auditLine()

	/**
	 * Store the audit trail.
	 *
	 * @param array<int,array<string,mixed>> $audit The audit trail.
	 *
	 * @return void
	 */
	private function storeAudit(array $audit): void {
		$encoded = json_encode($audit);
		if ($encoded === false) {
			$encoded = '[]';
		}

		$this->appConfig->setValueString(self::APP_ID, self::AUDIT_KEY, $encoded);
	}//end storeAudit()

	/**
	 * Every broker change this instance has made.
	 *
	 * @return array<int,array<string,mixed>> The audit trail.
	 */
	public function audit(): array {
		$raw = $this->appConfig->getValueString(self::APP_ID, self::AUDIT_KEY, '[]');
		$decoded = json_decode($raw, true);

		if (is_array($decoded) === true) {
			return $decoded;
		}

		return [];
	}//end audit()
}//end class
