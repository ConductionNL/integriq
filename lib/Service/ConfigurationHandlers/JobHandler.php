<?php

/**
 * Job configuration handler.
 *
 * Handler for exporting and importing job configurations, translating between
 * internal numeric ids and stable slugs for cross-environment portability.
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
 * Handler for exporting and importing job configurations.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class JobHandler implements ConfigurationHandlerInterface {
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
	 * Export a job entity to its serialised configuration form.
	 *
	 * @param Entity $entity The job entity to export.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 * @param array<int, int|string> $mappingIds Collected mapping ids (out param).
	 *
	 * @return array The serialised job configuration.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function export(Entity $entity, array $mappings, array &$mappingIds = []): array {
		if ($entity instanceof ObjectEntity) {
			$jobArray = $entity->getObject();
		} else {
			$jobArray = $entity->jsonSerialize();
		}

		unset($jobArray['id'], $jobArray['uuid']);

		// Ensure slug is set.
		if (empty($jobArray['slug']) === true && $entity instanceof ObjectEntity) {
			$jobArray['slug'] = $entity->getUuid();
		}

		// Redact secret-shaped values from the nested configuration array
		// (secret-hygiene). Distinct from `arguments` below, which is NOT
		// walked here — no evidence was found that `arguments` structurally
		// carries raw secret values, as opposed to entity id/slug references
		// already governed by the id<->slug maps (see proposal Open Questions).
		if (isset($jobArray['configuration']) === true && is_array($jobArray['configuration']) === true) {
			$jobArray['configuration'] = $this->sensitiveFieldRegistry->redactArray(data: $jobArray['configuration']);
		}

		// Replace IDs with slugs in arguments.
		if (isset($jobArray['arguments']) === true && is_array($jobArray['arguments']) === true) {
			$jobArray['arguments'] = $this->convertArgumentIdsToSlugs(arguments: $jobArray['arguments'], mappings: $mappings);
		}

		return $jobArray;
	}//end export()

	/**
	 * Convert entity id references inside a job's arguments array to slugs.
	 *
	 * Extracted from export() (pure refactor, behaviour-preserving) to keep
	 * export()'s NPath complexity within the configured threshold.
	 *
	 * @param array $arguments The job arguments array.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return array The arguments array with ids converted to slugs.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-004--translate-cross-entity-references-between-ids-and-slugs
	 */
	private function convertArgumentIdsToSlugs(array $arguments, array $mappings): array {
		// Convert synchronizationId from integer to string if it exists.
		if (isset($arguments['synchronizationId']) === true) {
			$synchronizationId = (string)$arguments['synchronizationId'];
			if (isset($mappings['synchronization']['idToSlug'][$synchronizationId]) === true) {
				$arguments['synchronizationId'] = $mappings['synchronization']['idToSlug'][$synchronizationId];
			}
		}

		if (isset($arguments['endpointId']) === true
			&& isset($mappings['endpoint']['idToSlug'][$arguments['endpointId']]) === true
		) {
			$arguments['endpointId'] = $mappings['endpoint']['idToSlug'][$arguments['endpointId']];
		}

		if (isset($arguments['sourceId']) === true
			&& isset($mappings['source']['idToSlug'][$arguments['sourceId']]) === true
		) {
			$arguments['sourceId'] = $mappings['source']['idToSlug'][$arguments['sourceId']];
		}

		return $arguments;
	}//end convertArgumentIdsToSlugs()

	/**
	 * Import a job configuration into a job entity.
	 *
	 * @param array $data The serialised job configuration.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return Entity The imported job entity.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	public function import(array $data, array $mappings): Entity {
		// Convert slugs back to IDs in arguments JSON.
		if (isset($data['arguments']) === true) {
			if (is_array($data['arguments']) === false) {
				$arguments = json_decode($data['arguments'], true);
			} else {
				$arguments = $data['arguments'];
			}

			if (is_array($arguments) === true) {
				if (isset($arguments['synchronizationId']) === true
					&& isset($mappings['synchronization']['slugToId'][$arguments['synchronizationId']]) === true
				) {
					$arguments['synchronizationId'] = $mappings['synchronization']['slugToId'][$arguments['synchronizationId']];
				}

				if (isset($arguments['endpointId']) === true
					&& isset($mappings['endpoint']['slugToId'][$arguments['endpointId']]) === true
				) {
					$arguments['endpointId'] = $mappings['endpoint']['slugToId'][$arguments['endpointId']];
				}

				if (isset($arguments['sourceId']) === true
					&& isset($mappings['source']['slugToId'][$arguments['sourceId']]) === true
				) {
					$arguments['sourceId'] = $mappings['source']['slugToId'][$arguments['sourceId']];
				}

				$data['arguments'] = $arguments;
			}//end if
		}//end if

		// Check if job with this slug already exists.
		$slug = $data['slug'] ?? null;
		if ($slug !== null && isset($mappings['job']['slugToId'][$slug]) === true) {
			// Update existing job.
			return $this->orObjectService->saveObject(
				object: $data,
				register: 'openconnector',
				schema: 'job',
				uuid: $mappings['job']['slugToId'][$slug]
			);
		}

		// Create new job.
		return $this->orObjectService->saveObject(object: $data, register: 'openconnector', schema: 'job');
	}//end import()

	/**
	 * Get the entity type this handler is responsible for.
	 *
	 * @return string The entity type identifier.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-003--import-an-oas-document-in-dependency-order
	 */
	public function getEntityType(): string {
		return 'job';
	}//end getEntityType()
}//end class
