<?php

/**
 * Integriq DSO Activity Overview.
 *
 * The activity table as a case app's admin page shows it (dossiq VTH): every
 * `dso_activity_mapping` row with when a verzoek last matched it, then the
 * activities no row maps yet (change dso-activity-overview, REQ-DSO-014).
 * Read only: when a row last matched is read off the verzoeken, so intake
 * never writes to the admin table.
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
 * @spec openspec/changes/dso-activity-overview/specs/dso-omgevingsloket/spec.md#requirement-administrators-read-the-activities-with-when-each-last-arrived-req-dso-014
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Dso;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * The mapping rows with their last arrival, and the unmapped activities.
 *
 * @spec openspec/changes/dso-activity-overview/specs/dso-omgevingsloket/spec.md#requirement-administrators-read-the-activities-with-when-each-last-arrived-req-dso-014
 */
class DsoActivityOverview {

	/**
	 * How many of the latest verzoeken are read for the last arrivals.
	 *
	 * @var integer
	 */
	public const SCAN_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param ORObjectService       $objectService Reads the verzoeken.
	 * @param DsoActivityTable      $table         Reads the rows.
	 * @param DsoUnmappedActivities $unmapped      Groups the unmapped activities.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly DsoActivityTable $table,
		private readonly DsoUnmappedActivities $unmapped,
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
			$key = 'imowId:' . $activity['imowId'];
			if ($activity['imowId'] === '') {
				$key = 'activityId:' . $activity['activityId'];
			}

			$rows[] = [
				'id' => 'unmapped:' . $key,
				'imowId' => $activity['imowId'],
				'activityId' => $activity['activityId'],
				'activityName' => $activity['activityName'],
				'caseTypes' => [],
				'unmapped' => true,
				'waiting' => $activity['count'],
				'lastReceivedAt' => $activity['lastSeen'],
			];
		}

		return ['results' => $rows];

	}//end rows()

	/**
	 * Per mapping row uuid, the newest `receivedAt` of a verzoek one of whose
	 * activities matched it.
	 *
	 * @return array<string, string> The arrivals by row uuid.
	 */
	private function lastReceivedByRow(): array {
		$result = $this->objectService->findAll(
			config: [
				'filters' => ['register' => DsoActivityTable::REGISTER, 'schema' => 'dso_verzoek'],
				'limit' => self::SCAN_LIMIT,
				'sort' => ['receivedAt' => 'DESC'],
			],
			_rbac: false,
			_multitenancy: false
		);

		$lastReceived = [];
		foreach ((array)($result['results'] ?? $result) as $entity) {
			if ($entity instanceof ObjectEntity === false) {
				continue;
			}

			$verzoek = (array)$entity->getObject();
			$receivedAt = (string)($verzoek['receivedAt'] ?? '');
			foreach ((array)($verzoek['mappedActivities'] ?? []) as $entry) {
				$rowId = (string)($entry['mappingRow'] ?? '');
				if (is_array($entry) === false || ($entry['mapped'] ?? false) !== true || $rowId === '') {
					continue;
				}

				if (strcmp($receivedAt, (string)($lastReceived[$rowId] ?? '')) > 0) {
					$lastReceived[$rowId] = $receivedAt;
				}
			}
		}

		return $lastReceived;

	}//end lastReceivedByRow()
}//end class
