<?php
/**
 * Mapping configuration handler.
 *
 * Handler for exporting and importing mapping configurations to/from the
 * OpenAPI-flavoured configuration format used by OpenConnector.
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

/**
 * Handler for exporting and importing mapping configurations.
 *
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class MappingHandler implements ConfigurationHandlerInterface
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
     * Export a mapping entity to its serialised configuration form.
     *
     * @param Entity                                                                           $entity     The mapping entity to export.
     * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings   The global mappings for ID/slug conversion.
     * @param array<int, int|string>                                                           $mappingIds Collected mapping ids (out param).
     *
     * @return array The serialised mapping configuration.
     *
     * @spec openspec/changes/retrofit-2026-05-25-configuration-export-import/tasks.md#task-4
     */
    public function export(Entity $entity, array $mappings, array &$mappingIds=[]): array
    {
        if ($entity instanceof ObjectEntity) {
            $mappingArray = $entity->getObject();
        } else {
            $mappingArray = $entity->jsonSerialize();
        }

        unset($mappingArray['id'], $mappingArray['uuid']);

        // Ensure slug is set.
        if (empty($mappingArray['slug']) === true && $entity instanceof ObjectEntity) {
            $mappingArray['slug'] = $entity->getUuid();
        }

        // Replace IDs with slugs where applicable.
        if (isset($mappingArray['source_id']) === true
            && isset($mappings['source']['idToSlug'][$mappingArray['source_id']]) === true
        ) {
            $mappingArray['source_id'] = $mappings['source']['idToSlug'][$mappingArray['source_id']];
        }

        if (isset($mappingArray['target_id']) === true
            && isset($mappings['source']['idToSlug'][$mappingArray['target_id']]) === true
        ) {
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
     * Import a mapping configuration into a mapping entity.
     *
     * @param array                                                                            $data     The serialised mapping configuration.
     * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
     *
     * @return Entity The imported mapping entity.
     *
     * @spec openspec/changes/retrofit-2026-05-25-configuration-export-import/tasks.md#task-3
     */
    public function import(array $data, array $mappings): Entity
    {
        // Convert slugs back to IDs.
        if (isset($data['source_id']) === true
            && isset($mappings['source']['slugToId'][$data['source_id']]) === true
        ) {
            $data['source_id'] = $mappings['source']['slugToId'][$data['source_id']];
        }

        if (isset($data['target_id']) === true
            && isset($mappings['source']['slugToId'][$data['target_id']]) === true
        ) {
            $data['target_id'] = $mappings['source']['slugToId'][$data['target_id']];
        }

        // Check if mapping with this slug already exists.
        $slug = $data['slug'] ?? null;
        if ($slug !== null && isset($mappings['mapping']['slugToId'][$slug]) === true) {
            // Update existing mapping.
            return $this->orObjectService->saveObject(
                object: $data,
                extend: [],
                register: 'openconnector',
                schema: 'mapping',
                uuid: $mappings['mapping']['slugToId'][$slug]
            );
        }

        // Create new mapping.
        return $this->orObjectService->saveObject(
            object: $data,
            extend: [],
            register: 'openconnector',
            schema: 'mapping'
        );

    }//end import()

    /**
     * Get the entity type this handler is responsible for.
     *
     * @return string The entity type identifier.
     */
    public function getEntityType(): string
    {
        return 'mapping';

    }//end getEntityType()
}//end class
