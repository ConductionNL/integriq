<?php

/**
 * Integriq CloudEventReceiver.
 *
 * Stores a CloudEvent an outside system sent to its receiver URL and hands
 * it to the subscriptions, as on an event integriq produced itself. An event
 * whose id the same source already sent is not stored or routed again.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Event;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Integriq\Service\EventServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Stores a received CloudEvent once per source and id, then routes it.
 *
 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
 */
class CloudEventReceiver {

	/**
	 * Namespace of the uuid a received event is stored under. The uuid is
	 * derived from the receiver's source, the event's source and its id, so a
	 * repeat lands on the same object and is recognised without a search.
	 *
	 * @var string
	 */
	public const RECEIVED_EVENT_NAMESPACE = '0d2f6a3c-8e51-5b7a-9c44-1f3e2b6d7a90';

	/**
	 * Constructor.
	 *
	 * @param ORObjectService       $objectService The OpenRegister object service.
	 * @param EventServiceInterface $eventService  Routes a stored event to the matching subscriptions.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly EventServiceInterface $eventService,
	) {

	}//end __construct()

	/**
	 * Store a received CloudEvent and route it, unless its id was received before.
	 *
	 * @param string              $receiver   The source id from the receiver URL.
	 * @param array<string,mixed> $cloudEvent The decoded event, its required attributes checked.
	 *
	 * @return ObjectEntity|null The stored event, or null when the same source sent this id before.
	 *
	 * @throws \Exception When the event cannot be stored or routed.
	 *
	 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
	 */
	public function receive(string $receiver, array $cloudEvent): ?ObjectEntity {
		$uuid = $this->uuidFor(receiver: $receiver, cloudEvent: $cloudEvent);
		if ($this->alreadyReceived(uuid: $uuid) === true) {
			return null;
		}

		$event = $this->objectService->saveObject(
			object: $this->eventObject(cloudEvent: $cloudEvent),
			register: 'integriq',
			schema: 'event',
			uuid: $uuid,
			// System context: the `event` schema lets only administrators create
			// an event through the object API, and the sender was checked by the
			// receiver's signature or credential before this runs.
			_rbac: false,
			_multitenancy: false
		);

		$this->eventService->processEvent(event: $event);

		return $event;

	}//end receive()

	/**
	 * The uuid a received event is stored under.
	 *
	 * @param string              $receiver   The source id from the receiver URL.
	 * @param array<string,mixed> $cloudEvent The decoded event.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
	 */
	public function uuidFor(string $receiver, array $cloudEvent): string {
		return (string)Uuid::v5(
			Uuid::fromString(self::RECEIVED_EVENT_NAMESPACE),
			$receiver . '|' . (string)($cloudEvent['source'] ?? '') . '|' . (string)($cloudEvent['id'] ?? '')
		);

	}//end uuidFor()

	/**
	 * Whether an event is already stored under this uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return bool
	 */
	private function alreadyReceived(string $uuid): bool {
		try {
			// Unscoped: the inbound receiver checks for a duplicate of the event it stores unscoped above.
			return $this->objectService->find(id: $uuid, register: 'integriq', schema: 'event', _rbac: false, _multitenancy: false) !== null;
		} catch (Throwable) {
			return false;
		}

	}//end alreadyReceived()

	/**
	 * The `event` object for a received CloudEvent.
	 *
	 * @param array<string,mixed> $cloudEvent The decoded event.
	 *
	 * @return array<string,mixed> The object to store.
	 */
	private function eventObject(array $cloudEvent): array {
		return [
			'source' => (string)$cloudEvent['source'],
			'type' => (string)$cloudEvent['type'],
			'specversion' => (string)$cloudEvent['specversion'],
			'time' => $this->time(value: ($cloudEvent['time'] ?? null)),
			'datacontenttype' => $this->optionalString(value: ($cloudEvent['datacontenttype'] ?? null)),
			'dataschema' => $this->optionalString(value: ($cloudEvent['dataschema'] ?? null)),
			'subject' => $this->optionalString(value: ($cloudEvent['subject'] ?? null)),
			'data' => $this->data(cloudEvent: $cloudEvent),
			'userId' => null,
			// The receiver routes the event itself, so the object listener must
			// not route it a second time.
			EventLoopGuard::MARKER_KEY => EventLoopGuard::MARKER_VALUE,
		];

	}//end eventObject()

	/**
	 * The event's time as stored: the sender's time when it is a date-time, else now.
	 *
	 * @param mixed $value The `time` attribute.
	 *
	 * @return string The time, ISO 8601.
	 */
	private function time(mixed $value): string {
		if (is_string($value) === true && trim($value) !== '') {
			try {
				return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
			} catch (Throwable) {
				// Not a date-time: fall through to the time it arrived.
			}
		}

		return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

	}//end time()

	/**
	 * A string attribute, or null when it is absent or empty.
	 *
	 * @param mixed $value The attribute.
	 *
	 * @return string|null
	 */
	private function optionalString(mixed $value): ?string {
		if (is_string($value) === true && trim($value) !== '') {
			return $value;
		}

		return null;

	}//end optionalString()

	/**
	 * The event's data in the shape the schema stores: a JSON object.
	 * Data that is not a JSON object is kept under `value`, base64 data under `data_base64`.
	 *
	 * @param array<string,mixed> $cloudEvent The decoded event.
	 *
	 * @return array<string,mixed>|null
	 */
	private function data(array $cloudEvent): ?array {
		if (array_key_exists('data_base64', $cloudEvent) === true) {
			return ['data_base64' => (string)$cloudEvent['data_base64']];
		}

		$data = ($cloudEvent['data'] ?? null);
		if ($data === null) {
			return null;
		}

		if (is_array($data) === true && ($data === [] || array_is_list($data) === false)) {
			return $data;
		}

		return ['value' => $data];

	}//end data()
}//end class
