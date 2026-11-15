<?php

/**
 * Integriq ConnectionAlertService: count failures against the thresholds on
 * sources and synchronizations, and open or clear connection alerts.
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
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use Psr\Log\LoggerInterface;

/**
 * Counts imperatively, notifies declaratively (design D4).
 *
 * The notification dialect has no time window or grouping per source, so this
 * counts. The warning itself is a `created` rule on `connection_alert`: this
 * service writes the alert and OpenRegister's engine sends the notification.
 * An alert stays open until the count falls back, so a source failing all
 * night warns once, not every five minutes.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
 */
class ConnectionAlertService implements ConnectionAlertServiceInterface {

	/**
	 * The register every schema here lives in.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The alert schema.
	 *
	 * @var string
	 */
	public const SCHEMA = 'connection_alert';

	/**
	 * The subjects that carry thresholds, and the field a call log and a run
	 * record name them by.
	 *
	 * @var array<string, array{calls: string, runs: string}>
	 */
	private const SUBJECTS = [
		'source' => ['calls' => 'source', 'runs' => 'sourceId'],
		'synchronization' => ['calls' => 'synchronizationId', 'runs' => 'synchronizationId'],
	];

	/**
	 * Per rule: the records it counts, the subject field (a SUBJECTS key) they
	 * name the subject in, their timestamp field and the further filters.
	 *
	 * @var array<string, array{schema: string, subjectField: string, timeField: string, filters: array<string, string>}>
	 */
	private const RULE_COUNTS = [
		'failedCalls' => ['schema' => 'call_log', 'subjectField' => 'calls', 'timeField' => 'created', 'filters' => []],
		'failedRuns' => [
			'schema' => SynchronizationRunProgressService::SCHEMA,
			'subjectField' => 'runs',
			'timeField' => 'startedAt',
			'filters' => ['status' => 'failed'],
		],
		'invalidObjects' => [
			'schema' => SynchronizationRunProgressService::SCHEMA,
			'subjectField' => 'runs',
			'timeField' => 'startedAt',
			'filters' => [],
		],
	];

	/**
	 * The rules a threshold may set.
	 *
	 * @var array<int, string>
	 */
	public const RULES = ['failedCalls', 'failedRuns', 'invalidObjects'];

	/**
	 * Records read per page.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * The most pages one count reads (ADR-058: every read is bounded).
	 *
	 * @var int
	 */
	private const MAX_PAGES = 20;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService $objectService The OpenRegister object service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Count every threshold and open or clear alerts.
	 *
	 * @param DateTimeImmutable $now The moment to count back from.
	 *
	 * @return array{opened: int, cleared: int} How many alerts opened and cleared.
	 *
	 * @spec openspec/specs/connection-run-monitoring/spec.md#requirement-thresholds-per-source-and-synchronization-open-an-alert-req-crun-004
	 */
	public function evaluate(DateTimeImmutable $now): array {
		$outcome = ['opened' => 0, 'cleared' => 0];

		foreach ($this->checks(now: $now) as $check) {
			$result = $this->check(check: $check);
			if ($result !== null) {
				$outcome[$result]++;
			}
		}

		return $outcome;
	}//end evaluate()

	/**
	 * Every rule with a usable threshold on every subject, read subject type by subject type.
	 *
	 * @param DateTimeImmutable $now The moment to count back from.
	 *
	 * @return iterable<AlertCheck> The checks, each one built when the previous one is done.
	 */
	private function checks(DateTimeImmutable $now): iterable {
		foreach (array_keys(self::SUBJECTS) as $subjectType) {
			foreach ($this->read(schema: $subjectType, filters: []) as $entity) {
				$subject = $entity->getObject();
				$check = new AlertCheck(subjectType: $subjectType, now: $now);
				$check->subjectName = (string)($subject['name'] ?? '');
				$check->subjectId = (string)$entity->getUuid();
				yield from $this->ruleChecks(subject: $check, thresholds: ($subject['alertThresholds'] ?? null));
			}
		}
	}//end checks()

