<?php

/**
 * Integriq approval decision service.
 *
 * Carries an authorized approve or reject through to the suspended run, for
 * both decision surfaces: the Pending Approvals REST controller and the
 * shared OpenRegister task inbox (hitl-on-shared-tasks follow-ups 2.1 and
 * 2.2). The resume paths were composed in `ApprovalsController`; they live
 * here so a decision taken on the shared task resumes the run exactly the
 * way a decision taken in Integriq does. Composing them outside
 * `ApprovalService` keeps the service graph acyclic: `EndpointService` and
 * `SynchronizationService` both depend on `ApprovalService` to suspend.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

use OCA\Integriq\Exception\ApprovalStateException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resumes or stops a suspended run after an authorized decision.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.LongVariable)
 *
 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
 */
class ApprovalDecisionService {

	/**
	 * Answer codes for a resumed run's message, where it is not a plain 200:
	 * the source changed after the preview (REQ-INAV-004).
	 */
	private const RESUME_STATUS_BY_MESSAGE = ['approval_superseded' => Http::STATUS_CONFLICT];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current request; the endpoint resume falls back to its method.
	 * @param ApprovalService $approvalService The approval state machine.
	 * @param EndpointService $endpointService Resumes a suspended endpoint rule-pipeline run.
	 * @param SynchronizationService $synchronizationService Resumes a gated Synchronization batch run.
	 * @param FlowRunnerService $flowRunnerService Resumes (approve) or stops (reject) a flow-sourced suspension.
	 * @param OrObjectService $orObjectService OpenRegister object service (loads the gated synchronization).
	 * @param IL10N $l The localization service.
	 * @param LoggerInterface $logger Logger for non-fatal diagnostics.
	 * @param EngineSignalService|null $engineSignal Delivers decisions to suspended OpenRegister engine runs.
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly ApprovalService $approvalService,
		private readonly EndpointService $endpointService,
		private readonly SynchronizationService $synchronizationService,
		private readonly FlowRunnerService $flowRunnerService,
		private readonly OrObjectService $orObjectService,
		private readonly IL10N $l,
		private readonly LoggerInterface $logger,
		private readonly ?EngineSignalService $engineSignal = null,
	) {

	}//end __construct()

	/**
	 * Approve an authorized, actionable request and resume its run.
	 *
	 * The caller has already checked the action matrix, the approver group
	 * and that the request is actionable.
	 *
	 * @param ObjectEntity $approvalRequest The pending request.
	 * @param IUser $user The approving user.
	 * @param string|null $comment Optional approve comment.
	 *
	 * @return JSONResponse The resumed run's result, envelope-wrapped with `_approval`.
	 *
	 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
	 */
	public function approve(ObjectEntity $approvalRequest, IUser $user, ?string $comment): JSONResponse {
		return $this->routeApproval(approvalRequest: $approvalRequest, user: $user, comment: $comment);

	}//end approve()

	/**
	 * Reject an authorized, actionable request and let its run reflect it.
	 *
	 * @param ObjectEntity $approvalRequest The pending request.
	 * @param IUser $user The rejecting user.
	 * @param string $comment The mandatory rejection comment.
	 *
	 * @return ObjectEntity The rejected (or dead-lettered) request.
	 *
	 * @throws ApprovalStateException (400) When the comment is empty.
	 *
	 * @spec openspec/specs/hitl-on-shared-tasks/spec.md#requirement-a-decision-taken-on-the-shared-task-resumes-the-run
	 */
	public function reject(ObjectEntity $approvalRequest, IUser $user, string $comment): ObjectEntity {
		$rejected = $this->approvalService->reject(approvalRequest: $approvalRequest, approver: $user, comment: $comment);
		$this->propagateRejection(approvalRequest: $rejected, data: $rejected->getObject(), user: $user, comment: $comment);

		return $rejected;

	}//end reject()

	/**
	 * Dispatch an authorized approve to the resume path its FK selects.
	 *
	 * @param ObjectEntity $approvalRequest The pending, authorized-to-act-on request.
	 * @param IUser $user The approving user.
	 * @param string|null $comment Optional approve comment.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	private function routeApproval(ObjectEntity $approvalRequest, IUser $user, ?string $comment): JSONResponse {
		$data = $approvalRequest->getObject();

		if (empty($data['endpointId']) === false) {
			return $this->approveEndpointSuspension(approvalRequest: $approvalRequest, data: $data, user: $user, comment: $comment);
		}

		if (empty($data['synchronizationId']) === false) {
			return $this->approveSynchronizationGate(approvalRequest: $approvalRequest, data: $data, user: $user, comment: $comment);
		}

		if (empty($data['flowRunId']) === false) {
			return $this->approveFlowSuspension(approvalRequest: $approvalRequest, user: $user, comment: $comment);
		}

		if (empty($data['engineRunUuid']) === false) {
			return $this->approveEngineSuspension(approvalRequest: $approvalRequest, data: $data, user: $user, comment: $comment);
		}

		$this->logger->error(
			'ApprovalDecisionService: approval_request has neither endpointId, synchronizationId, flowRunId nor engineRunUuid',
			['id' => $approvalRequest->getUuid()]
		);
		return new JSONResponse(['error' => $this->l->t('Malformed approval request')], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end routeApproval()

	/**
	 * Let the suspended run reflect a rejection, per its FK kind.
	 *
	 * Flow-sourced suspension (flowRunId): stop the app-local flow_run — no
	 * pipeline to re-invoke (self-contained, per `ApprovalService::reject()`'s
	 * own docblock), but the flow_run's OWN status must still reflect the
	 * rejection (flow-orchestration REQ-005).
	 *
	 * Engine-run suspension (engineRunUuid): wake the suspended OpenRegister
	 * run with the rejection so the approval node routes or fails it now.
	 * Best-effort by design — the record IS the decision, and the node's
	 * heartbeat re-reads it, so a lost signal costs one heartbeat rather
	 * than the flow.
	 *
	 * @param ObjectEntity $approvalRequest The just-rejected request.
	 * @param array $data The approval_request's object data.
	 * @param IUser $user The rejecting user.
	 * @param string $comment The rejection comment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function propagateRejection(ObjectEntity $approvalRequest, array $data, IUser $user, string $comment): void {
		if (empty($data['flowRunId']) === false) {
			$this->flowRunnerService->stopFromApprovalOutcome(approvalRequest: $approvalRequest);
		}

		if (empty($data['engineRunUuid']) === false) {
			$this->signalEngineRun(data: $data, decision: 'rejected', user: $user, comment: $comment);
		}

	}//end propagateRejection()

	/**
	 * Resume a suspended endpoint rule-pipeline run and finalize the
	 * approval_request with the resumed chain's outcome.
	 *
	 * @param ObjectEntity $approvalRequest The pending, authorized-to-act-on request.
	 * @param array $data The approval_request's object data.
	 * @param IUser $user The approving user.
	 * @param string|null $comment Optional approve comment.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	private function approveEndpointSuspension(ObjectEntity $approvalRequest, array $data, IUser $user, ?string $comment): JSONResponse {
		$endpoint = $this->endpointService->getEndpointById((string)$data['endpointId']);
		if ($endpoint === null) {
			return new JSONResponse(['error' => $this->l->t('The suspended endpoint no longer exists')], Http::STATUS_NOT_FOUND);
		}

		$flowToken = $this->approvalService->rehydrateFlowToken(($data['snapshot'] ?? []));
		$path = (string)($flowToken->getRequestAmended()['path'] ?? '');
		// Execution-trace REQ-004: reconstruct the SAME trace this run was
		// suspended under (null when the suspended run predates this change
		// or was otherwise untraced) so resume appends rather than creates.
		$trace = $this->approvalService->rehydrateTraceContext(($data['snapshot'] ?? []));

		$resumed = $this->endpointService->resumeFromApproval(
			endpoint: $endpoint,
			request: $this->request,
			flowToken: $flowToken,
			resumeAfterOrder: (int)($data['resumeOrder'] ?? 0),
			path: $path,
			trace: $trace
		);

		$resumeResult = 'error';
		if ($resumed->getStatus() >= 200 && $resumed->getStatus() < 300) {
			$resumeResult = 'success';
		}

		$approvalRequest = $this->approvalService->completeApproval(
			approvalRequest: $approvalRequest,
			approver: $user,
			resumeResult: $resumeResult,
			comment: $comment
		);

		return new JSONResponse(
			$this->envelopeApprovalOutcome(resumed: $resumed, approvalRequest: $approvalRequest),
			$resumed->getStatus()
		);

	}//end approveEndpointSuspension()

	/**
	 * Resume a gated Synchronization batch by re-invoking `synchronize()`
	 * with this approval_request's id as the bypass token, then finalize
	 * the approval_request with the outcome.
	 *
	 * @param ObjectEntity $approvalRequest The pending, authorized-to-act-on request.
	 * @param array $data The approval_request's object data.
	 * @param IUser $user The approving user.
	 * @param string|null $comment Optional approve comment.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md
	 */
	private function approveSynchronizationGate(ObjectEntity $approvalRequest, array $data, IUser $user, ?string $comment): JSONResponse {
		try {
			$synchronization = $this->orObjectService->find(
				id: (string)$data['synchronizationId'],
				register: 'integriq',
				schema: 'synchronization',
				_rbac: false,
				_multitenancy: false
			);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => $this->l->t('The gated synchronization no longer exists')], Http::STATUS_NOT_FOUND);
		}

		// Store the approve FIRST. The gate honours only an approved,
		// unconsumed request (REQ-015), so a run resumed while the request is
		// still pending pauses again and opens a new request on every approve
		// (REQ-INAV-004).
		$approvalRequest = $this->approvalService->completeApproval(
			approvalRequest: $approvalRequest,
			approver: $user,
			resumeResult: 'success',
			comment: $comment
		);

		$statusCode = Http::STATUS_OK;
		$result = [];

		try {
			$result = $this->synchronizationService->synchronize(
				synchronization: $synchronization,
				force: true,
				approvalRequestId: $approvalRequest->getUuid()
			);
			// The engine marked the request consumed or superseded; read it
			// back so the answer shows what is stored.
			$approvalRequest = $this->approvalService->find(id: (string)$approvalRequest->getUuid());
		} catch (Throwable $e) {
			$this->logger->error('ApprovalDecisionService: resumed synchronization failed: ' . $e->getMessage(), ['exception' => $e]);
			$statusCode = Http::STATUS_INTERNAL_SERVER_ERROR;
			$result = ['error' => $e->getMessage()];
			$approvalRequest = $this->approvalService->recordResumeResult(approvalRequest: $approvalRequest, resumeResult: 'error');
		}

		// The source changed after the preview: nothing was written and a
		// new request carries the new change set.
		$statusCode = (self::RESUME_STATUS_BY_MESSAGE[(string)($result['message'] ?? '')] ?? $statusCode);

		$body = ['data' => $result];
		if (is_array($result) === true) {
			$body = $result;
		}

		$approvalRequestData = $approvalRequest->getObject();
		$body['_approval'] = [
			'id' => $approvalRequest->getUuid(),
			'status' => ($approvalRequestData['status'] ?? 'approved'),
			'resumedAt' => ($approvalRequestData['approvedAt'] ?? null),
			'resumeResult' => ($approvalRequestData['resumeResult'] ?? null),
			'supersededBy' => ($approvalRequestData['supersededBy'] ?? null),
		];

		return new JSONResponse($body, $statusCode);
	}//end approveSynchronizationGate()

	/**
	 * Resume a suspended flow run via `FlowRunnerService::resumeFromApproval()`
	 * and finalize the approval_request with the resumed run's outcome.
	 *
	 * @param ObjectEntity $approvalRequest The pending, authorized-to-act-on request.
	 * @param IUser $user The approving user.
	 * @param string|null $comment Optional approve comment.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005
	 */
	private function approveFlowSuspension(ObjectEntity $approvalRequest, IUser $user, ?string $comment): JSONResponse {
		$resumeResult = 'success';
		$statusCode = Http::STATUS_OK;
		$flowRunData = [];

		try {
			$flowRun = $this->flowRunnerService->resumeFromApproval(approvalRequest: $approvalRequest);
			$flowRunData = $flowRun->getObject();
			$flowRunErrorStatuses = ['stopped', 'dead_letter', 'failed'];
			if (in_array(($flowRunData['status'] ?? ''), $flowRunErrorStatuses, true) === true) {
				$resumeResult = 'error';
			}
		} catch (Throwable $e) {
			$this->logger->error('ApprovalDecisionService: resumed flow run failed: ' . $e->getMessage(), ['exception' => $e]);
			$resumeResult = 'error';
			$statusCode = Http::STATUS_INTERNAL_SERVER_ERROR;
			$flowRunData = ['error' => $e->getMessage()];
		}

		$approvalRequest = $this->approvalService->completeApproval(
			approvalRequest: $approvalRequest,
			approver: $user,
			resumeResult: $resumeResult,
			comment: $comment
		);

		$approvalRequestData = $approvalRequest->getObject();
		$flowRunData['_approval'] = [
			'id' => $approvalRequest->getUuid(),
			'status' => ($approvalRequestData['status'] ?? 'approved'),
			'resumedAt' => ($approvalRequestData['approvedAt'] ?? null),
		];

		return new JSONResponse($flowRunData, $statusCode);
	}//end approveFlowSuspension()

	/**
	 * Resolve an ENGINE-run approval: finalize the approval_request, then
	 * wake the suspended OpenRegister flow run with the decision.
	 *
	 * The order is deliberate. The record is resolved FIRST because it is
	 * the system of record — the approval node's heartbeat re-reads it, so
	 * a signal that fails to deliver (OpenRegister mid-upgrade, run already
	 * woken) only delays the resume by one heartbeat instead of losing the
	 * decision. `resumeResult` therefore reports the DELIVERY, not the run's
	 * eventual outcome, which the engine owns.
	 *
	 * @param ObjectEntity $approvalRequest The pending, authorized-to-act-on request.
	 * @param array $data The approval_request's object data.
	 * @param IUser $user The approving user.
	 * @param string|null $comment Optional approve comment.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function approveEngineSuspension(ObjectEntity $approvalRequest, array $data, IUser $user, ?string $comment): JSONResponse {
		$signalled = $this->signalEngineRun(data: $data, decision: 'approved', user: $user, comment: $comment);

		$resumeResult = 'error';
		if ($signalled === true) {
			$resumeResult = 'success';
		}

		$approvalRequest = $this->approvalService->completeApproval(
			approvalRequest: $approvalRequest,
			approver: $user,
			resumeResult: $resumeResult,
			comment: $comment
		);

		$approvalRequestData = $approvalRequest->getObject();

		return new JSONResponse(
			[
				'engineRunUuid' => (string)($data['engineRunUuid'] ?? ''),
				'signalled' => $signalled,
				'_approval' => [
					'id' => $approvalRequest->getUuid(),
					'status' => ($approvalRequestData['status'] ?? 'approved'),
					'resumedAt' => ($approvalRequestData['approvedAt'] ?? null),
				],
			]
		);

	}//end approveEngineSuspension()

	/**
	 * Deliver a decision to a suspended OpenRegister engine run, guarded.
	 *
	 * Delegates to {@see EngineSignalService::deliver()} so the approve and
	 * reject paths ship the identical signal. The service dependency is
	 * defaulted (nullable) so pre-existing positional test instantiations
	 * keep working; the container always injects it in production.
	 *
	 * @param array $data The approval_request's object data (`engineRunUuid`/`signalNodeId`).
	 * @param string $decision `approved` or `rejected`.
	 * @param IUser $user The deciding user.
	 * @param string|null $comment Optional decision comment.
	 *
	 * @return boolean True when the signal was delivered.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function signalEngineRun(array $data, string $decision, IUser $user, ?string $comment): bool {
		if ($this->engineSignal === null) {
			$this->logger->warning(
				'ApprovalDecisionService: no EngineSignalService wired; the engine run resumes on its next heartbeat instead',
				['engineRunUuid' => ($data['engineRunUuid'] ?? '')]
			);
			return false;
		}

		return $this->engineSignal->deliver(data: $data, decision: $decision, user: $user, comment: $comment);

	}//end signalEngineRun()

	/**
	 * Build the `_approval`-enveloped response body for a resumed endpoint response.
	 *
	 * @param Response $resumed The resumed pipeline's final Response.
	 * @param ObjectEntity $approvalRequest The finalized approval_request.
	 *
	 * @return array
	 */
	private function envelopeApprovalOutcome(Response $resumed, ObjectEntity $approvalRequest): array {
		$body = [];
		if ($resumed instanceof JSONResponse) {
			$body = $resumed->getData();
		}

		if (is_array($body) === false) {
			$body = ['data' => $body];
		}

		$data = $approvalRequest->getObject();
		$body['_approval'] = [
			'id' => $approvalRequest->getUuid(),
			'status' => ($data['status'] ?? 'approved'),
			'resumedAt' => ($data['approvedAt'] ?? null),
		];

		return $body;
	}//end envelopeApprovalOutcome()
}//end class
