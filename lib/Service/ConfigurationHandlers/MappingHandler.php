<?php

namespace OCA\OpenConnector\Service\ConfigurationHandlers;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\Entity;

/**
 * Class MappingHandler
 *
 * Handler for exporting and importing mapping configurations.
 *
 * @package   OCA\OpenConnector\Service\ConfigurationHandlers
 * @category  Service
 * @author    OpenConnector Team
 * @copyright 2024 OpenConnector
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/OpenConnector/openconnector
 *
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class MappingHandler implements ConfigurationHandlerInterface
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
        $mappingArray = ($entity instanceof ObjectEntity) ? $entity->getObject() : $entity->jsonSerialize();
        unset($mappingArray['id'], $mappingArray['uuid']);

        // Ensure slug is set
        if (empty($mappingArray['slug']) && $entity instanceof ObjectEntity) {
            $mappingArray['slug'] = $entity->getUuid();
        }

        // Replace IDs with slugs where applicable.
        if (isset($mappingArray['source_id']) && isset($mappings['source']['idToSlug'][$mappingArray['source_id']])) {
            $mappingArray['source_id'] = $mappings['source']['idToSlug'][$mappingArray['source_id']];
        }

        if (isset($mappingArray['target_id']) && isset($mappings['source']['idToSlug'][$mappingArray['target_id']])) {
            $mappingArray['target_id'] = $mappings['source']['idToSlug'][$mappingArray['target_id']];
        }

        if (isset($mappingArray['mapping']) === false) {
            return $mappingArray;
        }

        $matchedMappings = array_map(
          function (string $field) use ($mappings) {

            $regex = '$executeMapping\(([^)]+)\)$';
            preg_match_all($regex, $field, $matches);
            [$fullMatches, $subMatches] = $matches;

            return array_map(
            callback: function (string $match) use ($mappings) {
                [$mapping, $data]  = explode(separator: ',', string: $match, limit: 2);
                $mappingIdentifier = trim($mapping, '\' ');

                if (isset($mappings['mapping']['slugToId'][$mappingIdentifier]) === true) {
                    return $mappings['mapping']['slugToId'][$mappingIdentifier];
                }

                return $mappingIdentifier;

            },
            array: $subMatches
            );
          },
          $mappingArray['mapping']
          );

        $addingMappingIds = array_merge(...array_values($matchedMappings));

        $mappingIds = array_merge($mappingIds, $addingMappingIds);

        return $mappingArray;
    }//end export()

    /**
     * {@inheritDoc}
     */
    public function import(array $data, array $mappings): Entity
    {
        // Convert slugs back to IDs.
        if (isset($data['source_id']) && isset($mappings['source']['slugToId'][$data['source_id']])) {
            $data['source_id'] = $mappings['source']['slugToId'][$data['source_id']];
        }

        if (isset($data['target_id']) && isset($mappings['source']['slugToId'][$data['target_id']])) {
            $data['target_id'] = $mappings['source']['slugToId'][$data['target_id']];
        }

        // Check if mapping with this slug already exists.
        $slug = $data['slug'] ?? null;
        if ($slug !== null && isset($mappings['mapping']['slugToId'][$slug])) {
            // Update existing mapping.
            return $this->orObjectService->saveObject(
                object: $data,
                register: 'openconnector',
                schema: 'mapping',
                uuid: $mappings['mapping']['slugToId'][$slug]
            );
        }

        // Create new mapping.
        return $this->orObjectService->saveObject(object: $data, register: 'openconnector', schema: 'mapping');
    }//end import()

    /**
     * {@inheritDoc}
     */
    public function getEntityType(): string
    {
        return 'mapping';
    }//end getEntityType()
}//end class
