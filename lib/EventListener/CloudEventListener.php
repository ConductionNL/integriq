<?php

namespace OCA\OpenConnector\EventListener;

use OCA\OpenConnector\Service\EventService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Listener that forwards object changes to the EventService
 */
class CloudEventListener implements IEventListener
{
    /**
     * @param EventService $eventService Service for managing CloudEvents
     * @param LoggerInterface $logger Logger instance
     */
    public function __construct(
        private readonly EventService $eventService,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Handle incoming events by forwarding them to the EventService
     *
     * @param Event $event The incoming event
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
            $this->logger->error('Failed to process object event: ' . $e->getMessage(), [
                'exception' => $e,
                'event' => get_class($event)
            ]);
        }
    }
} 
