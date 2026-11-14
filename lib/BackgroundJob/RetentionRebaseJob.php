<?php

/**
 * Integriq Retention Rebase Job.
 *
 * Applies changed log retention to logs that already exist: for each log kind
 * it was queued for, it pages through that kind's OpenRegister log objects and
 * sets `expires` from `created` plus the kind's retention now, the success
 * retention for a log its schema counts as a success. One run handles a
 * bounded number of pages and queues the next run for the rest, so a large
 * `call_log` never holds a worker for long. Progress is kept in the
 * `retention_rebase` app config key, which the admin page reads.
 *
 * It replaces the SQL rebase on the legacy `openconnector_*` tables, which
 * the logs no longer live in.
 *
 * @category BackgroundJob
 * @package  OCA\Integriq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
 */

declare(strict_types=1);

namespace OCA\Integriq\BackgroundJob;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Integriq\Service\RetentionSettings;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recomputes `expires` on existing log objects, a bounded number of pages per run.
 *
 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
 */
class RetentionRebaseJob extends QueuedJob {

	/**
	 * The app config key progress is kept under.
	 */
	public const PROGRESS_KEY = 'retention_rebase';

	/**
	 * Objects read per page.
	 */
	public const PAGE_SIZE = 100;

	/**
	 * Pages one run handles before it queues the next run.
	 */
	public const PAGES_PER_RUN = 10;

	/**
	 * The log kinds a rebase can apply to, with the schema their logs live in.
	 * Event messages carry no expiry, so there is nothing to rebase on them.
	 *
	 * @var array<string,string>
	 */
	public const SCHEMAS = [
		'callLog' => 'call_log',
		'jobLog' => 'job_log',
		'syncLog' => 'synchronization_log',
		'syncContractLog' => 'synchronization_contract_log',
	];

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory     $time          The clock.
	 * @param ORObjectService  $objectService Reads and writes the log objects.
	 * @param IAppConfig       $appConfig     The retention and the progress.
	 * @param IJobList         $jobList       Queues the next run.
	 * @param LoggerInterface  $logger        Records a page that could not be saved.
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ORObjectService $objectService,
		private readonly IAppConfig $appConfig,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Handle up to PAGES_PER_RUN pages, then queue the next run or finish.
	 *
	 * @param mixed $argument `kinds` (log kinds), `kind` (index into kinds) and `offset`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	protected function run($argument): void {
		$cursor = self::cursorOf(argument: $argument);
		if ($cursor['kinds'] === []) {
			return;
		}

		$this->runPages(cursor: $cursor, progress: array_merge($this->progress(), ['status' => 'running']));
	}//end run()

