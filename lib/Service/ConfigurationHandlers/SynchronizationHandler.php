<?php

namespace OCA\OpenConnector\Service\ConfigurationHandlers;

use OCA\OpenConnector\Db\Synchronization;
use OCA\OpenConnector\Db\SynchronizationMapper;
use OCP\AppFramework\Db\Entity;
use Symfony\Component\Uid\Uuid;

/**
 * Class SynchronizationHandler
 *
 * Handler for exporting and importing synchronization configurations.
 *
 * @package OCA\OpenConnector\Service\ConfigurationHandlers
 * @category Service
 * @author OpenConnector Team
 * @copyright 2024 OpenConnector
 * @license AGPL-3.0
 * @version 1.0.0
 * @link https://github.com/OpenConnector/openconnector
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class SynchronizationHandler implements ConfigurationHandlerInterface
{
    /**
     * @param SynchronizationMapper $synchronizationMapper The synchronization mapper
     */
    public function __construct(
        private readonly SynchronizationMapper $synchronizationMapper
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function export(Entity $entity, array $mappings, array &$mappingIds = []): array
    {
        if (!$entity instanceof Synchronization) {
            throw new \InvalidArgumentException('Entity must be an instance of Synchronization');
        }

        $syncArray = $entity->jsonSerialize();
        unset($syncArray['id'], $syncArray['uuid']);

        // Ensure slug is set
        if (empty($syncArray['slug'])) {
            $syncArray['slug'] = $entity->getSlug();
        }

        // Handle sourceId based on sourceType.
        if (isset($syncArray['sourceId']) && isset($syncArray['sourceType'])) {
            switch ($syncArray['sourceType']) {
                case 'api':
                case 'database':
                    // For api/database sources, use source mapping.
                    if (isset($mappings['source']['idToSlug'][$syncArray['sourceId']])) {
                        $syncArray['sourceId'] = $mappings['source']['idToSlug'][$syncArray['sourceId']];
                    }
                    break;

                case 'register/schema':
                    // For register/schema sources, split the ID and map both parts.
                    if (str_contains($syncArray['sourceId'], '/')) {
                        [$registerId, $schemaId] = explode('/', $syncArray['sourceId']);

                        // Map register ID to slug (fallback to original ID if no mapping found)
                        $registerSlug = $mappings['register']['idToSlug'][$registerId] ?? $registerId;

                        // Map schema ID to slug (fallback to original ID if no mapping found)
                        $schemaSlug = $mappings['schema']['idToSlug'][$schemaId] ?? $schemaId;

                        // Combine the slugs
                        $syncArray['sourceId'] = $registerSlug . '/' . $schemaSlug;
                    }
                    break;
            }
        }

        // Handle targetId based on targetType
        if (isset($syncArray['targetId']) && isset($syncArray['targetType'])) {
            switch ($syncArray['targetType']) {
                case 'api':
                case 'database':
                    // For api/database targets, use source mapping.
                    if (isset($mappings['source']['idToSlug'][$syncArray['targetId']])) {
                        $syncArray['targetId'] = $mappings['source']['idToSlug'][$syncArray['targetId']];
                    }
                    break;

                case 'register/schema':
                    // For register/schema targets, split the ID and map both parts.
                    if (str_contains($syncArray['targetId'], '/')) {
                        [$registerId, $schemaId] = explode('/', $syncArray['targetId']);

                        // Map register ID to slug (fallback to original ID if no mapping found)
                        $registerSlug = $mappings['register']['idToSlug'][$registerId] ?? $registerId;

                        // Map schema ID to slug (fallback to original ID if no mapping found)
                        $schemaSlug = $mappings['schema']['idToSlug'][$schemaId] ?? $schemaId;

                        // Combine the slugs
                        $syncArray['targetId'] = $registerSlug . '/' . $schemaSlug;
                    }
                    break;
            }
        }

        // Handle mapping IDs
        if (isset($syncArray['sourceTargetMapping']) && isset($mappings['mapping']['idToSlug'][(int) $syncArray['sourceTargetMapping']])) {
            $syncArray['sourceTargetMapping'] = $mappings['mapping']['idToSlug'][(int) $syncArray['sourceTargetMapping']];
        }
        if (isset($syncArray['targetSourceMapping']) && isset($mappings['mapping']['idToSlug'][(int) $syncArray['targetSourceMapping']])) {
            $syncArray['targetSourceMapping'] = $mappings['mapping']['idToSlug'][(int) $syncArray['targetSourceMapping']];
        }

        // Handle arrays of IDs that need to be converted to slugs
        $idArrays = ['actions', 'followUps', 'conditions'];
        foreach ($idArrays as $arrayKey) {
            if (isset($syncArray[$arrayKey]) && is_array($syncArray[$arrayKey])) {
                $syncArray[$arrayKey] = array_map(function($id) use ($mappings, $arrayKey) {
                    // Check for valid id, must be numeric or uuid
                    if (is_scalar($id) === false || (is_numeric($id) === false && Uuid::isValid($id) === false)) {
                        return $id;
                    }

                    // For actions, use rule mapping
                    if ($arrayKey === 'actions' && isset($mappings['rule']['idToSlug'][$id])) {
                        return $mappings['rule']['idToSlug'][$id];
                    }
                    // For followUps, use synchronization mapping
                    if ($arrayKey === 'followUps' && isset($mappings['synchronization']['idToSlug'][$id])) {
                        return $mappings['synchronization']['idToSlug'][$id];
                    }
                    // For conditions, use rule mapping
                    if ($arrayKey === 'conditions' && isset($mappings['rule']['idToSlug'][$id])) {
                        return $mappings['rule']['idToSlug'][$id];
                    }
                    return $id;
                }, $syncArray[$arrayKey]);
            }
        }

        return $syncArray;
    }

    /**
     * {@inheritDoc}
     */
    public function import(array $data, array $mappings): Entity
    {
        // Convert source slugs back to IDs.
        if (isset($data['sourceId']) && isset($data['sourceType'])) {
            switch ($data['sourceType']) {
                case 'api':
                case 'database':
                    // For api/database sources, use source mapping.
                    if (isset($mappings['source']['slugToId'][$data['sourceId']])) {
                        $data['sourceId'] = $mappings['source']['slugToId'][$data['sourceId']];
                    }
                    break;

                case 'register/schema':
                    // For register/schema sources, split the ID and map both parts.
                    if (str_contains($data['sourceId'], '/')) {
                        [$registerSlug, $schemaSlug] = explode('/', $data['sourceId']);

                        // Map register slug to ID (fallback to original slug if no mapping found)
                        $registerId = $mappings['register']['slugToId'][$registerSlug] ?? $registerSlug;

                        // Map schema slug to ID (fallback to original slug if no mapping found)
                        $schemaId = $mappings['schema']['slugToId'][$schemaSlug] ?? $schemaSlug;

                        // Combine the IDs
                        $data['sourceId'] = $registerId . '/' . $schemaId;
                    }
                    break;
            }
        }

        // Convert target slugs back to IDs.
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

                        // Combine the IDs
                        $data['targetId'] = $registerId . '/' . $schemaId;
                    }
                    break;
            }
        }

        // Convert mapping slugs back to IDs.
        if (isset($data['sourceTargetMapping']) && isset($mappings['mapping']['slugToId'][$data['sourceTargetMapping']])) {
            $data['sourceTargetMapping'] = $mappings['mapping']['slugToId'][$data['sourceTargetMapping']];
        }
        if (isset($data['targetSourceMapping']) && isset($mappings['mapping']['slugToId'][$data['targetSourceMapping']])) {
            $data['targetSourceMapping'] = $mappings['mapping']['slugToId'][$data['targetSourceMapping']];
        }

        // Convert arrays of slugs back to IDs
        $idArrays = ['actions', 'followUps'];
        foreach ($idArrays as $arrayKey) {
            if (isset($data[$arrayKey]) && is_array($data[$arrayKey])) {
                $data[$arrayKey] = array_map(function($slug) use ($mappings, $arrayKey) {
                    // For actions, use rule mapping
                    if ($arrayKey === 'actions' && isset($mappings['rule']['slugToId'][$slug])) {
                        return $mappings['rule']['slugToId'][$slug];
                    }
                    // For followUps, use synchronization mapping
                    if ($arrayKey === 'followUps' && isset($mappings['synchronization']['slugToId'][$slug])) {
                        return $mappings['synchronization']['slugToId'][$slug];
                    }
                    // For conditions, use rule mapping
                    if ($arrayKey === 'conditions' && isset($mappings['rule']['slugToId'][$slug])) {
                        return $mappings['rule']['slugToId'][$slug];
                    }
                    return $slug;
                }, $data[$arrayKey]);
            }
        }

        // Check if synchronization with this slug already exists.
        if (isset($data['slug']) && isset($mappings['synchronization']['slugToId'][$data['slug']])) {
            // Update existing synchronization.
            return $this->synchronizationMapper->updateFromArray($mappings['synchronization']['slugToId'][$data['slug']], $data);
        }

        // Create new synchronization.
        return $this->synchronizationMapper->createFromArray($data);
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityType(): string
    {
        return 'synchronization';
    }
}
