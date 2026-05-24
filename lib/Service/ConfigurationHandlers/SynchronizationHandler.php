<?php
/**
 * Synchronization configuration handler.
 *
 * Handler for exporting and importing synchronization configurations,
 * translating source/target ids, mappings, actions, followUps and conditions
 * between numeric ids and stable slugs for portability.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\ConfigurationHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenConnector.nl
 */

namespace OCA\OpenConnector\Service\ConfigurationHandlers;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\Entity;
use Symfony\Component\Uid\Uuid;

/**
 * Handler for exporting and importing synchronization configurations.
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
     * Constructor.
     *
     * @param OrObjectService $orObjectService The OR object service.
     */
    public function __construct(
        private readonly OrObjectService $orObjectService
    ) {

    }//end __construct()

    /**
     * Export a synchronization entity to its serialised configuration form.
     *
     * @param Entity                 $entity     The synchronization entity to export.
     * @param array<string,mixed>    $mappings   The global mappings for ID/slug conversion.
     *                                           Shape: `[$type => ['idToSlug' => array, 'slugToId' => array]]` where `$type`
     *                                           is one of `register|schema|source|mapping|action|followUp|condition`. The
     *                                           inner idToSlug/slugToId arrays are looked up by both int and string keys
     *                                           at runtime, so the value type is intentionally relaxed to `mixed` for
     *                                           static-analysis purposes.
     * @param array<int, int|string> $mappingIds Collected mapping ids (out param).
     *
     * @return array The serialised synchronization configuration.
     */
    public function export(Entity $entity, array $mappings, array &$mappingIds=[]): array
    {
        if ($entity instanceof ObjectEntity) {
            $syncArray = $entity->getObject();
        } else {
            $syncArray = $entity->jsonSerialize();
        }

        unset($syncArray['id'], $syncArray['uuid']);

        // Ensure slug is set.
        if (empty($syncArray['slug']) === true && $entity instanceof ObjectEntity) {
            $syncArray['slug'] = $entity->getUuid();
        }

        // Handle sourceId based on sourceType.
        if (isset($syncArray['sourceId']) === true && isset($syncArray['sourceType']) === true) {
            switch ($syncArray['sourceType']) {
                case 'api':
                case 'database':
                    // For api/database sources, use source mapping.
                    if (isset($mappings['source']['idToSlug'][$syncArray['sourceId']]) === true) {
                        $syncArray['sourceId'] = $mappings['source']['idToSlug'][$syncArray['sourceId']];
                    }
                    break;

                case 'register/schema':
                    // For register/schema sources, split the ID and map both parts.
                    if (str_contains($syncArray['sourceId'], '/') === true) {
                        [$registerId, $schemaId] = explode('/', $syncArray['sourceId']);

                        // Map register ID to slug (fallback to original ID if no mapping found).
                        $registerSlug = $mappings['register']['idToSlug'][$registerId] ?? $registerId;

                        // Map schema ID to slug (fallback to original ID if no mapping found).
                        $schemaSlug = $mappings['schema']['idToSlug'][$schemaId] ?? $schemaId;

                        // Combine the slugs.
                        $syncArray['sourceId'] = $registerSlug.'/'.$schemaSlug;
                    }
                    break;
            }//end switch
        }//end if

        // Handle targetId based on targetType.
        if (isset($syncArray['targetId']) === true && isset($syncArray['targetType']) === true) {
            switch ($syncArray['targetType']) {
                case 'api':
                case 'database':
                    // For api/database targets, use source mapping.
                    if (isset($mappings['source']['idToSlug'][$syncArray['targetId']]) === true) {
                        $syncArray['targetId'] = $mappings['source']['idToSlug'][$syncArray['targetId']];
                    }
                    break;

                case 'register/schema':
                    // For register/schema targets, split the ID and map both parts.
                    if (str_contains($syncArray['targetId'], '/') === true) {
                        [$registerId, $schemaId] = explode('/', $syncArray['targetId']);

                        // Map register ID to slug (fallback to original ID if no mapping found).
                        $registerSlug = $mappings['register']['idToSlug'][$registerId] ?? $registerId;

                        // Map schema ID to slug (fallback to original ID if no mapping found).
                        $schemaSlug = $mappings['schema']['idToSlug'][$schemaId] ?? $schemaId;

                        // Combine the slugs.
                        $syncArray['targetId'] = $registerSlug.'/'.$schemaSlug;
                    }
                    break;
            }//end switch
        }//end if

        // Handle mapping IDs.
        if (isset($syncArray['sourceTargetMapping']) === true
            && isset($mappings['mapping']['idToSlug'][(int) $syncArray['sourceTargetMapping']]) === true
        ) {
            $syncArray['sourceTargetMapping'] = $mappings['mapping']['idToSlug'][(int) $syncArray['sourceTargetMapping']];
        }

        if (isset($syncArray['targetSourceMapping']) === true
            && isset($mappings['mapping']['idToSlug'][(int) $syncArray['targetSourceMapping']]) === true
        ) {
            $syncArray['targetSourceMapping'] = $mappings['mapping']['idToSlug'][(int) $syncArray['targetSourceMapping']];
        }

        // Handle arrays of IDs that need to be converted to slugs.
        $idArrays = ['actions', 'followUps', 'conditions'];
        foreach ($idArrays as $arrayKey) {
            if (isset($syncArray[$arrayKey]) === true && is_array($syncArray[$arrayKey]) === true) {
                $syncArray[$arrayKey] = array_map(
                    function ($id) use ($mappings, $arrayKey) {
                        // Check for valid id, must be numeric or uuid.
                        if (is_scalar($id) === false
                            || (is_numeric($id) === false && Uuid::isValid($id) === false)
                        ) {
                            return $id;
                        }

                        // For actions, use rule mapping.
                        if ($arrayKey === 'actions' && isset($mappings['rule']['idToSlug'][$id]) === true) {
                            return $mappings['rule']['idToSlug'][$id];
                        }

                        // For followUps, use synchronization mapping.
                        if ($arrayKey === 'followUps' && isset($mappings['synchronization']['idToSlug'][$id]) === true) {
                            return $mappings['synchronization']['idToSlug'][$id];
                        }

                        // For conditions, use rule mapping.
                        if ($arrayKey === 'conditions' && isset($mappings['rule']['idToSlug'][$id]) === true) {
                            return $mappings['rule']['idToSlug'][$id];
                        }

                        return $id;
                    },
                    $syncArray[$arrayKey]
                );
            }//end if
        }//end foreach

        return $syncArray;

    }//end export()

    /**
     * Import a synchronization configuration into a synchronization entity.
     *
     * @param array               $data     The serialised synchronization configuration.
     * @param array<string,mixed> $mappings The global mappings for ID/slug conversion.
     *                                      See {@see self::export()} for the runtime shape.
     *
     * @return Entity The imported synchronization entity.
     */
    public function import(array $data, array $mappings): Entity
    {
        // Convert source slugs back to IDs.
        if (isset($data['sourceId']) === true && isset($data['sourceType']) === true) {
            switch ($data['sourceType']) {
                case 'api':
                case 'database':
                    // For api/database sources, use source mapping.
                    if (isset($mappings['source']['slugToId'][$data['sourceId']]) === true) {
                        $data['sourceId'] = $mappings['source']['slugToId'][$data['sourceId']];
                    }
                    break;

                case 'register/schema':
                    // For register/schema sources, split the ID and map both parts.
                    if (str_contains($data['sourceId'], '/') === true) {
                        [$registerSlug, $schemaSlug] = explode('/', $data['sourceId']);

                        // Map register slug to ID (fallback to original slug if no mapping found).
                        $registerId = $mappings['register']['slugToId'][$registerSlug] ?? $registerSlug;

                        // Map schema slug to ID (fallback to original slug if no mapping found).
                        $schemaId = $mappings['schema']['slugToId'][$schemaSlug] ?? $schemaSlug;

                        // Combine the IDs.
                        $data['sourceId'] = $registerId.'/'.$schemaId;
                    }
                    break;
            }//end switch
        }//end if

        // Convert target slugs back to IDs.
        if (isset($data['targetId']) === true && isset($data['targetType']) === true) {
            switch ($data['targetType']) {
                case 'api':
                case 'database':
                    // For api/database targets, use source mapping.
                    if (isset($mappings['source']['slugToId'][$data['targetId']]) === true) {
                        $data['targetId'] = $mappings['source']['slugToId'][$data['targetId']];
                    }
                    break;

                case 'register/schema':
                    // For register/schema targets, split the ID and map both parts.
                    if (str_contains($data['targetId'], '/') === true) {
                        [$registerSlug, $schemaSlug] = explode('/', $data['targetId']);

                        // Map register slug to ID (fallback to original slug if no mapping found).
                        $registerId = $mappings['register']['slugToId'][$registerSlug] ?? $registerSlug;

                        // Map schema slug to ID (fallback to original slug if no mapping found).
                        $schemaId = $mappings['schema']['slugToId'][$schemaSlug] ?? $schemaSlug;

                        // Combine the IDs.
                        $data['targetId'] = $registerId.'/'.$schemaId;
                    }
                    break;
            }//end switch
        }//end if

        // Convert mapping slugs back to IDs.
        if (isset($data['sourceTargetMapping']) === true
            && isset($mappings['mapping']['slugToId'][$data['sourceTargetMapping']]) === true
        ) {
            $data['sourceTargetMapping'] = $mappings['mapping']['slugToId'][$data['sourceTargetMapping']];
        }

        if (isset($data['targetSourceMapping']) === true
            && isset($mappings['mapping']['slugToId'][$data['targetSourceMapping']]) === true
        ) {
            $data['targetSourceMapping'] = $mappings['mapping']['slugToId'][$data['targetSourceMapping']];
        }

        // Convert arrays of slugs back to IDs (mirrors $idArrays in export()).
        $idArrays = ['actions', 'followUps', 'conditions'];
        foreach ($idArrays as $arrayKey) {
            if (isset($data[$arrayKey]) === true && is_array($data[$arrayKey]) === true) {
                $data[$arrayKey] = array_map(
                    function ($slug) use ($mappings, $arrayKey) {
                        // For actions, use rule mapping.
                        if ($arrayKey === 'actions' && isset($mappings['rule']['slugToId'][$slug]) === true) {
                            return $mappings['rule']['slugToId'][$slug];
                        }

                        // For followUps, use synchronization mapping.
                        if ($arrayKey === 'followUps'
                            && isset($mappings['synchronization']['slugToId'][$slug]) === true
                        ) {
                            return $mappings['synchronization']['slugToId'][$slug];
                        }

                        // For conditions, use rule mapping.
                        if ($arrayKey === 'conditions' && isset($mappings['rule']['slugToId'][$slug]) === true) {
                            return $mappings['rule']['slugToId'][$slug];
                        }

                        return $slug;
                    },
                    $data[$arrayKey]
                );
            }//end if
        }//end foreach

        // Check if synchronization with this slug already exists.
        $slug = $data['slug'] ?? null;
        if ($slug !== null && isset($mappings['synchronization']['slugToId'][$slug]) === true) {
            // Update existing synchronization.
            return $this->orObjectService->saveObject(
                object: $data,
                register: 'openconnector',
                schema: 'synchronization',
                uuid: $mappings['synchronization']['slugToId'][$slug]
            );
        }

        // Create new synchronization.
        return $this->orObjectService->saveObject(object: $data, register: 'openconnector', schema: 'synchronization');

    }//end import()

    /**
     * Get the entity type this handler is responsible for.
     *
     * @return string The entity type identifier.
     */
    public function getEntityType(): string
    {
        return 'synchronization';

    }//end getEntityType()
}//end class
