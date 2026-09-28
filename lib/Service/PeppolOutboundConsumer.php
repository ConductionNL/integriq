<?php

/**
 * Integriq Peppol Outbound Event Consumer.
 *
 * Listens for `OCA\OpenRegister\Event\ObjectCreatedEvent` — the same
 * cross-app hook `ObjectCreatedEventListener`/`CloudEventListener` use — and
 * reacts when the created object is a `nl.conduction.peppol.outbound.requested`
 * CloudEvent (register `integriq`, schema `event`, matched on the ids
 * OpenRegister stamps through {@see ListenerSchemaResolver}). A producing app
 * (e.g. shillinq) emits that event by creating such an object through
 * OpenRegister's `ObjectService`, or through `EventService::emitCloudEvent()`,
 * both of which persist into the same register/schema and therefore fire the
 * same underlying `ObjectCreatedEvent`. All transmission logic lives in
 * {@see PeppolTransmissionService} so this class stays a thin dispatch shell.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-event-driven-outbound-transmission-with-status-lifecycle-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatches `nl.conduction.peppol.outbound.requested` CloudEvents to PeppolTransmissionService.
 *
 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-event-driven-outbound-transmission-with-status-lifecycle-req-003
 */
class PeppolOutboundConsumer implements IEventListener {
	/**
	 * Schema slug of integriq's CloudEvent storage.
	 *
	 * @var string
	 */
	private const EVENT_SCHEMA = 'event';

	/**
	 * Constructor.
	 *
	 * @param PeppolTransmissionService $transmissionService Drives the transmission lifecycle.
	 * @param ListenerSchemaResolver $schemaResolver Resolves the register and schema ids OpenRegister stamps back to slugs.
	 * @param LoggerInterface $logger Logger for non-fatal dispatch failures.
	 */
	public function __construct(
		private readonly PeppolTransmissionService $transmissionService,
		private readonly ListenerSchemaResolver $schemaResolver,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle an incoming NC event, reacting only to a matching outbound.requested CloudEvent.
	 *
	 * @param Event $event The incoming event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-event-driven-outbound-transmission-with-status-lifecycle-req-003
	 */
	public function handle(Event $event): void {
		$objectData = $this->extractOutboundRequestedPayload(event: $event);
		if ($objectData === null) {
			return;
		}

		try {
			$this->transmissionService->handleOutboundRequested(eventData: (array)($objectData['data'] ?? []));
		} catch (Throwable $exception) {
			$this->logger->error(
				'[PeppolOutboundConsumer] failed to process outbound.requested event: ' . $exception->getMessage(),
				['exception' => $exception]
			);
		}

	}//end handle()

	/**
	 * Extract the CloudEvent data array when, and only when, the incoming NC
	 * event is an `ObjectCreatedEvent` for a `nl.conduction.peppol.outbound.requested`
	 * event object in integriq's own register and `event` schema.
	 *
	 * OpenRegister stamps the numeric register and schema ids on the object,
	 * and `ObjectEntity` declares `getRegister()`/`getSchema()`, so comparing
	 * them with the slugs `integriq` and `event` never matched and every
	 * request was dropped (integriq#1222). {@see ListenerSchemaResolver} maps
	 * the ids back to slugs, still accepts a slug as-is, and answers "not
	 * ours" when it cannot resolve them, so the guard fails closed.
	 *
	 * The payload `type` is checked first: it is a plain array read, and it
	 * keeps the id lookups off every other object write on the instance.
	 *
	 * @param Event $event The incoming NC event.
	 *
	 * @return array|null The matched event object's data array, or null when the event does not match.
	 *
	 * @spec openspec/changes/peppol-readable-payloads-and-scoped-consumer/specs/peppol-access-point-connector/spec.md#requirement-the-outbound-consumer-reacts-only-to-integriqs-own-event-schema-req-007
	 */
	private function extractOutboundRequestedPayload(Event $event): ?array {
		if ($event instanceof ObjectCreatedEvent === false) {
			return null;
		}

		$object = $event->getObject();
		$objectData = $object->getObject();
		if (($objectData['type'] ?? null) !== PeppolTransmissionService::EVENT_TYPE_OUTBOUND_REQUESTED) {
			return null;
		}

		if ($this->schemaResolver->matchesSchema(entity: $object, expectedSlug: self::EVENT_SCHEMA) === false) {
			return null;
		}

		return $objectData;
	}//end extractOutboundRequestedPayload()
}//end class
