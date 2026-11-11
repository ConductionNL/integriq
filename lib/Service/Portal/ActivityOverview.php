<?php

/**
 * Integriq activity overview.
 *
 * The activity table as a case app's admin page shows it (dossiq VTH): every
 * `dso_activity_mapping` row with when a verzoek last matched it, then the
 * activities no row maps yet (change dso-activity-overview, REQ-DSO-014).
 * Read only: when a row last matched is read off the verzoeken, so intake
 * never writes to the admin table.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Portal
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
 * @spec openspec/changes/dso-activity-overview/specs/dso-omgevingsloket/spec.md#requirement-administrators-read-the-activities-with-when-each-last-arrived-req-dso-014
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Portal;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * The mapping rows with their last arrival, and the unmapped activities.
 *
 * @spec openspec/changes/dso-activity-overview/specs/dso-omgevingsloket/spec.md#requirement-administrators-read-the-activities-with-when-each-last-arrived-req-dso-014
 */
class ActivityOverview implements ActivityOverviewInterface {

	/**
	 * How many of the latest verzoeken are read for the last arrivals.
	 *
	 * @var integer
	 */
	public const SCAN_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ORObjectService               $objectService Reads the verzoeken.
	 * @param ActivityMappingTableInterface $table         Reads the rows.
	 * @param UnmappedActivitiesInterface   $unmapped      Groups the unmapped activities.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly ActivityMappingTableInterface $table,
		private readonly UnmappedActivitiesInterface $unmapped,
	) {

	}//end __construct()

	/**
	 * Every row, active or not, with `lastReceivedAt` and `unmapped: false`,
	 * then one row per unmapped activity with `unmapped: true` and `waiting`.
	 *
	 * @return array{results: array<int, array<string, mixed>>} The rows.
	 *
	 * @spec openspec/changes/dso-activity-overview/tasks.md#task-2
	 */
	public function rows(): array {
		$lastReceived = $this->lastReceivedByRow();

		$rows = [];
		foreach ($this->table->allRows() as $row) {
			$row['lastReceivedAt'] = ($lastReceived[(string)$row['id']] ?? null);
			$row['unmapped'] = false;
			$rows[] = $row;
		}

		foreach ($this->unmapped->list()['activities'] as $activity) {
			$rows[] = self::unmappedRow(activity: $activity);
		}

		return ['results' => $rows];

	}//end rows()

	/**
	 * An activity no row maps yet, as an overview row.
	 *
	 * @param array<string, mixed> $activity One unmapped activity.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/dso-activity-overview/specs/dso-omgevingsloket/spec.md#requirement-administrators-read-the-activities-with-when-each-last-arrived-req-dso-014
	 */
	private static function unmappedRow(array $activity): array {
		return [
			'id' => 'unmapped:' . self::unmappedKey(activity: $activity),
			'imowId' => $activity['imowId'],
			'activityId' => $activity['activityId'],
			'activityName' => $activity['activityName'],
			'caseTypes' => [],
			'unmapped' => true,
			'waiting' => $activity['count'],
			'lastReceivedAt' => $activity['lastSeen'],
		];
	}//end unmappedRow()

	/**
	 * An unmapped activity's key: its imowId, else its activityId.
	 *
	 * @param array<string, mixed> $activity One unmapped activity.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/dso-activity-overview/specs/dso-omgevingsloket/spec.md#requirement-administrators-read-the-activities-with-when-each-last-arrived-req-dso-014
	 */
	private static function unmappedKey(array $activity): string {
		if ($activity['imowId'] === '') {
			return 'activityId:' . $activity['activityId'];
		}

		return 'imowId:' . $activity['imowId'];
	}//end unmappedKey()

	/**
	 * Per mapping row uuid, the newest `receivedAt` of a verzoek one of whose
	 * activities matched it.
	 *
	 * @return array<string, string> The arrivals by row uuid.
	 */
	private function lastReceivedByRow(): array {
		$lastReceived = [];
		foreach ($this->recentVerzoeken() as $entity) {
			$verzoek = (array)$entity->getObject();
			$receivedAt = (string)($verzoek['receivedAt'] ?? '');
			foreach (self::mappedRowIds(verzoek: $verzoek) as $rowId) {
				if (strcmp($receivedAt, (string)($lastReceived[$rowId] ?? '')) > 0) {
					$lastReceived[$rowId] = $receivedAt;
				}
			}
		}

		return $lastReceived;

	}//end lastReceivedByRow()

	/**
	 * The most recent verzoeken, newest first, read without RBAC or multitenancy.
	 *
	 * Anything in the answer that is not an ObjectEntity is left out.
	 *
	 * @return array<ObjectEntity>
	 *
	 * @spec openspec/changes/dso-activity-overview/specs/dso-omgevingsloket/spec.md#requirement-administrators-read-the-activities-with-when-each-last-arrived-req-dso-014
	 */
	private function recentVerzoeken(): array {
		$result = $this->objectService->findAll(
			config: [
				'filters' => ['register' => ActivityMappingTableInterface::REGISTER, 'schema' => 'dso_verzoek'],
				'limit' => self::SCAN_LIMIT,
				'sort' => ['receivedAt' => 'DESC'],
			],
			_rbac: false,
			_multitenancy: false
		);

		return array_filter(
			(array)($result['results'] ?? $result),
			static fn (mixed $entity): bool => $entity instanceof ObjectEntity
		);
	}//end recentVerzoeken()

	/**
	 * The mapping row ids a verzoek's mapped activities point at, in order.
	 *
	 * @param array<string, mixed> $verzoek The verzoek.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/dso-activity-overview/specs/dso-omgevingsloket/spec.md#requirement-administrators-read-the-activities-with-when-each-last-arrived-req-dso-014
	 */
	private static function mappedRowIds(array $verzoek): array {
		$rowIds = [];
		foreach ((array)($verzoek['mappedActivities'] ?? []) as $entry) {
			$rowId = (string)($entry['mappingRow'] ?? '');
			if (is_array($entry) === true && ($entry['mapped'] ?? false) === true && $rowId !== '') {
				$rowIds[] = $rowId;
			}
		}

		return $rowIds;
	}//end mappedRowIds()
}//end class
