<?php

namespace OCA\OpenConnector\Service\ConfigurationHandlers;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\Entity;

/**
 * Class SourceHandler
 *
 * Handler for exporting and importing source configurations.
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
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class SourceHandler implements ConfigurationHandlerInterface
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
        $sourceArray = ($entity instanceof ObjectEntity) ? $entity->getObject() : $entity->jsonSerialize();

        // Ensure slug is set
        if (empty($sourceArray['slug']) && $entity instanceof ObjectEntity) {
            $sourceArray['slug'] = $entity->getUuid();
        }

        // Remove sensitive data
        unset(
            $sourceArray['id'],
            $sourceArray['uuid'],
            $sourceArray['authorizationHeader'],
            $sourceArray['auth'],
            $sourceArray['authenticationConfig'],
            $sourceArray['authorizationPassthroughMethod'],
            $sourceArray['jwt'],
            $sourceArray['jwtId'],
            $sourceArray['secret'],
            $sourceArray['username'],
            $sourceArray['password'],
            $sourceArray['apikey']
        );

        // Sanitize configuration to remove sensitive headers
        if (isset($sourceArray['configuration']) && is_array($sourceArray['configuration'])) {
            foreach ($sourceArray['configuration'] as $key => $value) {
                if (str_starts_with($key, 'headers.')
                    && (str_contains(strtolower($key), 'authorization')
                    || str_contains(strtolower($key), 'token')
                    || str_contains(strtolower($key), 'key')
                    || str_contains(strtolower($key), 'secret'))
                ) {
                    unset($sourceArray['configuration'][$key]);
                }
            }
        }

        return $sourceArray;
    }//end export()

    /**
     * {@inheritDoc}
     */
    public function import(array $data, array $mappings): Entity
    {
        // Check if source with this slug already exists.
        $slug = $data['slug'] ?? null;
        if ($slug !== null && isset($mappings['source']['slugToId'][$slug])) {
            // Update existing source
            return $this->orObjectService->saveObject(
                object: $data,
                register: 'openconnector',
                schema: 'source',
                uuid: $mappings['source']['slugToId'][$slug]
            );
        }

        // Create new source.
        return $this->orObjectService->saveObject(object: $data, register: 'openconnector', schema: 'source');
    }//end import()

    /**
     * {@inheritDoc}
     */
    public function getEntityType(): string
    {
        return 'source';
    }//end getEntityType()
}//end class
