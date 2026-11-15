<?php

/**
 * Endpoint configuration handler.
 *
 * Handler for exporting and importing endpoint configurations, translating
 * targetId, mapping references and attached rules between ids and slugs.
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
		$endpointArray = $this->exportedArray(entity: $entity);

		// Redact secret-shaped values from every field of the endpoint, not one
		// block: the endpoint schema has no `configuration` property, so its
		// own fields and `conditions` are where an inline secret would sit.
		$endpointArray = $this->sensitiveFieldRegistry->redactConfiguration(data: $endpointArray);

		if (isset($endpointArray['targetId']) === true && isset($endpointArray['targetType']) === true) {
			$endpointArray['targetId'] = $this->exportTargetId(data: $endpointArray, mappings: $mappings);
		}

		foreach (['inputMapping', 'outputMapping'] as $field) {
			if (isset($endpointArray[$field]) === true && isset($mappings['mapping']['idToSlug'][$endpointArray[$field]]) === true) {
				$endpointArray[$field] = $mappings['mapping']['idToSlug'][$endpointArray[$field]];
			}
		}

		if (isset($endpointArray['rules']) === true) {
			$endpointArray['rules'] = $this->exportRules(rules: $endpointArray['rules'], mappings: $mappings);
		}

		return $endpointArray;
	}//end export()

	/**
	 * An endpoint's stored fields without its id and uuid, with its uuid as
	 * slug when an OpenRegister object carries no slug.
	 *
	 * @param Entity $entity The endpoint entity.
	 *
	 * @return array The endpoint fields.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	private function exportedArray(Entity $entity): array {
		if ($entity instanceof ObjectEntity) {
			$endpointArray = $entity->getObject();
		} else {
			$endpointArray = $entity->jsonSerialize();
		}

		unset($endpointArray['id'], $endpointArray['uuid']);

		if (empty($endpointArray['slug']) === true && $entity instanceof ObjectEntity) {
			$endpointArray['slug'] = $entity->getUuid();
		}

		return $endpointArray;
	}//end exportedArray()

	/**
	 * The slug an exported endpoint's target id resolves to, by target type.
	 *
	 * @param array $data The endpoint configuration, with a targetId and targetType.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return mixed The target slug, or the target id when no mapping is found.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-004--translate-cross-entity-references-between-ids-and-slugs
	 */
	private function exportTargetId(array $data, array $mappings): mixed {
		$targetId = $data['targetId'];
		switch ($data['targetType']) {
			case 'api':
			case 'database':
				return ($mappings['source']['idToSlug'][$targetId] ?? $targetId);

			case 'register/schema':
				return $this->exportRegisterSchemaTargetId(targetId: $targetId, mappings: $mappings);
		}

		return $targetId;
	}//end exportTargetId()

	/**
	 * The `{register}/{schema}` slugs a `{register}/{schema}` id pair resolves to.
	 *
	 * Each part falls back to its id when no mapping is found; a target
	 * without a slash is returned unchanged.
	 *
	 * @param mixed $targetId The register/schema id pair.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return mixed The register/schema slug pair.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-004--translate-cross-entity-references-between-ids-and-slugs
	 */
	private function exportRegisterSchemaTargetId(mixed $targetId, array $mappings): mixed {
		if (str_contains($targetId, '/') === false) {
			return $targetId;
		}

		[$registerId, $schemaId] = explode('/', $targetId);

		$registerSlug = ($mappings['register']['idToSlug'][$registerId] ?? $registerId);
		$schemaSlug = ($mappings['schema']['idToSlug'][$schemaId] ?? $schemaId);

		return $registerSlug . '/' . $schemaSlug;
	}//end exportRegisterSchemaTargetId()

	/**
	 * The rule slugs an exported endpoint's rule ids resolve to; unknown ids are dropped.
	 *
	 * @param array $rules The rule ids; numeric strings are read as integers.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return array The rule slugs, keyed as the ids were.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-004--translate-cross-entity-references-between-ids-and-slugs
	 */
	private function exportRules(array $rules, array $mappings): array {
		return array_filter(
			array_map(
				function (int|string $rule) use ($mappings): mixed {
					if (is_numeric($rule) === true) {
						$rule = (int)$rule;
					}

					return ($mappings['rule']['idToSlug'][$rule] ?? null);
				},
				$rules
			)
		);
	}//end exportRules()

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
		$data = $this->sensitiveFieldRegistry->withoutRedactedValues(data: $data);

		if (isset($data['targetId']) === true && isset($data['targetType']) === true) {
			$data['targetId'] = $this->importTargetId(data: $data, mappings: $mappings);
		}

		foreach (['inputMapping', 'outputMapping'] as $field) {
			if (isset($data[$field]) === true && isset($mappings['mapping']['slugToId'][$data[$field]]) === true) {
				$data[$field] = $mappings['mapping']['slugToId'][$data[$field]];
			}
		}

		$data['rules'] = $this->importRules(rules: ($data['rules'] ?? null), mappings: $mappings);

		return $this->saveEndpoint(data: $data, mappings: $mappings);
	}//end import()

	/**
	 * The id an imported endpoint's target slug resolves to, by target type.
	 *
	 * @param array $data The serialised endpoint configuration, with a targetId and targetType.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return mixed The target id, or the target slug when no mapping is found.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-004--translate-cross-entity-references-between-ids-and-slugs
	 */
	private function importTargetId(array $data, array $mappings): mixed {
		$targetId = $data['targetId'];
		switch ($data['targetType']) {
			case 'api':
			case 'database':
				return ($mappings['source']['slugToId'][$targetId] ?? $targetId);

			case 'register/schema':
				return $this->importRegisterSchemaTargetId(targetId: $targetId, mappings: $mappings);
		}

		return $targetId;
	}//end importTargetId()

	/**
	 * The `{register}/{schema}` ids a `{register}/{schema}` slug pair resolves to.
	 *
	 * Each part falls back to its slug when no mapping is found; a target
	 * without a slash is returned unchanged.
	 *
	 * @param mixed $targetId The register/schema slug pair.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return mixed The register/schema id pair.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-004--translate-cross-entity-references-between-ids-and-slugs
	 */
	private function importRegisterSchemaTargetId(mixed $targetId, array $mappings): mixed {
		if (str_contains($targetId, '/') === false) {
			return $targetId;
		}

		[$registerSlug, $schemaSlug] = explode('/', $targetId);

		$registerId = ($mappings['register']['slugToId'][$registerSlug] ?? $registerSlug);
		$schemaId = ($mappings['schema']['slugToId'][$schemaSlug] ?? $schemaSlug);

		return $registerId . '/' . $schemaId;
	}//end importRegisterSchemaTargetId()

	/**
	 * The rule ids an imported endpoint's rule slugs resolve to; unknown slugs are dropped.
	 *
	 * @param mixed $rules The rule slugs; anything but an array imports as no rules.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return array The rule ids, keyed as the slugs were.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-004--translate-cross-entity-references-between-ids-and-slugs
	 */
	private function importRules(mixed $rules, array $mappings): array {
		if (is_array($rules) === false) {
			return [];
		}

		return array_filter(
			array_map(
				fn (int|string $rule): mixed => ($mappings['rule']['slugToId'][$rule] ?? null),
				$rules
			)
		);
	}//end importRules()

	/**
	 * Save an imported endpoint: over the existing endpoint with its slug, or as a new one.
	 *
	 * @param array $data The endpoint configuration, with ids resolved.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return Entity The saved endpoint entity.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	private function saveEndpoint(array $data, array $mappings): Entity {
		$slug = ($data['slug'] ?? null);
		if ($slug !== null && isset($mappings['endpoint']['slugToId'][$slug]) === true) {
			return $this->orObjectService->saveObject(
				object: $data,
				register: 'integriq',
				schema: 'endpoint',
				uuid: $mappings['endpoint']['slugToId'][$slug]
			);
		}

		return $this->orObjectService->saveObject(object: $data, register: 'integriq', schema: 'endpoint');
	}//end saveEndpoint()

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
