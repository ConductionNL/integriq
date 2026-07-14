<?php
/**
 * OpenConnector CloudEvent EventListener.
 *
 * Forwards OpenRegister object change events to the OpenConnector
 * EventService so CloudEvents can be dispatched downstream.
 *
 * @category EventListener
 * @package  OCA\OpenConnector\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\EventListener;

use OCA\OpenConnector\Service\EventService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listener that forwards object changes to the EventService.
 *
 * Two safety guards on top of the plain forward, both required before this
 * listener is safe to register (see design.md "The recursion bug"):
 *
 * - **Firehose gate**: when the instance has zero active `event_subscription`s
 *   (the common case — outbound webhooks are opt-in), skip entirely. No
 *   `event` OR object is persisted, no matching logic runs, for ANY object
 *   mutation fleet-wide.
 * - **Self-reference guard**: OpenConnector's own `event`/`event_message`
 *   plumbing objects (register `openconnector`) are never re-forwarded.
 *   `handleObjectCreated`/`Updated`/`Deleted` themselves persist an `event`
 *   (and, on a match, an `event_message`) OR object — without this guard,
 *   that persistence would re-trigger this very listener and recurse forever.
 *
 * @spec openspec/changes/outbound-webhooks-activation/tasks.md#task-2
 */
class CloudEventListener implements IEventListener
{
    /**
     * Schemas, within the `openconnector` register, that this listener MUST
     * NOT forward — they are the CloudEvent machinery's own storage and
     * forwarding them would recurse (see class docblock).
     *
     * @var array<int, string>
     */
    private const SELF_SCHEMAS = ['event', 'event_message'];

    /**
     * The register whose objects are exempted by {@see self::SELF_SCHEMAS}.
     *
     * @var string
     */
    private const SELF_REGISTER = 'openconnector';

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
     * Handle incoming events by forwarding them to the EventService.
     *
     * @param Event $event The incoming event.
     *
     * @return void
     *
     * @spec openspec/changes/outbound-webhooks-activation/tasks.md#task-4
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent === false
            && $event instanceof ObjectUpdatedEvent === false
            && $event instanceof ObjectDeletedEvent === false
        ) {
            return;
        }

        try {
            // Firehose gate: no configured subscriptions anywhere on this
            // instance means the outbound-webhooks capability is unused —
            // do not pay a persistence cost for every object mutation.
            if ($this->eventService->hasActiveSubscriptions() === false) {
                return;
            }

            if ($event instanceof ObjectCreatedEvent) {
                if ($this->isSelfReference(object: $event->getObject()) === true) {
                    return;
                }

                $this->eventService->handleObjectCreated($event->getObject());
            }//end if

            if ($event instanceof ObjectUpdatedEvent) {
                $newObject = $event->getNewObject();
                $oldObject = $event->getOldObject();

                if ($this->isSelfReference(object: $newObject) === true) {
                    return;
                }

                // OR's own event class declares getOldObject() nullable
                // ("null if not available"); EventService::handleObjectUpdated()
                // requires a non-null old state. Every current OR call site
                // populates it, but this listener runs synchronously inside
                // an arbitrary host app's save — a null here must degrade to
                // a skipped CloudEvent, never an uncaught TypeError that
                // would 500 that unrelated save.
                if ($oldObject === null) {
                    $this->logger->warning(
                            'Skipped CloudEvent forwarding: ObjectUpdatedEvent carried no previous object state.',
                            ['uuid' => $newObject->getUuid()]
                            );
                    return;
                }//end if

                $this->eventService->handleObjectUpdated($oldObject, $newObject);
            }//end if

            if ($event instanceof ObjectDeletedEvent) {
                if ($this->isSelfReference(object: $event->getObject()) === true) {
                    return;
                }

                $this->eventService->handleObjectDeleted($event->getObject());
            }//end if
        } catch (\Throwable $e) {
            // Broad catch is deliberate: this listener runs synchronously
            // inside whatever host app's save triggered the OR event. A
            // failure here (including a TypeError/Error, not just Exception)
            // must never unwind into — and 500 — that unrelated save.
            $this->logger->error(
                    'Failed to process object event: '.$e->getMessage(),
                    [
                        'exception' => $e,
                        'event'     => get_class($event),
                    ]
                    );
        }//end try

    }//end handle()

    /**
     * Whether the given object is CloudEvent-machinery's own storage
     * (register `openconnector`, schema `event`/`event_message`) and must
     * therefore not be re-forwarded (see class docblock).
     *
     * @param ObjectEntity $object The object under evaluation.
     *
     * @return boolean
     */
    private function isSelfReference(ObjectEntity $object): bool
    {
        return $object->getRegister() === self::SELF_REGISTER
            && in_array($object->getSchema(), self::SELF_SCHEMAS, true) === true;

    }//end isSelfReference()
}//end class
