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

        // A soft-delete is persisted as an update (setDeleted() + save()), so
        // OpenRegister never dispatches ObjectDeletedEvent for it — only a true
        // hard delete does, a path no caller reaches. Detect the transition
        // here instead: old object had no deletion metadata, new object does.
        // Without the "was not already deleted" half of this check, every
        // subsequent update to an already soft-deleted object would keep
        // re-firing a delete against a target that is already gone.
        // (WOO-557; ported onto the 0.2.24 line from
        // fix/delete-event-intern-to-extern, Barry Brands, 2026-09-04.)
        $oldObject = method_exists($event, 'getOldObject') === true ? $event->getOldObject() : null;
        $wasDeleted = $oldObject !== null && empty($oldObject->getDeleted()) === false;
        $isNowDeleted = empty($object->getDeleted()) === false;

        if ($isNowDeleted === true && $wasDeleted === false) {
            $this->synchronizationService->handleObjectEventSynchronization(
                object: $object,
                eventMutationType: 'delete'
            );
            return;
        }

        $this->synchronizationService->handleObjectEventSynchronization(
            object: $object,
            eventMutationType: 'update'
        );
    }
}
