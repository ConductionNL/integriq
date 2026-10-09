<?php

/**
 * Integriq SLO curriculum source adapter (dormant).
 *
 * Source-pattern facade over the SLO curriculum client. Reads one SLO set
 * (renewed kerndoelen, 2006 kerndoelen, examenprogramma's, leerdoelenkaarten)
 * and returns it as one learniq `competency-framework` record plus its
 * `competency` records, parents first, with stable ids, the school years SLO
 * itself names and the CC BY 4.0 attribution. It writes nothing: the write
 * step into learniq is a Synchronization that lands after learniq's
 * `competency-year-scope` (openspec/changes/archive/2026-09-29-slo-kerndoelen-import/design.md, D7).
 *
 * Ships dormant: DI resolves the recorded-fixture mock until
 * `slo.curriculum.feature_flag` is `1`, and the seeded source stays disabled
 * until an operator enters SLO's API key. Logs carry the set key, the root
 * uuid, counts and the client flavour, nothing else.
 *
 * @category Source
 * @package  OCA\Integriq\Sources\Slo
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
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
 */

declare(strict_types=1);

namespace OCA\Integriq\Sources\Slo;

use InvalidArgumentException;
use OCA\Integriq\Adapters\Slo\SloCurriculumClient;
use OCA\Integriq\Adapters\Slo\SloCurriculumMapper;
use OCA\Integriq\Adapters\Slo\SloCurriculumPresetRegistry;
use OCA\Integriq\Adapters\Slo\SloCurriculumTreeWalker;
use OCA\Integriq\Exception\SloCurriculumException;
use OCA\Integriq\Exception\UnknownSloCurriculumSetException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Dormant facade: discover SLO roots, import one framework.
 *
 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
 */
final class SloCurriculumSourceAdapter {
	/**
	 * App id used for IAppConfig look-ups.
	 */
	public const APP_ID = 'integriq';

	/**
	 * App-config key of the dormant flag.
	 */
	public const FLAG_KEY = 'slo.curriculum.feature_flag';

	/**
	 * Base URL of SLO's REST API, for an aggregate framework's sourceRef.
	 */
	public const API_BASE = 'https://opendata.slo.nl/curriculum/api/v1/';

