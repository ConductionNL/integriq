<?php

/**
 * Integriq Synchronization Action.
 *
 * Cron action that runs a synchronization. Post OpenRegister-cutover the
 * synchronization is resolved through SynchronizationService::getSynchronization()
 * (backed by OpenRegister, register `openconnector`, schema `synchronization`),
 * the legacy SynchronizationMapper having been removed.
 *
 * @category Action
 * @package  OCA\Integriq\Service\Job
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.Integriq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Service\Job;

use Exception;
use OCA\Integriq\Service\SynchronizationServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * This action handles the synchronization of data from the source to the target.
 *
 * @package OCA\Integriq\Service\Job
 *
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class SynchronizationAction {

	/**
	 * The synchronization service that runs the synchronization.
	 *
	 * @var SynchronizationServiceInterface
	 */
	private SynchronizationServiceInterface $synchronizationService;

	/**
	 * Constructor.
	 *
	 * @param SynchronizationServiceInterface $synchronizationService The synchronization service.
	 */
	public function __construct(
		SynchronizationServiceInterface $synchronizationService,
	) {
		$this->synchronizationService = $synchronizationService;

	}//end __construct()

	/**
	 * Executes the synchronization process based on the provided arguments.
	 *
	 * This method checks for a valid synchronization ID and performs the
	 * synchronization action, returning a stack trace of operations performed.
	 *
	 * @param array $argument An array of arguments that can include 'synchronizationId' and 'force'.
	 *
	 * @return array Returns an array containing the stack trace of actions performed and any warnings or messages.
	 *
	 * @throws Exception Throws an exception if the synchronization process fails or encounters an error.
	 *
	 * @todo Make this method more generic to handle different synchronization processes.
	 * @todo Implement proper error handling when 'synchronizationId' is missing or invalid.
	 * @todo Improve handling for testing purposes and synchronization contract logic.
	 */
	public function run(array $argument = []): array {
		$response = [];

		// If we do not have a synchronization id then everything is wrong.
		$response['message'] = $response['stackTrace'][] = 'Check for a valid synchronization ID';
		if (isset($argument['synchronizationId']) === false) {
			return $this->failed(response: $response, message: 'No synchronization ID provided');
		}

		// Resolve the synchronization (OpenRegister ids are UUID strings).
		$response['stackTrace'][] = 'Getting synchronization: ' . $argument['synchronizationId'];
		try {
			$synchronization = $this->synchronizationService->getSynchronization(id: (string)$argument['synchronizationId']);
		} catch (DoesNotExistException $e) {
			return $this->warned(response: $response, message: 'Synchronization not found: ' . $argument['synchronizationId']);
		}

		return $this->synchronizeFound(response: $response, job: new SynchronizationJob(synchronization: $synchronization, argument: $argument));
	}//end run()

	/**
	 * Run the found synchronization and report how it ended.
	 *
	 * @param array              $response The response so far.
	 * @param SynchronizationJob $job      The synchronization and the job arguments.
	 *
	 * @return array The response.
	 */
	private function synchronizeFound(array $response, SynchronizationJob $job): array {
		$force = filter_var(($job->argument['force'] ?? false), FILTER_VALIDATE_BOOLEAN);
		if ($force === true) {
			$response['stackTrace'][] = 'Force enabled for synchronization job';
		}

		// Run the synchronization.
		$response['stackTrace'][] = 'Doing the synchronization';
		try {
			$objects = $this->synchronize(job: $job, force: $force);
		} catch (TooManyRequestsHttpException $e) {
			return $this->rateLimited(response: $response, exception: $e);
		} catch (Exception $e) {
			return $this->failed(response: $response, message: 'Failed to synchronize: ' . $e->getMessage());
		}

		return $this->synchronized(response: $response, objects: $objects);

	}//end synchronizeFound()

	/**
	 * Hand the synchronization to the service, with the job's execution trace.
	 *
	 * Execution-trace REQ-001: JobService::executeJob() threads the
	 * active `job`-entryPoint trace context via this internal argument
	 * key (never part of the job's own persisted `arguments`). Only
	 * JobService populates this key, always with an ExecutionTraceContext
	 * or nothing — `synchronize(trace:)`'s own `?ExecutionTraceContext`
	 * type hint is the single source of truth for this contract.
	 *
	 * @param SynchronizationJob $job   The synchronization and the job arguments.
	 * @param bool               $force Whether to force the run.
	 *
	 * @return array|null The synchronization log result.
	 */
	private function synchronize(SynchronizationJob $job, bool $force): ?array {
		return $this->synchronizationService->synchronize(
			synchronization: $job->synchronization,
			force: $force,
			trace: ($job->argument['_executionTrace'] ?? null)
		);

	}//end synchronize()

	/**
	 * A run stopped by the source's rate limit: a warning, and the next run time when the source sent one.
	 *
	 * @param array                        $response  The response so far.
	 * @param TooManyRequestsHttpException $exception The rate-limit stop.
	 *
	 * @return array The response.
	 */
	private function rateLimited(array $response, TooManyRequestsHttpException $exception): array {
		$response = $this->warned(response: $response, message: 'Stopped synchronization: ' . $exception->getMessage());
		if (isset($exception->getHeaders()['X-RateLimit-Reset']) === true) {
			$response['nextRun'] = $exception->getHeaders()['X-RateLimit-Reset'];
			$response['stackTrace'][] = 'Returning X-RateLimit-Reset header to update Job nextRun: ' . $response['nextRun'];
		}

		return $response;

	}//end rateLimited()

	/**
	 * A finished run: how many objects it synchronized, and whether more batches remain.
	 *
	 * @param array      $response The response so far.
	 * @param array|null $objects  The synchronization log result.
	 *
	 * @return array The response.
	 */
	private function synchronized(array $response, ?array $objects): array {
		$response['level'] = 'INFO';

		$objectCount = 0;
		if (is_array($objects) === true) {
			$objectCount = $this->objectCount(objects: $objects);
		}

		$response['stackTrace'][] = $response['message'] = 'Synchronized ' . $objectCount . ' successfully';

		// Report back about what we have just done.
		return $this->withResume(response: $response, objects: $objects);

	}//end synchronized()

	/**
	 * The number of synchronized objects: the contracts when there are any, else the objects found.
	 *
	 * @param array $objects The synchronization log result.
	 *
	 * @return mixed The count.
	 */
	private function objectCount(array $objects): mixed {
		if (empty($objects['result']['contracts']) === false) {
			return count($objects['result']['contracts']);
		}

		return $objects['result']['objects']['found'];

	}//end objectCount()

	/**
	 * End the run as an error with a message.
	 *
	 * @param array  $response The response so far.
	 * @param string $message  The message.
	 *
	 * @return array The response.
	 */
	private function failed(array $response, string $message): array {
		$response['level'] = 'ERROR';
		$response['stackTrace'][] = $response['message'] = $message;

		return $response;

	}//end failed()

	/**
	 * End the run as a warning with a message.
	 *
	 * @param array  $response The response so far.
	 * @param string $message  The message.
	 *
	 * @return array The response.
	 */
	private function warned(array $response, string $message): array {
		$response['level'] = 'WARNING';
		$response['stackTrace'][] = $response['message'] = $message;

		return $response;

	}//end warned()

	/**
	 * Ask the scheduler to run this job again on the next cron tick when the
	 * synchronization run left batches to read.
	 *
	 * @param array $response The action response so far.
	 * @param mixed $objects What the synchronization run returned.
	 *
	 * @return array The response, with `resumeSoon` when batches remain.
	 *
	 * @spec openspec/changes/bounded-jsonl-sync/specs/job-scheduling/spec.md#requirement-a-job-with-work-left-runs-again-on-the-next-tick
	 */
	private function withResume(array $response, mixed $objects): array {
		if (is_array($objects) === false || isset($objects['result']['resume']) === false) {
			return $response;
		}

		$response['resumeSoon'] = true;
		$response['stackTrace'][] = 'More batches remain; resuming at batch ' . ($objects['result']['resume']['page'] ?? '?') . ' on the next run';

		return $response;
	}//end withResume()
}//end class
