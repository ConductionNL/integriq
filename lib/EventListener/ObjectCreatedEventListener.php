<?php

/**
 * Integriq ObjectCreated EventListener.
 *
 * Listens for OpenRegister ObjectCreatedEvent and triggers downstream
 * synchronization for the affected object.
 *
 * @category EventListener
 * @package  OCA\Integriq\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

namespace OCA\Integriq\EventListener;

use OCA\Integriq\Service\SynchronizationService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Event listener that triggers synchronization on object creation.
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class ObjectCreatedEventListener implements IEventListener {
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

	}//end handle()
}//end class
