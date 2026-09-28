<?php

/**
 * Integriq exchange job runner.
 *
 * Runs one exchange job: handler check, mapping check, gate, mapping,
 * dispatch, rejections, conclusion.
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

use DateTime;
use OCA\Integriq\Event\ExchangeJobConcludedEvent;
use OCA\Integriq\Service\MappingService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One run of one exchange job (design D4).
 *
 * The order matters: a target without a handler and a job naming a missing
 * mapping fail before the owning app is asked anything, and the gate is asked
 * before a single record is touched. The records the gate hands over are
 * mapped and dispatched in this process and never written anywhere.
 *
 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ExchangeJobRunner {

	/**
	 * Constructor.
	 *
	 * @param ExchangeJobService       $jobs       Job store access.
	 * @param ExchangeGateClient       $gate       Asks the owning app.
	 * @param ExchangeTargetDispatcher $dispatcher Hands records to the adapters.
	 * @param ExchangeRejectionService $rejections Stores rejected records.
	 * @param MappingService           $mappings   Applies the job's mapping.
	 * @param IEventDispatcher         $events     Raises the concluded event.
	 * @param LoggerInterface          $logger     Logger.
	 */
	public function __construct(
		private readonly ExchangeJobService $jobs,
		private readonly ExchangeGateClient $gate,
		private readonly ExchangeTargetDispatcher $dispatcher,
		private readonly ExchangeRejectionService $rejections,
		private readonly MappingService $mappings,
		private readonly IEventDispatcher $events,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Run one exchange job.
	 *
	 * @param string $jobId The job's uuid.
	 *
	 * @return array{level: string, message: string} The job_log entry.
	 *
	 * @spec openspec/changes/learniq-exchange-jobs-native/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function run(string $jobId): array {
		$job = $this->jobs->findJob(jobId: $jobId);
		if ($job === null) {
			return ['level' => 'ERROR', 'message' => sprintf('Exchange job "%s" was not found.', $jobId)];
		}

		$data = $job->getObject();
		$target = (string)($data['exchangeTarget'] ?? '');
		$direction = (string)($data['exchangeDirection'] ?? '');
		if ($target === '') {
			return ['level' => 'WARNING', 'message' => 'This job is not an exchange job; nothing to do.'];
		}

		$status = (string)($data['exchangeStatus'] ?? ExchangeJobService::STATUS_QUEUED);
		if ($status !== ExchangeJobService::STATUS_QUEUED) {
			return [
				'level' => 'WARNING',
				'message' => sprintf('Exchange job is %s, not queued; it did not run again.', $status),
			];
		}

		if ($this->dispatcher->supports(target: $target, direction: $direction) === false) {
			return $this->fail(job: $job, data: $data, code: 'no-handler', detail: sprintf('No handler for %s %s.', $target, $direction));
		}

		$mapping = null;
		$mappingSlug = (string)($data['exchangeMapping'] ?? '');
		if ($mappingSlug !== '') {
			$mapping = $this->jobs->findMapping(slug: $mappingSlug);
			if ($mapping === null) {
				return $this->fail(job: $job, data: $data, code: 'mapping-missing', detail: sprintf('No mapping "%s".', $mappingSlug));
			}
		}

		$data['exchangeStatus'] = ExchangeJobService::STATUS_RUNNING;
		$data['startedAt'] = (new DateTime())->format('c');
		$this->jobs->saveJob(data: $data, uuid: $jobId);

		$decision = $this->gate->ask(jobId: $jobId, job: $data);
		$records = $decision['records'];
		unset($decision['records']);
		$data['gateDecision'] = $decision;

		if ($decision['decision'] !== ExchangeGateClient::DECISION_ALLOW) {
			return $this->finish(
				job: $job,
				data: $data,
				status: ExchangeJobService::STATUS_REFUSED,
				result: $this->result(processed: 0, accepted: 0),
				message: sprintf('Refused by %s: %s %s', (string)($data['ownerApp'] ?? ''), $decision['code'], $decision['reason'])
			);
		}

		return $this->dispatchRecords(job: $job, data: $data, records: $records, mapping: $mapping);

	}//end run()

	/**
	 * Map and dispatch the allowed records, record rejections, and finish.
	 *
	 * @param ObjectEntity                     $job     The job.
	 * @param array<string,mixed>              $data    The job data.
	 * @param array<int, array<string, mixed>> $records The allowed records.
	 * @param ObjectEntity|null                $mapping The mapping row, when the job names one.
	 *
	 * @return array{level: string, message: string} The job_log entry.
	 */
	private function dispatchRecords(ObjectEntity $job, array $data, array $records, ?ObjectEntity $mapping): array {
		$jobId = $job->getUuid();
		$target = (string)$data['exchangeTarget'];
		$scope = $data['exchangeScope'] ?? [];
		if (is_array($scope) === false) {
			$scope = [];
		}

		$records = $this->onlyRequested(records: $records, scope: $scope);
		$rejected = [];
		$mapped = [];
		foreach ($records as $record) {
			$recordData = $record['data'] ?? [];
			if (is_array($recordData) === false) {
				$recordData = [];
			}

			if ($mapping !== null) {
				try {
					$recordData = $this->mappings->executeMapping(mapping: $mapping, input: $recordData);
				} catch (Throwable $exception) {
					$this->logger->info('[ExchangeJobRunner] mapping failed for a record of job ' . $jobId . ': ' . $exception->getMessage());
					$rejected[] = $this->rejectionFor(record: $record, code: 'mapping-failed');
					continue;
				}
			}

			$record['data'] = $recordData;
			$mapped[] = $record;
		}//end foreach

		$outcome = $this->dispatcher->dispatch(
			jobId: $jobId,
			target: $target,
			direction: (string)$data['exchangeDirection'],
			scope: $scope,
			records: $mapped,
			ownerApp: (string)($data['ownerApp'] ?? ''),
			ownerRef: (string)($data['ownerRef'] ?? '')
		);
		if ($outcome['refusal'] !== null) {
			return $this->fail(job: $job, data: $data, code: $outcome['refusal'], detail: $this->refusalDetail(code: $outcome['refusal']));
		}

		$rejected = array_merge($rejected, $outcome['rejected']);
		$this->storeRejections(jobId: $jobId, target: $target, data: $data, rejected: $rejected);

		$processed = count($records);
		// A landed import answers with a count; an export lists the accepted ids.
		$accepted = ($outcome['acceptedCount'] ?? count($outcome['accepted']));
		$status = ExchangeJobService::STATUS_PARTIAL;
		if ($accepted === $processed) {
			$status = ExchangeJobService::STATUS_SUCCEEDED;
		} elseif ($accepted === 0) {
			$status = ExchangeJobService::STATUS_FAILED;
		}

		return $this->finish(
			job: $job,
			data: $data,
			status: $status,
			result: $this->result(processed: $processed, accepted: $accepted),
			message: sprintf('%d of %d records accepted by %s.', $accepted, $processed, $target)
		);

	}//end dispatchRecords()

	/**
	 * The job error detail for a job-wide refusal.
	 *
	 * @param string $code The refusal code.
	 *
	 * @return string The detail.
	 *
	 * @spec openspec/changes/exchange-import-landing/specs/exchange-jobs/spec.md#requirement-req-003-an-unanswered-import-ends-with-no-owner-answer
	 */
	private function refusalDetail(string $code): string {
		if ($code === ExchangeTargetDispatcher::CODE_NO_OWNER_ANSWER) {
			return 'The owning app did not take the received records.';
		}

		return 'The target cannot run this job.';

	}//end refusalDetail()

	/**
	 * Keep only the records a resubmission asks for.
	 *
	 * @param array<int, array<string, mixed>> $records The allowed records.
	 * @param array<string,mixed>              $scope   The job's scope.
	 *
	 * @return array<int, array<string, mixed>> The records to send.
	 */
	private function onlyRequested(array $records, array $scope): array {
		$wanted = $scope['recordIds'] ?? null;
		if (is_array($wanted) === false || $wanted === []) {
			return array_values($records);
		}

		$wanted = array_map('strval', $wanted);
		return array_values(
			array_filter(
				$records,
				static fn (array $record): bool => in_array((string)($record['recordId'] ?? ''), $wanted, true)
			)
		);

	}//end onlyRequested()

	/**
	 * Store this run's rejections. A resubmission reopens its own rejection.
	 *
	 * @param string                           $jobId    The job's uuid.
	 * @param string                           $target   The target.
	 * @param array<string,mixed>              $data     The job data.
	 * @param array<int, array<string, mixed>> $rejected The rejections.
	 *
	 * @return void
	 */
	private function storeRejections(string $jobId, string $target, array $data, array $rejected): void {
		$resubmissionOf = (string)($data['resubmissionOf'] ?? '');
		foreach ($rejected as $rejection) {
			try {
				if ($resubmissionOf !== '') {
					$this->rejections->reopen(rejectionId: $resubmissionOf, rejection: $rejection);
					continue;
				}

				$this->rejections->record(
					jobId: $jobId,
					target: $target,
					rejection: $rejection,
					ownerApp: (string)($data['ownerApp'] ?? '')
				);
			} catch (Throwable $exception) {
				$this->logger->warning(
					'[ExchangeJobRunner] a rejection of job ' . $jobId . ' was not stored: ' . $exception->getMessage()
				);
			}
		}

	}//end storeRejections()

	/**
	 * Fail the job as a whole.
	 *
	 * @param ObjectEntity        $job    The job.
	 * @param array<string,mixed> $data   The job data.
	 * @param string              $code   The error code.
	 * @param string              $detail A short explanation.
	 *
	 * @return array{level: string, message: string} The job_log entry.
	 */
	private function fail(ObjectEntity $job, array $data, string $code, string $detail): array {
		$data['exchangeError'] = $code . ': ' . $detail;

		return $this->finish(
			job: $job,
			data: $data,
			status: ExchangeJobService::STATUS_FAILED,
			result: $this->result(processed: 0, accepted: 0),
			message: $data['exchangeError']
		);

	}//end fail()

	/**
	 * Save the terminal state and raise the concluded event.
	 *
	 * @param ObjectEntity        $job     The job.
	 * @param array<string,mixed> $data    The job data.
	 * @param string              $status  The terminal status.
	 * @param array<string,mixed> $result  The counts.
	 * @param string              $message The log line.
	 *
	 * @return array{level: string, message: string} The job_log entry.
	 */
	private function finish(ObjectEntity $job, array $data, string $status, array $result, string $message): array {
		$data['exchangeStatus'] = $status;
		$data['exchangeResult'] = $result;
		$data['finishedAt'] = (new DateTime())->format('c');
		$this->jobs->saveJob(data: $data, uuid: $job->getUuid());

		$gateDecision = $data['gateDecision'] ?? null;
		if (is_array($gateDecision) === false) {
			$gateDecision = null;
		}

		$errorMessage = null;
		if (empty($data['exchangeError']) === false) {
			$errorMessage = (string)$data['exchangeError'];
		}

		try {
			$this->events->dispatchTyped(
				new ExchangeJobConcludedEvent(
					ownerApp: (string)($data['ownerApp'] ?? ''),
					jobId: $job->getUuid(),
					target: (string)($data['exchangeTarget'] ?? ''),
					direction: (string)($data['exchangeDirection'] ?? ''),
					ownerRef: (string)($data['ownerRef'] ?? ''),
					status: $status,
					result: $result,
					gateDecision: $gateDecision,
					errorMessage: $errorMessage
				)
			);
		} catch (Throwable $exception) {
			// A consumer's listener failing must not undo the run it observes.
			$this->logger->warning('[ExchangeJobRunner] a concluded listener failed: ' . $exception->getMessage());
		}

		$level = 'SUCCESS';
		if ($status === ExchangeJobService::STATUS_FAILED) {
			$level = 'ERROR';
		} elseif ($status !== ExchangeJobService::STATUS_SUCCEEDED) {
			$level = 'WARNING';
		}

		return ['level' => $level, 'message' => $message];

	}//end finish()

	/**
	 * Build a result block.
	 *
	 * @param int $processed Records processed.
	 * @param int $accepted  Records accepted.
	 *
	 * @return array{recordsProcessed: int, recordsAccepted: int, recordsRejected: int, runId: string, artefactRef: null}
	 */
	private function result(int $processed, int $accepted): array {
		return [
			'recordsProcessed' => $processed,
			'recordsAccepted' => $accepted,
			'recordsRejected' => ($processed - $accepted),
			'runId' => '',
			'artefactRef' => null,
		];

	}//end result()

	/**
	 * A rejection for a record that never reached the adapter.
	 *
	 * @param array<string,mixed> $record The record.
	 * @param string              $code   The error code.
	 *
	 * @return array<string,mixed> The rejection.
	 */
	private function rejectionFor(array $record, string $code): array {
		return [
			'recordId' => (string)($record['recordId'] ?? ''),
			'sourceKind' => (string)($record['sourceKind'] ?? ''),
			'errorCode' => $code,
			'offendingFields' => [],
		];

	}//end rejectionFor()
}//end class
