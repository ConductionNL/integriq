<?php

namespace OCA\OpenConnector\Service\ConfigurationHandlers;

use OCP\AppFramework\Db\Entity;

/**
 * Interface ConfigurationHandlerInterface
 *
 * Interface for configuration handlers that handle export and import of entities.
 *
 * @package   OCA\OpenConnector\Service\ConfigurationHandlers
 * @category  Service
 * @author    OpenConnector Team
 * @copyright 2024 OpenConnector
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/OpenConnector/openconnector
 */
interface ConfigurationHandlerInterface
{
    /**
     * Export an entity to OpenAPI format
     *
     * @param  Entity                                                                           $entity   The entity to export
     * @param  array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion
     * @return array The OpenAPI entity specification
     */
    public function export(Entity $entity, array $mappings, array &$mappingIds=[]): array;

    /**
     * Import an entity from OpenAPI format
     *
     * @param  array                                                                            $data     The OpenAPI entity specification
     * @param  array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion
     * @return Entity The imported entity
     */
    public function import(array $data, array $mappings): Entity;

    /**
     * Get the entity type this handler is responsible for
     *
     * @return string The entity type
     */
    public function getEntityType(): string;
}//end interface
