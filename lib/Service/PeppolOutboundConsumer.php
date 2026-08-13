<?php

/**
 * OpenConnector Peppol Outbound Event Consumer.
 *
 * Listens for `OCA\OpenRegister\Event\ObjectCreatedEvent` — the same
 * cross-app hook `ObjectCreatedEventListener`/`CloudEventListener` use — and
 * reacts when the created object is a `nl.conduction.peppol.outbound.requested`
 * CloudEvent (register `openconnector`, schema `event`). A producing app
 * (e.g. shillinq) emits that event by creating such an object through
 * OpenRegister's `ObjectService`, or through `EventService::emitCloudEvent()`,
 * both of which persist into the same register/schema and therefore fire the
 * same underlying `ObjectCreatedEvent`. All transmission logic lives in
 * {@see PeppolTransmissionService} so this class stays a thin dispatch shell.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/peppol-access-point-connector/spec.md#requirement-event-driven-outbound-transmission-with-status-lifecycle-req-003
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

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
	 * Constructor.
	 *
	 * @param PeppolTransmissionService $transmissionService Drives the transmission lifecycle.
	 * @param LoggerInterface $logger Logger for non-fatal dispatch failures.
	 */
	public function __construct(
		private readonly PeppolTransmissionService $transmissionService,
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
	 * event object (register `openconnector`, schema `event`).
	 *
	 * Split out of {@see handle()} to keep both methods under the cyclomatic/
	 * NPath complexity thresholds — each guard is a single early return.
	 *
	 * @param Event $event The incoming NC event.
	 *
	 * @return array|null The matched event object's data array, or null when the event does not match.
	 */
	private function extractOutboundRequestedPayload(Event $event): ?array {
		if ($event instanceof ObjectCreatedEvent === false) {
			return null;
		}

		if (method_exists($event, 'getObject') === false) {
			return null;
		}

		$object = $event->getObject();
		if ($object === null) {
			return null;
		}

		if (method_exists($object, 'getRegister') === true
			&& $object->getRegister() !== PeppolTransmissionService::REGISTER
		) {
			return null;
		}

		if (method_exists($object, 'getSchema') === true && $object->getSchema() !== 'event') {
			return null;
		}

		$objectData = $object->getObject();
		if (($objectData['type'] ?? null) !== PeppolTransmissionService::EVENT_TYPE_OUTBOUND_REQUESTED) {
			return null;
		}

		return $objectData;
	}//end extractOutboundRequestedPayload()
}//end class
