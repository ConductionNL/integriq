<?php

namespace OCA\OpenConnector\EventListener;

use OCA\OpenConnector\Service\SoftwareCatalogueService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Event listener for handling software catalog specific events.
 * 
 * This listener handles organization and contact related events in the software catalog,
 * including user management and email notifications.
 * 
 * @category EventListener
 * @package  OCA\OpenConnector\EventListener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenConnector
 * @version  1.0.0
 * @todo     This listener should be moved to the software catalog app
 */
/**
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class SoftwareCatalogEventListener implements IEventListener
{
    /**
     * Schema ID for organizations
     */
    private const ORGANIZATION_SCHEMA_ID = 1;

    /**
     * Schema ID for contacts
     */
    private const CONTACT_SCHEMA_ID = 2;

    /**
     * Constructor for SoftwareCatalogEventListener
     *
     * @param SoftwareCatalogueService $softwareCatalogueService The software catalog service
     * @param LoggerInterface $logger The logger instance
     */
    public function __construct(
        private readonly SoftwareCatalogueService $softwareCatalogueService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Handles events related to software catalog objects
     *
     * @param Event $event The event to handle
     * @return void
     */
    public function handle(Event $event): void
    {
        // Handle object creation
        if ($event instanceof ObjectCreatedEvent) {
            $this->handleObjectCreated($event);
            return;
        }

        // Handle object updates
        if ($event instanceof ObjectUpdatedEvent) {
            $this->handleObjectUpdated($event);
            return;
        }

        // Handle object deletion
        if ($event instanceof ObjectDeletedEvent) {
            $this->handleObjectDeleted($event);
            return;
        }
    }

    /**
     * Handles object creation events
     *
     * @param ObjectCreatedEvent $event The creation event
     * @return void
     */
    private function handleObjectCreated(ObjectCreatedEvent $event): void
    {
        $object = $event->getObject();
        if ($object === null) {
            return;
        }

        // Handle organization creation
        if ($object->getSchema() === self::ORGANIZATION_SCHEMA_ID) {
            try {
                $this->softwareCatalogueService->handleNewOrganization($object);
            } catch (\Exception $e) {
                $this->logger->error('Failed to handle new organization: ' . $e->getMessage(), [
                    'exception' => $e,
                    'object' => $object
                ]);
            }
            return;
        }

        // Handle contact creation
        if ($object->getSchema() === self::CONTACT_SCHEMA_ID) {
            try {
                $this->softwareCatalogueService->handleNewContact($object);
            } catch (\Exception $e) {
                $this->logger->error('Failed to handle new contact: ' . $e->getMessage(), [
                    'exception' => $e,
                    'object' => $object
                ]);
            }
        }
    }

    /**
     * Handles object update events
     *
     * @param ObjectUpdatedEvent $event The update event
     * @return void
     */
    private function handleObjectUpdated(ObjectUpdatedEvent $event): void
    {
        $object = $event->getNewObject();
        if ($object === null) {
            return;
        }

        // Handle contact updates
        if ($object->getSchema() === self::CONTACT_SCHEMA_ID) {
            try {
                $this->softwareCatalogueService->handleContactUpdate($object);
            } catch (\Exception $e) {
                $this->logger->error('Failed to handle contact update: ' . $e->getMessage(), [
                    'exception' => $e,
                    'object' => $object
                ]);
            }
        }
    }

    /**
     * Handles object deletion events
     *
     * @param ObjectDeletedEvent $event The deletion event
     * @return void
     */
    private function handleObjectDeleted(ObjectDeletedEvent $event): void
    {
        $object = $event->getObject();
        if ($object === null) {
            return;
        }

        // Handle contact deletion
        if ($object->getSchema() === self::CONTACT_SCHEMA_ID) {
            try {
                $this->softwareCatalogueService->handleContactDeletion($object);
            } catch (\Exception $e) {
                $this->logger->error('Failed to handle contact deletion: ' . $e->getMessage(), [
                    'exception' => $e,
                    'object' => $object
                ]);
            }
        }
    }
} 