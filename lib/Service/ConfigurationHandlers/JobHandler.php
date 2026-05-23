<?php

namespace OCA\OpenConnector\Service\ConfigurationHandlers;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\Entity;

/**
 * Class JobHandler
 *
 * Handler for exporting and importing job configurations.
 *
 * @package   OCA\OpenConnector\Service\ConfigurationHandlers
 * @category  Service
 * @author    OpenConnector Team
 * @copyright 2024 OpenConnector
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/OpenConnector/openconnector
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.MissingImport)
 */
class JobHandler implements ConfigurationHandlerInterface
{
    /**
     * @param OrObjectService $orObjectService The OR object service
     */
    public function __construct(
        private readonly OrObjectService $orObjectService
    ) {
    }//end __construct()

    /**
     * {@inheritDoc}
     */
    public function export(Entity $entity, array $mappings, array &$mappingIds=[]): array
    {
        $jobArray = ($entity instanceof ObjectEntity) ? $entity->getObject() : $entity->jsonSerialize();
        unset($jobArray['id'], $jobArray['uuid']);

        // Ensure slug is set
        if (empty($jobArray['slug']) && $entity instanceof ObjectEntity) {
            $jobArray['slug'] = $entity->getUuid();
        }

        // Replace IDs with slugs in arguments
        if (isset($jobArray['arguments']) && is_array($jobArray['arguments'])) {
            $arguments = $jobArray['arguments'];
            // Convert synchronizationId from integer to string if it exists
            if (isset($arguments['synchronizationId'])) {
                $synchronizationId = (string) $arguments['synchronizationId'];
                if (isset($mappings['synchronization']['idToSlug'][$synchronizationId])) {
                    $arguments['synchronizationId'] = $mappings['synchronization']['idToSlug'][$synchronizationId];
                }
            }

            if (isset($arguments['endpointId']) && isset($mappings['endpoint']['idToSlug'][$arguments['endpointId']])) {
                $arguments['endpointId'] = $mappings['endpoint']['idToSlug'][$arguments['endpointId']];
            }

            if (isset($arguments['sourceId']) && isset($mappings['source']['idToSlug'][$arguments['sourceId']])) {
                $arguments['sourceId'] = $mappings['source']['idToSlug'][$arguments['sourceId']];
            }

            $jobArray['arguments'] = $arguments;
        }

        return $jobArray;
    }//end export()

    /**
     * {@inheritDoc}
     */
    public function import(array $data, array $mappings): Entity
    {
        // Convert slugs back to IDs in arguments JSON.
        if (isset($data['arguments'])) {
            if (is_array($data['arguments']) === false) {
                $arguments = json_decode($data['arguments'], true);
            } else {
                $arguments = $data['arguments'];
            }

            if (is_array($arguments)) {
                if (isset($arguments['synchronizationId']) && isset($mappings['synchronization']['slugToId'][$arguments['synchronizationId']])) {
                    $arguments['synchronizationId'] = $mappings['synchronization']['slugToId'][$arguments['synchronizationId']];
                }

                if (isset($arguments['endpointId']) && isset($mappings['endpoint']['slugToId'][$arguments['endpointId']])) {
                    $arguments['endpointId'] = $mappings['endpoint']['slugToId'][$arguments['endpointId']];
                }

                if (isset($arguments['sourceId']) && isset($mappings['source']['slugToId'][$arguments['sourceId']])) {
                    $arguments['sourceId'] = $mappings['source']['slugToId'][$arguments['sourceId']];
                }

                $data['arguments'] = $arguments;
            }
        }//end if

        // Check if job with this slug already exists.
        $slug = $data['slug'] ?? null;
        if ($slug !== null && isset($mappings['job']['slugToId'][$slug])) {
            // Update existing job.
            return $this->orObjectService->saveObject(
                object: $data,
                register: 'openconnector',
                schema: 'job',
                uuid: $mappings['job']['slugToId'][$slug]
            );
        }

        // Create new job.
        return $this->orObjectService->saveObject(object: $data, register: 'openconnector', schema: 'job');
    }//end import()

    /**
     * {@inheritDoc}
     */
    public function getEntityType(): string
    {
        return 'job';
    }//end getEntityType()
}//end class
