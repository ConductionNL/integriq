<?php

namespace OCA\OpenConnector\Service\ConfigurationHandlers;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\Entity;

/**
 * Class EndpointHandler
 *
 * Handler for exporting and importing endpoint configurations.
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
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.MissingImport)
 */
class EndpointHandler implements ConfigurationHandlerInterface
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
        $endpointArray = ($entity instanceof ObjectEntity) ? $entity->getObject() : $entity->jsonSerialize();
        unset($endpointArray['id'], $endpointArray['uuid']);

        // Ensure slug is set
        if (empty($endpointArray['slug']) && $entity instanceof ObjectEntity) {
            $endpointArray['slug'] = $entity->getUuid();
        }

        // Handle targetId based on targetType.
        if (isset($endpointArray['targetId']) && isset($endpointArray['targetType'])) {
            switch ($endpointArray['targetType']) {
                case 'api':
                case 'database':
                    // For api/database targets, use source mapping.
                    if (isset($mappings['source']['idToSlug'][$endpointArray['targetId']])) {
                        $endpointArray['targetId'] = $mappings['source']['idToSlug'][$endpointArray['targetId']];
                    }
                    break;

                case 'register/schema':
                    // For register/schema targets, split the ID and map both parts.
                    if (str_contains($endpointArray['targetId'], '/')) {
                        [$registerId, $schemaId] = explode('/', $endpointArray['targetId']);

                        // Map register ID to slug (fallback to original ID if no mapping found)
                        $registerSlug = $mappings['register']['idToSlug'][$registerId] ?? $registerId;

                        // Map schema ID to slug (fallback to original ID if no mapping found)
                        $schemaSlug = $mappings['schema']['idToSlug'][$schemaId] ?? $schemaId;

                        // Combine the slugs
                        $endpointArray['targetId'] = $registerSlug.'/'.$schemaSlug;
                    }
                    break;
            }//end switch
        }//end if

        // Handle mapping IDs
        if (isset($endpointArray['inputMapping']) && isset($mappings['mapping']['idToSlug'][$endpointArray['inputMapping']])) {
            $endpointArray['inputMapping'] = $mappings['mapping']['idToSlug'][$endpointArray['inputMapping']];
        }

        if (isset($endpointArray['outputMapping']) && isset($mappings['mapping']['idToSlug'][$endpointArray['outputMapping']])) {
            $endpointArray['outputMapping'] = $mappings['mapping']['idToSlug'][$endpointArray['outputMapping']];
        }

        if (isset($endpointArray['rules']) === true) {
            $endpointArray['rules'] = array_filter(
              array_map(
              function (int|string $rule) use ($mappings) {
                if (is_numeric($rule)) {
                    $rule = (int) $rule;
                }

                if (isset($mappings['rule']['idToSlug'][$rule]) === true) {
                    return $mappings['rule']['idToSlug'][$rule];
                }

                return null;
              },
                $endpointArray['rules']
              )
              );
        }

        return $endpointArray;
    }//end export()

    /**
     * {@inheritDoc}
     */
    public function import(array $data, array $mappings): Entity
    {
        // Convert slugs back to IDs.
        if (isset($data['targetId']) && isset($data['targetType'])) {
            switch ($data['targetType']) {
                case 'api':
                case 'database':
                    // For api/database targets, use source mapping.
                    if (isset($mappings['source']['slugToId'][$data['targetId']])) {
                        $data['targetId'] = $mappings['source']['slugToId'][$data['targetId']];
                    }
                    break;

                case 'register/schema':
                    // For register/schema targets, split the ID and map both parts.
                    if (str_contains($data['targetId'], '/')) {
                        [$registerSlug, $schemaSlug] = explode('/', $data['targetId']);

                        // Map register slug to ID (fallback to original slug if no mapping found)
                        $registerId = $mappings['register']['slugToId'][$registerSlug] ?? $registerSlug;

                        // Map schema slug to ID (fallback to original slug if no mapping found)
                        $schemaId = $mappings['schema']['slugToId'][$schemaSlug] ?? $schemaSlug;

                        // Combine the IDs.
                        $data['targetId'] = $registerId.'/'.$schemaId;
                    }
                    break;
            }//end switch
        }//end if

        // Handle mapping IDs.
        if (isset($data['inputMapping']) && isset($mappings['mapping']['slugToId'][$data['inputMapping']])) {
            $data['inputMapping'] = $mappings['mapping']['slugToId'][$data['inputMapping']];
        }

        if (isset($data['outputMapping']) && isset($mappings['mapping']['slugToId'][$data['outputMapping']])) {
            $data['outputMapping'] = $mappings['mapping']['slugToId'][$data['outputMapping']];
        }

        // Ensure rules is always an array before processing
        if (!isset($data['rules']) || !is_array($data['rules'])) {
            $data['rules'] = [];
        }

        $data['rules'] = array_filter(
          array_map(
          function (int|string $rule) use ($mappings) {
            if (isset($mappings['rule']['slugToId'][$rule]) === true) {
                return $mappings['rule']['slugToId'][$rule];
            }

            return null;
          },
            $data['rules']
          )
          );

        // Check if endpoint with this slug already exists.
        $slug = $data['slug'] ?? null;
        if ($slug !== null && isset($mappings['endpoint']['slugToId'][$slug])) {
            // Update existing endpoint.
            return $this->orObjectService->saveObject(
                object: $data,
                register: 'openconnector',
                schema: 'endpoint',
                uuid: $mappings['endpoint']['slugToId'][$slug]
            );
        }

        // Create new endpoint.
        return $this->orObjectService->saveObject(object: $data, register: 'openconnector', schema: 'endpoint');
    }//end import()

    /**
     * {@inheritDoc}
     */
    public function getEntityType(): string
    {
        return 'endpoint';
    }//end getEntityType()
}//end class
