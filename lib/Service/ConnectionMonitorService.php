<?php

/**
 * Integriq ConnectionMonitorService: how one source performed in the last 24
 * hours, from integriq's own call log, and when its client certificate expires.
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
 * @spec openspec/specs/connection-run-monitoring/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IL10N;
use Throwable;

/**
 * Generic per source: the page that shows it names the source in its configuration.
 *
 * @spec openspec/specs/connection-run-monitoring/spec.md
 */
class ConnectionMonitorService implements ConnectionMonitorServiceInterface {

	/**
	 * The register integriq keeps its sources and call log in.
	 */
	private const REGISTER = 'integriq';

	/**
	 * A call slower than this, in milliseconds, counts as slow.
	 */
	private const SLOW_MS = 1000;

	/**
	 * Rows read per page, and the most pages one report reads.
	 */
	private const PAGE_SIZE = 500;

	private const MAX_PAGES = 40;

	/**
	 * Constructor.
	 *
	 * @param OrObjectService             $objectService Reads sources and the call log.
	 * @param ConnectionCertificateReader $certificates  Reads the source's certificate expiry.
	 * @param IL10N                       $l10n          Translates the labels.
	 */
	public function __construct(
		private readonly OrObjectService $objectService,
		private readonly ConnectionCertificateReader $certificates,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The monitor report for one source, by uuid or slug.
	 *
	 * @param string            $sourceRef The source's uuid or slug.
	 * @param DateTimeImmutable $now       The end of the reporting period.
	 *
	 * @return array<string, mixed>|null The report, or null when no such source exists.
	 *
	 * @spec openspec/changes/brp-connection-monitor-from-pipelinq/specs/connection-run-monitoring/spec.md#requirement-a-source-shows-how-it-performed-in-the-last-24-hours-req-crun-006
	 */
	public function report(string $sourceRef, DateTimeImmutable $now): ?array {
		$source = $this->findSource(sourceRef: $sourceRef);
		if ($source === null) {
			return null;
		}

		$data = ($source->getObject() ?? []);
		$from = $now->modify('-24 hours');

		return [
			'source' => [
				'id' => $source->getUuid(),
				'name' => (string)($data['name'] ?? $source->getUuid()),
				'slug' => ($data['slug'] ?? null),
			],
			'period' => [
				'from' => $from->format('c'),
				'to' => $now->format('c'),
				'generatedAt' => $now->format('c'),
			],
			'performance' => $this->performance(sourceId: (string)$source->getUuid(), since: $from->getTimestamp()),
			'certificate' => $this->certificates->certificate(data: $data, now: $now),
		];
	}//end report()

	/**
	 * Aggregate the source's calls since a moment.
	 *
	 * @param string  $sourceId The source uuid.
	 * @param integer $since    Unix time the period starts.
	 *
	 * @return array<string, mixed> Calls, errors by kind, slowest and average duration.
	 */
	private function performance(string $sourceId, int $since): array {
		$calls = $this->callsSince(sourceId: $sourceId, since: $since);
		$durations = [];
		$errors = ['timeout' => 0, 'client' => 0, 'server' => 0, 'tls' => 0];
		foreach ($calls as $call) {
			// CallRecorder and the dispatchers write durationMs; CallService keeps it in response.responseTime.
			$duration = ($call['durationMs'] ?? ($call['response']['responseTime'] ?? null));
			if (is_numeric($duration) === true) {
				$durations[] = (int)round((float)$duration);
			}

			$kind = $this->errorKind(call: $call);
			if ($kind !== null) {
				$errors[$kind]++;
			}
		}

		$slowest = 0;
		if ($durations !== []) {
			$slowest = max($durations);
		}

		$slow = count(array_filter($durations, static fn (int $duration): bool => $duration > self::SLOW_MS));
		$timed = count($durations);
		$durationSum = array_sum($durations);
		$calls = count($calls);

		$errorCount = array_sum($errors);
		$average = null;
		if ($timed > 0) {
			$average = (int)round($durationSum / $timed);
		}

		return [
			'calls' => $calls,
			'errors' => $errorCount,
			'errorsByKind' => $errors,
			'errorSummary' => $this->errorSummary(errors: $errors, total: $errorCount),
			'slowestMs' => $slowest,
			'slowestSeconds' => round($slowest / 1000, 1),
			'slowCount' => $slow,
			'slowSummary' => $this->l10n->n('%n call slower than 1 s', '%n calls slower than 1 s', $slow),
			'averageMs' => $average,
		];
	}//end performance()

	/**
	 * The source's call log entries since a moment, newest first, paged.
	 *
	 * @param string  $sourceId The source uuid.
	 * @param integer $since    Unix time the period starts.
	 *
	 * @return array<int, array<string, mixed>> The calls in the period.
	 */
	private function callsSince(string $sourceId, int $since): array {
		$calls = [];
		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$rows = $this->read(schema: 'call_log', filters: ['source' => $sourceId], page: $page);
			$older = false;
			foreach ($rows as $row) {
				$call = ($row->getObject() ?? []);
				$time = strtotime((string)($call['created'] ?? ''));
				if ($time === false || $time < $since) {
					$older = true;
					continue;
				}

				$calls[] = $call;
			}

			if ($older === true || count($rows) < self::PAGE_SIZE) {
				break;
			}
		}

		return $calls;
	}//end callsSince()

