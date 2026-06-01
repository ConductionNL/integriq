<?php
/**
 * OpenConnector ViewDeleted EventListener.
 *
 * Listens for OpenRegister ObjectDeletedEvent on view objects in the
 * Software Catalog application and removes the matching extended view
 * objects.
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
 * @todo Remove this temporary listener once it lives in the software catalog application.
 */

namespace OCA\OpenConnector\EventListener;

use OCA\OpenConnector\Service\SourceMappingService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Event listener that removes extended views when a view is deleted.
 *
 * @SuppressWarnings(PHPMD.IfStatementAssignment)
 */
class ViewDeletedEventListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param SchemaMapper   $schemaMapper   Schema mapper used to resolve view + extendview schemas.
     * @param RegisterMapper $registerMapper Register mapper used to resolve the vng-gemma register.
     * @param ObjectService  $objectService  Service providing access to the OR object layer.
     */
    public function __construct(
        private readonly SchemaMapper $schemaMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SourceMappingService $objectService,
    ) {

    }//end __construct()

    /**
     * Handle a fired event.
     *
     * @param Event $event Event payload to handle.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        // Filter out all events that are not an ObjectDeletedEvent.
        if ($event instanceof ObjectDeletedEvent === false) {
            return;
        }

        // Make sure that we have the proper register and schema.
        $object   = $event->getObject();
        $register = $this->registerMapper->find($object->getRegister());
        if ($register->getSlug() !== 'vng-gemma'
            || $this->schemaMapper->find($object->getSchema())->getSlug() !== 'view'
        ) {
            return;
        }

        $identifier = $object->jsonSerialize()['identifier'];

        $schema       = $this->schemaMapper->find('extendview');
        $openregister = $this->objectService->getOpenRegisters();

        $extendedViews = $openregister->findAll(
                [
                    'filters' => [
                        'register'   => $register->getId(),
                        'schema'     => $schema->getId(),
                        'identifier' => $identifier,
                    ],
                ]
                );

        foreach ($extendedViews as $extendedView) {
            $openregister->delete($extendedView);
        }

        // Now we can do our update magic by using the SoftwareCatalogueService or it might be called from a rule.
    }//end handle()
}//end class
