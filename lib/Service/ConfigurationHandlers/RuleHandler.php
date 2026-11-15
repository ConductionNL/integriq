<?php

/**
 * Rule configuration handler.
 *
 * Handler for exporting and importing rule configurations, including nested
 * configuration structures that reference other entities by id or slug.
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
 * Handler for exporting and importing rule configurations.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class RuleHandler implements ConfigurationHandlerInterface {
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
	 * Export a rule entity to its serialised configuration form.
	 *
	 * @param Entity $entity The rule entity to export.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 * @param array<int, mixed> $mappingIds Collected mapping ids (out param). The
	 *                                      values come straight out of the caller-supplied $config, so they are
	 *                                      only int|string by convention — nothing here narrows them.
	 *
	 * @return array The serialised rule configuration.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function export(Entity $entity, array $mappings, array &$mappingIds = []): array {
		if ($entity instanceof ObjectEntity) {
			$ruleArray = $entity->getObject();
		} else {
			$ruleArray = $entity->jsonSerialize();
		}

		unset($ruleArray['id'], $ruleArray['uuid']);

		// Ensure slug is set.
		if (empty($ruleArray['slug']) === true && $entity instanceof ObjectEntity) {
			$ruleArray['slug'] = $entity->getUuid();
		}

		// Handle nested configuration structures.
		if (isset($ruleArray['configuration']) === true && is_array($ruleArray['configuration']) === true) {
			$ruleArray['configuration'] = $this->convertIdsToSlugs(
				config: $ruleArray['configuration'],
				mappings: $mappings,
				mappingIds: $mappingIds
			);
		}

		// Redact every field of the (now slug-translated) rule, not one block:
		// `action` and `conditions` can hold secrets too. id/slug reference keys
		// (e.g. `sourceId`) never match the sensitive-name pattern.
		$ruleArray = $this->sensitiveFieldRegistry->redactConfiguration(data: $ruleArray);

		return $ruleArray;
	}//end export()

	/**
	 * Recursively convert IDs to slugs in configuration arrays.
	 *
	 * @param array $config The configuration array to process.
	 * @param array $mappings The mappings array containing idToSlug mappings.
	 * @param array<int, mixed> $mappingIds Collected mapping ids (out param). The
	 *                                      values come straight out of the caller-supplied $config, so they are
	 *                                      only int|string by convention — nothing here narrows them.
	 *
	 * @return array The processed configuration with IDs converted to slugs.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	private function convertIdsToSlugs(array $config, array $mappings, array &$mappingIds = []): array {
		$translator = ConfigurationReferenceTranslator::idsToSlugs(mappings: $mappings);
		$config = $translator->translate(config: $config);
		foreach ($translator->mappingIds as $mappingId) {
			$mappingIds[] = $mappingId;
		}

		return $config;
	}//end convertIdsToSlugs()

	/**
	 * Import a rule configuration into a rule entity.
	 *
	 * @param array $data The serialised rule configuration.
	 * @param array<string,array{idToSlug:array<string,string>,slugToId:array<string,string>}> $mappings The global mappings for ID/slug conversion.
	 *
	 * @return Entity The imported rule entity.
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

		// Handle nested configuration structures.
		if (isset($data['configuration']) === true && is_array($data['configuration']) === true) {
			$data['configuration'] = $this->convertSlugsToIds(config: $data['configuration'], mappings: $mappings);
		}

		// Check if rule with this slug already exists.
		$slug = $data['slug'] ?? null;
		if ($slug !== null && isset($mappings['rule']['slugToId'][$slug]) === true) {
			// Update existing rule.
			return $this->orObjectService->saveObject(
				object: $data,
				register: 'integriq',
				schema: 'rule',
				uuid: $mappings['rule']['slugToId'][$slug]
			);
		}

		// Create new rule.
		return $this->orObjectService->saveObject(object: $data, register: 'integriq', schema: 'rule');
	}//end import()

	/**
	 * Recursively convert slugs to IDs in configuration arrays.
	 *
	 * @param array $config The configuration array to process.
	 * @param array $mappings The mappings array containing slugToId mappings.
	 *
	 * @return array The processed configuration with slugs converted to IDs.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	private function convertSlugsToIds(array $config, array $mappings): array {
		return ConfigurationReferenceTranslator::slugsToIds(mappings: $mappings)->translate(config: $config);
	}//end convertSlugsToIds()

	/**
	 * Get the entity type this handler is responsible for.
	 *
	 * @return string The entity type identifier.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-003--import-an-oas-document-in-dependency-order
	 */
	public function getEntityType(): string {
		return 'rule';
	}//end getEntityType()
}//end class
