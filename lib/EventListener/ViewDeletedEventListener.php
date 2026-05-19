<?php

namespace OCA\OpenConnector\EventListener;

use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
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
 * @SuppressWarnings(PHPMD.IfStatementAssignment)
 */
class ViewDeletedEventListener implements IEventListener
{

	public function __construct(
		private readonly LoggerInterface $logger,
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly ObjectService $objectService,
    )
	{
	}

	/**
     * @inheritDoc
     */
    public function handle(Event $event): void
    {
        // Lets filter out all events that are not an ObjectUpdatedEvent or ObjectCreatedEvent
        if ($event instanceof ObjectDeletedEvent === false) {
            return;
        }


        // lets make sure that we have the proper register and schema
        $object = $event->getObject();
        if (($register = $this->registerMapper->find($object->getRegister()))->getSlug() !== 'vng-gemma' || $this->schemaMapper->find($object->getSchema())->getSlug() !== 'view') {
            return;
        }

        $identifier = $object->jsonSerialize()['identifier'];


        $schema = $this->schemaMapper->find('extendview');
        $openregister = $this->objectService->getOpenRegisters();

        $extendedViews = $openregister->findAll(['filters' => ['register' => $register->getId(), 'schema' => $schema->getId(), 'identifier' => $identifier]]);

        foreach($extendedViews as $extendedView) {
            $openregister->delete($extendedView);
        }

        // Now we can do our update magic by using the SoftwareCatalogueService or it might be called from a rule
    }
}
