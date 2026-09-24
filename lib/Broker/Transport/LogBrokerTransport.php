<?php

/**
 * Integriq LogBrokerTransport.
 *
 * The dormant seam. It records the attempt and refuses with "broker not
 * configured", and it never reports a publish as sent. A deployment with no
 * broker therefore fails loudly on the first matched event instead of
 * accumulating delivered messages nothing received.
 *
 * @category Broker
 * @package  OCA\Integriq\Broker\Transport
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

namespace OCA\Integriq\Broker\Transport;

use OCA\Integriq\Broker\BrokerPublication;
use OCA\Integriq\Broker\BrokerResult;
use OCA\Integriq\Broker\BrokerTransportInterface;
use OCA\Integriq\Broker\CloudEventHttpBinding;
use Psr\Log\LoggerInterface;

/**
 * The transport that refuses instead of pretending.
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-an-unconfigured-broker-refuses-rather-than-reporting-success-req-016
 */
class LogBrokerTransport implements BrokerTransportInterface {

	/**
	 * The broker id.
	 *
	 * @var string
	 */
	public const BROKER_ID = 'log';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Records the attempt.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The broker id this transport answers to.
	 *
	 * @return string The broker id.
	 *
	 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-an-unconfigured-broker-refuses-rather-than-reporting-success-req-016
	 */
	public function getId(): string {
		return self::BROKER_ID;

	}//end getId()

	/**
	 * What this transport needs and can do.
	 *
	 * @return array<string,mixed> The description.
	 *
	 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-an-unconfigured-broker-refuses-rather-than-reporting-success-req-016
	 */
	public function describe(): array {
		return [
			'id' => self::BROKER_ID,
			'label' => 'No broker configured',
			'needsTopic' => false,
			'contentModes' => [CloudEventHttpBinding::MODE_STRUCTURED],
		];

	}//end describe()

	/**
	 * Record the attempt and refuse it.
	 *
	 * @param BrokerPublication $publication The event and its routing.
	 * @param array<string,mixed> $configuration Ignored: there is nothing to connect to.
	 *
	 * @return BrokerResult Always a refusal.
	 *
	 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-an-unconfigured-broker-refuses-rather-than-reporting-success-req-016
	 */
	public function publish(BrokerPublication $publication, array $configuration): BrokerResult {
		$this->logger->warning(
			'Integriq broker: a publish reached the dormant transport, so no broker is configured.',
			[
				'broker' => self::BROKER_ID,
				'topic' => $publication->getTopic(),
				'routingKey' => $publication->getRoutingKey(),
				'eventId' => $publication->getEventId(),
			]
		);

		return BrokerResult::unconfigured(
			self::BROKER_ID,
			'Broker not configured: this subscription points at the dormant transport.'
		);

	}//end publish()

}//end class