	/**
	 * Rebase up to PAGES_PER_RUN pages from the cursor, then queue the next run or finish.
	 *
	 * @param array{kinds: list<string>, kind: int, offset: int} $cursor   Where to start.
	 * @param array<string,mixed>                                 $progress The progress so far.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	private function runPages(array $cursor, array $progress): void {
		$retention = new RetentionSettings(appConfig: $this->appConfig);
		for ($page = 0; $page < self::PAGES_PER_RUN; $page++) {
			if ($cursor['kind'] >= count($cursor['kinds'])) {
				$this->finish(progress: $progress);
				return;
			}

			$this->runPage(cursor: $cursor, progress: $progress, retention: $retention);
		}

		$this->saveProgress(progress: $progress);
		$this->jobList->add(self::class, $cursor);
	}//end runPages()

	/**
	 * Rebase the page the cursor points at, then move the cursor on.
	 *
	 * @param array{kinds: list<string>, kind: int, offset: int} $cursor    The cursor, moved on.
	 * @param array<string,mixed>                                 $progress The progress, counted on.
	 * @param RetentionSettings                                   $retention The retention settings.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	private function runPage(array &$cursor, array &$progress, RetentionSettings $retention): void {
		$kind = $cursor['kinds'][$cursor['kind']];
		$rows = $this->page(schema: self::SCHEMAS[$kind], offset: $cursor['offset']);
		$progress['processed'] = (int)($progress['processed'] ?? 0) + count($rows);
		$progress['updated'] = (int)($progress['updated'] ?? 0) + $this->rebasePage(
			rows: $rows,
			retention: ['kind' => $kind, 'error' => $retention->get(kind: $kind), 'success' => $retention->get(kind: 'success')]
		);
		$progress['current'] = $kind;

		$cursor['offset'] += self::PAGE_SIZE;
		if (count($rows) < self::PAGE_SIZE) {
			$cursor['kind']++;
			$cursor['offset'] = 0;
		}
	}//end runPage()

	/**
	 * Mark the rebase done and save the progress.
	 *
	 * @param array<string,mixed> $progress The progress so far.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	private function finish(array $progress): void {
		$progress['status'] = 'done';
		$progress['finishedAt'] = $this->time->getDateTime()->format(DateTimeInterface::ATOM);
		$this->saveProgress(progress: $progress);
	}//end finish()

	/**
	 * Set `expires` on the rows whose expiry the retention moves.
	 *
	 * @param array<int,mixed>    $rows      The page of log objects.
	 * @param array<string,mixed> $retention `kind`, and the `error` and `success` retention in milliseconds.
	 *
	 * @return int How many logs were saved with a new expiry.
	 */
	private function rebasePage(array $rows, array $retention): int {
		$updated = 0;
		foreach ($rows as $row) {
			if (($row instanceof ObjectEntity) === true && $this->rebaseRow(row: $row, retention: $retention) === true) {
				$updated++;
			}
		}

		return $updated;
	}//end rebasePage()

	/**
	 * Set `expires` on one log when its retention moves it.
	 *
	 * @param ObjectEntity        $row       The log object.
	 * @param array<string,mixed> $retention `kind`, and the `error` and `success` retention in milliseconds.
	 *
	 * @return bool Whether the log was saved with a new expiry.
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	private function rebaseRow(ObjectEntity $row, array $retention): bool {
		$object = $row->getObject();
		$retentionMs = $retention['error'];
		if (self::isSuccess(kind: $retention['kind'], object: $object) === true) {
			$retentionMs = $retention['success'];
		}

		$expires = self::expiresFor(created: (string)($object['created'] ?? ''), retentionMs: $retentionMs);
		if ($expires === false || $expires === ($object['expires'] ?? null)) {
			return false;
		}

		$object['expires'] = $expires;
		return $this->saveExpiry(row: $row, object: $object, kind: $retention['kind']);
	}//end rebaseRow()

	/**
	 * Save a log with its new expiry; a failed save is logged, not thrown.
	 *
	 * @param ObjectEntity        $row    The log object.
	 * @param array<string,mixed> $object The log data with the new expiry.
	 * @param string              $kind   The log kind.
	 *
	 * @return bool Whether it was saved.
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	private function saveExpiry(ObjectEntity $row, array $object, string $kind): bool {
		try {
			$this->persist(row: $row, object: $object, kind: $kind);
			return true;
		} catch (Throwable $exception) {
			$this->logger->warning(
				'[RetentionRebaseJob] could not save the new expiry of ' . $kind . ' ' . $row->getUuid(),
				['exception' => $exception->getMessage()]
			);
			return false;
		}
	}//end saveExpiry()

	/**
	 * Save a log object in its schema, outside RBAC and multitenancy and without events.
	 *
	 * @param ObjectEntity        $row    The log object.
	 * @param array<string,mixed> $object The log data.
	 * @param string              $kind   The log kind.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	private function persist(ObjectEntity $row, array $object, string $kind): void {
		$this->objectService->saveObject(
			object: $object,
			register: 'integriq',
			schema: self::SCHEMAS[$kind],
			uuid: (string)$row->getUuid(),
			_rbac: false,
			_multitenancy: false,
			silent: true,
		);
	}//end persist()

	/**
	 * One page of a schema's log objects, oldest offset first.
	 *
	 * @param string $schema The schema slug.
	 * @param int    $offset Where the page starts.
	 *
	 * @return array<int,mixed> The rows.
	 */
	private function page(string $schema, int $offset): array {
		$config = ['filters' => ['register' => 'integriq', 'schema' => $schema], 'limit' => self::PAGE_SIZE, 'offset' => $offset];
		$found = $this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false);
		$rows = ($found['results'] ?? $found);
		if (is_array($rows) === false) {
			return [];
		}

