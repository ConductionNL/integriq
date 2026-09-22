<?php

/**
 * Integriq KafkaRestTransport.
 *
 * Publishes a CloudEvent to Kafka over the Confluent REST Proxy. The proxy
 * answers `200 OK` with a per-record `error_code` when the produce failed, so
 * this transport reads the record and not only the status. A produce that
 * failed under a 200 looks exactly like one that succeeded to anything
 * reading the status alone.
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
 * Publishes to Kafka over the Confluent REST Proxy.
 *
 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-broker-that-accepted-a-message-it-delivered-to-nobody-is-a-failure-req-014
 */
class KafkaRestTransport implements BrokerTransportInterface {

	/**
	 * The broker id.
	 *
	 * @var string
	 */
	public const BROKER_ID = 'kafka-rest';

	/**
	 * The media type the v2 JSON produce endpoint takes.
	 *
	 * @var string
	 */
	private const PRODUCE_MEDIA_TYPE = 'application/vnd.kafka.json.v2+json';

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService The Nextcloud HTTP client factory.
	 */
	public function __construct(
		private readonly IClientService $clientService,
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
	 * Structured only. The produce endpoint takes a JSON record, so the whole
	 * CloudEvent travels as the record value; there are no HTTP headers for a
	 * `ce-` attribute to live in once the proxy has parsed the record.
	 *
	 * @return array<string,mixed> The description.
	 *
	 * @spec openspec/changes/event-broker-transport/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
	 */
	public function describe(): array {
		return [
			'id' => self::BROKER_ID,
			'label' => 'Kafka (REST Proxy)',
			'needsTopic' => true,
			'contentModes' => [CloudEventHttpBinding::MODE_STRUCTURED],
		];

	}//end describe()

	/**
	 * Produce one record.
	 *
	 * @param BrokerPublication $publication The event and its routing.
	 * @param array<string,mixed> $configuration `{baseUrl, token, username, password, headers}`.
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
				'The subscription names no Kafka REST Proxy baseUrl.'
			);
		}

		$topic = $publication->getTopic();
		if ($topic === '') {
			return BrokerResult::unconfigured(
				self::BROKER_ID,
				'The subscription names no topic, and Kafka produces into one.'
			);
		}

		$record = ['value' => $publication->getCloudEvent()];

		$key = $publication->getOrderingKey();
		if ($key !== null && $key !== '') {
			// Kafka keeps records with the same key in one partition, which is
			// the only ordering guarantee it gives. Without a key the broker
			// round-robins and two events about one object can arrive swapped.
			$record['key'] = $key;
		}

		$headers = [
			'Content-Type' => self::PRODUCE_MEDIA_TYPE,
			'Accept' => 'application/vnd.kafka.v2+json',
		];

		$token = trim((string)($configuration['token'] ?? ''));
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$options = [
			'headers' => array_merge($headers, $publication->getHeaders()),
			'json' => ['records' => [$record]],
			'timeout' => 30,
		];

		$username = trim((string)($configuration['username'] ?? ''));
		if ($username !== '') {
			$options['auth'] = [$username, (string)($configuration['password'] ?? '')];
		}

		try {
			$response = $this->clientService->newClient()->post(
				rtrim($baseUrl, '/') . '/topics/' . rawurlencode($topic),
				$options
			);
		} catch (Throwable $exception) {
			return BrokerResult::refused(
				self::BROKER_ID,
				'The Kafka REST Proxy refused the produce: ' . $exception->getMessage()
			);
		}

		$statusCode = $response->getStatusCode();
		if ($statusCode < 200 || $statusCode >= 300) {
			return BrokerResult::refused(
				self::BROKER_ID,
				'The Kafka REST Proxy answered ' . $statusCode . ' to the produce.',
				$statusCode
			);
		}

		return $this->readOffsets(
			body: (string)$response->getBody(),
			statusCode: $statusCode,
			publication: $publication
		);

	}//end publish()

	/**
	 * Read the produce answer's per-record result.
	 *
	 * @param string $body The response body.
	 * @param integer $statusCode The response status.
	 * @param BrokerPublication $publication The publication.
	 *
	 * @return BrokerResult What the record says happened.
	 */
	private function readOffsets(string $body, int $statusCode, BrokerPublication $publication): BrokerResult {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false) {
			return BrokerResult::refused(
				self::BROKER_ID,
				'The Kafka REST Proxy answered ' . $statusCode . ' with a body that is not a produce response.',
				$statusCode
			);
		}

		$offsets = ($decoded['offsets'] ?? []);
		if (is_array($offsets) === false || $offsets === []) {
			return BrokerResult::refused(
				self::BROKER_ID,
				'The Kafka REST Proxy answered ' . $statusCode . ' with no offsets, so no record was produced.',
				$statusCode
			);
		}

		$first = ($offsets[0] ?? []);
		if (is_array($first) === false) {
			$first = [];
		}

		$errorCode = ($first['error_code'] ?? null);
		if ($errorCode !== null) {
			// THE SILENT SUCCESS THIS TRANSPORT EXISTS TO CATCH. The proxy
			// answers 200 for the request and reports the produce failure per
			// record, so a transport reading the status alone records a
			// delivery Kafka never accepted.
			return BrokerResult::refused(
				self::BROKER_ID,
				'The Kafka REST Proxy reported error_code ' . (string)$errorCode
				. ' on the record: ' . (string)($first['error'] ?? 'no reason given'),
				$statusCode
			);
		}

		$reference = $publication->getEventId();
		$offset = ($first['offset'] ?? null);
		if ($offset !== null) {
			$reference = $publication->getTopic() . '@' . (string)($first['partition'] ?? '0') . ':' . (string)$offset;
		}

		return BrokerResult::published(self::BROKER_ID, $reference);

	}//end readOffsets()

}//end class
