<?php
/**
 * Source configuration handler.
 *
 * Handler for exporting and importing source configurations. Strips secrets on
 * export and resolves slug → uuid on import.
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
 * Handler for exporting and importing source configurations.
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 */
class SourceHandler implements ConfigurationHandlerInterface
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
     * Export a source entity to its serialised configuration form.
     *
     * @param Entity                                                                           $entity     The source entity to export.
     * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings   The global mappings for ID/slug conversion.
     * @param array<int, int|string>                                                           $mappingIds Collected mapping ids (out param).
     *
     * @return array The serialised source configuration.
     *
     * @spec openspec/changes/retrofit-2026-05-25-configuration-export-import/tasks.md#task-5
     */
    public function export(Entity $entity, array $mappings, array &$mappingIds=[]): array
    {
        if ($entity instanceof ObjectEntity) {
            $sourceArray = $entity->getObject();
        } else {
            $sourceArray = $entity->jsonSerialize();
        }

        // Ensure slug is set.
        if (empty($sourceArray['slug']) === true && $entity instanceof ObjectEntity) {
            $sourceArray['slug'] = $entity->getUuid();
        }

        // Remove sensitive data.
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

        // Sanitize configuration to remove sensitive headers.
        if (isset($sourceArray['configuration']) === true && is_array($sourceArray['configuration']) === true) {
            foreach ($sourceArray['configuration'] as $key => $value) {
                if (str_starts_with($key, 'headers.') === true
                    && (str_contains(strtolower($key), 'authorization') === true
                    || str_contains(strtolower($key), 'token') === true
                    || str_contains(strtolower($key), 'key') === true
                    || str_contains(strtolower($key), 'secret') === true)
                ) {
                    unset($sourceArray['configuration'][$key]);
                }
            }
        }

        return $sourceArray;

    }//end export()

    /**
     * Import a source configuration into a source entity.
     *
     * @param array                                                                            $data     The serialised source configuration.
     * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
     *
     * @return Entity The imported source entity.
     *
     * @spec openspec/changes/retrofit-2026-05-25-configuration-export-import/tasks.md#task-3
     */
    public function import(array $data, array $mappings): Entity
    {
        // Check if source with this slug already exists.
        $slug = $data['slug'] ?? null;
        if ($slug !== null && isset($mappings['source']['slugToId'][$slug]) === true) {
            // Update existing source.
            return $this->orObjectService->saveObject(
                object: $data,
                extend: [],
                register: 'openconnector',
                schema: 'source',
                uuid: $mappings['source']['slugToId'][$slug]
            );
        }

        // Create new source.
        return $this->orObjectService->saveObject(
            object: $data,
            extend: [],
            register: 'openconnector',
            schema: 'source'
        );

    }//end import()

    /**
     * Get the entity type this handler is responsible for.
     *
     * @return string The entity type identifier.
     */
    public function getEntityType(): string
    {
        return 'source';

    }//end getEntityType()
}//end class