		return array_values($rows);
	}//end page()

	/**
	 * The expiry a log gets: `created` plus the retention, null to keep it
	 * indefinitely (retention 0), or false when `created` cannot be read.
	 *
	 * @param string $created     The log's creation time.
	 * @param int    $retentionMs The retention in milliseconds.
	 *
	 * @return string|null|false
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	public static function expiresFor(string $created, int $retentionMs): string|null|false {
		if (trim($created) === '') {
			return false;
		}

		try {
			$from = new DateTimeImmutable($created);
		} catch (Throwable) {
			return false;
		}

		if ($retentionMs === 0) {
			return null;
		}

		return $from->modify('+' . intdiv($retentionMs, 1000) . ' seconds')->format('c');
	}//end expiresFor()

	/**
	 * Whether a log is one its schema keeps for the success retention, by the
	 * rule its `x-openregister-archival` block declares.
	 *
	 * @param string              $kind   The log kind.
	 * @param array<string,mixed> $object The log.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	public static function isSuccess(string $kind, array $object): bool {
		return match ($kind) {
			'callLog' => is_numeric($object['statusCode'] ?? null) === true && (int)$object['statusCode'] < 400,
			'jobLog' => ($object['level'] ?? null) === 'info',
			'syncLog' => empty($object['result']['errors'] ?? null) === true,
			'syncContractLog' => in_array($object['targetResult'] ?? null, ['created', 'updated', 'skipped'], true),
			default => false,
		};
	}//end isSuccess()

	/**
	 * The cursor in a job argument, with unknown kinds dropped.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return array{kinds: list<string>, kind: int, offset: int}
	 */
	private static function cursorOf(mixed $argument): array {
		if (is_array($argument) === false) {
			$argument = [];
		}

		return [
			'kinds' => self::kindsOf(kinds: ($argument['kinds'] ?? [])),
			'kind' => max(0, (int)($argument['kind'] ?? 0)),
			'offset' => max(0, (int)($argument['offset'] ?? 0)),
		];
	}//end cursorOf()

	/**
	 * The known log kinds in a job argument's `kinds`, in their order.
	 *
	 * @param mixed $kinds The `kinds` of a job argument.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/platform-admin-defaults/specs/logs-and-statistics/spec.md#requirement-changed-retention-can-be-applied-to-existing-logs-req-adef-003
	 */
	private static function kindsOf(mixed $kinds): array {
		return array_values(
			array_filter(
				(array)$kinds,
				static fn ($kind): bool => is_string($kind) === true && isset(self::SCHEMAS[$kind]) === true
			)
		);
	}//end kindsOf()

	/**
	 * The progress so far.
	 *
	 * @return array<string,mixed>
	 */
	private function progress(): array {
		$decoded = json_decode($this->appConfig->getValueString(app: 'integriq', key: self::PROGRESS_KEY, default: ''), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end progress()

	/**
	 * Keep the progress for the admin page.
	 *
	 * @param array<string,mixed> $progress The progress.
	 *
	 * @return void
	 */
	private function saveProgress(array $progress): void {
		$this->appConfig->setValueString(app: 'integriq', key: self::PROGRESS_KEY, value: (string)json_encode($progress));
	}//end saveProgress()
}//end class
