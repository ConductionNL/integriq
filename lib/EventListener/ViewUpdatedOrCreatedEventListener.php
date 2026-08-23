<?php

/**
 * Integriq ViewUpdatedOrCreated EventListener.
 *
 * Listens for OpenRegister ObjectUpdatedEvent / ObjectCreatedEvent on
 * view objects in the Software Catalog application and synchronises
 * the related software catalog items.
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
 *
 * @todo Remove this temporary listener once it lives in the software catalog application.
 */

namespace OCA\Integriq\EventListener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Event listener that handles view updates and creations.
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class ViewUpdatedOrCreatedEventListener implements IEventListener {

	/**
	 * Register ID for the Software Catalog.
	 *
	 * @var int
	 */
	private const SOFTWARE_CATALOG_REGISTER_ID = 1;

	/**
	 * Schema ID for Software Items.
	 *
	 * @var int
	 */
	private const SOFTWARE_ITEM_SCHEMA_ID = 1;

	/**
	 * Schema ID for Software Versions.
	 *
	 * @var int
	 */
	private const SOFTWARE_VERSION_SCHEMA_ID = 2;

	/**
	 * Constructor.
	 */
	public function __construct() {

	}//end __construct()

	/**
	 * Handle a fired event.
	 *
	 * @param Event $event Event payload to handle.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		// Filter out all events that are not an ObjectUpdatedEvent or ObjectCreatedEvent.
		if ($event instanceof ObjectUpdatedEvent === false && $event instanceof ObjectCreatedEvent === false) {
			return;
		}

		// Make sure that we have an object.
		if (method_exists($event, 'getNewObject') === false) {
			return;
		}

		// Make sure that we have the proper register and schema.
		$object = $event->getNewObject();
		if ($object->getRegister() !== self::SOFTWARE_VERSION_SCHEMA_ID || $object->getSchema() !== self::SOFTWARE_ITEM_SCHEMA_ID) {
			return;
		}

		// Now we can do our update magic by using the SoftwareCatalogueService or it might be called from a rule.
	}//end handle()
}//end class
