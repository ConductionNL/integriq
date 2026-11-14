<?php

/**
 * Configuration Import Preview Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Non-mutating dry-run companion to the existing
 * {@see \OCA\Integriq\Service\ConfigurationService::importConfiguration()}:
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
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/configuration-export-import/spec.md#requirement-req-007--preview-an-import-before-writing-anything
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

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
class ConfigurationImportPreviewService implements ConfigurationImportPreviewServiceInterface {
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
		$run = new ConfigurationImportPreviewRun(slugMaps: $this->buildTargetSlugMaps());

		foreach (self::COMPONENT_SCHEMAS as $bucket => $schema) {
			foreach ($this->bucketEntities(entities: ($components[$bucket] ?? []), schema: $schema) as $entity) {
				$this->previewEntity(run: $run, entity: $entity);
			}
		}

		return $run->result;
	}//end preview()

	/**
	 * The entities of one component bucket, as previewEntity() takes them;
	 * a bucket or payload that is no array holds none.
	 *
	 * @param mixed  $entities The bucket's entities, slug key => payload.
	 * @param string $schema   The schema the bucket's entities belong to.
	 *
	 * @return array<int,array{type: string, slug: string, payload: array<string, mixed>}>
	 */
	private function bucketEntities(mixed $entities, string $schema): array {
		if (is_array($entities) === false) {
			return [];
		}

		$list = [];
		foreach ($entities as $slugKey => $payload) {
			if (is_array($payload) === true) {
				$list[] = ['type' => $schema, 'slug' => (string)($payload['slug'] ?? $slugKey), 'payload' => $payload];
			}
		}

		return $list;
	}//end bucketEntities()

	/**
	 * Add one entity's classification, unresolved references and missing credentials to the preview.
	 *
	 * @param ConfigurationImportPreviewRun $run The preview; its result is filled.
	 * @param array{type: string, slug: string, payload: array<string, mixed>} $entity The entity's schema, slug and payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-classifies-creates-updates-and-collisions
	 */
	private function previewEntity(ConfigurationImportPreviewRun $run, array $entity): void {
		$this->recordClassification(run: $run, entity: $entity);

		foreach ($this->findUnresolvedReferences(entity: $entity, slugMaps: $run->slugMaps) as $unresolved) {
			$run->result['unresolvedReferences'][] = $unresolved;
		}

		if ($entity['type'] === 'source') {
			$missing = $this->missingCredentialFields(payload: $entity['payload']);
			if (count($missing) > 0) {
				$run->result['credentialsNeedingReentry'][] = [
					'type' => 'source',
					'slug' => $entity['slug'],
					'fields' => $missing,
				];
			}
		}

	}//end previewEntity()

	/**
	 * Add one entity to the creates, updates or collisions of the preview.
	 *
	 * @param ConfigurationImportPreviewRun $run The preview; its result is filled.
	 * @param array{type: string, slug: string, payload: array<string, mixed>} $entity The entity's schema, slug and payload.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-classifies-creates-updates-and-collisions
	 */
	private function recordClassification(ConfigurationImportPreviewRun $run, array $entity): void {
		$classified = $this->classify(schema: $entity['type'], slug: $entity['slug'], slugMaps: $run->slugMaps);
		$row = ['type' => $entity['type'], 'slug' => $entity['slug']];
		if ($classified['bucket'] === 'updates') {
			$row['id'] = $classified['id'];
		}

		if ($classified['bucket'] === 'collisions') {
			$row['reason'] = 'slug matches an existing ' . $classified['collidesWith'] . ' object';
		}

		$run->result[$classified['bucket']][] = $row;

	}//end recordClassification()

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
	 * @param array{type: string, slug: string, payload: array<string, mixed>} $entity The entity's schema, slug (for reporting) and payload.
	 * @param array<string,array<string,string>> $slugMaps schema => slug => uuid.
	 *
	 * @return array<int,array<string,string>>
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-surfaces-an-unresolvable-slug-reference-as-a-blocking-warning
	 */
	private function findUnresolvedReferences(array $entity, array $slugMaps): array {
		$payload = $entity['payload'];

		// Top-level source_id / target_id (mapping + rule handlers translate
		// these against the source slug map).
		$refs = $this->unresolvedSourceFields(payload: $payload, known: $slugMaps['source']);
		if ($entity['type'] === 'endpoint') {
			$refs = array_merge($refs, $this->unresolvedEndpointReferences(payload: $payload, slugMaps: $slugMaps));
		}

		$refs = array_merge($refs, $this->unresolvedConfigurationReferences(payload: $payload, slugMaps: $slugMaps));

		return array_map(
			static fn (array $ref): array => ['type' => $entity['type'], 'slug' => $entity['slug'], 'field' => $ref['field'], 'value' => $ref['value']],
			$refs
		);
	}//end findUnresolvedReferences()

	/**
	 * The unresolved top-level `source_id` / `target_id` references.
	 *
	 * @param array<string,mixed>  $payload The entity payload.
	 * @param array<string,string> $known   The source slug map.
	 *
	 * @return array<int,array{field: string, value: mixed}>
	 */
	private function unresolvedSourceFields(array $payload, array $known): array {
		$refs = [];
		foreach (['source_id', 'target_id'] as $field) {
			$value = ($payload[$field] ?? null);
			if ($this->isUnresolvedSlug(value: $value, known: $known) === true) {
				$refs[] = ['field' => $field, 'value' => $value];
			}
		}

		return $refs;
	}//end unresolvedSourceFields()

	/**
	 * The unresolved references inside a nested `configuration` array (rule
	 * handler convention: keys equal to an entity type or ending with `<type>Id`).
	 *
	 * @param array<string,mixed>                $payload  The entity payload.
	 * @param array<string,array<string,string>> $slugMaps schema => slug => uuid.
	 *
	 * @return array<int,array{field: string, value: mixed}>
	 */
	private function unresolvedConfigurationReferences(array $payload, array $slugMaps): array {
		$configuration = ($payload['configuration'] ?? null);
		if (is_array($configuration) === false) {
			return [];
		}

		$refs = [];
		foreach ($this->scanConfigurationReferences(config: $configuration, scope: ['slugMaps' => $slugMaps, 'path' => '']) as $ref) {
			$refs[] = ['field' => 'configuration.' . $ref['field'], 'value' => $ref['value']];
		}

		return $refs;
	}//end unresolvedConfigurationReferences()

	/**
	 * The endpoint-specific top-level references of one endpoint payload that
	 * resolve to nothing in the target: its targetId, input and output mapping and rules.
	 *
	 * @param array<string, mixed> $payload The endpoint payload.
	 * @param array<string, array<string, string>> $slugMaps The target's slug maps.
	 *
	 * @return array<int, array{field: string, value: string}>
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-classifies-creates-updates-and-collisions
	 */
	private function unresolvedEndpointReferences(array $payload, array $slugMaps): array {
		$refs = $this->unresolvedEndpointTarget(payload: $payload, sources: $slugMaps['source']);

		foreach (['inputMapping', 'outputMapping'] as $field) {
			$value = ($payload[$field] ?? null);
			if ($this->isUnresolvedSlug(value: $value, known: $slugMaps['mapping']) === true) {
				$refs[] = ['field' => $field, 'value' => $value];
			}
		}

		return array_merge($refs, $this->unresolvedRuleReferences(rules: ($payload['rules'] ?? []), known: $slugMaps['rule']));
	}//end unresolvedEndpointReferences()

	/**
	 * The endpoint's rule slugs the target does not have, as unresolved references.
	 *
	 * @param mixed $rules The endpoint's rule slugs; anything but an array has none.
	 * @param array<string, string> $known The target's rule slugs.
	 *
	 * @return array<int, array{field: string, value: string}>
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-classifies-creates-updates-and-collisions
	 */
	private function unresolvedRuleReferences(mixed $rules, array $known): array {
		if (is_array($rules) === false) {
			return [];
		}

		$refs = [];
		foreach ($rules as $ruleRef) {
			if ($this->isUnresolvedSlug(value: $ruleRef, known: $known) === true) {
				$refs[] = ['field' => 'rules[]', 'value' => $ruleRef];
			}
		}

		return $refs;
	}//end unresolvedRuleReferences()

	/**
	 * The endpoint's targetId as an unresolved reference, when it names a
	 * source slug the target does not have. Register/schema targets and
	 * targets with a slash are not source slugs.
	 *
	 * @param array<string, mixed> $payload The endpoint payload.
	 * @param array<string, string> $sources The target's source slugs.
	 *
	 * @return array<int, array{field: string, value: string}>
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md#scenario-preview-classifies-creates-updates-and-collisions
	 */
	private function unresolvedEndpointTarget(array $payload, array $sources): array {
		$targetType = (string)($payload['targetType'] ?? '');
		$targetId = ($payload['targetId'] ?? null);
		if ($targetType === 'register/schema' || $this->isUnresolvedSlug(value: $targetId, known: $sources) === false) {
			return [];
		}

		if (str_contains($targetId, '/') === true) {
			return [];
		}

		return [['field' => 'targetId', 'value' => $targetId]];
	}//end unresolvedEndpointTarget()

	/**
	 * Whether a value is a non-empty slug that is not among the known slugs.
	 *
	 * @param mixed $value The referenced value.
	 * @param array<string, string> $known The target's slugs of the referenced type.
	 *
	 * @return bool
	 *
	 * @psalm-assert-if-true non-empty-string $value
	 */
	private function isUnresolvedSlug(mixed $value, array $known): bool {
		return is_string($value) === true && $value !== '' && isset($known[$value]) === false;
	}//end isUnresolvedSlug()

	/**
	 * Recursively scan a nested configuration array for entity-reference
	 * keys whose slug value does not resolve against the target maps.
	 *
	 * @param array<string,mixed> $config The configuration array.
	 * @param array{slugMaps: array<string,array<string,string>>, path: string} $scope The target's slug maps
	 *                                                                              (schema => slug => uuid) and the
	 *                                                                              dotted key path of $config.
	 *
	 * @return array<int,array{field:string,value:string}>
	 */
	private function scanConfigurationReferences(array $config, array $scope): array {
		$found = [];

		foreach ($config as $key => $value) {
			$keyPath = $this->configurationKeyPath(path: $scope['path'], key: $key);
			if (is_array($value) === true) {
				$nested = ['slugMaps' => $scope['slugMaps'], 'path' => $keyPath];
				$found = array_merge($found, $this->scanConfigurationReferences(config: $value, scope: $nested));
				continue;
			}

			$type = $this->referenceType(key: $key);
			if ($type !== null && $this->isUnresolvedSlug(value: $value, known: $scope['slugMaps'][$type]) === true) {
				$found[] = ['field' => $keyPath, 'value' => $value];
			}
		}

		return $found;
	}//end scanConfigurationReferences()

	/**
	 * The dotted key path of a configuration key: the key itself at the root (an empty path).
	 *
	 * @param string $path The dotted key path of the key's parent.
	 * @param int|string $key The key.
	 *
	 * @return string
	 */
	private function configurationKeyPath(string $path, int|string $key): string {
		if ($path === '') {
			return (string)$key;
		}

		return $path . '.' . $key;
	}//end configurationKeyPath()

	/**
	 * The entity type a configuration key references: a key equal to the
	 * type or ending in `<type>Id` / `<Type>Id`, first type in REFERENCE_TYPES order.
	 *
	 * @param int|string $key The configuration key.
	 *
	 * @return string|null The type, or null when the key references none.
	 */
	private function referenceType(int|string $key): ?string {
		foreach (self::REFERENCE_TYPES as $type) {
			if ($this->keyReferences(key: $key, type: $type) === true) {
				return $type;
			}
		}

		return null;
	}//end referenceType()

	/**
	 * Whether a configuration key references one entity type: equal to it,
	 * or ending in `<Type>Id` or `<type>Id`.
	 *
	 * @param int|string $key  The configuration key.
	 * @param string     $type The entity type.
	 *
	 * @return bool
	 */
	private function keyReferences(int|string $key, string $type): bool {
		return $key === $type
			|| str_ends_with((string)$key, ucfirst($type) . 'Id') === true
			|| str_ends_with((string)$key, $type . 'Id') === true;
	}//end keyReferences()

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
				config: ['filters' => ['register' => 'integriq', 'schema' => $schema]]
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
