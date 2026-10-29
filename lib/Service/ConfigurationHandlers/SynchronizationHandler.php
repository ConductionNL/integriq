<?php

/**
 * Synchronization configuration handler.
 *
 * Handler for exporting and importing synchronization configurations,
 * translating source/target ids, mappings, actions, followUps and conditions
 * between numeric ids and stable slugs for portability.
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

use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\Entity;
use Symfony\Component\Uid\Uuid;

/**
 * Handler for exporting and importing synchronization configurations.
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.ShortVariable)
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.StaticAccess)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class SynchronizationHandler implements ConfigurationHandlerInterface {

	/**
	 * The id lists of a synchronization and the mapping type their ids translate by.
	 *
	 * @var array<string, string>
	 */
	private const ID_LIST_TYPES = ['actions' => 'rule', 'followUps' => 'synchronization', 'conditions' => 'rule'];

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
	 * Export a synchronization entity to its serialised configuration form.
	 *
	 * @param Entity $entity The synchronization entity to export.
	 * @param array<string,mixed> $mappings The global mappings for ID/slug conversion.
	 *                                      Shape: `[$type => ['idToSlug' => array, 'slugToId' => array]]` where `$type`
	 *                                      is one of `register|schema|source|mapping|action|followUp|condition`. The
	 *                                      inner idToSlug/slugToId arrays are looked up by both int and string keys
	 *                                      at runtime, so the value type is intentionally relaxed to `mixed` for
	 *                                      static-analysis purposes.
	 * @param array<int, int|string> $mappingIds Collected mapping ids (out param).
	 *
	 * @return array The serialised synchronization configuration.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-005--redact-source-credentials-from-exported-configurations
	 */
	public function export(Entity $entity, array $mappings, array &$mappingIds = []): array {
		if ($entity instanceof ObjectEntity) {
			$syncArray = $entity->getObject();
		} else {
			$syncArray = $entity->jsonSerialize();
		}

		unset($syncArray['id'], $syncArray['uuid']);

		// Ensure slug is set.
		if (empty($syncArray['slug']) === true && $entity instanceof ObjectEntity) {
			$syncArray['slug'] = $entity->getUuid();
		}

		// Redact secret-shaped values from the nested configuration array (secret-hygiene).
		if (isset($syncArray['configuration']) === true && is_array($syncArray['configuration']) === true) {
			$syncArray['configuration'] = $this->sensitiveFieldRegistry->redactArray(data: $syncArray['configuration']);
		}

		$lookup    = $this->directionOf(mappings: $mappings, direction: 'idToSlug');
		$syncArray = $this->translateEndpoints(data: $syncArray, lookup: $lookup);

		$syncArray = $this->exportMappingIds(syncArray: $syncArray, idToSlug: $lookup['mapping']);

		foreach (self::ID_LIST_TYPES as $arrayKey => $type) {
			if (isset($syncArray[$arrayKey]) === true && is_array($syncArray[$arrayKey]) === true) {
				$syncArray[$arrayKey] = $this->exportIdList(ids: $syncArray[$arrayKey], idToSlug: $lookup[$type]);
			}
		}

		return $syncArray;
	}//end export()

	/**
	 * Import a synchronization configuration into a synchronization entity.
	 *
	 * @param array $data The serialised synchronization configuration.
	 * @param array<string,mixed> $mappings The global mappings for ID/slug conversion.
	 *                                      See {@see self::export()} for the runtime shape.
	 *
	 * @return Entity The imported synchronization entity.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	public function import(array $data, array $mappings): Entity {
		$lookup = $this->directionOf(mappings: $mappings, direction: 'slugToId');
		$data   = $this->translateEndpoints(data: $data, lookup: $lookup);

		$data = $this->importMappingSlugs(data: $data, slugToId: $lookup['mapping']);

		foreach (self::ID_LIST_TYPES as $arrayKey => $type) {
			if (isset($data[$arrayKey]) === true && is_array($data[$arrayKey]) === true) {
				$data[$arrayKey] = $this->importSlugList(slugs: $data[$arrayKey], slugToId: $lookup[$type]);
			}
		}

		// Check if synchronization with this slug already exists.
		$slug = $data['slug'] ?? null;
		if ($slug !== null && isset($lookup['synchronization'][$slug]) === true) {
			// Update existing synchronization.
			return $this->orObjectService->saveObject(
				object: $data,
				register: 'integriq',
				schema: 'synchronization',
				uuid: $lookup['synchronization'][$slug]
			);
		}

		// Create new synchronization.
		return $this->orObjectService->saveObject(object: $data, register: 'integriq', schema: 'synchronization');
	}//end import()

	/**
	 * One direction of the global mappings, per type: `idToSlug` for export, `slugToId` for import.
	 *
	 * @param array<string,mixed> $mappings  The global mappings for ID/slug conversion.
	 * @param string              $direction 'idToSlug' or 'slugToId'.
	 *
	 * @return array<string,mixed> The lookup per type; a type without mappings looks up nothing.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	private function directionOf(array $mappings, string $direction): array {
		$lookup = [];
		foreach (['source', 'register', 'schema', 'mapping', 'rule', 'synchronization'] as $type) {
			$lookup[$type] = ($mappings[$type][$direction] ?? []);
		}

		return $lookup;
	}//end directionOf()

	/**
	 * Translate the source and target references by their type.
	 *
	 * An api or database endpoint is a source; a register/schema endpoint is a
	 * `register/schema` pair whose parts translate on their own, each falling
	 * back to itself.
	 *
	 * @param array               $data   The synchronization configuration.
	 * @param array<string,mixed> $lookup One direction of the mappings, per type.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	private function translateEndpoints(array $data, array $lookup): array {
		foreach (['sourceId' => 'sourceType', 'targetId' => 'targetType'] as $idField => $typeField) {
			if (isset($data[$idField]) === false || isset($data[$typeField]) === false) {
				continue;
			}

			$reference = $data[$idField];
			switch ($data[$typeField]) {
				case 'api':
				case 'database':
					if (isset($lookup['source'][$reference]) === true) {
						$data[$idField] = $lookup['source'][$reference];
					}
					break;

				case 'register/schema':
					if (str_contains($reference, '/') === true) {
						[$register, $schema] = explode('/', $reference);
						$data[$idField]      = ($lookup['register'][$register] ?? $register) . '/' . ($lookup['schema'][$schema] ?? $schema);
					}
					break;
			}//end switch
		}//end foreach

		return $data;
	}//end translateEndpoints()

	/**
	 * The source-target and target-source mapping ids exported as their slugs, read as integers.
	 *
	 * @param array $syncArray The synchronization configuration.
	 * @param mixed $idToSlug  The mapping id-to-slug lookup.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	private function exportMappingIds(array $syncArray, mixed $idToSlug): array {
		foreach (['sourceTargetMapping', 'targetSourceMapping'] as $field) {
			if (isset($syncArray[$field]) === true && isset($idToSlug[(int)$syncArray[$field]]) === true) {
				$syncArray[$field] = $idToSlug[(int)$syncArray[$field]];
			}
		}

		return $syncArray;
	}//end exportMappingIds()

	/**
	 * The source-target and target-source mapping slugs imported as their ids.
	 *
	 * @param array $data     The synchronization configuration.
	 * @param mixed $slugToId The mapping slug-to-id lookup.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	private function importMappingSlugs(array $data, mixed $slugToId): array {
		foreach (['sourceTargetMapping', 'targetSourceMapping'] as $field) {
			if (isset($data[$field]) === true && isset($slugToId[$data[$field]]) === true) {
				$data[$field] = $slugToId[$data[$field]];
			}
		}

		return $data;
	}//end importMappingSlugs()

	/**
	 * Exported ids of a list: a numeric or UUID id becomes its slug when it has one.
	 *
	 * @param array $ids      The ids.
	 * @param mixed $idToSlug The id-to-slug lookup of the list's type.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	private function exportIdList(array $ids, mixed $idToSlug): array {
		return array_map(
			function ($id) use ($idToSlug) {
				// Check for valid id, must be numeric or uuid.
				if (is_scalar($id) === false
					|| (is_numeric($id) === false && Uuid::isValid($id) === false)
				) {
					return $id;
				}

				if (isset($idToSlug[$id]) === true) {
					return $idToSlug[$id];
				}

				return $id;
			},
			$ids
		);
	}//end exportIdList()

	/**
	 * Imported ids of a list: a scalar slug becomes its id when it has one.
	 *
	 * @param array $slugs    The slugs.
	 * @param mixed $slugToId The slug-to-id lookup of the list's type.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	private function importSlugList(array $slugs, mixed $slugToId): array {
		return array_map(
			function ($slug) use ($slugToId) {
				// Skip non-scalar entries (nested arrays, objects) — can't be slugs.
				if (is_scalar($slug) === true && isset($slugToId[$slug]) === true) {
					return $slugToId[$slug];
				}

				return $slug;
			},
			$slugs
		);
	}//end importSlugList()

	/**
	 * Get the entity type this handler is responsible for.
	 *
	 * @return string The entity type identifier.
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-003--import-an-oas-document-in-dependency-order
	 */
	public function getEntityType(): string {
		return 'synchronization';
	}//end getEntityType()
}//end class
