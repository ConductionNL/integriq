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
 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ExchangeJobRunner implements ExchangeJobRunnerInterface {

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
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-003-integriq-asks-the-owning-app-before-a-job-runs-and-fails-closed
	 */
	public function run(string $jobId): array {
		$job = $this->jobs->findJob(jobId: $jobId);
		if ($job === null) {
			return ['level' => 'ERROR', 'message' => sprintf('Exchange job "%s" was not found.', $jobId)];
		}

		$run = new ExchangeJobRun(job: $job, data: $job->getObject());
		$stopped = $this->notRunnable(data: $run->data) ?? $this->handlerFailure(run: $run) ?? $this->mappingFailure(run: $run);
		if ($stopped !== null) {
			return $stopped;
		}

		return $this->askAndDispatch(run: $run, jobId: $jobId);

	}//end run()

	/**
	 * The log entry for a job that is not an exchange job or not queued; nothing is saved.
	 *
	 * @param array<string,mixed> $data The job data.
	 *
	 * @return array{level: string, message: string}|null The job_log entry, or null when the job may run.
	 */
	private function notRunnable(array $data): ?array {
		if ((string)($data['exchangeTarget'] ?? '') === '') {
			return ['level' => 'WARNING', 'message' => 'This job is not an exchange job; nothing to do.'];
		}

		$status = (string)($data['exchangeStatus'] ?? ExchangeJobService::STATUS_QUEUED);
		if ($status !== ExchangeJobService::STATUS_QUEUED) {
			return [
				'level' => 'WARNING',
				'message' => sprintf('Exchange job is %s, not queued; it did not run again.', $status),
			];
		}

		return null;

	}//end notRunnable()

	/**
	 * Fail the job when no handler serves its target and direction.
	 *
	 * @param ExchangeJobRun $run The run.
	 *
	 * @return array{level: string, message: string}|null The job_log entry, or null when a handler exists.
	 */
	private function handlerFailure(ExchangeJobRun $run): ?array {
		$target = (string)($run->data['exchangeTarget'] ?? '');
		$direction = (string)($run->data['exchangeDirection'] ?? '');
		if ($this->dispatcher->supports(target: $target, direction: $direction) === true) {
			return null;
		}

		return $this->fail(run: $run, outcome: $this->failure(code: 'no-handler', detail: sprintf('No handler for %s %s.', $target, $direction)));

	}//end handlerFailure()

	/**
	 * Load the job's mapping onto the run, or fail the job when it names one that does not exist.
	 *
	 * @param ExchangeJobRun $run The run.
	 *
	 * @return array{level: string, message: string}|null The job_log entry, or null when the run may go on.
	 */
	private function mappingFailure(ExchangeJobRun $run): ?array {
		$mappingSlug = (string)($run->data['exchangeMapping'] ?? '');
		if ($mappingSlug === '') {
			return null;
		}

		$run->mapping = $this->jobs->findMapping(slug: $mappingSlug);
		if ($run->mapping !== null) {
			return null;
		}

		return $this->fail(run: $run, outcome: $this->failure(code: 'mapping-missing', detail: sprintf('No mapping "%s".', $mappingSlug)));

	}//end mappingFailure()

	/**
	 * Mark the job running, ask the owning app, and dispatch what it allows.
	 *
	 * @param ExchangeJobRun $run   The run.
	 * @param string         $jobId The job's uuid as asked for.
	 *
	 * @return array{level: string, message: string} The job_log entry.
	 */
	private function askAndDispatch(ExchangeJobRun $run, string $jobId): array {
		$run->data['exchangeStatus'] = ExchangeJobService::STATUS_RUNNING;
		$run->data['startedAt'] = (new DateTime())->format('c');
		$this->jobs->saveJob(data: $run->data, uuid: $jobId);

		$decision = $this->gate->ask(jobId: $jobId, job: $run->data);
		$records = $decision['records'];
		unset($decision['records']);
		$run->data['gateDecision'] = $decision;

		if ($decision['decision'] !== ExchangeGateClient::DECISION_ALLOW) {
			return $this->finish(run: $run, outcome: $this->refusal(data: $run->data, decision: $decision));
		}

		return $this->dispatchRecords(run: $run, records: $records);

	}//end askAndDispatch()

	/**
	 * The outcome of a job the owning app refused.
	 *
	 * @param array<string,mixed> $data     The job data.
	 * @param array<string,mixed> $decision The gate decision.
	 *
	 * @return ExchangeJobOutcome The refused outcome.
	 */
	private function refusal(array $data, array $decision): ExchangeJobOutcome {
		return new ExchangeJobOutcome(
			status: ExchangeJobService::STATUS_REFUSED,
			message: sprintf('Refused by %s: %s %s', (string)($data['ownerApp'] ?? ''), $decision['code'], $decision['reason'])
		);

	}//end refusal()

	/**
	 * Map and dispatch the allowed records, record rejections, and finish.
	 *
	 * @param ExchangeJobRun                   $run     The run.
	 * @param array<int, array<string, mixed>> $records The allowed records.
	 *
	 * @return array{level: string, message: string} The job_log entry.
	 */
	private function dispatchRecords(ExchangeJobRun $run, array $records): array {
		$records = $this->onlyRequested(records: $records, scope: $this->scopeOf(data: $run->data));
		$mapping = $this->mapRecords(run: $run, records: $records);

		$outcome = $this->dispatcher->dispatch(handOff: $this->handOff(run: $run, mapped: $mapping['mapped']));
		if ($outcome['refusal'] !== null) {
			return $this->fail(run: $run, outcome: $this->failure(code: $outcome['refusal'], detail: $this->refusalDetail(code: $outcome['refusal'])));
		}

		$this->storeRejections(run: $run, rejected: array_merge($mapping['rejected'], $outcome['rejected']));

		// A landed import answers with a count; an export lists the accepted ids.
		$counts = new ExchangeRecordCounts(processed: count($records), accepted: ($outcome['acceptedCount'] ?? count($outcome['accepted'])));

		return $this->finish(run: $run, outcome: $this->delivered(run: $run, counts: $counts));

	}//end dispatchRecords()

	/**
	 * The job's scope, or an empty scope when it is not a list.
	 *
	 * @param array<string,mixed> $data The job data.
	 *
	 * @return array<string,mixed> The scope.
	 */
	private function scopeOf(array $data): array {
		$scope = $data['exchangeScope'] ?? [];
		if (is_array($scope) === false) {
			return [];
		}

		return $scope;

	}//end scopeOf()

	/**
	 * Apply the job's mapping to each record; a record that fails mapping becomes a rejection.
	 *
	 * @param ExchangeJobRun                   $run     The run.
	 * @param array<int, array<string, mixed>> $records The records to send.
	 *
	 * @return array{mapped: array<int, array<string, mixed>>, rejected: array<int, array<string, mixed>>}
	 */
	private function mapRecords(ExchangeJobRun $run, array $records): array {
		$mapped = [];
		$rejected = [];
		foreach ($records as $record) {
			$recordData = $this->mapRecord(run: $run, record: $record);
			if ($recordData === null) {
				$rejected[] = $this->rejectionFor(record: $record, code: 'mapping-failed');
				continue;
			}

			$record['data'] = $recordData;
			$mapped[] = $record;
		}

		return ['mapped' => $mapped, 'rejected' => $rejected];

	}//end mapRecords()

	/**
	 * One record's data, mapped when the job names a mapping.
	 *
	 * @param ExchangeJobRun       $run    The run.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return array<string, mixed>|null The record data, or null when the mapping failed.
	 */
	private function mapRecord(ExchangeJobRun $run, array $record): ?array {
		$recordData = $record['data'] ?? [];
		if (is_array($recordData) === false) {
			$recordData = [];
		}

		if ($run->mapping === null) {
			return $recordData;
		}

		try {
			return $this->mappings->executeMapping(mapping: $run->mapping, input: $recordData);
		} catch (Throwable $exception) {
			// The exception class only: a mapping error can quote its input,
			// which may hold a persoonsgebonden nummer.
			$this->logger->info('[ExchangeJobRunner] mapping failed for a record of job '.$run->job->getUuid().': '.get_class($exception));
			return null;
		}

	}//end mapRecord()

	/**
	 * The hand-off of the mapped records to the target's adapter.
	 *
	 * @param ExchangeJobRun                   $run    The run.
	 * @param array<int, array<string, mixed>> $mapped The mapped records.
	 *
	 * @return ExchangeHandOff The hand-off.
	 */
	private function handOff(ExchangeJobRun $run, array $mapped): ExchangeHandOff {
		return new ExchangeHandOff(
			jobId: $run->job->getUuid(),
			target: (string)$run->data['exchangeTarget'],
			direction: (string)$run->data['exchangeDirection'],
			scope: $this->scopeOf(data: $run->data),
			records: $mapped,
			ownerApp: (string)($run->data['ownerApp'] ?? ''),
			ownerRef: (string)($run->data['ownerRef'] ?? '')
		);

	}//end handOff()

	/**
	 * The outcome of a dispatch the target answered: succeeded, partial or failed by the accepted count.
	 *
	 * @param ExchangeJobRun       $run    The run.
	 * @param ExchangeRecordCounts $counts Processed and accepted records.
	 *
	 * @return ExchangeJobOutcome The outcome.
	 */
	private function delivered(ExchangeJobRun $run, ExchangeRecordCounts $counts): ExchangeJobOutcome {
		$status = ExchangeJobService::STATUS_PARTIAL;
		if ($counts->accepted === $counts->processed) {
			$status = ExchangeJobService::STATUS_SUCCEEDED;
		} elseif ($counts->accepted === 0) {
			$status = ExchangeJobService::STATUS_FAILED;
		}

		$outcome = new ExchangeJobOutcome(
			status: $status,
			message: sprintf('%d of %d records accepted by %s.', $counts->accepted, $counts->processed, (string)$run->data['exchangeTarget'])
		);
		$outcome->counts = $counts;

		return $outcome;

	}//end delivered()

	/**
	 * The job error detail for a job-wide refusal.
	 *
	 * @param string $code The refusal code.
	 *
	 * @return string The detail.
	 *
	 * @spec openspec/specs/exchange-jobs/spec.md#requirement-req-012-an-unanswered-import-ends-with-no-owner-answer
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
	 * @param ExchangeJobRun                   $run      The run.
	 * @param array<int, array<string, mixed>> $rejected The rejections.
	 *
	 * @return void
	 */
	private function storeRejections(ExchangeJobRun $run, array $rejected): void {
		foreach ($rejected as $rejection) {
			try {
				$this->storeRejection(run: $run, rejection: $rejection);
			} catch (Throwable $exception) {
				$this->logger->warning(
					'[ExchangeJobRunner] a rejection of job ' . $run->job->getUuid() . ' was not stored: ' . $exception->getMessage()
				);
			}
		}

	}//end storeRejections()

	/**
	 * Store one rejection, or reopen the rejection a resubmission answers.
	 *
	 * @param ExchangeJobRun       $run       The run.
	 * @param array<string, mixed> $rejection The rejection.
	 *
	 * @return void
	 */
	private function storeRejection(ExchangeJobRun $run, array $rejection): void {
		$resubmissionOf = (string)($run->data['resubmissionOf'] ?? '');
		if ($resubmissionOf !== '') {
			$this->rejections->reopen(rejectionId: $resubmissionOf, rejection: $rejection);
			return;
		}

		$this->rejections->record(
			jobId: $run->job->getUuid(),
			target: (string)$run->data['exchangeTarget'],
			rejection: $rejection,
			ownerApp: (string)($run->data['ownerApp'] ?? '')
		);

	}//end storeRejection()

	/**
	 * A job-wide failure: the error code and a short explanation.
	 *
	 * @param string $code   The error code.
	 * @param string $detail A short explanation.
	 *
	 * @return ExchangeJobOutcome The failed outcome, its message `code: detail`.
	 */
	private function failure(string $code, string $detail): ExchangeJobOutcome {
		return new ExchangeJobOutcome(status: ExchangeJobService::STATUS_FAILED, message: $code . ': ' . $detail);

	}//end failure()

	/**
	 * Fail the job as a whole: the failure's message is also the job's error.
	 *
	 * @param ExchangeJobRun     $run     The run.
	 * @param ExchangeJobOutcome $outcome The failed outcome.
	 *
	 * @return array{level: string, message: string} The job_log entry.
	 */
	private function fail(ExchangeJobRun $run, ExchangeJobOutcome $outcome): array {
		$run->data['exchangeError'] = $outcome->message;

		return $this->finish(run: $run, outcome: $outcome);

	}//end fail()

	/**
	 * Save the terminal state and raise the concluded event.
	 *
	 * @param ExchangeJobRun     $run     The run.
	 * @param ExchangeJobOutcome $outcome The terminal status, message and counts.
	 *
	 * @return array{level: string, message: string} The job_log entry.
	 */
	private function finish(ExchangeJobRun $run, ExchangeJobOutcome $outcome): array {
		$run->data['exchangeStatus'] = $outcome->status;
		$run->data['exchangeResult'] = $outcome->counts->result();
		$run->data['finishedAt'] = (new DateTime())->format('c');
		$this->jobs->saveJob(data: $run->data, uuid: $run->job->getUuid());

		try {
			$this->events->dispatchTyped($this->concludedEvent(run: $run));
		} catch (Throwable $exception) {
			// A consumer's listener failing must not undo the run it observes.
			$this->logger->warning('[ExchangeJobRunner] a concluded listener failed: ' . $exception->getMessage());
		}

		return ['level' => $this->logLevel(status: $outcome->status), 'message' => $outcome->message];

	}//end finish()

	/**
	 * The concluded event for the run's saved terminal state.
	 *
	 * @param ExchangeJobRun $run The run.
	 *
	 * @return ExchangeJobConcludedEvent The event.
	 */
	private function concludedEvent(ExchangeJobRun $run): ExchangeJobConcludedEvent {
		$data = $run->data;

		return new ExchangeJobConcludedEvent(
			ownerApp: (string)($data['ownerApp'] ?? ''),
			jobId: $run->job->getUuid(),
			target: (string)($data['exchangeTarget'] ?? ''),
			direction: (string)($data['exchangeDirection'] ?? ''),
			ownerRef: (string)($data['ownerRef'] ?? ''),
			status: $data['exchangeStatus'],
			result: $data['exchangeResult'],
			gateDecision: $this->gateDecisionOf(data: $data),
			errorMessage: $this->errorMessageOf(data: $data)
		);

	}//end concludedEvent()

	/**
	 * The stored gate decision, or null when the job has none.
	 *
	 * @param array<string,mixed> $data The job data.
	 *
	 * @return array<string,mixed>|null The gate decision.
	 */
	private function gateDecisionOf(array $data): ?array {
		$gateDecision = $data['gateDecision'] ?? null;
		if (is_array($gateDecision) === false) {
			return null;
		}

		return $gateDecision;

	}//end gateDecisionOf()

	/**
	 * The job error, or null when the job has none.
	 *
	 * @param array<string,mixed> $data The job data.
	 *
	 * @return string|null The error message.
	 */
	private function errorMessageOf(array $data): ?string {
		if (empty($data['exchangeError']) === false) {
			return (string)$data['exchangeError'];
		}

		return null;

	}//end errorMessageOf()

	/**
	 * The job_log level of a terminal status.
	 *
	 * @param string $status The terminal status.
	 *
	 * @return string SUCCESS, WARNING or ERROR.
	 */
	private function logLevel(string $status): string {
		if ($status === ExchangeJobService::STATUS_FAILED) {
			return 'ERROR';
		}

		if ($status !== ExchangeJobService::STATUS_SUCCEEDED) {
			return 'WARNING';
		}

		return 'SUCCESS';

	}//end logLevel()

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
