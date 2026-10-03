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
class ConnectionAlertService {

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

		foreach (array_keys(self::SUBJECTS) as $subjectType) {
			foreach ($this->read(schema: $subjectType, filters: []) as $entity) {
				$subject = $entity->getObject();
				$thresholds = ($subject['alertThresholds'] ?? null);
				if (is_array($thresholds) === false || $thresholds === []) {
					continue;
				}

				foreach (self::RULES as $rule) {
					$threshold = $this->threshold(value: ($thresholds[$rule] ?? null));
					if ($threshold === null) {
						continue;
					}

					$result = $this->check(
						subjectType: $subjectType,
						subjectId: (string)$entity->getUuid(),
						subjectName: (string)($subject['name'] ?? ''),
						rule: $rule,
						threshold: $threshold,
						now: $now
					);
					if ($result !== null) {
						$outcome[$result]++;
					}
				}
			}//end foreach
		}//end foreach

		return $outcome;
	}//end evaluate()

	/**
	 * Count one rule for one subject and open or clear its alert.
	 *
	 * @param string $subjectType `source` or `synchronization`.
	 * @param string $subjectId The subject's id.
	 * @param string $subjectName The subject's name.
	 * @param string $rule The rule.
	 * @param array{count: int, windowMinutes: int} $threshold The threshold.
	 * @param DateTimeImmutable $now The moment to count back from.
	 *
	 * @return string|null `opened`, `cleared`, or null when nothing changed.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Six facts of one check, all needed to write the alert.
	 */
	private function check(
		string $subjectType,
		string $subjectId,
		string $subjectName,
		string $rule,
		array $threshold,
		DateTimeImmutable $now,
	): ?string {
		$since = $now->modify('-' . $threshold['windowMinutes'] . ' minutes')->getTimestamp();
		$count = $this->count(subjectType: $subjectType, subjectId: $subjectId, rule: $rule, since: $since);
		$open = $this->openAlert(subjectId: $subjectId, rule: $rule);

		if ($count > $threshold['count'] && $open === null) {
			$this->save(
				alert: [
					'subjectType' => $subjectType,
					'subject' => $subjectId,
					'subjectName' => $subjectName,
					'rule' => $rule,
					'count' => $count,
					'threshold' => $threshold['count'],
					'windowMinutes' => $threshold['windowMinutes'],
					'state' => 'open',
					'openedAt' => $now->format('c'),
				],
				uuid: null
			);
			return 'opened';
		}

		if ($count <= $threshold['count'] && $open !== null) {
			$alert = $open->getObject();
			$alert['state'] = 'cleared';
			$alert['clearedAt'] = $now->format('c');
			$this->save(alert: $alert, uuid: (string)$open->getUuid());
			return 'cleared';
		}

		return null;
	}//end check()

	/**
	 * Count one rule for one subject since a moment.
	 *
	 * @param string $subjectType `source` or `synchronization`.
	 * @param string $subjectId The subject's id.
	 * @param string $rule The rule.
	 * @param int $since Unix time to count from.
	 *
	 * @return int The count.
	 */
	private function count(string $subjectType, string $subjectId, string $rule, int $since): int {
		$fields = self::SUBJECTS[$subjectType];
		if ($rule === 'failedCalls') {
			return $this->sumSince(
				schema: 'call_log',
				filters: [$fields['calls'] => $subjectId],
				timeField: 'created',
				since: $since,
				value: static fn (array $call): int => (int)(((int)($call['statusCode'] ?? 0)) >= 400)
			);
		}

		if ($rule === 'failedRuns') {
			return $this->sumSince(
				schema: SynchronizationRunProgressService::SCHEMA,
				filters: [$fields['runs'] => $subjectId, 'status' => 'failed'],
				timeField: 'startedAt',
				since: $since,
				value: static fn (array $run): int => 1
			);
		}

		return $this->sumSince(
			schema: SynchronizationRunProgressService::SCHEMA,
			filters: [$fields['runs'] => $subjectId],
			timeField: 'startedAt',
			since: $since,
			value: static fn (array $run): int => (int)($run['invalid'] ?? 0)
		);
	}//end count()

	/**
	 * Sum a value over the records newer than a moment, newest first, in bounded pages.
	 *
	 * @param string $schema The schema.
	 * @param array<string, mixed> $filters The filters.
	 * @param string $timeField The record's timestamp field.
	 * @param int $since Unix time to sum from.
	 * @param callable(array<string, mixed>): int $value What one record adds.
	 *
	 * @return int The sum.
	 */
	private function sumSince(string $schema, array $filters, string $timeField, int $since, callable $value): int {
		$sum = 0;
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$entities = $this->read(schema: $schema, filters: $filters, sort: $timeField, page: $page);
			$older = false;
			foreach ($entities as $entity) {
				$record = $entity->getObject();
				$time = strtotime((string)($record[$timeField] ?? ''));
				if ($time === false || $time < $since) {
					$older = true;
					continue;
				}

				$sum += $value($record);
			}

			if ($older === true || count($entities) < self::PAGE_SIZE) {
				break;
			}
		}

		return $sum;
	}//end sumSince()

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
