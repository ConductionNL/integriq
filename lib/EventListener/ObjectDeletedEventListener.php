<?php

/**
 * OpenConnector ObjectDeleted EventListener.
 *
 * Listens for OpenRegister ObjectDeletedEvent and triggers downstream
 * synchronization for the affected object.
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

use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Event listener that triggers synchronization on object deletion.
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class ObjectDeletedEventListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SynchronizationService $synchronizationService Service that performs the synchronization.
	 */
	public function __construct(
		private readonly SynchronizationService $synchronizationService,
	) {

	}//end __construct()

	/**
	 * Handle a fired event.
	 *
	 * @param Event $event Event payload to handle.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectDeletedEvent === false) {
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
			eventMutationType: 'delete'
		);

	}//end handle()
}//end class
