<?php

/**
 * Integriq CloudEventsHttpTransport.
 *
 * Publishes a CloudEvent to any CloudEvents HTTP sink: a Knative broker, an
 * Azure Event Grid topic, or a broker sitting behind its own gateway. It is
 * the generic transport, and the only one that speaks binary content mode end
 * to end, because a `ce-` header survives the whole way to the sink.
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
 * Publishes to a CloudEvents HTTP sink.
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-cloudevents-travel-in-structured-or-binary-content-mode-req-015
 */
class CloudEventsHttpTransport implements BrokerTransportInterface {

	/**
	 * The broker id.
	 *
	 * @var string
	 */
	public const BROKER_ID = 'cloudevents-http';

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
			'label' => 'CloudEvents HTTP sink',
			'needsTopic' => false,
			'contentModes' => [CloudEventHttpBinding::MODE_STRUCTURED, CloudEventHttpBinding::MODE_BINARY],
		];

	}//end describe()

	/**
	 * Post one event to the sink.
	 *
	 * @param BrokerPublication $publication The event and its routing.
	 * @param array<string,mixed> $configuration `{baseUrl, token, headers}`.
	 *
	 * @return BrokerResult What happened.
	 *
	 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-cloudevents-travel-in-structured-or-binary-content-mode-req-015
	 */
	public function publish(BrokerPublication $publication, array $configuration): BrokerResult {
		$baseUrl = trim((string)($configuration['baseUrl'] ?? ''));
		if ($baseUrl === '') {
			return BrokerResult::unconfigured(
				self::BROKER_ID,
				'The subscription names no CloudEvents sink baseUrl.'
			);
		}

		$url = rtrim($baseUrl, '/');
		$topic = $publication->getTopic();
		if ($topic !== '') {
			$url .= '/' . ltrim($topic, '/');
		}

		$rendered = $this->binding->render(
			cloudEvent: $publication->getCloudEvent(),
			contentMode: $publication->getContentMode()
		);

		$headers = $rendered['headers'];
		$token = trim((string)($configuration['token'] ?? ''));
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		try {
			$response = $this->clientService->newClient()->post(
				$url,
				[
					'headers' => array_merge($headers, $publication->getHeaders()),
					'body' => $rendered['body'],
					'timeout' => 30,
				]
			);
		} catch (Throwable $exception) {
			return BrokerResult::refused(
				self::BROKER_ID,
				'The sink refused the event: ' . $exception->getMessage()
			);
		}

		$statusCode = $response->getStatusCode();
		if ($statusCode < 200 || $statusCode >= 300) {
			return BrokerResult::refused(
				self::BROKER_ID,
				'The sink answered ' . $statusCode . ' to the event.',
				$statusCode
			);
		}

		return BrokerResult::published(self::BROKER_ID, $publication->getEventId());

	}//end publish()

}//end class
