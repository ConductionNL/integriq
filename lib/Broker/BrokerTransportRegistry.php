<?php

/**
 * Integriq BrokerTransportRegistry.
 *
 * Holds the broker transports this instance has, keyed by broker id, with the
 * same first-wins collision policy the intake channel registry uses: a second
 * transport claiming a taken id is refused and logged, so a broker never
 * quietly changes behaviour because a later registration overwrote it.
 *
 * @category Broker
 * @package  OCA\Integriq\Broker
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
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Broker;

use OCA\Integriq\Exception\BrokerTransportException;
use Psr\Log\LoggerInterface;

/**
 * The broker transports this instance knows.
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
 */
class BrokerTransportRegistry {

	/**
	 * The transports, keyed by broker id.
	 *
	 * @var array<string,BrokerTransportInterface>
	 */
	private array $transports = [];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Records a refused collision.
	 * @param array<int,BrokerTransportInterface> $transports The transports registered at boot.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		array $transports = [],
	) {
		foreach ($transports as $transport) {
			$this->register(transport: $transport);
		}

	}//end __construct()

	/**
	 * Add one transport.
	 *
	 * @param BrokerTransportInterface $transport The transport.
	 *
	 * @return boolean True when it was taken, false when its id was already claimed.
	 *
	 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function register(BrokerTransportInterface $transport): bool {
		$brokerId = $transport->getId();
		if (isset($this->transports[$brokerId]) === true) {
			$this->logger->warning(
				'Integriq broker: a second transport claimed an existing broker id and was refused.',
				[
					'broker' => $brokerId,
					'kept' => $this->transports[$brokerId]::class,
					'refused' => $transport::class,
				]
			);
			return false;
		}

		$this->transports[$brokerId] = $transport;
		return true;

	}//end register()

	/**
	 * Whether a broker id has a transport.
	 *
	 * @param string $brokerId The broker id.
	 *
	 * @return boolean True when it has.
	 *
	 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function has(string $brokerId): bool {
		return isset($this->transports[$brokerId]);

	}//end has()

	/**
	 * The transport for one broker id.
	 *
	 * @param string $brokerId The broker id.
	 *
	 * @return BrokerTransportInterface The transport.
	 *
	 * @throws BrokerTransportException When nothing answers to that id.
	 *
	 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function get(string $brokerId): BrokerTransportInterface {
		if (isset($this->transports[$brokerId]) === false) {
			throw new BrokerTransportException(
				message: 'No broker transport answers to "' . $brokerId . '".'
			);
		}

		return $this->transports[$brokerId];

	}//end get()

	/**
	 * Every broker id, sorted.
	 *
	 * @return array<int,string> The broker ids.
	 *
	 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function getBrokerIds(): array {
		$ids = array_keys($this->transports);
		sort($ids);
		return $ids;

	}//end getBrokerIds()

	/**
	 * What every transport needs and can do.
	 *
	 * @return array<int,array<string,mixed>> The descriptions, in broker id order.
	 *
	 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function describeAll(): array {
		$described = [];
		foreach ($this->getBrokerIds() as $brokerId) {
			$described[] = $this->transports[$brokerId]->describe();
		}

		return $described;

	}//end describeAll()

}//end class
