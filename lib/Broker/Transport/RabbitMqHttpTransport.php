<?php

/**
 * Integriq RabbitMqHttpTransport.
 *
 * Publishes a CloudEvent to RabbitMQ over its management API. The API answers
 * `200 OK` with `{"routed": false}` when the exchange took the message and no
 * queue matched it, so this transport reads the body and not only the status.
 * A message that reached no queue is gone, and a transport that calls that a
 * delivery reports success for an event nobody received.
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
use OCP\Http\Client\IClientService;
use Throwable;

/**
 * Publishes to RabbitMQ over the management API.
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-broker-that-accepted-a-message-it-delivered-to-nobody-is-a-failure-req-014
 */
class RabbitMqHttpTransport implements BrokerTransportInterface {

	/**
	 * The broker id.
	 *
	 * @var string
	 */
	public const BROKER_ID = 'rabbitmq';

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService The Nextcloud HTTP client factory.
	 * @param CloudEventHttpBinding $binding Renders the event for HTTP.
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly CloudEventHttpBinding $binding,
	) {

	}//end __construct()

	/**
	 * The broker id this transport answers to.
	 *
	 * @return string The broker id.
	 *
	 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function getId(): string {
		return self::BROKER_ID;

	}//end getId()

	/**
	 * What this transport needs and can do.
	 *
	 * @return array<string,mixed> The description.
	 *
	 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function describe(): array {
		return [
			'id' => self::BROKER_ID,
			'label' => 'RabbitMQ',
			'needsTopic' => true,
			'contentModes' => [CloudEventHttpBinding::MODE_STRUCTURED, CloudEventHttpBinding::MODE_BINARY],
		];

	}//end describe()

	/**
	 * Publish one event to an exchange.
	 *
	 * @param BrokerPublication $publication The event and its routing.
	 * @param array<string,mixed> $configuration `{baseUrl, vhost, username, password, headers}`.
	 *
	 * @return BrokerResult What happened.
	 *
	 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-broker-that-accepted-a-message-it-delivered-to-nobody-is-a-failure-req-014
	 */
	public function publish(BrokerPublication $publication, array $configuration): BrokerResult {
		$baseUrl = trim((string)($configuration['baseUrl'] ?? ''));
		if ($baseUrl === '') {
			return BrokerResult::unconfigured(
				self::BROKER_ID,
				'The subscription names no RabbitMQ management baseUrl.'
			);
		}

		$exchange = $publication->getTopic();
		if ($exchange === '') {
			return BrokerResult::unconfigured(
				self::BROKER_ID,
				'The subscription names no exchange, and RabbitMQ publishes into one.'
			);
		}

		$vhost = trim((string)($configuration['vhost'] ?? '/'));
		$rendered = $this->binding->render(
			cloudEvent: $publication->getCloudEvent(),
			contentMode: $publication->getContentMode()
		);

		$url = rtrim($baseUrl, '/')
			. '/api/exchanges/' . rawurlencode($vhost)
			. '/' . rawurlencode($exchange) . '/publish';

		$body = [
			'properties' => $this->properties(publication: $publication, rendered: $rendered),
			'routing_key' => $publication->getRoutingKey(),
			'payload' => $rendered['body'],
			'payload_encoding' => 'string',
		];

		try {
			$response = $this->clientService->newClient()->post(
				$url,
				[
					'headers' => array_merge(['Accept' => 'application/json'], $publication->getHeaders()),
					'auth' => [
						(string)($configuration['username'] ?? ''),
						(string)($configuration['password'] ?? ''),
					],
					'json' => $body,
					'timeout' => 30,
				]
			);
		} catch (Throwable $exception) {
			return BrokerResult::refused(
				self::BROKER_ID,
				'RabbitMQ refused the publish: ' . $exception->getMessage()
			);
		}

		$statusCode = $response->getStatusCode();
		if ($statusCode < 200 || $statusCode >= 300) {
			return BrokerResult::refused(
				self::BROKER_ID,
				'RabbitMQ answered ' . $statusCode . ' to the publish.',
				$statusCode
			);
		}

		$decoded = json_decode((string)$response->getBody(), true);
		$routed = null;
		if (is_array($decoded) === true) {
			$routed = ($decoded['routed'] ?? null);
		}

		if ($routed !== true) {
			// THE SILENT SUCCESS THIS TRANSPORT EXISTS TO CATCH. A 200 with
			// `routed: false` means the exchange took the message and no queue
			// was bound to the routing key, so nothing received it. Reading
			// the status alone records a delivery that reached nobody.
			return BrokerResult::refused(
				self::BROKER_ID,
				'RabbitMQ accepted the message and routed it to no queue (routing key "'
				. $publication->getRoutingKey() . '").',
				$statusCode
			);
		}

		return BrokerResult::published(self::BROKER_ID, $publication->getEventId());

	}//end publish()

	/**
	 * The AMQP message properties.
	 *
	 * @param BrokerPublication $publication The publication.
	 * @param array{headers: array<string,string>, body: string} $rendered The rendered event.
	 *
	 * @return array<string,mixed> The properties.
	 */
	private function properties(BrokerPublication $publication, array $rendered): array {
		$properties = [
			'content_type' => (string)($rendered['headers']['Content-Type'] ?? CloudEventHttpBinding::CLOUDEVENTS_JSON),
			'delivery_mode' => 2,
			'headers' => $rendered['headers'],
		];

		$messageId = $publication->getEventId();
		if ($messageId !== '') {
			$properties['message_id'] = $messageId;
		}

		$orderingKey = $publication->getOrderingKey();
		if ($orderingKey !== null && $orderingKey !== '') {
			$properties['correlation_id'] = $orderingKey;
		}

		return $properties;

	}//end properties()

}//end class
