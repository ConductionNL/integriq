<?php

/**
 * OpenConnector SoftwareCatalog EventListener.
 *
 * Handles organization and contact related events in the software catalog,
 * including user management and email notifications.
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
 *
 * @todo This listener should be moved to the software catalog app.
 */

namespace OCA\OpenConnector\EventListener;

use OCA\OpenConnector\Service\SoftwareCatalogueService;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Event listener for handling software catalog specific events.
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class SoftwareCatalogEventListener implements IEventListener {

	/**
	 * Schema ID for organizations.
	 *
	 * @var int
	 */
	private const ORGANIZATION_SCHEMA_ID = 1;

	/**
	 * Schema ID for contacts.
	 *
	 * @var int
	 */
	private const CONTACT_SCHEMA_ID = 2;

	/**
	 * Constructor.
	 *
	 * @param SoftwareCatalogueService $softwareCatalogueService The software catalog service.
	 * @param LoggerInterface $logger The logger instance.
	 */
	public function __construct(
		private readonly SoftwareCatalogueService $softwareCatalogueService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handles events related to software catalog objects.
	 *
	 * @param Event $event The event to handle.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		// Handle object creation.
		if ($event instanceof ObjectCreatedEvent) {
			$this->handleObjectCreated(event: $event);
			return;
		}

		// Handle object updates.
		if ($event instanceof ObjectUpdatedEvent) {
			$this->handleObjectUpdated(event: $event);
			return;
		}

		// Handle object deletion.
		if ($event instanceof ObjectDeletedEvent) {
			$this->handleObjectDeleted(event: $event);
			return;
		}

	}//end handle()

	/**
	 * Handles object creation events.
	 *
	 * @param ObjectCreatedEvent $event The creation event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function handleObjectCreated(ObjectCreatedEvent $event): void {
		$object = $event->getObject();
		if ($object === null) {
			return;
		}

		// Handle organization creation.
		if ($object->getSchema() === self::ORGANIZATION_SCHEMA_ID) {
			try {
				$this->softwareCatalogueService->handleNewOrganization($object);
			} catch (\Exception $e) {
				$this->logger->error(
					'Failed to handle new organization: ' . $e->getMessage(),
					[
						'exception' => $e,
						'object' => $object,
					]
				);
			}

			return;
		}

		// Handle contact creation.
		if ($object->getSchema() === self::CONTACT_SCHEMA_ID) {
			try {
				$this->softwareCatalogueService->handleNewContact($object);
			} catch (\Exception $e) {
				$this->logger->error(
					'Failed to handle new contact: ' . $e->getMessage(),
					[
						'exception' => $e,
						'object' => $object,
					]
				);
			}
		}

	}//end handleObjectCreated()

	/**
	 * Handles object update events.
	 *
	 * @param ObjectUpdatedEvent $event The update event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function handleObjectUpdated(ObjectUpdatedEvent $event): void {
		$object = $event->getNewObject();
		if ($object === null) {
			return;
		}

		// Handle contact updates.
		if ($object->getSchema() === self::CONTACT_SCHEMA_ID) {
			try {
				$this->softwareCatalogueService->handleContactUpdate($object);
			} catch (\Exception $e) {
				$this->logger->error(
					'Failed to handle contact update: ' . $e->getMessage(),
					[
						'exception' => $e,
						'object' => $object,
					]
				);
			}
		}

	}//end handleObjectUpdated()

	/**
	 * Handles object deletion events.
	 *
	 * @param ObjectDeletedEvent $event The deletion event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/software-catalogus-events/spec.md
	 */
	private function handleObjectDeleted(ObjectDeletedEvent $event): void {
		$object = $event->getObject();
		if ($object === null) {
			return;
		}

		// Handle contact deletion.
		if ($object->getSchema() === self::CONTACT_SCHEMA_ID) {
			try {
				$this->softwareCatalogueService->handleContactDeletion($object);
			} catch (\Exception $e) {
				$this->logger->error(
					'Failed to handle contact deletion: ' . $e->getMessage(),
					[
						'exception' => $e,
						'object' => $object,
					]
				);
			}
		}

	}//end handleObjectDeleted()
}//end class
