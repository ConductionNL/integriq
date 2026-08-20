<?php

/**
 * Configuration Import Preview Service
 *
 * Non-mutating dry-run companion to the existing
 * {@see \OCA\OpenConnector\Service\ConfigurationService::importConfiguration()}:
 * given the same OAS document, computes the creates/updates/collisions
 * classification the import would perform, the set of unresolved slug
 * references (the REQ-004 "left verbatim" case) it would leave dangling,
 * and the credential fields each imported Source will need re-entered
 * (REQ-009) — WITHOUT calling saveObject() on anything.
 *
 * Slug resolution mirrors ConfigurationService::buildSchemaSlugMaps()
 * (per-schema OR findAll over the TARGET environment) and the handlers'
 * reference-field vocabulary (RuleHandler::convertSlugsToIds's
 * exact-type / `<type>Id`-suffix key convention, EndpointHandler's
 * targetId / inputMapping / outputMapping / rules[], MappingHandler's
 * source_id / target_id), so the preview classifies references exactly
 * the way the real import resolves them.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-007--preview-an-import-before-writing-anything
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * Computes the non-mutating import preview (REQ-007) and the
 * credential-re-entry flags (REQ-009).
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-007--preview-an-import-before-writing-anything
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class ConfigurationImportPreviewService {
	/**
	 * OAS `components` bucket name => openconnector schema slug, in the
	 * same order importConfiguration() processes them.
	 *
	 * @var array<string,string>
	 */
	private const COMPONENT_SCHEMAS = [
		'sources' => 'source',
		'mappings' => 'mapping',
		'rules' => 'rule',
		'endpoints' => 'endpoint',
		'synchronizations' => 'synchronization',
		'jobs' => 'job',
	];

	/**
	 * Entity types whose slugs the handlers translate inside nested
	 * configuration payloads (RuleHandler::convertSlugsToIds vocabulary).
	 *
	 * @var array<int,string>
	 */
	private const REFERENCE_TYPES = [
		'source',
		'job',
		'endpoint',
		'mapping',
		'synchronization',
	];

	/**
	 * Credential fields SourceHandler::export() strips (REQ-005), i.e. the
	 * fields an operator must re-enter after import (REQ-009).
	 *
	 * @var array<int,string>
	 */
	private const CREDENTIAL_FIELDS = [
		'apikey',
		'secret',
		'username',
		'password',
		'jwt',
		'authorizationHeader',
		'authenticationConfig',
	];

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $orObjectService The OR object service, used to
	 *                                         build the target environment's
	 *                                         slug maps (read-only).
	 */
	public function __construct(
		private readonly OrObjectService $orObjectService,
	) {
	}//end __construct()

	/**
	 * Compute the full import preview for an OAS document (REQ-007 + REQ-009).
	 *
	 * @param array<string,mixed> $oas The OAS document (must contain `components`).
	 *
	 * @return array<string, array<int,array<string,mixed>>> Keys: creates, updates, collisions,
	 *                                                       unresolvedReferences, credentialsNeedingReentry.
	 *
	 * @throws InvalidArgumentException When the document has no top-level `components` key (mirrors importConfiguration()).
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-classifies-creates-updates-and-collisions
	 */
	public function preview(array $oas): array {
		if (isset($oas['components']) === false) {
			throw new InvalidArgumentException('OAS must contain a components property');
		}

		$components = $oas['components'];
		$slugMaps = $this->buildTargetSlugMaps();

		$result = [
			'creates' => [],
			'updates' => [],
			'collisions' => [],
			'unresolvedReferences' => [],
			'credentialsNeedingReentry' => [],
		];

		foreach (self::COMPONENT_SCHEMAS as $bucket => $schema) {
			$entities = ($components[$bucket] ?? []);
			if (is_array($entities) === false) {
				continue;
			}

			foreach ($entities as $slugKey => $payload) {
				if (is_array($payload) === false) {
					continue;
				}

				$slug = (string)($payload['slug'] ?? $slugKey);

				$classified = $this->classify(schema: $schema, slug: $slug, slugMaps: $slugMaps);
				if ($classified['bucket'] === 'updates') {
					$result['updates'][] = [
						'type' => $schema,
						'slug' => $slug,
						'id' => $classified['id'],
					];
				}

				if ($classified['bucket'] === 'collisions') {
					$result['collisions'][] = [
						'type' => $schema,
						'slug' => $slug,
						'reason' => 'slug matches an existing ' . $classified['collidesWith'] . ' object',
					];
				}

				if ($classified['bucket'] === 'creates') {
					$result['creates'][] = [
						'type' => $schema,
						'slug' => $slug,
					];
				}

				$unresolvedRefs = $this->findUnresolvedReferences(
					schema: $schema,
					slug: $slug,
					payload: $payload,
					slugMaps: $slugMaps
				);
				foreach ($unresolvedRefs as $unresolved) {
					$result['unresolvedReferences'][] = $unresolved;
				}

				if ($schema === 'source') {
					$missing = $this->missingCredentialFields(payload: $payload);
					if (count($missing) > 0) {
						$result['credentialsNeedingReentry'][] = [
							'type' => 'source',
							'slug' => $slug,
							'fields' => $missing,
						];
					}
				}
			}//end foreach
		}//end foreach

		return $result;
	}//end preview()

	/**
	 * Classify one entity against the target environment's slug maps:
	 * same-schema slug hit => update; other-schema hit => collision;
	 * no hit => create.
	 *
	 * @param string $schema The entity's schema slug.
	 * @param string $slug The entity's slug.
	 * @param array<string,array<string,string>> $slugMaps schema => slug => uuid.
	 *
	 * @return array{bucket: string, id: string, collidesWith: string}
	 */
	private function classify(string $schema, string $slug, array $slugMaps): array {
		if (isset($slugMaps[$schema][$slug]) === true) {
			return [
				'bucket' => 'updates',
				'id' => $slugMaps[$schema][$slug],
				'collidesWith' => '',
			];
		}

		foreach ($slugMaps as $otherSchema => $map) {
			if ($otherSchema === $schema) {
				continue;
			}

			if (isset($map[$slug]) === true) {
				return [
					'bucket' => 'collisions',
					'id' => $map[$slug],
					'collidesWith' => $otherSchema,
				];
			}
		}

		return [
			'bucket' => 'creates',
			'id' => '',
			'collidesWith' => '',
		];

	}//end classify()

	/**
	 * Find every slug reference in an entity payload that the real import
	 * would leave verbatim (REQ-004) because it resolves against neither
	 * the target environment nor the import document itself... it resolves
	 * against the target environment's slug maps ONLY — matching
	 * importConfiguration()'s actual behaviour, which builds its maps once
	 * before any entity is written.
	 *
	 * Reference vocabulary (mirrors the handlers):
	 *   - top-level `source_id` / `target_id` (mapping + rule handlers)
	 *   - top-level `targetId` when `targetType` is source-like, plus
	 *     `inputMapping` / `outputMapping` / `rules[]` (endpoint handler)
	 *   - inside nested `configuration` arrays: keys equal to an entity
	 *     type or ending in `<type>Id` (rule handler convention)
	 *
	 * @param string $schema The entity's schema slug.
	 * @param string $slug The entity's own slug (for reporting).
	 * @param array<string,mixed> $payload The entity payload.
	 * @param array<string,array<string,string>> $slugMaps schema => slug => uuid.
	 *
	 * @return array<int,array<string,string>>
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-surfaces-an-unresolvable-slug-reference-as-a-blocking-warning
	 */
	private function findUnresolvedReferences(string $schema, string $slug, array $payload, array $slugMaps): array {
		$unresolved = [];

		$report = function (string $field, string $value) use (&$unresolved, $schema, $slug): void {
			$unresolved[] = [
				'type' => $schema,
				'slug' => $slug,
				'field' => $field,
				'value' => $value,
			];
		};

		// Top-level source_id / target_id (mapping + rule handlers translate
		// these against the source slug map).
		foreach (['source_id', 'target_id'] as $field) {
			$value = ($payload[$field] ?? null);
			if (is_string($value) === true && $value !== '' && isset($slugMaps['source'][$value]) === false) {
				$report($field, $value);
			}
		}

		// Endpoint-specific top-level references.
		if ($schema === 'endpoint') {
			$targetType = (string)($payload['targetType'] ?? '');
			$targetId = ($payload['targetId'] ?? null);
			if (is_string($targetId) === true && $targetId !== ''
				&& $targetType !== 'register/schema'
				&& str_contains($targetId, '/') === false
				&& isset($slugMaps['source'][$targetId]) === false
			) {
				$report('targetId', $targetId);
			}

			foreach (['inputMapping', 'outputMapping'] as $field) {
				$value = ($payload[$field] ?? null);
				if (is_string($value) === true && $value !== '' && isset($slugMaps['mapping'][$value]) === false) {
					$report($field, $value);
				}
			}

			$rules = ($payload['rules'] ?? []);
			if (is_array($rules) === true) {
				foreach ($rules as $ruleRef) {
					if (is_string($ruleRef) === true && $ruleRef !== '' && isset($slugMaps['rule'][$ruleRef]) === false) {
						$report('rules[]', $ruleRef);
					}
				}
			}
		}//end if

		// Nested configuration references (rule handler convention: keys
		// equal to an entity type or ending with `<type>Id`).
		$configuration = ($payload['configuration'] ?? null);
		if (is_array($configuration) === true) {
			foreach ($this->scanConfigurationReferences(config: $configuration, slugMaps: $slugMaps) as $ref) {
				$report('configuration.' . $ref['field'], $ref['value']);
			}
		}

		return $unresolved;
	}//end findUnresolvedReferences()

	/**
	 * Recursively scan a nested configuration array for entity-reference
	 * keys whose slug value does not resolve against the target maps.
	 *
	 * @param array<string,mixed> $config The configuration array.
	 * @param array<string,array<string,string>> $slugMaps schema => slug => uuid.
	 * @param string $path Dotted key path accumulator.
	 *
	 * @return array<int,array{field:string,value:string}>
	 */
	private function scanConfigurationReferences(array $config, array $slugMaps, string $path = ''): array {
		$found = [];

		foreach ($config as $key => $value) {
			$keyPath = $path . '.' . $key;
			if ($path === '') {
				$keyPath = (string)$key;
			}

			if (is_array($value) === true) {
				$nested = $this->scanConfigurationReferences(config: $value, slugMaps: $slugMaps, path: $keyPath);
				foreach ($nested as $ref) {
					$found[] = $ref;
				}

				continue;
			}

			if (is_string($value) === false || $value === '') {
				continue;
			}

			foreach (self::REFERENCE_TYPES as $type) {
				$isReferenceKey = ($key === $type
					|| str_ends_with((string)$key, ucfirst($type) . 'Id') === true
					|| str_ends_with((string)$key, $type . 'Id') === true);
				if ($isReferenceKey === false) {
					continue;
				}

				if (isset($slugMaps[$type][$value]) === false) {
					$found[] = [
						'field' => $keyPath,
						'value' => $value,
					];
				}

				break;
			}
		}//end foreach

		return $found;
	}//end scanConfigurationReferences()

	/**
	 * Which REQ-005-redacted credential fields are absent/empty on an
	 * imported Source payload — always all of them for a genuine export,
	 * since export strips every one (REQ-009).
	 *
	 * @param array<string,mixed> $payload The source payload.
	 *
	 * @return array<int,string>
	 */
	private function missingCredentialFields(array $payload): array {
		$missing = [];
		foreach (self::CREDENTIAL_FIELDS as $field) {
			$value = ($payload[$field] ?? null);
			if ($value === null || $value === '' || $value === []) {
				$missing[] = $field;
			}
		}

		return $missing;
	}//end missingCredentialFields()

	/**
	 * Build the target environment's slug => uuid map per schema, the same
	 * way ConfigurationService::buildSchemaSlugMaps() does (read-only OR
	 * findAll per schema).
	 *
	 * @return array<string,array<string,string>>
	 */
	private function buildTargetSlugMaps(): array {
		$maps = [];
		foreach (array_values(self::COMPONENT_SCHEMAS) as $schema) {
			$maps[$schema] = [];

			$result = $this->orObjectService->findAll(
				config: ['filters' => ['register' => 'openconnector', 'schema' => $schema]]
			);
			$items = ($result['results'] ?? $result);

			foreach ($items as $item) {
				if ($item instanceof ObjectEntity === false) {
					continue;
				}

				$data = $item->getObject();
				$slug = (string)($data['slug'] ?? $item->getUuid());
				if ($slug !== '') {
					$maps[$schema][$slug] = $item->getUuid();
				}
			}
		}

		return $maps;
	}//end buildTargetSlugMaps()
}//end class