	/**
	 * One check per rule the subject sets a usable threshold for.
	 *
	 * @param AlertCheck $subject    The subject, its rule not yet set.
	 * @param mixed      $thresholds The subject's `alertThresholds`.
	 *
	 * @return iterable<AlertCheck> The checks.
	 */
	private function ruleChecks(AlertCheck $subject, mixed $thresholds): iterable {
		if (is_array($thresholds) === false || $thresholds === []) {
			return;
		}

		foreach (self::RULES as $rule) {
			$threshold = $this->threshold(value: ($thresholds[$rule] ?? null));
			if ($threshold !== null) {
				$check = clone $subject;
				$check->rule = $rule;
				$check->threshold = $threshold;
				yield $check;
			}
		}
	}//end ruleChecks()

	/**
	 * Count one rule for one subject and open or clear its alert.
	 *
	 * @param AlertCheck $check The subject, the rule, its threshold and the moment.
	 *
	 * @return string|null `opened`, `cleared`, or null when nothing changed.
	 */
	private function check(AlertCheck $check): ?string {
		$count = $this->count(check: $check);
		$open = $this->openAlert(subjectId: $check->subjectId, rule: $check->rule);
		if ($open === null) {
			return $this->openWhenAbove(check: $check, count: $count);
		}

		if ($count <= $check->threshold['count']) {
			$this->clear(open: $open, now: $check->now);
			return 'cleared';
		}

		return null;
	}//end check()

	/**
	 * Open an alert when the count is above the threshold and none is open yet.
	 *
	 * @param AlertCheck $check The check, with no open alert.
	 * @param int        $count What it counted.
	 *
	 * @return string|null `opened`, or null when the count stays within the threshold.
	 */
	private function openWhenAbove(AlertCheck $check, int $count): ?string {
		if ($count <= $check->threshold['count']) {
			return null;
		}

		$this->save(alert: $this->openedAlert(check: $check, count: $count), uuid: null);
		return 'opened';
	}//end openWhenAbove()

	/**
	 * The alert a check opens.
	 *
	 * @param AlertCheck $check The check.
	 * @param int        $count What it counted, above the threshold.
	 *
	 * @return array<string, mixed> The alert.
	 */
	private function openedAlert(AlertCheck $check, int $count): array {
		return [
			'subjectType' => $check->subjectType,
			'subject' => $check->subjectId,
			'subjectName' => $check->subjectName,
			'rule' => $check->rule,
			'count' => $count,
			'threshold' => $check->threshold['count'],
			'windowMinutes' => $check->threshold['windowMinutes'],
			'state' => 'open',
			'openedAt' => $check->now->format('c'),
		];
	}//end openedAlert()

	/**
	 * Clear an open alert.
	 *
	 * @param object            $open The open alert entity.
	 * @param DateTimeImmutable $now  The moment it clears.
	 *
	 * @return void
	 */
	private function clear(object $open, DateTimeImmutable $now): void {
		$alert = $open->getObject();
		$alert['state'] = 'cleared';
		$alert['clearedAt'] = $now->format('c');
		$this->save(alert: $alert, uuid: (string)$open->getUuid());
	}//end clear()

	/**
	 * What a check's rule counts; an unknown rule counts invalid objects.
	 *
	 * @param AlertCheck $check The check.
	 *
	 * @return array{schema: string, subjectField: string, timeField: string, filters: array<string, string>} The counted records.
	 */
	private function countedRecords(AlertCheck $check): array {
		return (self::RULE_COUNTS[$check->rule] ?? self::RULE_COUNTS['invalidObjects']);
	}//end countedRecords()

	/**
	 * Count one rule for one subject since the start of its window.
	 *
	 * @param AlertCheck $check The check.
	 *
	 * @return int The count.
	 */
	private function count(AlertCheck $check): int {
		$counted = $this->countedRecords(check: $check);
		$subjectField = self::SUBJECTS[$check->subjectType][$counted['subjectField']];

		return $this->sumSince(check: $check, filters: ([$subjectField => $check->subjectId] + $counted['filters']));
	}//end count()

	/**
	 * Sum the rule's value over the records newer than its window start, newest first, in bounded pages.
	 *
	 * @param AlertCheck           $check   The check.
	 * @param array<string, mixed> $filters The filters naming the subject.
	 *
	 * @return int The sum.
	 */
	private function sumSince(AlertCheck $check, array $filters): int {
		$counted = $this->countedRecords(check: $check);
		$sum = 0;
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$entities = $this->read(schema: $counted['schema'], filters: $filters, sort: $counted['timeField'], page: $page);
			[$pageSum, $older] = $this->sumPage(check: $check, entities: $entities);
			$sum += $pageSum;
			if ($older === true || count($entities) < self::PAGE_SIZE) {
				break;
			}
		}

