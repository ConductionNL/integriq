<?php

/**
 * Integriq Nextcloud Calendar EventListener.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Normalizes NC calendar object lifecycle events into the CloudEvents
 * `event` envelope. Registered against two event families discovered while
 * verifying this change against a live NC 33 checkout (`discovery.md`):
 *
 *   - `OCP\Calendar\Events\CalendarObjectCreated/Updated/DeletedEvent`
 *     (stable public OCP API, `@since 32.0.0`) — fired for a REGULAR
 *     calendar (a user's own calendar).
 *   - `OCA\DAV\Events\CachedCalendarObjectCreated/Updated/DeletedEvent`
 *     (app-internal OCA API, present since NC 20.0.0) — fired for a
 *     calendar SUBSCRIPTION (an externally-hosted ICS feed NC caches
 *     locally), NOT for a user's own calendar objects. Confirmed by reading
 *     `CalDavBackend::createCalendarObject()` in the live checkout: it
 *     dispatches `CalendarObjectCreatedEvent` for `CALENDAR_TYPE_CALENDAR`
 *     and `CachedCalendarObjectCreatedEvent` for `CALENDAR_TYPE_SUBSCRIPTION`
 *     — two genuinely different triggers, not a stability-tier fallback pair.
 *
 * Both families are wired so `com.nextcloud.calendar.object.*` events fire
 * for a user's own calendar activity on every NC version this app targets
 * (NC 28-34) where regular calendar-object coverage is achievable: NC 32+
 * via the OCP event, and cached/subscribed-calendar coverage on every
 * version via the OCA event. On NC 28-31 a user's own (non-subscription)
 * calendar object changes are NOT observable via any known NC event class —
 * documented here rather than silently gapped.
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
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\EventListener;

use OCA\DAV\Events\CachedCalendarObjectCreatedEvent;
use OCA\DAV\Events\CachedCalendarObjectDeletedEvent;
use OCA\DAV\Events\CachedCalendarObjectUpdatedEvent;
use OCA\Integriq\Service\EventServiceInterface;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listener that normalizes calendar object lifecycle events into CloudEvents.
 *
 * Registered unconditionally in `Application.php::register()` — `dav` (and,
 * as of NC 32, the `OCP\Calendar\Events\*` family) ships bundled with every
 * Nextcloud instance, so no `IAppManager` feature-detection gate is needed
 * for "is calendar present" (REQ-002). Because `OCA\DAV\Events\*` is
 * app-internal API without an OCP stability guarantee, every accessor read
 * from an OCA-family event is guarded by `method_exists`; a shape mismatch
 * logs a warning and returns without throwing into the NC event dispatcher.
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
 */
class NextcloudCalendarEventListener implements IEventListener {

	/**
	 * The CloudEvents `source` this producer stamps on every event.
	 *
	 * @var string
	 */
	private const SOURCE = '/nextcloud/calendar';

	/**
	 * The recognised event classes, OCP and OCA, and their CloudEvents type.
	 *
	 * @var array<class-string, string>
	 */
	private const TYPES = [
		CalendarObjectCreatedEvent::class => 'com.nextcloud.calendar.object.created',
		CachedCalendarObjectCreatedEvent::class => 'com.nextcloud.calendar.object.created',
		CalendarObjectUpdatedEvent::class => 'com.nextcloud.calendar.object.updated',
		CachedCalendarObjectUpdatedEvent::class => 'com.nextcloud.calendar.object.updated',
		CalendarObjectDeletedEvent::class => 'com.nextcloud.calendar.object.deleted',
		CachedCalendarObjectDeletedEvent::class => 'com.nextcloud.calendar.object.deleted',
	];

