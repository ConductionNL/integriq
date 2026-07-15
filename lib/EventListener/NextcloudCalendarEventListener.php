<?php
/**
 * OpenConnector Nextcloud Calendar EventListener.
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
 * @package  OCA\OpenConnector\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\EventListener;

use OCA\DAV\Events\CachedCalendarObjectCreatedEvent;
use OCA\DAV\Events\CachedCalendarObjectDeletedEvent;
use OCA\DAV\Events\CachedCalendarObjectUpdatedEvent;
use OCA\OpenConnector\Service\EventService;
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
class NextcloudCalendarEventListener implements IEventListener
{

    /**
     * The CloudEvents `source` this producer stamps on every event.
     *
     * @var string
     */
    private const SOURCE = '/nextcloud/calendar';

    /**
     * Constructor.
     *
     * @param EventService    $eventService Service for managing CloudEvents.
     * @param LoggerInterface $logger       Logger instance.
     */
    public function __construct(
        private readonly EventService $eventService,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Handle a fired calendar object event by normalizing and forwarding it.
     *
     * @param Event $event The incoming event.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) -- the branching IS the
     * feature: six recognised event classes across two API families (OCP +
     * OCA) plus the mandated defensive OCA-stability accessor checks (REQ-002)
     * are inherently multi-branch; splitting them would obscure the one
     * normalize-and-forward flow.
     * @SuppressWarnings(PHPMD.NPathComplexity)
     *
     * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
     */
    public function handle(Event $event): void
    {
        $type = null;
        if ($event instanceof CalendarObjectCreatedEvent || $event instanceof CachedCalendarObjectCreatedEvent) {
            $type = 'com.nextcloud.calendar.object.created';
        } else if ($event instanceof CalendarObjectUpdatedEvent || $event instanceof CachedCalendarObjectUpdatedEvent) {
            $type = 'com.nextcloud.calendar.object.updated';
        } else if ($event instanceof CalendarObjectDeletedEvent || $event instanceof CachedCalendarObjectDeletedEvent) {
            $type = 'com.nextcloud.calendar.object.deleted';
        }

        if ($type === null) {
            return;
        }

        // Defensive OCA-stability check (REQ-002): both families are expected
        // to expose getCalendarId()/getSubscriptionId() + getObjectData();
        // a shape mismatch (e.g. an NC-major DAV signature change) logs and
        // skips rather than throwing into the NC event dispatcher.
        $idMethod = null;
        if (method_exists($event, 'getCalendarId') === true) {
            $idMethod = 'getCalendarId';
        } else if (method_exists($event, 'getSubscriptionId') === true) {
            $idMethod = 'getSubscriptionId';
        }

        if ($idMethod === null || method_exists($event, 'getObjectData') === false) {
            $this->logger->warning(
                    'Skipped Nextcloud calendar event: unexpected event shape (missing calendar/subscription id or object data accessor).',
                    ['event' => get_class($event)]
                    );
            return;
        }

        try {
            // Firehose gate: no configured subscriptions anywhere on this
            // instance means the outbound-webhooks capability is unused — do
            // not pay a persistence cost for every calendar mutation
            // fleet-wide.
            if ($this->eventService->hasActiveSubscriptions() === false) {
                return;
            }

            $calendarId = $event->{$idMethod}();
            $objectData = $event->getObjectData();
            $objectUri  = ($objectData['uri'] ?? null);
            $provenance = 'cached-subscription';
            if ($idMethod === 'getCalendarId') {
                $provenance = 'own';
            }

            $this->eventService->handleNextcloudEvent(
                type: $type,
                payload: [
                    'source'  => self::SOURCE,
                    'subject' => $objectUri,
                    'data'    => [
                        'calendarId' => $calendarId,
                        'objectUri'  => $objectUri,
                        'provenance' => $provenance,
                        'etag'       => ($objectData['etag'] ?? null),
                    ],
                ]
            );
        } catch (\Throwable $e) {
            // Broad catch is deliberate: this listener runs synchronously
            // inside the CalDAV operation that triggered it. A failure here
            // must never unwind into — and 500 — that unrelated operation.
            $this->logger->error(
                    'Failed to process Nextcloud calendar event: '.$e->getMessage(),
                    [
                        'exception' => $e,
                        'event'     => get_class($event),
                    ]
                    );
        }//end try

    }//end handle()
}//end class
