<?php

/**
 * Integriq SLO curriculum tree walker.
 *
 * Walks one or more SLO roots through the child collections a set profile
 * names and returns a flat node list, parents before children. It works on
 * the raw graph `/tree/{id}` returns (JSONTag, every level present) and on
 * SLO's JSON-LD typed-query responses (one level projected): a child that
 * arrives as a bare reference is expanded through `/uuid/{id}`.
 *
 * Profile rules (openspec/changes/archive/2026-09-29-slo-kerndoelen-import/design.md, D2 and D12):
 * depth first in the profile's key order, so a 2006 kerndoel that hangs both
 * under a domein and directly under its vakleergebied lands under the domein;
 * an entity seen twice keeps its first parent; `deprecated` and `unreleased`
 * entities are skipped; a leaf whose SLO niveaus miss the profile's niveau
 * filter is dropped and a branch left without leaves is pruned.
 *
 * @category Adapter
 * @package  OCA\Integriq\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Slo;

use OCA\Integriq\Exception\SloCurriculumException;

/**
 * Flattens SLO curriculum trees into parent-first node lists.
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
 */
final class SloCurriculumTreeWalker {
	/**
	 * Most nodes one run may emit.
	 */
	public const MAX_NODES = 25000;

	/**
	 * Deepest level one run may descend to.
	 */
	public const MAX_DEPTH = 16;

	/**
	 * Most `/uuid/{id}` expansions one run may make.
	 */
	public const MAX_EXPANSIONS = 500;

	/**
	 * Keys a bare reference carries; anything else means the entity has content.
	 */
	private const REFERENCE_KEYS = ['@id', '@type', '@link', '@references', '@context', 'uuid', 'id', 'deprecated'];

	/**
	 * Objects of the current run keyed by id, from every document read.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $index = [];

	/**
	 * SLO uuids already emitted in the current run.
	 *
	 * @var array<string,bool>
	 */
	private array $visited = [];

	/**
	 * Counters of the current run.
	 *
	 * @var array<string,int>
	 */
	private array $stats = [];

	/**
	 * The profile of the current run.
	 *
	 * @var array<string,mixed>
	 */
	private array $profile = [];

	/**
	 * The client of the current run, for expansions.
	 *
	 * @var SloCurriculumClient|null
	 */
	private ?SloCurriculumClient $client = null;

	/**
	 * Constructor.
	 *
	 * @param JsonTagReader $reader Reads JSONTag and JSON bodies.
	 * @param SloCurriculumNodeReader $nodes Reads the facts of one entity.
	 */
	public function __construct(
		private readonly JsonTagReader $reader,
		private readonly SloCurriculumNodeReader $nodes,
	) {
	}//end __construct()

	/**
	 * Fetch and read the full SLO tree under one id.
	 *
	 * @param SloCurriculumClient $client The client.
	 * @param string $uuid The SLO root uuid.
	 *
	 * @return array<string,mixed> The root entity with its whole graph.
	 *
	 * @throws SloCurriculumException When the answer is not an object.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
	 */
	public function fetchTree(SloCurriculumClient $client, string $uuid): array {
		$decoded = $this->reader->decode(
			text: $client->fetch(path: 'tree/' . rawurlencode($uuid), query: [], accept: 'application/jsontag')
		);
		if (is_array($decoded) === false || array_is_list($decoded) === true) {
			throw new SloCurriculumException(message: sprintf('SLO answered tree/%s with something other than one object.', $uuid));
		}

		return $decoded;
	}//end fetchTree()

	/**
	 * Fetch and read a JSON response (a collection or one entity).
	 *
	 * @param SloCurriculumClient $client The client.
	 * @param string $path Path under the API base.
	 * @param array<string,scalar> $query Query parameters.
	 *
	 * @return array<mixed> The decoded body.
	 *
	 * @throws SloCurriculumException When the answer is not a JSON array or object.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-roots-are-discovered-through-slos-collection-routes-req-008
	 */
	public function fetchJson(SloCurriculumClient $client, string $path, array $query = []): array {
		$decoded = $this->reader->decode(text: $client->fetch(path: $path, query: $query, accept: 'application/json'));
		if (is_array($decoded) === false) {
			throw new SloCurriculumException(message: sprintf('SLO answered %s with a scalar instead of a list or an object.', $path));
		}

		return $decoded;
	}//end fetchJson()

