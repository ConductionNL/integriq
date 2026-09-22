<?php

/**
 * Integriq BrokerPublication.
 *
 * One CloudEvent on its way to a broker, with the routing the subscription
 * asked for. A transport reads this and never the subscription, which is what
 * keeps subscription shape out of every transport.
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

/**
 * One event on its way to a broker.
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
 */
final class BrokerPublication {

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $cloudEvent The whole CloudEvent.
	 * @param string $topic The exchange, topic or path the broker publishes into.
	 * @param string $routingKey The routing key, for a broker that has one. Empty otherwise.
	 * @param string $contentMode `structured` or `binary`.
	 * @param string|null $orderingKey The key that keeps related events in order, when the broker honours one.
	 * @param array<string,string> $headers Extra outbound headers the subscription configured.
	 */
	public function __construct(
		private readonly array $cloudEvent,
		private readonly string $topic,
		private readonly string $routingKey = '',
		private readonly string $contentMode = CloudEventHttpBinding::MODE_STRUCTURED,
		private readonly ?string $orderingKey = null,
		private readonly array $headers = [],
	) {

	}//end __construct()

	/**
	 * The CloudEvent.
	 *
	 * @return array<string,mixed> The event.
	 */
	public function getCloudEvent(): array {
		return $this->cloudEvent;

	}//end getCloudEvent()

	/**
	 * The exchange, topic or path.
	 *
	 * @return string The topic.
	 */
	public function getTopic(): string {
		return $this->topic;

	}//end getTopic()

	/**
	 * The routing key, or the event type when the subscription named none.
	 *
	 * A broker that routes on a key and receives an empty one delivers to
	 * nothing, so the event type is the default rather than the empty string.
	 *
	 * @return string The routing key.
	 */
	public function getRoutingKey(): string {
		if ($this->routingKey !== '') {
			return $this->routingKey;
		}

		return (string)($this->cloudEvent['type'] ?? '');

	}//end getRoutingKey()

	/**
	 * The content mode.
	 *
	 * @return string `structured` or `binary`.
	 */
	public function getContentMode(): string {
		return $this->contentMode;

	}//end getContentMode()

	/**
	 * The ordering key.
	 *
	 * @return string|null The key, or null.
	 */
	public function getOrderingKey(): ?string {
		return $this->orderingKey;

	}//end getOrderingKey()

	/**
	 * The extra outbound headers.
	 *
	 * @return array<string,string> The headers.
	 */
	public function getHeaders(): array {
		return $this->headers;

	}//end getHeaders()

	/**
	 * The event's own id, for logging and for a broker message id.
	 *
	 * @return string The id, or an empty string when the event carries none.
	 */
	public function getEventId(): string {
		return (string)($this->cloudEvent['id'] ?? '');

	}//end getEventId()

}//end class
