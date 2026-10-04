<?php

/**
 * Integriq DSO Unmapped Activities.
 *
 * Lists the DSO activities that arrived on verzoeken and that no active
 * `dso_activity_mapping` row maps, with how often and when each was last
 * seen (change dso-activity-mapping-table, design D5, REQ-DSO-012). It
 * replaces the "Load default mappings" button: there is no public list to
 * load, so the verzoeken a gemeente really receives show what to map.
 *
 * OpenRegister's `x-openregister-aggregations` cannot do this grouping: its
 * `groupBy` accepts declared top-level properties only, and the identifiers
 * sit inside the `mappedActivities` list. So the grouping happens here.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Dso
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-administrators-maintain-the-activity-table-on-the-admin-settings-page-req-dso-012
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * Groups the unmapped activities of recent verzoeken by identifier.
 *
 * @spec openspec/changes/dso-activity-mapping-table/specs/dso-omgevingsloket/spec.md#requirement-administrators-maintain-the-activity-table-on-the-admin-settings-page-req-dso-012
 */
class DsoUnmappedActivities {

	/**
	 * How many of the latest flagged verzoeken are read.
	 *
	 * @var integer
	 */
	public const SCAN_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ORObjectService   $objectService Reads the verzoeken.
	 * @param DsoActivityTable  $table         Reads the active rows.
	 * @param DsoActivityMapper $mapper        Tells whether a row now maps an activity.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly DsoActivityTable $table,
		private readonly DsoActivityMapper $mapper,
	) {

	}//end __construct()

	/**
	 * The unmapped activities, most often seen first.
	 *
	 * An activity a row was added for after its verzoek arrived is left out.
	 * An activity without imowId or activityId cannot be mapped and is only
	 * counted. A verzoek written before the STAM identifiers carries `code`,
	 * which is read as the activityId.
	 *
	 * @return array{activities: array<int, array<string, mixed>>, withoutIdentifier: int, scanned: int, limit: int} The list.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-4.2
	 */
	public function list(): array {
		$index = $this->mapper->index(rows: $this->table->activeRows());
		$verzoeken = $this->flaggedVerzoeken();

		$groups = [];
		$withoutIdentifier = 0;
		foreach ($verzoeken as $verzoek) {
			$seenAt = (string)($verzoek['receivedAt'] ?? '');
			foreach ((array)($verzoek['mappedActivities'] ?? []) as $entry) {
				if (is_array($entry) === false || ($entry['mapped'] ?? false) === true) {
					continue;
				}

				$activity = $this->activity(entry: $entry);
				if ($this->mapper->isMapped(activity: $activity, index: $index) === true) {
					continue;
				}

				$key = $this->key(activity: $activity);
				if ($key === null) {
					$withoutIdentifier++;
					continue;
				}

				$groups[$key] = $this->count(group: ($groups[$key] ?? null), activity: $activity, seenAt: $seenAt);
			}
		}//end foreach

		$activities = array_values($groups);
		usort(
			$activities,
			static fn (array $one, array $other): int => [$other['count'], $other['lastSeen']] <=> [$one['count'], $one['lastSeen']]
		);

		return [
			'activities' => $activities,
			'withoutIdentifier' => $withoutIdentifier,
			'scanned' => count($verzoeken),
			'limit' => self::SCAN_LIMIT,
		];

	}//end list()

	/**
	 * The latest verzoeken flagged `activityUnmapped`, newest first.
	 *
	 * @return array<int, array<string, mixed>> Their data.
	 */
	private function flaggedVerzoeken(): array {
		$result = $this->objectService->findAll(
			config: [
				'filters' => ['register' => DsoActivityTable::REGISTER, 'schema' => 'dso_verzoek', 'activityUnmapped' => true],
				'limit' => self::SCAN_LIMIT,
				'sort' => ['receivedAt' => 'DESC'],
			],
			_rbac: false,
			_multitenancy: false
		);

		$verzoeken = [];
		foreach ((array)($result['results'] ?? $result) as $entity) {
			if ($entity instanceof ObjectEntity === true && (($entity->getObject()['activityUnmapped'] ?? false) === true)) {
				$verzoeken[] = (array)$entity->getObject();
			}
		}

		return $verzoeken;

	}//end flaggedVerzoeken()

	/**
	 * An entry in the shape the mapper matches on.
	 *
	 * @param array<string, mixed> $entry The `mappedActivities` entry.
	 *
	 * @return array<string, mixed> The activity.
	 */
	private function activity(array $entry): array {
		$activity = [
			'imowId' => trim((string)($entry['imowId'] ?? '')),
			'activityId' => trim((string)($entry['activityId'] ?? ($entry['code'] ?? ''))),
			'activityName' => trim((string)($entry['activityName'] ?? ($entry['description'] ?? ''))),
		];
		if (is_array($entry['underlying'] ?? null) === true) {
			$activity['underlying'] = $entry['underlying'];
		}

		return $activity;

	}//end activity()

	/**
	 * The grouping key: the imowId, else the activityId, else null.
	 *
	 * @param array<string, mixed> $activity The activity.
	 *
	 * @return string|null The key.
	 */
	private function key(array $activity): ?string {
		if ($activity['imowId'] !== '') {
			return 'imowId:' . $activity['imowId'];
		}

		if ($activity['activityId'] !== '') {
			return 'activityId:' . $activity['activityId'];
		}

		return null;

	}//end key()

	/**
	 * Add one sighting to a group.
	 *
	 * @param array<string, mixed>|null $group    The group so far, or null.
	 * @param array<string, mixed>      $activity The activity seen.
	 * @param string                    $seenAt   When its verzoek arrived.
	 *
	 * @return array<string, mixed> The group.
	 */
	private function count(?array $group, array $activity, string $seenAt): array {
		if ($group === null) {
			$group = [
				'imowId' => $activity['imowId'],
				'activityId' => $activity['activityId'],
				'activityName' => $activity['activityName'],
				'count' => 0,
				'lastSeen' => '',
			];
		}

		$group['count']++;
		foreach (['activityId', 'activityName'] as $field) {
			if ($group[$field] === '' && $activity[$field] !== '') {
				$group[$field] = $activity[$field];
			}
		}

		if (strcmp($seenAt, (string)$group['lastSeen']) > 0) {
			$group['lastSeen'] = $seenAt;
		}

		return $group;

	}//end count()
}//end class
