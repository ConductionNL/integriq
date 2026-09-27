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
 * Profile rules (openspec/changes/slo-kerndoelen-import/design.md, D2 and D12):
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
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
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
	 * Default field paths when a profile names none for a type.
	 */
	private const DEFAULT_FIELDS = [
		'code' => ['prefix', 'title'],
		'title' => ['title'],
		'description' => ['description'],
	];

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
	 */
	public function __construct(
		private readonly JsonTagReader $reader,
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

		$nodes = [];
		$order = 0;
		foreach ($roots as $root) {
			$records = $this->walkRoot(root: $root, rootsAreNodes: $rootsAreNodes);
			foreach ($records as $record) {
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
	 * Identity and headline facts of one entity.
	 *
	 * @param array<string,mixed> $entity An SLO entity.
	 *
	 * @return array{uuid:string,type:string,title:string,status:string|null,versie:string|null,subjectKeys:array<int,string>}
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function describeEntity(array $entity): array {
		return [
			'uuid' => self::uuidOf(entity: $entity),
			'type' => (string)($entity['@type'] ?? ''),
			'title' => trim((string)($entity['title'] ?? '')),
			'status' => self::optionalString(value: ($entity['status'] ?? null)),
			'versie' => self::optionalString(value: ($entity['versie'] ?? null)),
			'subjectKeys' => $this->subjectKeys(entity: $entity),
		];
	}//end describeEntity()

	/**
	 * The SLO uuid of an entity: `uuid`, else `id`, else the tail of `@id`.
	 *
	 * @param array<string,mixed> $entity An SLO entity or reference.
	 *
	 * @return string The uuid, or '' when there is none.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-tree-walk-follows-the-set-profile-req-005
	 */
	public static function uuidOf(array $entity): string {
		foreach (['uuid', 'id'] as $key) {
			if (is_string($entity[$key] ?? null) === true && $entity[$key] !== '') {
				return JsonTagReader::lastSegment(value: $entity[$key]);
			}
		}

		foreach (['@id', '@link'] as $key) {
			if (is_string($entity[$key] ?? null) === true && $entity[$key] !== '') {
				return JsonTagReader::lastSegment(value: $entity[$key]);
			}
		}

		return '';
	}//end uuidOf()

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

		$rootUuid = self::uuidOf(entity: $root);
		if ($rootUuid !== '') {
			$this->visited[$rootUuid] = true;
		}

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

		$entity = $this->materialise(value: $value);
		$uuid = '';
		if ($entity !== null) {
			$uuid = self::uuidOf(entity: $entity);
		}

		if ($entity === null || $uuid === '') {
			$this->stats['malformed']++;
			return [];
		}

		if ($this->skip(entity: $entity, uuid: $uuid) === true) {
			return [];
		}

		$this->visited[$uuid] = true;
		if (count($this->visited) > self::MAX_NODES) {
			throw new SloCurriculumException(message: sprintf('The SLO tree has more than %d nodes; the walk stopped.', self::MAX_NODES));
		}

		$type = (string)($entity['@type'] ?? '');
		$isLeaf = in_array($type, (array)($this->profile['leafTypes'] ?? []), true);
		$record = $this->buildRecord(entity: $entity, type: $type, uuid: $uuid, parentUuid: $parentUuid, isLeaf: $isLeaf);
		$filter = array_values((array)($this->profile['leafNiveauFilter'] ?? []));

		if ($isLeaf === true) {
			if ($filter !== [] && array_intersect(array_column($record['niveaus'], 'uuid'), $filter) === []) {
				$this->stats['filteredByNiveau']++;
				return [];
			}

			$this->stats['leaves']++;
			return [$record];
		}

		$children = $this->visitChildren(entity: $entity, parentUuid: $uuid, depth: ($depth + 1));
		if ($filter !== [] && $children === []) {
			$this->stats['prunedBranches']++;
			return [];
		}

		return array_merge([$record], $children);
	}//end visit()

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
			$children = ($entity[(string)$key] ?? null);
			if (is_array($children) === false) {
				continue;
			}

			if (array_is_list($children) === false) {
				$children = [$children];
			}

			foreach ($children as $child) {
				$subtree = $this->visit(value: $child, parentUuid: $parentUuid, depth: $depth);
				if ($subtree === []) {
					continue;
				}

				$subtree[0]['order'] = $order;
				$order++;
				foreach ($subtree as $record) {
					$records[] = $record;
				}
			}
		}//end foreach

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

		$uuid = self::uuidOf(entity: $value);
		if ($uuid === '' || $this->client === null) {
			return $value;
		}

		return $this->expand(uuid: $uuid);
	}//end materialise()

	/**
	 * Fetch one entity through `/uuid/{id}` and add it to the run's index.
	 *
	 * @param string $uuid The SLO uuid.
	 *
	 * @return array<string,mixed> The entity.
	 *
	 * @throws SloCurriculumException When the limit is reached or the answer is not an object.
	 */
	private function expand(string $uuid): array {
		$this->stats['expansions']++;
		if ($this->stats['expansions'] > self::MAX_EXPANSIONS) {
			throw new SloCurriculumException(
				message: sprintf('More than %d SLO entities needed a separate lookup; the walk stopped.', self::MAX_EXPANSIONS)
			);
		}

		if ($this->client === null) {
			throw new SloCurriculumException(message: sprintf('No SLO client is available to look up %s.', $uuid));
		}

		$entity = $this->fetchJson(client: $this->client, path: 'uuid/' . rawurlencode($uuid));
		if (array_is_list($entity) === true) {
			throw new SloCurriculumException(message: sprintf('SLO answered uuid/%s with a list instead of one entity.', $uuid));
		}

		$this->index += $this->reader->indexById(document: $entity);

		return $entity;
	}//end expand()

	/**
	 * Build the normalised record of one node.
	 *
	 * @param array<string,mixed> $entity The entity.
	 * @param string $type Its SLO type.
	 * @param string $uuid Its SLO uuid.
	 * @param string|null $parentUuid The parent node's SLO uuid.
	 * @param bool $isLeaf Whether its type is a leaf type.
	 *
	 * @return array<string,mixed> The record.
	 */
	private function buildRecord(array $entity, string $type, string $uuid, ?string $parentUuid, bool $isLeaf): array {
		$fields = array_replace(self::DEFAULT_FIELDS, (array)($this->profile['fields'][$type] ?? []));
		$code = $this->firstText(entity: $entity, paths: (array)$fields['code']);
		$title = $this->firstText(entity: $entity, paths: (array)$fields['title']);
		$description = $this->firstText(entity: $entity, paths: (array)$fields['description']);

		if ($title === '') {
			$title = $code;
		}

		if ($code === '') {
			$code = $title;
		}

		if ($code === '') {
			$code = $uuid;
			$title = $uuid;
		}

		if ($description === '') {
			$description = null;
		}

		return [
			'sloUuid' => $uuid,
			'sloType' => $type,
			'code' => $code,
			'title' => $title,
			'description' => $description,
			'parentSloUuid' => $parentUuid,
			'order' => 0,
			'isLeaf' => $isLeaf,
			'niveaus' => $this->niveaus(entity: $entity),
			'subjectKeys' => $this->subjectKeys(entity: $entity),
		];
	}//end buildRecord()

	/**
	 * The first non-empty text among dot paths into the entity.
	 *
	 * @param array<string,mixed> $entity The entity.
	 * @param array<int,mixed> $paths Candidate paths, such as `title` or `Doel.0.title`.
	 *
	 * @return string The trimmed text, or ''.
	 */
	private function firstText(array $entity, array $paths): string {
		foreach ($paths as $path) {
			$value = $this->valueAt(entity: $entity, path: (string)$path);
			if (is_int($value) === true || is_float($value) === true) {
				return (string)$value;
			}

			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return '';
	}//end firstText()

	/**
	 * Read a dot path, resolving links on the way.
	 *
	 * @param array<string,mixed> $entity The entity.
	 * @param string $path The dot path.
	 *
	 * @return mixed The value, or null when the path does not exist.
	 */
	private function valueAt(array $entity, string $path): mixed {
		$current = $entity;
		foreach (explode('.', $path) as $segment) {
			$current = $this->reader->resolve(value: $current, index: $this->index);
			if (is_array($current) === false || array_key_exists($segment, $current) === false) {
				return null;
			}

			$current = $current[$segment];
		}

		return $this->reader->resolve(value: $current, index: $this->index);
	}//end valueAt()

	/**
	 * The SLO niveaus an entity is tagged with.
	 *
	 * @param array<string,mixed> $entity The entity.
	 *
	 * @return array<int,array{uuid:string,title:string|null}> Niveaus.
	 */
	private function niveaus(array $entity): array {
		$raw = ($entity['Niveau'] ?? $entity['NiveauIndex'] ?? []);
		if (is_array($raw) === false) {
			return [];
		}

		if (array_is_list($raw) === false) {
			$raw = [$raw];
		}

		$niveaus = [];
		foreach ($raw as $item) {
			$niveau = $this->reader->resolve(value: $item, index: $this->index);
			if (is_array($niveau) === false) {
				continue;
			}

			$uuid = self::uuidOf(entity: $niveau);
			if ($uuid === '') {
				continue;
			}

			$niveaus[] = ['uuid' => $uuid, 'title' => self::optionalString(value: ($niveau['title'] ?? null))];
		}

		return $niveaus;
	}//end niveaus()

	/**
	 * Keys a caller's subject map can use for this entity: its vakleergebied's
	 * uuid and lower-cased title, then its own.
	 *
	 * @param array<string,mixed> $entity The entity.
	 *
	 * @return array<int,string> Keys, most specific first.
	 */
	private function subjectKeys(array $entity): array {
		$keys = [];
		$subject = ($entity['Vakleergebied'] ?? null);
		if (is_array($subject) === true && array_is_list($subject) === true) {
			$subject = ($subject[0] ?? null);
		}

		$subject = $this->reader->resolve(value: $subject, index: $this->index);
		foreach ([$subject, $entity] as $candidate) {
			if (is_array($candidate) === false) {
				continue;
			}

			$keys[] = self::uuidOf(entity: $candidate);
			$keys[] = mb_strtolower(trim((string)($candidate['title'] ?? '')));
		}

		return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
	}//end subjectKeys()

	/**
	 * A non-empty trimmed string, or null.
	 *
	 * @param mixed $value Any value.
	 *
	 * @return string|null The string, or null.
	 */
	private static function optionalString(mixed $value): ?string {
		if (is_string($value) === false && is_int($value) === false) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end optionalString()
}//end class
