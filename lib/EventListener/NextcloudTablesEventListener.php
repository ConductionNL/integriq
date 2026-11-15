<?php

/**
 * Integriq Nextcloud Tables Row EventListener.
 *
 * Normalizes the Tables app's row create/update/delete events into the
 * CloudEvents `event` envelope, when the `tables` app is installed.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\EventServiceInterface;
use OCA\Tables\Event\RowAddedEvent;
use OCA\Tables\Event\RowDeletedEvent;
use OCA\Tables\Event\RowUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listener that normalizes Tables row lifecycle events into CloudEvents.
 *
 * Class names/accessors verified against the public `nextcloud/tables`
 * source (`lib/Event/{AbstractRowEvent,RowAddedEvent,RowUpdatedEvent,
 * RowDeletedEvent}.php`, `lib/Model/Public/Row.php` — `main` branch, fetched
 * during this change's implementation) rather than a live installed
 * instance (neither this repo's nor the checked server checkout has the
 * `tables` app present) — see `discovery.md`. `Application::appEnabledForAnyone($appManager, 'tables')`
 * gates registration in `Application.php::register()` (REQ-003); this
 * listener is never constructed on an instance without the app.
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
 */
class NextcloudTablesEventListener implements IEventListener {

	/**
	 * The CloudEvents `source` this producer stamps on every event.
	 *
	 * @var string
	 */
	private const SOURCE = '/nextcloud/tables';

	/**
	 * Constructor.
	 *
	 * @param EventServiceInterface $eventService Service for managing CloudEvents.
	 * @param LoggerInterface $logger Logger instance.
	 */
	public function __construct(
		private readonly EventServiceInterface $eventService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a fired Tables row event by normalizing and forwarding it.
	 *
	 * @param Event $event The incoming event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
	 */
	public function handle(Event $event): void {
		$type = $this->typeOf(event: $event);
		if ($type === null || method_exists($event, 'getRow') === false) {
			return;
		}

		try {
			// Firehose gate: no configured subscriptions anywhere on this
			// instance means the outbound-webhooks capability is unused — do
			// not pay a persistence cost for every row mutation fleet-wide.
			if ($this->eventService->hasActiveSubscriptions() === false) {
				return;
			}

			$this->eventService->handleNextcloudEvent(type: $type, payload: $this->payloadOf(row: $event->getRow()));
		} catch (\Throwable $e) {
			// Broad catch is deliberate: this listener runs synchronously
			// inside the Tables row operation that triggered it.
			$this->logFailure(exception: $e, event: $event);
		}

	}//end handle()

	/**
	 * The CloudEvent type of a Tables row event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return string|null The type, or null for any other event.
	 */
	private function typeOf(Event $event): ?string {
		if ($event instanceof RowAddedEvent) {
			return 'com.nextcloud.tables.row.created';
		}

		if ($event instanceof RowUpdatedEvent) {
			return 'com.nextcloud.tables.row.updated';
		}

		if ($event instanceof RowDeletedEvent) {
			return 'com.nextcloud.tables.row.deleted';
		}

		return null;
	}//end typeOf()

	/**
	 * The CloudEvent payload of a row.
	 *
	 * @param object $row The Tables row (its public tableId, rowId, values and previousValues).
	 *
	 * @return array<string,mixed> The payload.
	 */
	private function payloadOf(object $row): array {
		return [
			'source' => self::SOURCE,
			'subject' => (string)$row->rowId,
			'data' => [
				'tableId' => $row->tableId,
				'rowId' => $row->rowId,
				'values' => $row->values,
				'previousValues' => $row->previousValues,
			],
		];
	}//end payloadOf()

	/**
	 * Log a row event that could not be processed.
	 *
	 * @param \Throwable $exception Why it failed.
	 * @param Event      $event     The row event.
	 *
	 * @return void
	 */
	private function logFailure(\Throwable $exception, Event $event): void {
		$this->logger->error(
			'Failed to process Nextcloud Tables row event: ' . $exception->getMessage(),
			[
				'exception' => $exception,
				'event' => get_class($event),
			]
		);
	}//end logFailure()
}//end class
