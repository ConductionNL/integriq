<?php

/**
 * Contract of the SloCurriculumTreeWalker.
 *
 * @category Contract
 * @package  OCA\Integriq\Adapters\Slo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Adapters\Slo;

use OCA\Integriq\Exception\SloCurriculumException;

/**
 * Public operations of {@see SloCurriculumTreeWalker} that other components call.
 */
interface SloCurriculumTreeWalkerInterface {
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
	public function fetchTree(SloCurriculumClient $client, string $uuid): array;

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
	public function fetchJson(SloCurriculumClient $client, string $path, array $query = []): array;

	/**
	 * Identity and headline facts of one entity (links resolved within it).
	 *
	 * @param array<string,mixed> $entity An SLO entity.
	 *
	 * @return array{uuid:string,type:string,title:string,status:string|null,versie:string|null,subjectKeys:array<int,string>}
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function describeEntity(array $entity): array;

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
	public function walk(array $roots, array $profile, SloCurriculumClient $client, bool $rootsAreNodes): array;
}