	/**
	 * Identity and headline facts of one entity (links resolved within it).
	 *
	 * @param array<string,mixed> $entity An SLO entity.
	 *
	 * @return array{uuid:string,type:string,title:string,status:string|null,versie:string|null,subjectKeys:array<int,string>}
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function describeEntity(array $entity): array {
		return $this->nodes->describe(entity: $entity, index: $this->reader->indexById(document: $entity));
	}//end describeEntity()

	/**
	 * Walk roots with a profile.
	 *
	 * @param array<int,array<string,mixed>> $roots Root entities (from fetchTree()).
	 * @param array<string,mixed> $profile The set profile (from SloCurriculumPresetRegistry::set()).
	 * @param SloCurriculumClient $client The client, for expansions.
	 * @param bool $rootsAreNodes True: every root is itself a top-level node (an aggregate
	 *                            set such as the 2006 kerndoelen). False: the one root is
	 *                            the framework and its children are the top level.
	 *
	 * @return array{nodes:array<int,array<string,mixed>>,stats:array<string,int>} Parent-first nodes and counters.
	 *
	 * @throws SloCurriculumException When a guard limit is reached or an expansion fails.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
	 */
	public function walk(array $roots, array $profile, SloCurriculumClient $client, bool $rootsAreNodes): array {
		$this->start(roots: $roots, profile: $profile, client: $client);

		$nodes = [];
		$order = 0;
		foreach ($roots as $root) {
			foreach ($this->walkRoot(root: $root, rootsAreNodes: $rootsAreNodes) as $record) {
				if ($record['parentSloUuid'] === null) {
					$record['order'] = $order;
					$order++;
				}

				$nodes[] = $record;
			}
		}

		$this->stats['nodes'] = count($nodes);
		$this->client = null;

		return ['nodes' => $nodes, 'stats' => $this->stats];
	}//end walk()

	/**
	 * Reset the run state.
	 *
	 * @param array<int,array<string,mixed>> $roots Root entities.
	 * @param array<string,mixed> $profile The set profile.
	 * @param SloCurriculumClient $client The client.
	 *
	 * @return void
	 */
	private function start(array $roots, array $profile, SloCurriculumClient $client): void {
		$this->index = [];
		$this->visited = [];
		$this->profile = $profile;
		$this->client = $client;
		$this->stats = [
			'nodes' => 0,
			'leaves' => 0,
			'skippedDeprecated' => 0,
			'skippedUnreleased' => 0,
			'skippedDuplicates' => 0,
			'filteredByNiveau' => 0,
			'prunedBranches' => 0,
			'expansions' => 0,
			'malformed' => 0,
		];

		foreach ($roots as $root) {
			$this->index += $this->reader->indexById(document: $root);
		}
	}//end start()

	/**
	 * Records for one root.
	 *
	 * @param array<string,mixed> $root The root entity.
	 * @param bool $rootsAreNodes Whether the root is itself a node.
	 *
	 * @return array<int,array<string,mixed>> Records.
	 */
	private function walkRoot(array $root, bool $rootsAreNodes): array {
		if ($rootsAreNodes === true) {
			return $this->visit(value: $root, parentUuid: null, depth: 0);
		}

		// An empty uuid is harmless here: admit() rejects it before this map is read.
		$this->visited[$this->nodes->uuidOf(entity: $root)] = true;

		return $this->visitChildren(entity: $root, parentUuid: null, depth: 1);
	}//end walkRoot()

	/**
	 * Records for one entity and its kept descendants.
	 *
	 * @param mixed $value An entity, a link or a bare reference.
	 * @param string|null $parentUuid SLO uuid of the parent node, or null at the top.
	 * @param int $depth Depth of this entity.
	 *
	 * @return array<int,array<string,mixed>> This node first, then its descendants; [] when skipped.
	 *
	 * @throws SloCurriculumException When a guard limit is reached.
	 */
	private function visit(mixed $value, ?string $parentUuid, int $depth): array {
		if ($depth > self::MAX_DEPTH) {
			throw new SloCurriculumException(message: sprintf('The SLO tree is deeper than %d levels; the walk stopped.', self::MAX_DEPTH));
		}

		$entity = $this->admit(value: $value);
		if ($entity === null) {
			return [];
		}

		$type = (string)($entity['@type'] ?? '');
		$record = $this->nodes->record(
			entity: $entity,
			context: [
				'uuid' => $this->nodes->uuidOf(entity: $entity),
				'type' => $type,
				'parentUuid' => $parentUuid,
				'isLeaf' => in_array($type, (array)($this->profile['leafTypes'] ?? []), true),
				'fields' => (array)($this->profile['fields'] ?? []),
			],
			index: $this->index
		);

		if ($record['isLeaf'] === true) {
			return $this->keepLeaf(record: $record);
		}

		$children = $this->visitChildren(entity: $entity, parentUuid: $record['sloUuid'], depth: ($depth + 1));
		if ($children === [] && $this->niveauFilter() !== []) {
			$this->stats['prunedBranches']++;
			return [];
		}

		return array_merge([$record], $children);
	}//end visit()

