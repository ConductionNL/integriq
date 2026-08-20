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

use OCA\OpenConnector\Service\Security\SensitiveFieldRegistry;
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
		if ($entity instanceof ObjectEntity) {
			$mappingArray = $entity->getObject();
		} else {
			$mappingArray = $entity->jsonSerialize();
		}

		unset($mappingArray['id'], $mappingArray['uuid']);

		// Redact secret-shaped values from the nested configuration array (secret-hygiene).
		$mappingArray = $this->redactConfiguration(entityArray: $mappingArray);

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
						[$mapping, $data] = explode(separator: ',', string: $match, limit: 2);
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
		if (isset($entityArray['configuration']) === true && is_array($entityArray['configuration']) === true) {
			$entityArray['configuration'] = $this->sensitiveFieldRegistry->redactArray(data: $entityArray['configuration']);
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
				register: 'openconnector',
				schema: 'mapping',
				uuid: $mappings['mapping']['slugToId'][$slug]
			);
		}

		// Create new mapping.
		return $this->orObjectService->saveObject(object: $data, register: 'openconnector', schema: 'mapping');
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
