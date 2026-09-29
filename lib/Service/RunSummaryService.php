<?php

/**
 * Integriq RunSummaryService: a source's pulls per day.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/integriq
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;

/**
 * Sums a source's synchronization runs per day.
 *
 * The manifest widget dialect counts per day or sums without a bucket, but not
 * several sums per day in one table (design D2), so this reads the run records
 * of one source in the window, newest first in bounded pages, and adds them up.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
 */
class RunSummaryService {

	/**
	 * The longest window one request may ask for, in days.
	 *
	 * @var int
	 */
	public const MAX_WINDOW_DAYS = 31;

	/**
	 * Run records read per page.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 200;

	/**
	 * The most pages one summary reads (ADR-058: every read is bounded).
	 *
	 * @var int
	 */
	private const MAX_PAGES = 25;

	/**
	 * How many of the source's latest runs the answer lists.
	 *
	 * @var int
	 */
	private const LATEST_RUNS = 25;

	/**
	 * The counters summed per day, as the run record names them.
	 *
	 * @var array<int, string>
	 */
	private const COUNTERS = ['found', 'created', 'updated', 'invalid'];

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService The OpenRegister object service.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
	) {
	}//end __construct()

	/**
	 * The source's pulls per day in the window, and its latest runs.
	 *
	 * @param string $sourceId The source.
	 * @param DateTimeImmutable $from The first day of the window.
	 * @param DateTimeImmutable $to The last day of the window.
	 *
	 * @return array{days: array<int, array<string, int|string>>, runs: array<int, array<string, mixed>>}
	 *
	 * @throws InvalidArgumentException When the window runs backwards or is longer than 31 days.
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-its-pulls-per-day-req-crun-002
	 */
	public function summarise(string $sourceId, DateTimeImmutable $from, DateTimeImmutable $to): array {
		$firstDay = $from->format('Y-m-d');
		$lastDay = $to->format('Y-m-d');
		$days = $this->emptyDays(firstDay: $firstDay, lastDay: $lastDay);

		$runs = [];
		foreach ($this->runsInWindow(sourceId: $sourceId, firstDay: $firstDay) as $id => $run) {
			$day = substr((string)($run['startedAt'] ?? ''), 0, 10);
			if (isset($days[$day]) === false) {
				continue;
			}

			$days[$day] = $this->addRun(row: $days[$day], run: $run);
			if (count($runs) < self::LATEST_RUNS) {
				$runs[] = $this->runRow(id: (string)$id, run: $run);
			}
		}

		return ['days' => array_values($days), 'runs' => $runs];
	}//end summarise()

	/**
	 * One zeroed row per day, newest day first.
	 *
	 * @param string $firstDay The first day, Y-m-d.
	 * @param string $lastDay The last day, Y-m-d.
	 *
	 * @return array<string, array<string, int|string>> Rows keyed by day.
	 *
	 * @throws InvalidArgumentException When the window runs backwards or is longer than 31 days.
	 */
	private function emptyDays(string $firstDay, string $lastDay): array {
		$start = new DateTimeImmutable($firstDay);
		$end = new DateTimeImmutable($lastDay);
		if ($end < $start) {
			throw new InvalidArgumentException('The window ends before it starts.');
		}

		$length = ((int)$start->diff($end)->days + 1);
		if ($length > self::MAX_WINDOW_DAYS) {
			throw new InvalidArgumentException(
				sprintf('A summary covers at most %d days; this window has %d.', self::MAX_WINDOW_DAYS, $length)
			);
		}

		$days = [];
		for ($day = $end; $day >= $start; $day = $day->modify('-1 day')) {
			$days[$day->format('Y-m-d')] = [
				'date' => $day->format('Y-m-d'),
				'runs' => 0,
				'succeeded' => 0,
				'failed' => 0,
				'found' => 0,
				'created' => 0,
				'updated' => 0,
				'invalid' => 0,
			];
		}

		return $days;
	}//end emptyDays()

	/**
	 * The source's run records, newest first, until the window's first day.
	 *
	 * @param string $sourceId The source.
	 * @param string $firstDay The first day of the window, Y-m-d.
	 *
	 * @return array<string, array<string, mixed>> Run records keyed by id.
	 */
	private function runsInWindow(string $sourceId, string $firstDay): array {
		$runs = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$result = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => SynchronizationRunProgressService::REGISTER,
						'schema' => SynchronizationRunProgressService::SCHEMA,
						'sourceId' => $sourceId,
					],
					'sort' => ['startedAt' => 'DESC'],
					'limit' => self::PAGE_SIZE,
					'offset' => ($page * self::PAGE_SIZE),
				],
				_rbac: false,
				_multitenancy: false,
			);
			$entities = ($result['results'] ?? $result);

			$reachedStart = false;
			foreach ($entities as $entity) {
				$run = $entity->getObject();
				if (substr((string)($run['startedAt'] ?? ''), 0, 10) < $firstDay) {
					$reachedStart = true;
					continue;
				}

				$runs[(string)$entity->getUuid()] = $run;
			}

			if ($reachedStart === true || count($entities) < self::PAGE_SIZE) {
				break;
			}
		}//end for

		return $runs;
	}//end runsInWindow()

	/**
	 * Add one run to its day's row.
	 *
	 * @param array<string, int|string> $row The day's row.
	 * @param array<string, mixed> $run The run record.
	 *
	 * @return array<string, int|string> The row with the run added.
	 */
	private function addRun(array $row, array $run): array {
		$row['runs'] = ((int)$row['runs'] + 1);
		$status = (string)($run['status'] ?? '');
		if ($status === 'success') {
			$row['succeeded'] = ((int)$row['succeeded'] + 1);
		}

		if ($status === 'failed') {
			$row['failed'] = ((int)$row['failed'] + 1);
		}

		foreach (self::COUNTERS as $counter) {
			$row[$counter] = ((int)$row[$counter] + (int)($run[$counter] ?? 0));
		}

		return $row;
	}//end addRun()

	/**
	 * The fields of a run the source page lists.
	 *
	 * @param string $id The run record's id.
	 * @param array<string, mixed> $run The run record.
	 *
	 * @return array<string, mixed>
	 */
	private function runRow(string $id, array $run): array {
		return [
			'id' => $id,
			'synchronizationId' => ($run['synchronizationId'] ?? null),
			'status' => ($run['status'] ?? null),
			'triggeredBy' => ($run['triggeredBy'] ?? null),
			'startedAt' => ($run['startedAt'] ?? null),
			'finishedAt' => ($run['finishedAt'] ?? null),
			'found' => (int)($run['found'] ?? 0),
			'created' => (int)($run['created'] ?? 0),
			'updated' => (int)($run['updated'] ?? 0),
			'invalid' => (int)($run['invalid'] ?? 0),
			'message' => ($run['message'] ?? null),
		];
	}//end runRow()
}//end class
