<?php

/**
 * Configuration handler interface.
 *
 * Contract for configuration handlers that handle export and import of entities
 * to/from the OpenAPI specification format.
 *
 * @category Service
 * @package  OCA\Integriq\Service\ConfigurationHandlers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

namespace OCA\Integriq\Service\ConfigurationHandlers;

use OCP\AppFramework\Db\Entity;

/**
 * Interface for configuration handlers that handle export and import of entities.
 */
interface ConfigurationHandlerInterface {
	/**
	 * Export an entity to OpenAPI format.
	 *
	 * @param Entity $entity The entity to export.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 * @param array<int, int|string> $mappingIds Collected mapping ids (out parameter).
	 *
	 * @return array The OpenAPI entity specification.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#REQ-001
	 */
	public function export(Entity $entity, array $mappings, array &$mappingIds = []): array;

	/**
	 * Import an entity from OpenAPI format.
	 *
	 * @param array $data The OpenAPI entity specification.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return Entity The imported entity.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#REQ-003
	 */
	public function import(array $data, array $mappings): Entity;

	/**
	 * Get the entity type this handler is responsible for.
	 *
	 * @return string The entity type.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#REQ-003
	 */
	public function getEntityType(): string;
}//end interface
