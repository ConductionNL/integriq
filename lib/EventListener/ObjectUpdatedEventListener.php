<?php

namespace OCA\OpenConnector\EventListener;

use OCA\OpenConnector\Service\SynchronizationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;

class ObjectUpdatedEventListener implements IEventListener
{

	public function __construct(
		private readonly SynchronizationService $synchronizationService,
	)
	{
	}

	/**
     * @inheritDoc
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectUpdatedEvent === false) {
            return;
        }

        if (method_exists($event, 'getNewObject') === false) {
            return;
        }

        $object = $event->getNewObject();
        if ($object === null) {
            return;
        }

        $this->synchronizationService->handleObjectEventSynchronization(
            object: $object,
            eventMutationType: 'update'
        );
    }
}
