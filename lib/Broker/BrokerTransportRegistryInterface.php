<?php

/**
 * Contract of the BrokerTransportRegistry.
 *
 * @category Contract
 * @package  OCA\Integriq\Broker
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Broker;

use OCA\Integriq\Exception\BrokerTransportException;

/**
 * Public operations of {@see BrokerTransportRegistry} that other components call.
 */
interface BrokerTransportRegistryInterface {
	/**
	 * Add one transport.
	 *
	 * @param BrokerTransportInterface $transport The transport.
	 *
	 * @return boolean True when it was taken, false when its id was already claimed.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function register(BrokerTransportInterface $transport): bool;

	/**
	 * Whether a broker id has a transport.
	 *
	 * @param string $brokerId The broker id.
	 *
	 * @return boolean True when it has.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function has(string $brokerId): bool;

	/**
	 * The transport for one broker id.
	 *
	 * @param string $brokerId The broker id.
	 *
	 * @return BrokerTransportInterface The transport.
	 *
	 * @throws BrokerTransportException When nothing answers to that id.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function get(string $brokerId): BrokerTransportInterface;

	/**
	 * Every broker id, sorted.
	 *
	 * @return array<int,string> The broker ids.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function getBrokerIds(): array;

	/**
	 * What every transport needs and can do.
	 *
	 * @return array<int,array<string,mixed>> The descriptions, in broker id order.
	 *
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function describeAll(): array;
}
