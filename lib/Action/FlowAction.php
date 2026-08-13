<?php

/**
 * OpenConnector Flow Action.
 *
 * Cron action that runs a `flow` via `FlowRunnerService::run()`. Implements
 * the same duck-typed `run(array $arguments): array` contract as
 * `SynchronizationAction`/`PingAction` — resolved by
 * `JobService::executeJob()` via `jobClass` on a `job` OR object, no new
 * Action interface introduced (job-management REQ-JOB-003).
 *
 * @category Action
 * @package  OCA\OpenConnector\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/job-management/spec.md#requirement-flowaction-runs-a-flow-as-a-scheduled-job-req-job-003
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Action;

use Exception;
use OCA\OpenConnector\Service\FlowRunnerService;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Runs a `flow` (via `FlowRunnerService::run()`) as a scheduled job Action.
 *
 * @spec openspec/specs/job-management/spec.md#requirement-flowaction-runs-a-flow-as-a-scheduled-job-req-job-003
 */
class FlowAction {

	/**
	 * Flow_run.status values that map to job_log level `ERROR`.
	 *
	 * @var array<int, string>
	 */
	private const ERROR_STATUSES = ['failed', 'stopped'];

	/**
	 * Constructor.
	 *
	 * @param FlowRunnerService $flowRunnerService The service that executes a flow's steps.
	 */
	public function __construct(
		private FlowRunnerService $flowRunnerService,
	) {
	}//end __construct()

	/**
	 * Execute the flow referenced by `$argument['flowId']`.
	 *
	 * `level` is derived from the resulting `flow_run.status`: `SUCCESS`
	 * for `completed`, `WARNING` for `dead_letter`/`suspended`, `ERROR` for
	 * `failed`/`stopped` — so `JobService::executeJob()`'s existing
	 * `job_log` persistence requires no changes to handle flow-backed jobs
	 * (job-management REQ-JOB-003).
	 *
	 * @param array $argument An array of arguments; MUST include `flowId`.
	 *
	 * @return array `{level, message, stackTrace}`, matching `SynchronizationAction::run()`'s return shape.
	 *
	 * @spec openspec/specs/job-management/spec.md#requirement-flowaction-runs-a-flow-as-a-scheduled-job-req-job-003
	 */
	public function run(array $argument = []): array {
		$response = [];

		$response['message'] = $response['stackTrace'][] = 'Check for a valid flow ID';
		if (isset($argument['flowId']) === false) {
			$response['level'] = 'ERROR';
			$response['stackTrace'][] = $response['message'] = 'No flow ID provided';

			return $response;
		}

		$flowId = (string)$argument['flowId'];

		$response['stackTrace'][] = 'Getting flow: ' . $flowId;
		try {
			$flow = $this->flowRunnerService->findFlow(id: $flowId);
		} catch (DoesNotExistException $e) {
			$response['level'] = 'WARNING';
			$response['stackTrace'][] = $response['message'] = 'Flow not found: ' . $flowId;

			return $response;
		}

		$response['stackTrace'][] = 'Running flow';
		try {
			$flowRun = $this->flowRunnerService->run(flow: $flow, triggerSource: 'cron');
		} catch (Exception $e) {
			$response['level'] = 'ERROR';
			$response['stackTrace'][] = $response['message'] = 'Failed to run flow: ' . $e->getMessage();

			return $response;
		}

		$status = (string)($flowRun->getObject()['status'] ?? '');

		$response['level'] = 'SUCCESS';
		if ($status === 'dead_letter' || $status === 'suspended') {
			$response['level'] = 'WARNING';
		} elseif (in_array($status, self::ERROR_STATUSES, true) === true) {
			$response['level'] = 'ERROR';
		}

		$response['stackTrace'][] = $response['message'] = 'Flow run ended with status: ' . $status;

		return $response;
	}//end run()
}//end class