		return $sum;
	}//end sumSince()

	/**
	 * Sum one page of records, newest first, and say whether it reached past the window.
	 *
	 * @param AlertCheck        $check    The check.
	 * @param array<int, mixed> $entities The page.
	 *
	 * @return array{0: int, 1: bool} The page's sum, and whether it held an older record.
	 */
	private function sumPage(AlertCheck $check, array $entities): array {
		$sum = 0;
		$older = false;
		foreach ($entities as $entity) {
			$value = $this->recordValue(check: $check, record: $entity->getObject());
			if ($value === null) {
				$older = true;
				continue;
			}

			$sum += $value;
		}

		return [$sum, $older];
	}//end sumPage()

	/**
	 * What one record adds, or null when it is older than the window or carries no usable time.
	 *
	 * @param AlertCheck           $check  The check.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return int|null The value, null for a record outside the window.
	 */
	private function recordValue(AlertCheck $check, array $record): ?int {
		$time = strtotime((string)($record[$this->countedRecords(check: $check)['timeField']] ?? ''));
		if ($time === false || $time < $check->since()) {
			return null;
		}

		return $check->valueOf(record: $record);
	}//end recordValue()

	/**
	 * The open alert for a subject and rule, if any.
	 *
	 * @param string $subjectId The subject's id.
	 * @param string $rule The rule.
	 *
	 * @return object|null The alert entity.
	 */
	private function openAlert(string $subjectId, string $rule): ?object {
		$alerts = $this->read(schema: self::SCHEMA, filters: ['subject' => $subjectId, 'rule' => $rule, 'state' => 'open']);

		return ($alerts[0] ?? null);
	}//end openAlert()

	/**
	 * Read one page of a schema.
	 *
	 * @param string $schema The schema.
	 * @param array<string, mixed> $filters The filters.
	 * @param string|null $sort A field to sort newest first by.
	 * @param int $page The page, from 0.
	 *
	 * @return array<int, mixed> The entities.
	 */
	private function read(string $schema, array $filters, ?string $sort = null, int $page = 0): array {
		$config = [
			'filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters),
			'limit' => self::PAGE_SIZE,
			'offset' => ($page * self::PAGE_SIZE),
		];
		if ($sort !== null) {
			$config['sort'] = [$sort => 'DESC'];
		}

		// Unscoped: the threshold job and setup check count failures across all sources, as the system.
		$result = $this->objectService->findAll(config: $config, _rbac: false, _multitenancy: false);

		return array_values(($result['results'] ?? $result));
	}//end read()

	/**
	 * Write an alert. Not silent: the created rule must see a new alert.
	 *
	 * @param array<string, mixed> $alert The alert.
	 * @param string|null $uuid The alert's uuid on update; null opens one.
	 *
	 * @return void
	 */
	private function save(array $alert, ?string $uuid): void {
		try {
			$this->objectService->saveObject(
				object: $alert,
				register: self::REGISTER,
				schema: self::SCHEMA,
				uuid: $uuid,
				_rbac: false,
				// Unscoped: system write of an alert raised by the connection monitor.
				_multitenancy: false,
			);
		} catch (\Throwable $exception) {
			$this->logger->warning(
				'[ConnectionAlertService] could not write a connection alert: ' . $exception->getMessage(),
				['subject' => ($alert['subject'] ?? null), 'rule' => ($alert['rule'] ?? null)]
			);
		}
	}//end save()

	/**
	 * A threshold as the job uses it, or null when it is not set or not usable.
	 *
	 * @param mixed $value The stored threshold.
	 *
	 * @return array{count: int, windowMinutes: int}|null
	 */
	private function threshold(mixed $value): ?array {
		if (is_array($value) === false) {
			return null;
		}

		$count = (int)($value['count'] ?? 0);
		$window = (int)($value['windowMinutes'] ?? 0);
		if ($count < 1 || $window < 1) {
			return null;
		}

		return ['count' => $count, 'windowMinutes' => $window];
	}//end threshold()
}//end class
