<?php

/**
 * Mapping configuration handler.
 *
 * Handler for exporting and importing mapping configurations to/from the
 * OpenAPI-flavoured configuration format used by Integriq.
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
 *
 * SPDX-FileCopyrightText: 2025 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Service\ConfigurationHandlers;

use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\Entity;

/**
 * Handler for exporting and importing mapping configurations.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 *
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.UnusedLocalVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class MappingHandler implements ConfigurationHandlerInterface {
	/**
	 * Constructor.
	 *
	 * @param OrObjectService $orObjectService The OR object service.
	 * @param SensitiveFieldRegistry $sensitiveFieldRegistry Shared secret-name detection/redaction registry (secret-hygiene).
	 */
	public function __construct(
		private readonly OrObjectService $orObjectService,
		private readonly SensitiveFieldRegistry $sensitiveFieldRegistry,
	) {

	}//end __construct()

	/**
	 * Export a mapping entity to its serialised configuration form.
	 *
	 * @param Entity $entity The mapping entity to export.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 * @param array<int, int|string> $mappingIds Collected mapping ids (out param).
	 *
	 * @return array The serialised mapping configuration.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function export(Entity $entity, array $mappings, array &$mappingIds = []): array {
		$mappingArray = $this->serialiseEntity(entity: $entity);
		unset($mappingArray['id'], $mappingArray['uuid']);

		// Redact secret-shaped values from the nested configuration array (secret-hygiene).
		$mappingArray = $this->redactConfiguration(entityArray: $mappingArray);

		// Ensure slug is set.
		if (empty($mappingArray['slug']) === true && $entity instanceof ObjectEntity) {
			$mappingArray['slug'] = $entity->getUuid();
		}

		// Replace IDs with slugs where applicable.
		$mappingArray = $this->slugSourceReferences(mappingArray: $mappingArray, idToSlug: ($mappings['source']['idToSlug'] ?? []));
		if (isset($mappingArray['mapping']) === false) {
			return $mappingArray;
		}

		$slugToId = ($mappings['mapping']['slugToId'] ?? []);
		$mappingIds = array_merge($mappingIds, $this->referencedMappingIds(fields: $mappingArray['mapping'], slugToId: $slugToId));

		return $mappingArray;
	}//end export()

	/**
	 * The entity's stored object, or its serialised form for a plain entity.
	 *
	 * @param Entity $entity The mapping entity.
	 *
	 * @return array The entity as an array.
	 */
	private function serialiseEntity(Entity $entity): array {
		if ($entity instanceof ObjectEntity) {
			return $entity->getObject();
		}

		return $entity->jsonSerialize();
	}//end serialiseEntity()

	/**
	 * Replace the source_id and target_id with the source's slug when the
	 * global mappings know it.
	 *
	 * @param array                $mappingArray The serialised mapping.
	 * @param array<string,string> $idToSlug     The source id-to-slug map.
	 *
	 * @return array The mapping with slug references.
	 */
	private function slugSourceReferences(array $mappingArray, array $idToSlug): array {
		foreach (['source_id', 'target_id'] as $key) {
			if (isset($mappingArray[$key]) === true && isset($idToSlug[$mappingArray[$key]]) === true) {
				$mappingArray[$key] = $idToSlug[$mappingArray[$key]];
			}
		}

		return $mappingArray;
	}//end slugSourceReferences()

	/**
	 * The mapping ids every executeMapping() call in the field mapping refers to.
	 *
	 * @param array                $fields   The field mapping.
	 * @param array<string,string> $slugToId The mapping slug-to-id map.
	 *
	 * @return array<int,int|string> The referenced ids, in field order.
	 */
	private function referencedMappingIds(array $fields, array $slugToId): array {
		$matchedMappings = array_map(
			fn (string $field) => $this->mappingIdsInField(field: $field, slugToId: $slugToId),
			$fields
		);

		return array_merge(...array_values($matchedMappings));
	}//end referencedMappingIds()

	/**
	 * The mapping ids the executeMapping() calls in one field refer to.
	 *
	 * @param string               $field    The field expression.
	 * @param array<string,string> $slugToId The mapping slug-to-id map.
	 *
	 * @return array<int,int|string> The referenced ids.
	 */
	private function mappingIdsInField(string $field, array $slugToId): array {
		$regex = '$executeMapping\(([^)]+)\)$';
		preg_match_all($regex, $field, $matches);
		[$fullMatches, $subMatches] = $matches;

		return array_map(
			callback: fn (string $match) => $this->resolveMappingReference(match: $match, slugToId: $slugToId),
			array: $subMatches
		);
	}//end mappingIdsInField()

	/**
	 * The id of the mapping one executeMapping() argument list names: its
	 * known id, or the identifier as written.
	 *
	 * @param string               $match    The call's argument list.
	 * @param array<string,string> $slugToId The mapping slug-to-id map.
	 *
	 * @return mixed The mapping id or identifier.
	 */
	private function resolveMappingReference(string $match, array $slugToId): mixed {
		[$mapping, $data] = explode(separator: ',', string: $match, limit: 2);
		$mappingIdentifier = trim($mapping, '\' ');

		if (isset($slugToId[$mappingIdentifier]) === true) {
			return $slugToId[$mappingIdentifier];
		}

		return $mappingIdentifier;
	}//end resolveMappingReference()

	/**
	 * Redact secret-shaped values from an entity array's nested `configuration`
	 * sub-array via the shared registry, when present.
	 *
	 * Extracted as a helper to keep export()'s NPath complexity within the
	 * configured threshold.
	 *
	 * @param array $entityArray The serialised entity array.
	 *
	 * @return array The entity array with its configuration redacted.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	private function redactConfiguration(array $entityArray): array {
		// `cast` names a type per field (`password: string`), never a value, so it is kept as is.
		$cast = ($entityArray['cast'] ?? null);
		$entityArray = $this->sensitiveFieldRegistry->redactConfiguration(data: $entityArray);
		if ($cast !== null) {
			$entityArray['cast'] = $cast;
		}

		return $entityArray;
	}//end redactConfiguration()

	/**
	 * Import a mapping configuration into a mapping entity.
	 *
	 * @param array $data The serialised mapping configuration.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return Entity The imported mapping entity.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	public function import(array $data, array $mappings): Entity {
		$data = $this->sensitiveFieldRegistry->withoutRedactedValues(data: $data);

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
				register: 'integriq',
				schema: 'mapping',
				uuid: $mappings['mapping']['slugToId'][$slug]
			);
		}

		// Create new mapping.
		return $this->orObjectService->saveObject(object: $data, register: 'integriq', schema: 'mapping');
	}//end import()

	/**
	 * Get the entity type this handler is responsible for.
	 *
	 * @return string The entity type identifier.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-003--import-an-oas-document-in-dependency-order
	 */
	public function getEntityType(): string {
		return 'mapping';
	}//end getEntityType()
}//end class
