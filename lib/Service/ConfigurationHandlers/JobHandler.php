<?php

namespace OCA\OpenConnector\Service\ConfigurationHandlers;

use OCA\OpenConnector\Db\Job;
use OCA\OpenConnector\Db\JobMapper;
use OCP\AppFramework\Db\Entity;

/**
 * Class JobHandler
 *
 * Handler for exporting and importing job configurations.
 *
 * @package OCA\OpenConnector\Service\ConfigurationHandlers
 * @category Service
 * @author OpenConnector Team
 * @copyright 2024 OpenConnector
 * @license AGPL-3.0
 * @version 1.0.0
 * @link https://github.com/OpenConnector/openconnector
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.MissingImport)
 */
class JobHandler implements ConfigurationHandlerInterface
{
    /**
     * @param JobMapper $jobMapper The job mapper
     */
    public function __construct(
        private readonly JobMapper $jobMapper
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function export(Entity $entity, array $mappings, array &$mappingIds = []): array
    {
        if (!$entity instanceof Job) {
            throw new \InvalidArgumentException('Entity must be an instance of Job');
        }

        $jobArray = $entity->jsonSerialize();
        unset($jobArray['id'], $jobArray['uuid']);

        // Ensure slug is set
        if (empty($jobArray['slug'])) {
            $jobArray['slug'] = $entity->getSlug();
        }

        // Replace IDs with slugs in arguments
        if (isset($jobArray['arguments']) && is_array($jobArray['arguments'])) {
            $arguments = $jobArray['arguments'];
            // Convert synchronizationId from integer to string if it exists
            if (isset($arguments['synchronizationId'])) {
                $synchronizationId = (string)$arguments['synchronizationId'];
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
    }

    /**
     * {@inheritDoc}
     */
    public function import(array $data, array $mappings): Entity
    {
        // Convert slugs back to IDs in arguments JSON.
        if (isset($data['arguments'])) {

			if(is_array($data['arguments']) === false) {
				$arguments = json_decode($data['arguments'], true);
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
                $data['arguments'] = json_encode($arguments);
            }
        }

        // Check if job with this slug already exists.
        if (isset($data['slug']) && isset($mappings['job']['slugToId'][$data['slug']])) {
            // Update existing job.
            return $this->jobMapper->updateFromArray($mappings['job']['slugToId'][$data['slug']], $data);
        }

        // Create new job.
        return $this->jobMapper->createFromArray($data);
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityType(): string
    {
        return 'job';
    }
}
