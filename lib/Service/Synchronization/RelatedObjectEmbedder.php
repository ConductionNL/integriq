<?php

/**
 * Embeds the objects that point at a pushed object, before it is mapped.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Synchronization;

use Adbar\Dot;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use RuntimeException;

/**
 * Reads a push's `sourceConfig.embedRelated` and adds the objects it names.
 *
 * `embedRelated` is `{property: {schema: "register/schema", filters: {field:
 * path}, single: bool}}`. Each filter value is a dot path into the pushed
 * object, so `{"handoffId": "id"}` finds the objects whose `handoffId` holds
 * the pushed object's id. A list is embedded under the property, or with
 * `single` the one match (null for none or several).
 *
 * Two rules keep a push from sending something it should not:
 * - a filter that reads nothing embeds nothing, and never widens the query
 *   to every object in the schema;
 * - more matches than MAX_RELATED refuse the push instead of sending a
 *   partial list, as `lookupAll()` refuses a partial read.
 *
 * Read in system context: only an administrator writes a synchronization.
 *
 * @spec openspec/changes/connectors-hr-data-exchange/specs/synchronization-engine/spec.md#requirement-a-push-can-carry-the-objects-that-point-at-the-pushed-object-req-hrx-003
 */
class RelatedObjectEmbedder {

	/**
	 * The most objects embedded under one property.
	 *
	 * @var integer
	 */
	public const MAX_RELATED = 500;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService OpenRegister's object service.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The object with every declared relation embedded.
	 *
	 * @param mixed                $declaration The synchronization's `sourceConfig.embedRelated`.
	 * @param array<string, mixed> $object      The serialized source object.
	 *
	 * @return array<string, mixed> The object, with the relations added.
	 *
	 * @throws RuntimeException When a declaration is malformed or more objects match than MAX_RELATED.
	 *
	 * @spec openspec/changes/connectors-hr-data-exchange/specs/synchronization-engine/spec.md#requirement-a-push-can-carry-the-objects-that-point-at-the-pushed-object-req-hrx-003
	 */
	public function embed(mixed $declaration, array $object): array {
		if (is_array($declaration) === false || $declaration === []) {
			return $object;
		}

		$source = new Dot($object);
		foreach ($declaration as $property => $relation) {
			$object[(string)$property] = $this->related(property: (string)$property, relation: $relation, source: $source);
		}

		return $object;
	}//end embed()

	/**
	 * The objects one relation names.
	 *
	 * @param string $property The property the relation is embedded under.
	 * @param mixed  $relation The relation's declaration.
	 * @param Dot    $source   The pushed object.
	 *
	 * @return array<string, mixed>|list<array<string, mixed>>|null
	 *
	 * @throws RuntimeException When the declaration is malformed or more objects match than MAX_RELATED.
	 */
	private function related(string $property, mixed $relation, Dot $source): ?array {
		[$register, $schema, $paths] = $this->declared(property: $property, relation: $relation);
		$single = (($relation['single'] ?? false) === true);

		$filters = [];
		foreach ($paths as $field => $path) {
			$value = $this->valueAt(source: $source, path: (string)$path);
			if ($value === null || $value === '' || is_scalar($value) === false) {
				return $this->nothing(single: $single);
			}

			$filters[(string)$field] = $value;
		}

		$rows = $this->find(register: $register, schema: $schema, filters: $filters);
		if (count($rows) > self::MAX_RELATED) {
			throw new RuntimeException(
				message: sprintf('embedRelated "%s": more than %d objects match, and a push does not send a partial list.', $property, self::MAX_RELATED)
			);
		}

		if ($single === false) {
			return $rows;
		}

		if (count($rows) !== 1) {
			return null;
		}

		return $rows[0];
	}//end related()

	/**
	 * The register, schema and filter paths of a relation, or a refusal.
	 *
	 * @param string $property The property, for the message.
	 * @param mixed  $relation The relation's declaration.
	 *
	 * @return array{0: string, 1: string, 2: array<string, mixed>}
	 *
	 * @throws RuntimeException When the declaration is malformed.
	 */
	private function declared(string $property, mixed $relation): array {
		$schema = '';
		if (is_array($relation) === true) {
			$schema = (string)($relation['schema'] ?? '');
		}

		$parts = explode('/', $schema);
		$paths = [];
		if (is_array($relation) === true && is_array($relation['filters'] ?? null) === true) {
			$paths = $relation['filters'];
		}

		if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '' || $paths === []) {
			throw new RuntimeException(
				message: sprintf('embedRelated "%s" needs a schema as "register/schema" and at least one filter.', $property)
			);
		}

		return [$parts[0], $parts[1], $paths];
	}//end declared()

	/**
	 * The value a filter path reads; `id` falls back to the object's own uuid.
	 *
	 * @param Dot    $source The pushed object.
	 * @param string $path   The dot path.
	 *
	 * @return mixed
	 */
	private function valueAt(Dot $source, string $path): mixed {
		$value = $source->get($path);
		if (($value === null || $value === '') && $path === 'id') {
			$value = ($source->get('@self.id') ?? $source->get('uuid'));
		}

		return $value;
	}//end valueAt()

	/**
	 * What an unresolvable relation embeds.
	 *
	 * @param bool $single Whether the relation is one object.
	 *
	 * @return array{}|null
	 */
	private function nothing(bool $single): ?array {
		if ($single === true) {
			return null;
		}

		return [];
	}//end nothing()

	/**
	 * At most MAX_RELATED + 1 matching objects, serialized.
	 *
	 * @param string               $register The register slug.
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $filters  The resolved filters.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function find(string $register, string $schema, array $filters): array {
		$found = $this->objectService->findAll(
			config: ['filters' => (['register' => $register, 'schema' => $schema] + $filters), 'limit' => (self::MAX_RELATED + 1)],
			_rbac: false,
			_multitenancy: false
		);

		$rows = [];
		foreach (($found['results'] ?? []) as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end find()
}//end class