	/**
	 * Materialise an entity and decide whether it enters the walk, counting why not.
	 *
	 * @param mixed $value An entity, a link or a bare reference.
	 *
	 * @return array<string,mixed>|null The entity, marked visited; null when it is skipped.
	 *
	 * @throws SloCurriculumException When the node limit is reached.
	 */
	private function admit(mixed $value): ?array {
		$entity = $this->materialise(value: $value);
		$uuid = '';
		if ($entity !== null) {
			$uuid = $this->nodes->uuidOf(entity: $entity);
		}

		if ($entity === null || $uuid === '') {
			$this->stats['malformed']++;
			return null;
		}

		if ($this->skip(entity: $entity, uuid: $uuid) === true) {
			return null;
		}

		$this->visited[$uuid] = true;
		if (count($this->visited) > self::MAX_NODES) {
			throw new SloCurriculumException(message: sprintf('The SLO tree has more than %d nodes; the walk stopped.', self::MAX_NODES));
		}

		return $entity;
	}//end admit()

	/**
	 * A leaf's records: itself, unless the niveau filter drops it.
	 *
	 * @param array<string,mixed> $record The leaf's record.
	 *
	 * @return array<int,array<string,mixed>> [record] or [].
	 */
	private function keepLeaf(array $record): array {
		$filter = $this->niveauFilter();
		if ($filter !== [] && array_intersect(array_column($record['niveaus'], 'uuid'), $filter) === []) {
			$this->stats['filteredByNiveau']++;
			return [];
		}

		$this->stats['leaves']++;
		return [$record];
	}//end keepLeaf()

	/**
	 * The profile's niveau filter.
	 *
	 * @return array<int,string> SLO niveau uuids; empty for no filter.
	 */
	private function niveauFilter(): array {
		return array_values(array_map('strval', (array)($this->profile['leafNiveauFilter'] ?? [])));
	}//end niveauFilter()

	/**
	 * Records for the children of one entity, in profile key order.
	 *
	 * @param array<string,mixed> $entity The parent entity.
	 * @param string|null $parentUuid The parent's SLO uuid, or null when the parent is the framework.
	 * @param int $depth Depth of the children.
	 *
	 * @return array<int,array<string,mixed>> Records.
	 */
	private function visitChildren(array $entity, ?string $parentUuid, int $depth): array {
		$records = [];
		$order = 0;

		foreach ((array)($this->profile['levels'] ?? []) as $key) {
			foreach ($this->nodes->listOf(value: ($entity[(string)$key] ?? null)) as $child) {
				$subtree = $this->visit(value: $child, parentUuid: $parentUuid, depth: $depth);
				if ($subtree === []) {
					continue;
				}

				$subtree[0]['order'] = $order;
				$order++;
				array_push($records, ...$subtree);
			}
		}

		return $records;
	}//end visitChildren()

	/**
	 * Whether to skip an entity (deprecated, unreleased or already emitted), counting why.
	 *
	 * @param array<string,mixed> $entity The entity.
	 * @param string $uuid Its SLO uuid.
	 *
	 * @return bool True to skip.
	 */
	private function skip(array $entity, string $uuid): bool {
		if (($entity['deprecated'] ?? false) === true) {
			$this->stats['skippedDeprecated']++;
			return true;
		}

		if (($entity['unreleased'] ?? false) === true) {
			$this->stats['skippedUnreleased']++;
			return true;
		}

		if (isset($this->visited[$uuid]) === true) {
			$this->stats['skippedDuplicates']++;
			return true;
		}

		return false;
	}//end skip()

	/**
	 * Turn a link or bare reference into an entity with content.
	 *
	 * @param mixed $value An entity, a link or a bare reference.
	 *
	 * @return array<string,mixed>|null The entity, or null when it is not an object.
	 *
	 * @throws SloCurriculumException When the expansion limit is reached.
	 */
	private function materialise(mixed $value): ?array {
		$value = $this->reader->resolve(value: $value, index: $this->index);
		if (is_array($value) === false || array_is_list($value) === true) {
			return null;
		}

		if (array_diff(array_keys($value), self::REFERENCE_KEYS) !== []) {
			return $value;
		}

		$uuid = $this->nodes->uuidOf(entity: $value);
		if ($uuid === '' || $this->client === null) {
			return $value;
		}

		return $this->expand(client: $this->client, uuid: $uuid);
	}//end materialise()

	/**
	 * Fetch one entity through `/uuid/{id}` and add it to the run's index.
	 *
	 * @param SloCurriculumClient $client The client.
	 * @param string $uuid The SLO uuid.
	 *
	 * @return array<string,mixed> The entity.
	 *
	 * @throws SloCurriculumException When the limit is reached or the answer is not an object.
	 */
	private function expand(SloCurriculumClient $client, string $uuid): array {
		$this->stats['expansions']++;
		if ($this->stats['expansions'] > self::MAX_EXPANSIONS) {
			throw new SloCurriculumException(
				message: sprintf('More than %d SLO entities needed a separate lookup; the walk stopped.', self::MAX_EXPANSIONS)
			);
		}

		$entity = $this->fetchJson(client: $client, path: 'uuid/' . rawurlencode($uuid));
		if (array_is_list($entity) === true) {
			throw new SloCurriculumException(message: sprintf('SLO answered uuid/%s with a list instead of one entity.', $uuid));
		}

		$this->index += $this->reader->indexById(document: $entity);

		return $entity;
	}//end expand()
}//end class
