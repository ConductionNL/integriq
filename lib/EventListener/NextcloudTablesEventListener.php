<?php
/**
 * OpenConnector Nextcloud Tables Row EventListener.
 *
 * Normalizes the Tables app's row create/update/delete events into the
 * CloudEvents `event` envelope, when the `tables` app is installed.
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
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
 */

declare(strict_types=1);

namespace OCA\OpenConnector\EventListener;

use OCA\OpenConnector\Service\EventService;
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
 * `tables` app present) — see `discovery.md`. `IAppManager::isEnabledForAnyUser('tables')`
 * gates registration in `Application.php::register()` (REQ-003); this
 * listener is never constructed on an instance without the app.
 *
 * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
 */
class NextcloudTablesEventListener implements IEventListener
{

    /**
     * The CloudEvents `source` this producer stamps on every event.
     *
     * @var string
     */
    private const SOURCE = '/nextcloud/tables';

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
     * Handle a fired Tables row event by normalizing and forwarding it.
     *
     * @param Event $event The incoming event.
     *
     * @return void
     *
     * @spec openspec/specs/nextcloud-event-triggers/spec.md#requirement-tables-row-events-must-be-normalized-to-cloudevents-when-the-tables-app-is-installed-req-003
     */
    public function handle(Event $event): void
    {
        $type = null;
        if ($event instanceof RowAddedEvent) {
            $type = 'com.nextcloud.tables.row.created';
        } else if ($event instanceof RowUpdatedEvent) {
            $type = 'com.nextcloud.tables.row.updated';
        } else if ($event instanceof RowDeletedEvent) {
            $type = 'com.nextcloud.tables.row.deleted';
        }

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

            $row = $event->getRow();

            $this->eventService->handleNextcloudEvent(
                type: $type,
                payload: [
                    'source'  => self::SOURCE,
                    'subject' => (string) $row->rowId,
                    'data'    => [
                        'tableId'        => $row->tableId,
                        'rowId'          => $row->rowId,
                        'values'         => $row->values,
                        'previousValues' => $row->previousValues,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            // Broad catch is deliberate: this listener runs synchronously
            // inside the Tables row operation that triggered it.
            $this->logger->error(
                    'Failed to process Nextcloud Tables row event: '.$e->getMessage(),
                    [
                        'exception' => $e,
                        'event'     => get_class($event),
                    ]
                    );
        }//end try

    }//end handle()
}//end class
