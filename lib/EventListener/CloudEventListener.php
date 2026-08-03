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
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Listener that forwards object changes to the EventService.
 */
class CloudEventListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param EventService    $eventService Service for managing CloudEvents.
     * @param LoggerInterface $logger       Logger instance.
     */
    /**
     * Schemas whose objects are this listener's OWN bookkeeping. Reacting to
     * them is what created the recursion: `handleObjectCreated()` persists a
     * CloudEvent via `saveObject()`, that create raises another
     * `ObjectCreatedEvent`, and the listener runs again — without bound.
     *
     * Measured on the dev instance 2026-07-28 before this guard: 45,715
     * `event` rows of which 45,398 (99.3%) carried
     * `source = /objects/com.nextcloud.openregister.object.created`, i.e. they
     * were generated FROM other events, growing 3,000-5,000/hour, and a single
     * object create took >120s.
     *
     * @var array<int, string>
     */
    private const OWN_SCHEMA_SLUGS = ['event', 'event_message', 'event_subscription'];

    /**
     * Resolved ids of {@see self::OWN_SCHEMA_SLUGS}, or null until first use.
     *
     * @var array<int, string>|null
     */
    private ?array $ownSchemaIds = null;

    /**
     * Constructor.
     *
     * @param EventService    $eventService Service for managing CloudEvents.
     * @param SchemaMapper    $schemaMapper Resolves this app's own schema ids.
     * @param LoggerInterface $logger       Logger instance.
     */
    public function __construct(
        private readonly EventService $eventService,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Decide whether an object is this listener's own bookkeeping.
     *
     * The register/schema are read from the ObjectEntity, never from the
     * payload: a crafted `type` in user data must not be able to steer the
     * guard. The provenance marker is checked too so the suppression survives
     * a register rename or a same-slug schema copied into another register
     * (the cross-app slug-collision shape behind openregister#2150).
     *
     * @param ObjectEntity $object The object whose change was announced.
     *
     * @return bool True when the object must NOT produce a CloudEvent.
     */
    private function isOwnBookkeeping(ObjectEntity $object): bool
    {
        // Provenance marker — no lookup needed, and register-independent.
        $data = $object->getObject();
        if (is_array($data) === true && isset($data[EventService::GENERATED_BY_KEY]) === true) {
            return true;
        }

        $schemaId = $object->getSchema();
        if ($schemaId === null || $schemaId === '') {
            return false;
        }

        if ($this->ownSchemaIds === null) {
            $this->ownSchemaIds = $this->resolveOwnSchemaIds();
        }

        return in_array((string) $schemaId, $this->ownSchemaIds, true);

    }//end isOwnBookkeeping()


    /**
     * Resolve the schema ids backing {@see self::OWN_SCHEMA_SLUGS}.
     *
     * Resolution is cached for the life of the listener and fails OPEN with an
     * empty set: if the schemas cannot be resolved the guard simply does not
     * suppress, which is the pre-existing behaviour rather than a new way to
     * lose events.
     *
     * @return array<int, string> Schema ids as strings.
     */
    private function resolveOwnSchemaIds(): array
    {
        $ids = [];
        foreach (self::OWN_SCHEMA_SLUGS as $slug) {
            try {
                // `findBySlug()` returns a LIST, not a single schema: slugs are
                // not globally unique (openregister#2150 — the same slug can
                // exist once per register). Every schema carrying one of our
                // bookkeeping slugs must be suppressed, whichever register
                // ended up owning it, so all matches are collected.
                foreach ($this->schemaMapper->findBySlug($slug) as $schema) {
                    $ids[] = (string) $schema->getId();
                }
            } catch (Throwable $e) {
                $this->logger->debug(
                    '[CloudEventListener] could not resolve own schema "'.$slug.'": '.$e->getMessage()
                );
            }
        }

        return $ids;

    }//end resolveOwnSchemaIds()

    /**
     * Handle incoming events by forwarding them to the EventService.
     *
     * @param Event $event The incoming event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent === false
            && $event instanceof ObjectUpdatedEvent === false
            && $event instanceof ObjectDeletedEvent === false
        ) {
            return;
        }

        // LOOP BREAKER: never react to our own event bookkeeping. Creating a
        // CloudEvent is itself an OpenRegister object create, so without this
        // the listener re-enters on its own output forever. One debug line per
        // suppressed object — deliberately NOT per recursion level, since the
        // point is to stop reproducing the flood.
        $subject = null;
        if ($event instanceof ObjectCreatedEvent || $event instanceof ObjectDeletedEvent) {
            $subject = $event->getObject();
        } else if ($event instanceof ObjectUpdatedEvent) {
            $subject = $event->getNewObject();
        }

        if ($subject !== null && $this->isOwnBookkeeping($subject) === true) {
            $this->logger->debug(
                '[CloudEventListener] suppressed own bookkeeping object (schema '
                .((string) $subject->getSchema()).') — no CloudEvent generated'
            );
            return;
        }

        try {
            if ($event instanceof ObjectCreatedEvent) {
                $this->eventService->handleObjectCreated($event->getObject());
            }

            if ($event instanceof ObjectUpdatedEvent) {
                $this->eventService->handleObjectUpdated($event->getOldObject(), $event->getNewObject());
            }

            if ($event instanceof ObjectDeletedEvent) {
                $this->eventService->handleObjectDeleted($event->getObject());
            }
        } catch (\Exception $e) {
            $this->logger->error(
                    'Failed to process object event: '.$e->getMessage(),
                    [
                        'exception' => $e,
                        'event'     => get_class($event),
                    ]
                    );
        }//end try

    }//end handle()
}//end class