	/**
	 * Most discovery pages one call follows.
	 */
	public const MAX_PAGES = 20;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config App config (dormant flag).
	 * @param LoggerInterface $logger Structured logger.
	 * @param SloCurriculumClient $sloClient Resolved client (mock or live).
	 * @param SloCurriculumPresetRegistry $registry The seeded source template and mapping presets.
	 * @param SloCurriculumTreeWalker $walker Reads and flattens SLO trees.
	 * @param SloCurriculumMapper $mapper Maps nodes to learniq records.
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly LoggerInterface $logger,
		private readonly SloCurriculumClient $sloClient,
		private readonly SloCurriculumPresetRegistry $registry,
		private readonly SloCurriculumTreeWalker $walker,
		private readonly SloCurriculumMapper $mapper,
	) {
	}//end __construct()

	/**
	 * Whether the operator switched the live SLO transport on.
	 *
	 * @return bool True when `slo.curriculum.feature_flag` is `1` or `true`.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-the-client-is-a-mock-by-default-and-live-only-behind-the-flag-req-003
	 */
	public function isActive(): bool {
		$raw = $this->config->getValueString(self::APP_ID, self::FLAG_KEY, '0');
		return ($raw === '1' || strtolower($raw) === 'true');
	}//end isActive()

	/**
	 * Every seeded set profile.
	 *
	 * @return array<int,array<string,mixed>> `{key, label, sourceAuthority, level, framework}` per set.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-a-dormant-slo-source-template-carries-the-set-profiles-and-the-attribution-req-001
	 */
	public function describeSets(): array {
		return $this->registry->describeSets();
	}//end describeSets()

	/**
	 * The roots a set's discovery route lists.
	 *
	 * @param string $setKey The set key.
	 *
	 * @return array<int,array{uuid:string,title:string,status:string|null}> Roots, deprecated and unreleased skipped.
	 *
	 * @throws UnknownSloCurriculumSetException When the set is not seeded.
	 * @throws SloCurriculumException When SLO fails or the page limit is reached.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-roots-are-discovered-through-slos-collection-routes-req-008
	 */
	public function discoverRoots(string $setKey): array {
		$profile = $this->registry->set(setKey: $setKey);
		$path = (string)$profile['discover']['path'];
		$query = (array)$profile['discover']['query'];
		$paged = isset($query['perPage']);

		$roots = [];
		$seen = 0;
		$page = 0;
		do {
			if ($page >= self::MAX_PAGES) {
				throw new SloCurriculumException(
					message: sprintf('SLO listed more than %d pages for %s; discovery stopped.', self::MAX_PAGES, $path)
				);
			}

			$pageQuery = $query;
			if ($paged === true) {
				$pageQuery['page'] = $page;
			}

			$decoded = $this->walker->fetchJson(client: $this->sloClient, path: $path, query: $pageQuery);
			[$items, $total] = $this->collectionItems(decoded: $decoded);
			$seen += count($items);
			$page++;

			foreach ($items as $item) {
				$root = $this->rootOf(item: $item);
				if ($root !== null) {
					$roots[] = $root;
				}
			}
		} while ($paged === true && $items !== [] && $seen < $total);

		return $roots;
	}//end discoverRoots()

	/**
	 * Import one framework: the set's root (or, for an aggregate set, all of
	 * its roots) as one learniq framework with its competencies.
	 *
	 * @param string $setKey The set key, such as `fo-kerndoelen`.
	 * @param string $tenantId The learniq tenant uuid.
	 * @param string|null $rootUuid The SLO root uuid; required for a per-root set, ignored for an aggregate set.
	 * @param array<string,string> $subjectCourseIds SLO vakleergebied uuid or title => learniq Course uuid.
	 *
	 * @return array<string,mixed> setKey, rootUuid, flavour, framework, competencies, attribution, stats.
	 *
	 * @throws InvalidArgumentException When the tenant id, the root or a subject map value is not valid.
	 * @throws UnknownSloCurriculumSetException When the set is not seeded.
	 * @throws SloCurriculumException When SLO fails or a guard limit is reached.
	 *
	 * @spec openspec/specs/slo-curriculum-import/spec.md#requirement-one-framework-per-set-and-root-with-stable-ids-and-attribution-req-007
	 */
	public function importFramework(string $setKey, string $tenantId, ?string $rootUuid = null, array $subjectCourseIds = []): array {
		if ($this->isUuid(value: $tenantId) === false) {
			throw new InvalidArgumentException(sprintf('The tenant id "%s" is not a UUID.', $tenantId));
		}

		$subjects = $this->normaliseSubjects(subjectCourseIds: $subjectCourseIds);
		$profile = $this->registry->set(setKey: $setKey);
		$aggregate = ($profile['framework'] === 'aggregate');
		$loaded = $this->loadRoots(profile: $profile, setKey: $setKey, rootUuid: $rootUuid);
		$roots = $loaded['roots'];
		$rootInfo = $loaded['rootInfo'];
		$rootUuid = $loaded['rootUuid'];

		$walk = $this->walker->walk(roots: $roots, profile: $profile, client: $this->sloClient, rootsAreNodes: $aggregate);
		$attribution = $this->registry->attribution();
		$frameworkUuid = $this->mapper->frameworkUuid(tenantId: $tenantId, setKey: $setKey, rootUuid: $rootUuid);
		$name = trim((string)$profile['namePrefix'] . $rootInfo['title']);

		$framework = $this->mapper->frameworkRecord(
			uuid: $frameworkUuid,
			framework: [
				'name' => $name,
				'sourceAuthority' => (string)$profile['sourceAuthority'],
				'sourceRef' => $loaded['sourceRef'],
				'edition' => $this->edition(profile: $profile, rootInfo: $rootInfo),
				'level' => $profile['level'],
				'description' => trim($name . '. ' . ($attribution['text'] ?? '')),
				'proficiencyLevels' => $this->registry->proficiencyLevels(),
				'tenantId' => $tenantId,
			],
			mapping: $this->registry->frameworkMapping(),
			originId: $loaded['originId']
		);

		$competencies = $this->mapper->competencyRecords(
			nodes: $walk['nodes'],
			context: [
				'frameworkUuid' => $frameworkUuid,
				'tenantId' => $tenantId,
				'yearNiveaus' => $this->registry->yearNiveaus(),
				'subjectCourseIds' => $subjects,
				'subjectFrom' => (string)$profile['subjectFrom'],
				'rootSubjectKeys' => $rootInfo['subjectKeys'],
			],
			mapping: $this->registry->competencyMapping()
		);

		$stats = $walk['stats'];
		$stats['competencies'] = count($competencies);
		$stats['withYears'] = count(
			array_filter($competencies, static fn (array $record): bool => ($record['object']['applicableYears'] ?? []) !== [])
		);

		$this->logger->debug(
			'slo-curriculum.importFramework',
			[
				'set' => $setKey,
				'root' => $rootUuid,
				'flavour' => $this->sloClient->flavour(),
				'active' => $this->isActive(),
				'competencies' => $stats['competencies'],
				'leaves' => $stats['leaves'],
			]
		);

		return [
			'setKey' => $setKey,
			'rootUuid' => $rootUuid,
			'flavour' => $this->sloClient->flavour(),
			'framework' => $framework,
			'competencies' => $competencies,
			'attribution' => $attribution,
			'stats' => $stats,
		];
	}//end importFramework()

	/**
	 * Fetch the trees an import walks.
	 *
	 * @param array<string,mixed> $profile The set profile.
	 * @param string $setKey The set key.
	 * @param string|null $rootUuid The requested root (per-root sets only).
	 *
	 * @return array{roots:array<int,array<string,mixed>>,rootInfo:array<string,mixed>,rootUuid:string|null,sourceRef:string,originId:string}
	 *
	 * @throws InvalidArgumentException When a per-root set gets no root uuid.
	 */
	private function loadRoots(array $profile, string $setKey, ?string $rootUuid): array {
		if ($profile['framework'] === 'aggregate') {
			$roots = [];
			foreach ($this->discoverRoots(setKey: $setKey) as $root) {
				$roots[] = $this->walker->fetchTree(client: $this->sloClient, uuid: $root['uuid']);
			}

			return [
				'roots' => $roots,
				'rootInfo' => ['title' => (string)$profile['label'], 'status' => null, 'versie' => null, 'subjectKeys' => []],
				'rootUuid' => null,
				'sourceRef' => self::API_BASE . ltrim((string)$profile['discover']['path'], '/'),
				'originId' => $setKey,
			];
		}

		if ($rootUuid === null || trim($rootUuid) === '') {
			throw new InvalidArgumentException(
				sprintf('The set "%s" has one framework per SLO root: pass a root uuid from discoverRoots().', $setKey)
			);
		}

		$root = $this->walker->fetchTree(client: $this->sloClient, uuid: $rootUuid);

		return [
			'roots' => [$root],
			'rootInfo' => $this->walker->describeEntity(entity: $root),
			'rootUuid' => $rootUuid,
			'sourceRef' => SloCurriculumMapper::SLO_URI_BASE . $rootUuid,
			'originId' => $rootUuid,
		];
	}//end loadRoots()

	/**
	 * Split a discovery answer into its items and its total count.
	 *
	 * @param array<mixed> $decoded A `{data, count}` envelope or a bare list.
	 *
	 * @return array{0:array<int,mixed>,1:int} Items and total.
	 */
	private function collectionItems(array $decoded): array {
		if (array_is_list($decoded) === true) {
			return [$decoded, count($decoded)];
		}

		$items = ($decoded['data'] ?? []);
		if (is_array($items) === false || array_is_list($items) === false) {
			return [[], 0];
		}

		$total = count($items);
		if (is_int($decoded['count'] ?? null) === true) {
			$total = $decoded['count'];
		}

		return [$items, $total];
	}//end collectionItems()

	/**
	 * One discovered root, or null when it is deprecated, unreleased or has no uuid.
	 *
	 * @param mixed $item One collection item.
	 *
	 * @return array{uuid:string,title:string,status:string|null}|null The root.
	 */
	private function rootOf(mixed $item): ?array {
		if (is_array($item) === false) {
			return null;
		}

		if (($item['deprecated'] ?? false) === true || ($item['unreleased'] ?? false) === true) {
			return null;
		}

		$info = $this->walker->describeEntity(entity: $item);
		if ($info['uuid'] === '') {
			return null;
		}

		return ['uuid' => $info['uuid'], 'title' => $info['title'], 'status' => $info['status']];
	}//end rootOf()

	/**
	 * The framework's edition: the profile's own, else the root's `status` or
	 * `versie` as the profile's `editionFrom` names.
	 *
	 * @param array<string,mixed> $profile The set profile.
	 * @param array<string,mixed> $rootInfo The root's headline facts.
	 *
	 * @return string|null The edition label.
	 */
	private function edition(array $profile, array $rootInfo): ?string {
		if (is_string($profile['edition']) === true && $profile['edition'] !== '') {
			return $profile['edition'];
		}

		$from = $profile['editionFrom'];
		if (is_string($from) === false || isset($rootInfo[$from]) === false) {
			return null;
		}

		return (string)$rootInfo[$from];
	}//end edition()

	/**
	 * Whether a value is an RFC 4122 UUID (the format learniq's `tenant_id`
	 * and Course ids use).
	 *
	 * @param string $value The value.
	 *
	 * @return bool True for a UUID.
	 */
	private function isUuid(string $value): bool {
		return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
	}//end isUuid()

	/**
	 * Normalise and validate the caller's subject map.
	 *
	 * @param array<string,string> $subjectCourseIds Vakleergebied uuid or title => Course uuid.
	 *
	 * @return array<string,string> Lower-cased, trimmed keys => Course uuid.
	 *
	 * @throws InvalidArgumentException When a value is not a UUID.
	 */
	private function normaliseSubjects(array $subjectCourseIds): array {
		$subjects = [];
		foreach ($subjectCourseIds as $key => $courseId) {
			if (is_string($courseId) === false || $this->isUuid(value: $courseId) === false) {
				throw new InvalidArgumentException(
					sprintf('The subject map value for "%s" is not a learniq Course UUID.', (string)$key)
				);
			}

			$normalisedKey = mb_strtolower(trim((string)$key));
			if ($normalisedKey !== '') {
				$subjects[$normalisedKey] = $courseId;
			}
		}

		return $subjects;
	}//end normaliseSubjects()
}//end class
