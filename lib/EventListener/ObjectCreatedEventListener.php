<?php

namespace OCA\OpenConnector\EventListener;

use OCA\OpenConnector\Service\SynchronizationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;

/**
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class ObjectCreatedEventListener implements IEventListener
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
        if ($event instanceof ObjectCreatedEvent === false) {
            return;
        }

        if (method_exists($event, 'getObject') === false) {
            return;
        }

        $object = $event->getObject();
        if ($object === null) {
            return;
        }

        $this->synchronizationService->handleObjectEventSynchronization(
            object: $object,
            eventMutationType: 'create'
        );
    }
}
