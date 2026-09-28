<?php

/**
 * Integriq exchange read model.
 *
 * What an app reads about its own exchange jobs and their rejections.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Exchange;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;

/**
 * Read-only projection over exchange `job`, `job_log` and
 * `sync_item_dead_letter` rows (design D8).
 *
 * Every read is filtered on the owning app and bounded (ADR-058). Nothing
 * here carries personal data, because nothing it reads does.
 *
 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
 */
class ExchangeReadModel {

	public const MAX_LIMIT = 200;

	/**
	 * How many open rejections one list call counts at most.
	 *
	 * @var int
	 */
	private const OPEN_REJECTION_SCAN = 1000;

	/**
	 * Constructor.
	 *
	 * @param ORObjectService            $objectService OpenRegister object access.
	 * @param ExchangeTargetCatalogue    $targets       Target labels.
	 * @param ExchangeTargetDispatcher   $dispatcher    Which targets are handled.
	 * @param ExchangeErrorCodeCatalogue $codes         Code labels.
	 */
	public function __construct(
		private readonly ORObjectService $objectService,
		private readonly ExchangeTargetCatalogue $targets,
		private readonly ExchangeTargetDispatcher $dispatcher,
		private readonly ExchangeErrorCodeCatalogue $codes,
	) {

	}//end __construct()

	/**
	 * List an app's exchange jobs.
	 *
	 * @param string               $ownerApp The owning app.
	 * @param array<string,string> $filters  Optional `target`, `status`, `ownerRef`.
	 * @param int                  $limit    Page size, capped at 200.
	 * @param int                  $offset   Page offset.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int} The page.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	public function listJobs(string $ownerApp, array $filters = [], int $limit = 50, int $offset = 0): array {
		$orFilters = [
			'register' => ExchangeJobService::REGISTER,
			'schema' => ExchangeJobService::SCHEMA_JOB,
			'ownerApp' => $ownerApp,
		];
		foreach (['target' => 'exchangeTarget', 'status' => 'exchangeStatus', 'ownerRef' => 'ownerRef'] as $key => $property) {
			if (($filters[$key] ?? '') !== '') {
				$orFilters[$property] = $filters[$key];
			}
		}

		$matches = $this->find(filters: $orFilters, limit: $limit, offset: $offset);
		$open = $this->openRejectionCounts(ownerApp: $ownerApp);

		$rows = [];
		foreach ($matches['results'] as $job) {
			$rows[] = $this->jobRow(job: $job, openRejections: ($open[$job->getUuid()] ?? 0));
		}

		return ['results' => $rows, 'total' => $matches['total']];

	}//end listJobs()

	/**
	 * One exchange job with its rejections and latest log line.
	 *
	 * @param string $ownerApp The owning app; a job of another app is not returned.
	 * @param string $jobId    The job's uuid.
	 *
	 * @return array<string, mixed>|null The row, or null when absent or foreign.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	public function getJob(string $ownerApp, string $jobId): ?array {
		try {
			$job = $this->objectService->find(
				id: $jobId,
				register: ExchangeJobService::REGISTER,
				schema: ExchangeJobService::SCHEMA_JOB,
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $exception) {
			unset($exception);
			return null;
		}

		if ($job === null || (string)($job->getObject()['ownerApp'] ?? '') !== $ownerApp) {
			return null;
		}

		$rejections = $this->listRejections(ownerApp: $ownerApp, filters: ['jobId' => $jobId], limit: self::MAX_LIMIT);
		$open = 0;
		foreach ($rejections['results'] as $rejection) {
			if ($rejection['status'] === ExchangeRejectionService::STATUS_FAILED) {
				$open++;
			}
		}

		$row = $this->jobRow(job: $job, openRejections: $open);
		$row['rejections'] = $rejections['results'];
		$row['lastLog'] = $this->lastLog(jobId: $jobId);

		return $row;

	}//end getJob()

	/**
	 * List an app's exchange rejections.
	 *
	 * @param string               $ownerApp The owning app.
	 * @param array<string,string> $filters  Optional `status`, `target`, `jobId`.
	 * @param int                  $limit    Page size, capped at 200.
	 * @param int                  $offset   Page offset.
	 *
	 * @return array{results: array<int, array<string, mixed>>, total: int} The page.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	public function listRejections(string $ownerApp, array $filters = [], int $limit = 50, int $offset = 0): array {
		$orFilters = [
			'register' => ExchangeJobService::REGISTER,
			'schema' => ExchangeRejectionService::SCHEMA,
			'ownerApp' => $ownerApp,
		];
		foreach (['status' => 'status', 'target' => 'exchangeTarget', 'jobId' => 'exchangeJob'] as $key => $property) {
			if (($filters[$key] ?? '') !== '') {
				$orFilters[$property] = $filters[$key];
			}
		}

		$matches = $this->find(filters: $orFilters, limit: $limit, offset: $offset);

		$rows = [];
		foreach ($matches['results'] as $entry) {
			$rows[] = $this->rejectionRow(entry: $entry);
		}

		return ['results' => $rows, 'total' => $matches['total']];

	}//end listRejections()

	/**
	 * The target catalogue with the directions integriq handles.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-008-apps-read-their-own-jobs-through-the-read-model
	 */
	public function targets(): array {
		$rows = [];
		foreach ($this->targets->all() as $target) {
			$handled = $this->dispatcher->handledDirections(target: $target['id']);
			$target['handled'] = [
				'export' => in_array('export', $handled, true),
				'import' => in_array('import', $handled, true),
				'sync' => in_array('sync', $handled, true),
			];
			$rows[] = $target;
		}

		return $rows;

	}//end targets()

