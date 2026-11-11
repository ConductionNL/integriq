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
 * @spec openspec/specs/events-cloudevents/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Broker\Transport;

use OCA\Integriq\Broker\BrokerPublication;
use OCA\Integriq\Broker\BrokerResult;
use OCA\Integriq\Broker\BrokerTransportInterface;
use OCA\Integriq\Broker\CloudEventHttpBinding;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Throwable;

/**
 * Publishes to Kafka over the Confluent REST Proxy.
 *
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-broker-that-accepted-a-message-it-delivered-to-nobody-is-a-failure-req-014
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
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
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
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-a-broker-kind-req-013
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
	 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-broker-that-accepted-a-message-it-delivered-to-nobody-is-a-failure-req-014
	 */
	public function publish(BrokerPublication $publication, array $configuration): BrokerResult {
		$unconfigured = $this->unconfigured(publication: $publication, configuration: $configuration);
		if ($unconfigured !== null) {
			return $unconfigured;
		}

		$response = $this->produce(publication: $publication, configuration: $configuration);
		if ($response instanceof BrokerResult) {
			return $response;
		}

		return $this->answer(response: $response, publication: $publication);

	}//end publish()

	/**
	 * Why the subscription cannot produce at all, if it cannot.
	 *
	 * @param BrokerPublication $publication   The publication.
	 * @param array             $configuration The subscription's broker configuration.
	 *
	 * @return BrokerResult|null Not configured without a baseUrl or a topic, else null.
	 */
	private function unconfigured(BrokerPublication $publication, array $configuration): ?BrokerResult {
		if (trim((string)($configuration['baseUrl'] ?? '')) === '') {
			return BrokerResult::unconfigured(
				self::BROKER_ID,
				'The subscription names no Kafka REST Proxy baseUrl.'
			);
		}

		if ($publication->getTopic() === '') {
			return BrokerResult::unconfigured(
				self::BROKER_ID,
				'The subscription names no topic, and Kafka produces into one.'
			);
		}

		return null;

	}//end unconfigured()

	/**
	 * Post the record to the topic.
	 *
	 * @param BrokerPublication $publication   The publication.
	 * @param array             $configuration The subscription's broker configuration.
	 *
	 * @return IResponse|BrokerResult The proxy's answer, or a refusal when the call itself failed.
	 */
	private function produce(BrokerPublication $publication, array $configuration): IResponse|BrokerResult {
		$options = $this->requestOptions(publication: $publication, configuration: $configuration);
		$url     = rtrim(trim((string)($configuration['baseUrl'] ?? '')), '/') . '/topics/' . rawurlencode($publication->getTopic());

		try {
			return $this->clientService->newClient()->post($url, $options);
		} catch (Throwable $exception) {
			return BrokerResult::refused(
				self::BROKER_ID,
				'The Kafka REST Proxy refused the produce: ' . $exception->getMessage()
			);
		}

	}//end produce()

	/**
	 * Read the proxy's answer: a non-2xx status is a refusal, else the record decides.
	 *
	 * @param IResponse         $response    The proxy's answer.
	 * @param BrokerPublication $publication The publication.
	 *
	 * @return BrokerResult What happened to the record.
	 */
	private function answer(IResponse $response, BrokerPublication $publication): BrokerResult {
		$statusCode = $response->getStatusCode();
		if ($statusCode < 200 || $statusCode >= 300) {
			return $this->answered(what: ' to the produce.', statusCode: $statusCode);
		}

		return $this->readOffsets(
			body: (string)$response->getBody(),
			statusCode: $statusCode,
			publication: $publication
		);

	}//end answer()

	/**
	 * The produce request's options.
	 *
	 * @param BrokerPublication $publication   The publication.
	 * @param array             $configuration The subscription's broker configuration.
	 *
	 * @return array The client options: headers, the one record, the timeout and basic auth when a username is set.
	 */
	private function requestOptions(BrokerPublication $publication, array $configuration): array {
		$options = [
			'headers' => array_merge($this->baseHeaders(configuration: $configuration), $publication->getHeaders()),
			'json' => ['records' => [$this->recordOf(publication: $publication)]],
			'timeout' => 30,
		];

		$username = trim((string)($configuration['username'] ?? ''));
		if ($username !== '') {
			$options['auth'] = [$username, (string)($configuration['password'] ?? '')];
		}

		return $options;

	}//end requestOptions()

	/**
	 * The record to produce: the CloudEvent, keyed by the ordering key when there is one.
	 *
	 * @param BrokerPublication $publication The publication.
	 *
	 * @return array The record.
	 */
	private function recordOf(BrokerPublication $publication): array {
		$record = ['value' => $publication->getCloudEvent()];

		$key = $publication->getOrderingKey();
		if ($key !== null && $key !== '') {
			// Kafka keeps records with the same key in one partition, which is
			// the only ordering guarantee it gives. Without a key the broker
			// round-robins and two events about one object can arrive swapped.
			$record['key'] = $key;
		}

		return $record;

	}//end recordOf()

	/**
	 * The produce headers before the publication's own: the media types and the bearer token when set.
	 *
	 * @param array $configuration The subscription's broker configuration.
	 *
	 * @return array<string,string> The headers.
	 */
	private function baseHeaders(array $configuration): array {
		$headers = [
			'Content-Type' => self::PRODUCE_MEDIA_TYPE,
			'Accept' => 'application/vnd.kafka.v2+json',
		];

		$token = trim((string)($configuration['token'] ?? ''));
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;

	}//end baseHeaders()

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
		$offsets = $this->offsetsIn(body: $body, statusCode: $statusCode);
		if ($offsets instanceof BrokerResult) {
			return $offsets;
		}

		$first = $this->firstRecord(offsets: $offsets);
		if (($first['error_code'] ?? null) !== null) {
			// THE SILENT SUCCESS THIS TRANSPORT EXISTS TO CATCH. The proxy
			// answers 200 for the request and reports the produce failure per
			// record, so a transport reading the status alone records a
			// delivery Kafka never accepted.
			return $this->refusal(detail: $this->recordError(first: $first), statusCode: $statusCode);
		}

		return BrokerResult::published(self::BROKER_ID, $this->referenceFor(first: $first, publication: $publication));

	}//end readOffsets()

	/**
	 * The offsets of a produce response.
	 *
	 * @param string  $body       The response body.
	 * @param integer $statusCode The response status.
	 *
	 * @return array|BrokerResult The offsets, or a refusal for a body that is not a produce response or has none.
	 */
	private function offsetsIn(string $body, int $statusCode): array|BrokerResult {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false) {
			return $this->answered(what: ' with a body that is not a produce response.', statusCode: $statusCode);
		}

		$offsets = ($decoded['offsets'] ?? []);
		if (is_array($offsets) === false || $offsets === []) {
			return $this->answered(what: ' with no offsets, so no record was produced.', statusCode: $statusCode);
		}

		return $offsets;

	}//end offsetsIn()

	/**
	 * The first record's result in the offsets.
	 *
	 * @param array $offsets The produce response's offsets.
	 *
	 * @return array The first result, or none when it is not an object.
	 */
	private function firstRecord(array $offsets): array {
		$first = ($offsets[0] ?? []);
		if (is_array($first) === false) {
			return [];
		}

		return $first;

	}//end firstRecord()

	/**
	 * What a record's error says.
	 *
	 * @param array $first The record's result, carrying an error_code.
	 *
	 * @return string The refusal detail.
	 */
	private function recordError(array $first): string {
		return 'The Kafka REST Proxy reported error_code ' . (string)$first['error_code']
			. ' on the record: ' . (string)($first['error'] ?? 'no reason given');

	}//end recordError()

	/**
	 * The reference of a produced record: topic@partition:offset, or the event id without an offset.
	 *
	 * @param array             $first       The record's result.
	 * @param BrokerPublication $publication The publication.
	 *
	 * @return string The reference.
	 */
	private function referenceFor(array $first, BrokerPublication $publication): string {
		$offset = ($first['offset'] ?? null);
		if ($offset === null) {
			return $publication->getEventId();
		}

		return $publication->getTopic() . '@' . (string)($first['partition'] ?? '0') . ':' . (string)$offset;

	}//end referenceFor()

	/**
	 * A refusal that says what the proxy answered.
	 *
	 * @param string  $what       What came with the status, e.g. " to the produce.".
	 * @param integer $statusCode The response status.
	 *
	 * @return BrokerResult The refusal.
	 */
	private function answered(string $what, int $statusCode): BrokerResult {
		return $this->refusal(detail: 'The Kafka REST Proxy answered ' . $statusCode . $what, statusCode: $statusCode);

	}//end answered()

	/**
	 * A refusal with the proxy's status.
	 *
	 * @param string  $detail     Why the record was not produced.
	 * @param integer $statusCode The response status.
	 *
	 * @return BrokerResult The refusal.
	 */
	private function refusal(string $detail, int $statusCode): BrokerResult {
		return BrokerResult::refused(self::BROKER_ID, $detail, $statusCode);

	}//end refusal()

}//end class