	/**
	 * The provenance per id accessor: an own calendar, or a cached subscription.
	 *
	 * @var array<string, string>
	 */
	private const PROVENANCE = [
		'getCalendarId' => 'own',
		'getSubscriptionId' => 'cached-subscription',
	];

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
	 * Handle a fired calendar object event by normalizing and forwarding it.
	 *
	 * @param Event $event The incoming event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
	 */
	public function handle(Event $event): void {
		$type = $this->eventType(event: $event);
		if ($type === null) {
			return;
		}

		// Defensive OCA-stability check (REQ-002): both families are expected
		// to expose getCalendarId()/getSubscriptionId() + getObjectData();
		// a shape mismatch (e.g. an NC-major DAV signature change) logs and
		// skips rather than throwing into the NC event dispatcher.
		if ($this->idAccessor(event: $event) === null || method_exists($event, 'getObjectData') === false) {
			$this->logger->warning(
				'Skipped Nextcloud calendar event: unexpected event shape (missing calendar/subscription id or object data accessor).',
				['event' => get_class($event)]
			);
			return;
		}

		$this->forward(event: $event, type: $type);

	}//end handle()

	/**
	 * The CloudEvents type of a recognised calendar object event, or null.
	 *
	 * @param Event $event The incoming event.
	 *
	 * @return string|null
	 */
	private function eventType(Event $event): ?string {
		foreach (self::TYPES as $class => $type) {
			if ($event instanceof $class) {
				return $type;
			}
		}

		return null;

	}//end eventType()

	/**
	 * The accessor of the event's calendar (own) or subscription (cached) id, or null.
	 *
	 * @param Event $event The incoming event.
	 *
	 * @return string|null `getCalendarId`, `getSubscriptionId`, or null.
	 */
	private function idAccessor(Event $event): ?string {
		if (method_exists($event, 'getCalendarId') === true) {
			return 'getCalendarId';
		}

		if (method_exists($event, 'getSubscriptionId') === true) {
			return 'getSubscriptionId';
		}

		return null;

	}//end idAccessor()

	/**
	 * Forward the event as a CloudEvent, never unwinding into the CalDAV operation.
	 *
	 * @param Event  $event The calendar object event, of the expected shape.
	 * @param string $type  Its CloudEvents type.
	 *
	 * @return void
	 */
	private function forward(Event $event, string $type): void {
		try {
			// Firehose gate: no configured subscriptions anywhere on this
			// instance means the outbound-webhooks capability is unused — do
			// not pay a persistence cost for every calendar mutation
			// fleet-wide.
			if ($this->eventService->hasActiveSubscriptions() === false) {
				return;
			}

			$this->eventService->handleNextcloudEvent(type: $type, payload: $this->payload(event: $event));
		} catch (\Throwable $e) {
			// Broad catch is deliberate: this listener runs synchronously
			// inside the CalDAV operation that triggered it. A failure here
			// must never unwind into — and 500 — that unrelated operation.
			$this->logger->error(
				'Failed to process Nextcloud calendar event: ' . $e->getMessage(),
				[
					'exception' => $e,
					'event' => get_class($event),
				]
			);
		}//end try

	}//end forward()

	/**
	 * The CloudEvent payload: source, subject and the calendar object's data.
	 *
	 * The caller has checked the event's shape; the accessor check here only
	 * keeps the OCA-stability guard local to the read.
	 *
	 * @param Event $event The calendar object event, of the expected shape.
	 *
	 * @return array<string,mixed> The payload.
	 */
	private function payload(Event $event): array {
		$idMethod = $this->idAccessor(event: $event);
		$calendarId = $event->{$idMethod}();
		$objectData = [];
		if (method_exists($event, 'getObjectData') === true) {
			$objectData = $event->getObjectData();
		}

		$objectUri = ($objectData['uri'] ?? null);

		return [
			'source' => self::SOURCE,
			'subject' => $objectUri,
			'data' => [
				'calendarId' => $calendarId,
				'objectUri' => $objectUri,
				'provenance' => self::PROVENANCE[$idMethod],
				'etag' => ($objectData['etag'] ?? null),
			],
		];

	}//end payload()
}//end class