	/**
	 * Project one job.
	 *
	 * @param ObjectEntity $job            The job.
	 * @param int          $openRejections Its open rejection count.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function jobRow(ObjectEntity $job, int $openRejections): array {
		$data = $job->getObject();
		$target = (string)($data['exchangeTarget'] ?? '');

		return [
			'id' => $job->getUuid(),
			'name' => (string)($data['name'] ?? ''),
			'ownerApp' => (string)($data['ownerApp'] ?? ''),
			'ownerRef' => (string)($data['ownerRef'] ?? ''),
			'target' => $target,
			'targetLabel' => $this->targets->label(target: $target),
			'direction' => (string)($data['exchangeDirection'] ?? ''),
			'status' => (string)($data['exchangeStatus'] ?? ExchangeJobService::STATUS_QUEUED),
			'requestedBy' => (string)($data['requestedBy'] ?? ''),
			'requestedAt' => ($data['requestedAt'] ?? null),
			'startedAt' => ($data['startedAt'] ?? null),
			'finishedAt' => ($data['finishedAt'] ?? null),
			'result' => ($data['exchangeResult'] ?? null),
			'gateDecision' => ($data['gateDecision'] ?? null),
			'error' => ($data['exchangeError'] ?? null),
			'migratedFrom' => ($data['migratedFrom'] ?? null),
			'resubmissionOf' => ($data['resubmissionOf'] ?? null),
			'openRejections' => $openRejections,
		];

	}//end jobRow()

	/**
	 * Project one rejection, resolving its code.
	 *
	 * @param ObjectEntity $entry The dead letter.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function rejectionRow(ObjectEntity $entry): array {
		$data = $entry->getObject();
		$target = (string)($data['exchangeTarget'] ?? '');
		$code = (string)($data['errorCode'] ?? '');
		$label = $this->codes->resolve(target: $target, code: $code);

		return [
			'id' => $entry->getUuid(),
			'jobId' => (string)($data['exchangeJob'] ?? ''),
			'target' => $target,
			'status' => (string)($data['status'] ?? ''),
			'errorCode' => $code,
			'errorLabel' => $label['label'],
			'errorLabelEn' => $label['labelEn'],
			'severity' => $label['severity'],
			'offendingFields' => ($data['offendingFields'] ?? []),
			'ownerRef' => (string)($data['ownerRef'] ?? ''),
			'sourceKind' => (string)($data['sourceKind'] ?? ''),
			'correctionDeadlineAt' => ($data['correctionDeadlineAt'] ?? null),
			'discardReason' => ($data['discardReason'] ?? null),
			'retryCount' => (int)($data['retryCount'] ?? 0),
			'created' => ($data['created'] ?? ($data['attempts'][0]['at'] ?? null)),
		];

	}//end rejectionRow()

	/**
	 * Open rejections per job for one app, in one bounded query.
	 *
	 * @param string $ownerApp The owning app.
	 *
	 * @return array<string, int> Job uuid to count.
	 */
	private function openRejectionCounts(string $ownerApp): array {
		$matches = $this->find(
			filters: [
				'register' => ExchangeJobService::REGISTER,
				'schema' => ExchangeRejectionService::SCHEMA,
				'ownerApp' => $ownerApp,
				'status' => ExchangeRejectionService::STATUS_FAILED,
			],
			limit: self::OPEN_REJECTION_SCAN,
			offset: 0,
			cap: self::OPEN_REJECTION_SCAN
		);

		$counts = [];
		foreach ($matches['results'] as $entry) {
			$jobId = (string)($entry->getObject()['exchangeJob'] ?? '');
			$counts[$jobId] = (($counts[$jobId] ?? 0) + 1);
		}

		return $counts;

	}//end openRejectionCounts()

	/**
	 * The latest job_log line of a job.
	 *
	 * @param string $jobId The job's uuid.
	 *
	 * @return array{level: string, message: string, created: mixed}|null The line, or null.
	 */
	private function lastLog(string $jobId): ?array {
		$matches = $this->find(
			filters: ['register' => ExchangeJobService::REGISTER, 'schema' => 'job_log', 'jobId' => $jobId],
			limit: 20,
			offset: 0
		);

		$latest = null;
		foreach ($matches['results'] as $entry) {
			$data = $entry->getObject();
			if ($latest === null || (string)($data['created'] ?? '') > (string)($latest['created'] ?? '')) {
				$latest = $data;
			}
		}

		if ($latest === null) {
			return null;
		}

		return [
			'level' => (string)($latest['level'] ?? ''),
			'message' => (string)($latest['message'] ?? ''),
			'created' => ($latest['created'] ?? null),
		];

	}//end lastLog()

	/**
	 * A bounded OpenRegister read.
	 *
	 * OpenRegister's findAll returns a flat list of entities; the `results`
	 * key is read too so a wrapped answer works the same.
	 *
	 * @param array<string, mixed> $filters The filters.
	 * @param int                  $limit   Page size.
	 * @param int                  $offset  Page offset.
	 * @param int                  $cap     The largest page allowed.
	 *
	 * @return array{results: array<int, ObjectEntity>, total: int} The rows.
	 */
	private function find(array $filters, int $limit, int $offset, int $cap = self::MAX_LIMIT): array {
		$limit = max(1, min($limit, $cap));
		$offset = max(0, $offset);

		$matches = $this->objectService->findAll(
			config: ['filters' => $filters, 'limit' => $limit, 'offset' => $offset],
			_rbac: false,
			_multitenancy: false
		);

		$results = [];
		foreach (($matches['results'] ?? $matches) as $row) {
			if ($row instanceof ObjectEntity) {
				$results[] = $row;
			}
		}

		// `total` is the number of rows in this page: OpenRegister's findAll
		// returns a plain list with no overall count.
		return ['results' => $results, 'total' => count($results)];

	}//end find()
}//end class
