<?php

/**
 * Job configuration handler.
 *
 * Handler for exporting and importing job configurations, translating between
 * internal numeric ids and stable slugs for cross-environment portability.
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
	 * The job argument that references another entity, and that entity's type.
	 *
	 * @var array<string,string>
	 */
	private const ARGUMENT_REFERENCES = [
		'synchronizationId' => 'synchronization',
		'endpointId' => 'endpoint',
		'sourceId' => 'source',
	];

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
		// Redact every field, not one block: the schema may hold secrets outside `configuration`.
		$jobArray = $this->sensitiveFieldRegistry->redactConfiguration(data: $jobArray);

		// Replace IDs with slugs in arguments.
		if (isset($jobArray['arguments']) === true && is_array($jobArray['arguments']) === true) {
			$jobArray['arguments'] = $this->convertArgumentIdsToSlugs(arguments: $jobArray['arguments'], mappings: $mappings);
		}

		return $jobArray;
	}//end export()

	/**
	 * Convert entity id references inside a job's arguments array to slugs.
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
		$data = $this->sensitiveFieldRegistry->withoutRedactedValues(data: $data);

		if (isset($data['arguments']) === true) {
			$arguments = $this->decodeArguments(arguments: $data['arguments']);
			if (is_array($arguments) === true) {
				$data['arguments'] = $this->convertArgumentSlugsToIds(arguments: $arguments, mappings: $mappings);
			}
		}

		return $this->saveJob(data: $data, mappings: $mappings);
	}//end import()

	/**
	 * A job's arguments as an array: JSON text is decoded, an array is kept.
	 *
	 * @param mixed $arguments The job arguments, as an array or JSON text.
	 *
	 * @return mixed The decoded arguments; not an array when the text is no JSON object or list.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-004--translate-cross-entity-references-between-ids-and-slugs
	 */
	private function decodeArguments(mixed $arguments): mixed {
		if (is_array($arguments) === true) {
			return $arguments;
		}

		return json_decode($arguments, true);
	}//end decodeArguments()

	/**
	 * Convert entity slug references inside a job's arguments array to ids.
	 *
	 * @param array $arguments The job arguments array.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return array The arguments array with slugs converted to ids.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-004--translate-cross-entity-references-between-ids-and-slugs
	 */
	private function convertArgumentSlugsToIds(array $arguments, array $mappings): array {
		foreach (self::ARGUMENT_REFERENCES as $field => $type) {
			if (isset($arguments[$field]) === true && isset($mappings[$type]['slugToId'][$arguments[$field]]) === true) {
				$arguments[$field] = $mappings[$type]['slugToId'][$arguments[$field]];
			}
		}

		return $arguments;
	}//end convertArgumentSlugsToIds()

	/**
	 * Save an imported job: over the existing job with its slug, or as a new one.
	 *
	 * @param array $data The job configuration, with ids resolved.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return Entity The saved job entity.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	private function saveJob(array $data, array $mappings): Entity {
		$slug = ($data['slug'] ?? null);
		if ($slug !== null && isset($mappings['job']['slugToId'][$slug]) === true) {
			return $this->orObjectService->saveObject(
				object: $data,
				register: 'integriq',
				schema: 'job',
				uuid: $mappings['job']['slugToId'][$slug]
			);
		}

		return $this->orObjectService->saveObject(object: $data, register: 'integriq', schema: 'job');
	}//end saveJob()

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
