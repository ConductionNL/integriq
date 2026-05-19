<?php

namespace OCA\OpenConnector\EventListener;

use OCA\OpenConnector\Service\SynchronizationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use Psr\Log\LoggerInterface;

/**
 * Event listener for handling view updates and creations in the Software Catalog application.
 * 
 * This listener is specifically designed for the Software Catalog application and handles
 * synchronization of software catalog items when they are updated or created.
 * 
 * @category EventListener
 * @package  OCA\OpenConnector\EventListener
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenConnector
 * @version  1.0.0
 * @todo     remove this temporary listener to the software catalog application
 */
/**
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class ViewUpdatedOrCreatedEventListener implements IEventListener
{
	/**
	 * Register ID for the Software Catalog
	 */
	private const SOFTWARE_CATALOG_REGISTER_ID = 1;

	/**
	 * Schema ID for Software Items
	 */
	private const SOFTWARE_ITEM_SCHEMA_ID = 1;

	/**
	 * Schema ID for Software Versions
	 */
	private const SOFTWARE_VERSION_SCHEMA_ID = 2;

	public function __construct(
		private readonly SynchronizationService $synchronizationService,
		private readonly LoggerInterface $logger,
	)
	{
	}

	/**
     * @inheritDoc
     */
    public function handle(Event $event): void
    {
        // Lets filter out all events that are not an ObjectUpdatedEvent or ObjectCreatedEvent
        if ($event instanceof ObjectUpdatedEvent === false && $event instanceof ObjectCreatedEvent === false) {
            return;
        }

        // Lets make sure that we have an object
        if (method_exists($event, 'getNewObject') === false) {
            return;
        }

        // lets make sure that we have the proper register and schema
        $object = $event->getNewObject();
        if ($object->getRegister() !== self::SOFTWARE_VERSION_SCHEMA_ID || $object->getSchema() !== self::SOFTWARE_ITEM_SCHEMA_ID) {
            return;
        }

        // Now we can do our update magic by using the SoftwareCatalogueService or it might be called from a rule
    }
}
