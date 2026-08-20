<?php

/**
 * Endpoint configuration handler.
 *
 * Handler for exporting and importing endpoint configurations, translating
 * targetId, mapping references and attached rules between ids and slugs.
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
 * Handler for exporting and importing endpoint configurations.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class EndpointHandler implements ConfigurationHandlerInterface {
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
	 * Export an endpoint entity to its serialised configuration form.
	 *
	 * @param Entity $entity The endpoint entity to export.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 * @param array<int, int|string> $mappingIds Collected mapping ids (out param).
	 *
	 * @return array The serialised endpoint configuration.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function export(Entity $entity, array $mappings, array &$mappingIds = []): array {
		if ($entity instanceof ObjectEntity) {
			$endpointArray = $entity->getObject();
		} else {
			$endpointArray = $entity->jsonSerialize();
		}

		unset($endpointArray['id'], $endpointArray['uuid']);

		// Redact secret-shaped values from the nested configuration array
		// (secret-hygiene) — e.g. an inline per-endpoint auth-override header.
		if (isset($endpointArray['configuration']) === true && is_array($endpointArray['configuration']) === true) {
			$endpointArray['configuration'] = $this->sensitiveFieldRegistry->redactArray(data: $endpointArray['configuration']);
		}

		// Ensure slug is set.
		if (empty($endpointArray['slug']) === true && $entity instanceof ObjectEntity) {
			$endpointArray['slug'] = $entity->getUuid();
		}

		// Handle targetId based on targetType.
		if (isset($endpointArray['targetId']) === true && isset($endpointArray['targetType']) === true) {
			switch ($endpointArray['targetType']) {
				case 'api':
				case 'database':
					// For api/database targets, use source mapping.
					if (isset($mappings['source']['idToSlug'][$endpointArray['targetId']]) === true) {
						$endpointArray['targetId'] = $mappings['source']['idToSlug'][$endpointArray['targetId']];
					}
					break;

				case 'register/schema':
					// For register/schema targets, split the ID and map both parts.
					if (str_contains($endpointArray['targetId'], '/') === true) {
						[$registerId, $schemaId] = explode('/', $endpointArray['targetId']);

						// Map register ID to slug (fallback to original ID if no mapping found).
						$registerSlug = $mappings['register']['idToSlug'][$registerId] ?? $registerId;

						// Map schema ID to slug (fallback to original ID if no mapping found).
						$schemaSlug = $mappings['schema']['idToSlug'][$schemaId] ?? $schemaId;

						// Combine the slugs.
						$endpointArray['targetId'] = $registerSlug . '/' . $schemaSlug;
					}
					break;
			}//end switch
		}//end if

		// Handle mapping IDs.
		if (isset($endpointArray['inputMapping']) === true
			&& isset($mappings['mapping']['idToSlug'][$endpointArray['inputMapping']]) === true
		) {
			$endpointArray['inputMapping'] = $mappings['mapping']['idToSlug'][$endpointArray['inputMapping']];
		}

		if (isset($endpointArray['outputMapping']) === true
			&& isset($mappings['mapping']['idToSlug'][$endpointArray['outputMapping']]) === true
		) {
			$endpointArray['outputMapping'] = $mappings['mapping']['idToSlug'][$endpointArray['outputMapping']];
		}

		if (isset($endpointArray['rules']) === true) {
			$endpointArray['rules'] = array_filter(
				array_map(
					function (int|string $rule) use ($mappings) {
						if (is_numeric($rule) === true) {
							$rule = (int)$rule;
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
	 * Import an endpoint configuration into an endpoint entity.
	 *
	 * @param array $data The serialised endpoint configuration.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return Entity The imported endpoint entity.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	public function import(array $data, array $mappings): Entity {
		// Convert slugs back to IDs.
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
						$data['targetId'] = $registerId . '/' . $schemaId;
					}
					break;
			}//end switch
		}//end if

		// Handle mapping IDs.
		if (isset($data['inputMapping']) === true
			&& isset($mappings['mapping']['slugToId'][$data['inputMapping']]) === true
		) {
			$data['inputMapping'] = $mappings['mapping']['slugToId'][$data['inputMapping']];
		}

		if (isset($data['outputMapping']) === true
			&& isset($mappings['mapping']['slugToId'][$data['outputMapping']]) === true
		) {
			$data['outputMapping'] = $mappings['mapping']['slugToId'][$data['outputMapping']];
		}

		// Ensure rules is always an array before processing.
		if (isset($data['rules']) === false || is_array($data['rules']) === false) {
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
		if ($slug !== null && isset($mappings['endpoint']['slugToId'][$slug]) === true) {
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
	 * Get the entity type this handler is responsible for.
	 *
	 * @return string The entity type identifier.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-003--import-an-oas-document-in-dependency-order
	 */
	public function getEntityType(): string {
		return 'endpoint';
	}//end getEntityType()
}//end class