	/**
	 * The kind of failure one call had, or null when it succeeded.
	 *
	 * @param array<string, mixed> $call The call log entry.
	 *
	 * @return string|null `timeout`, `tls`, `client` or `server`.
	 */
	private function errorKind(array $call): ?string {
		$status = (int)($call['statusCode'] ?? 0);
		$message = strtolower((string)($call['statusMessage'] ?? ''));

		if ($status >= 200 && $status < 400) {
			return null;
		}

		if ($status >= 500) {
			return 'server';
		}

		if ($status >= 400) {
			return 'client';
		}

		if (preg_match('/ssl|tls|certificate/', $message) === 1) {
			return 'tls';
		}

		return 'timeout';
	}//end errorKind()

	/**
	 * One line naming the kinds of error.
	 *
	 * @param array<string, int> $errors Errors per kind.
	 * @param integer            $total  All errors.
	 *
	 * @return string The line, empty without errors.
	 */
	private function errorSummary(array $errors, int $total): string {
		if ($total === 0) {
			return '';
		}

		$labels = [
			'timeout' => $this->l10n->t('time-outs'),
			'client' => $this->l10n->t('client errors'),
			'server' => $this->l10n->t('server errors'),
			'tls' => $this->l10n->t('TLS errors'),
		];
		$present = array_filter($errors);
		if (count($present) === 1) {
			return $this->l10n->t('all %s', [$labels[array_key_first($present)]]);
		}

		$parts = [];
		foreach ($present as $kind => $count) {
			$parts[] = $count . ' ' . $labels[$kind];
		}

		return implode(', ', $parts);
	}//end errorSummary()

	/**
	 * Find a source by uuid, else by slug.
	 *
	 * @param string $sourceRef The uuid or slug.
	 *
	 * @return ObjectEntity|null The source.
	 */
	private function findSource(string $sourceRef): ?ObjectEntity {
		try {
			$found = $this->objectService->find(
				id: $sourceRef,
				register: self::REGISTER,
				schema: 'source',
				_rbac: false,
				_multitenancy: false
			);
			if ($found instanceof ObjectEntity) {
				return $found;
			}
		} catch (Throwable) {
			// Not a uuid of a source; try the slug.
		}

		foreach ($this->read(schema: 'source', filters: ['slug' => $sourceRef], page: 0) as $entity) {
			if ((($entity->getObject() ?? [])['slug'] ?? null) === $sourceRef) {
				return $entity;
			}
		}

		return null;
	}//end findSource()

	/**
	 * Read one page of a schema, newest first.
	 *
	 * @param string               $schema  The schema slug.
	 * @param array<string, mixed> $filters Field filters.
	 * @param integer              $page    The page, from 0.
	 *
	 * @return ObjectEntity[] The objects.
	 */
	private function read(string $schema, array $filters, int $page): array {
		$result = $this->objectService->findAll(
			config: [
				'filters' => array_merge(['register' => self::REGISTER, 'schema' => $schema], $filters),
				'limit' => self::PAGE_SIZE,
				'offset' => ($page * self::PAGE_SIZE),
				'sort' => ['created' => 'DESC'],
			],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(array_filter(($result['results'] ?? $result), static fn ($item): bool => $item instanceof ObjectEntity));
	}//end read()
}//end class
