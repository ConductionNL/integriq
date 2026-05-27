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
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

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
