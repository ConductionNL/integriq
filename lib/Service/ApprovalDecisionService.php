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
class ApprovalDecisionService implements ApprovalDecisionServiceInterface {

	/**
	 * Answer codes for a resumed run's message, where it is not a plain 200:
	 * the source changed after the preview (REQ-INAV-004).
	 */
	private const RESUME_STATUS_BY_MESSAGE = ['approval_superseded' => Http::STATUS_CONFLICT];

	/**
	 * The run references an approval_request can carry, in the order the
	 * approve resumes them: the first one set wins.
	 *
	 * @var array<int, string>
	 */
	private const RESUME_KINDS = ['endpointId', 'synchronizationId', 'flowRunId', 'engineRunUuid'];

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
		$run          = new ApprovalDecisionRun(approvalRequest: $approvalRequest, user: $user);
		$run->comment = $comment;
		return $this->routeApproval(run: $run);

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
		$rejected     = $this->approvalService->reject(approvalRequest: $approvalRequest, approver: $user, comment: $comment);
		$run          = new ApprovalDecisionRun(approvalRequest: $rejected, user: $user);
		$run->comment = $comment;
		$this->propagateRejection(run: $run);

		return $rejected;

	}//end reject()

	/**
	 * Dispatch an authorized approve to the resume path its FK selects.
	 *
	 * @param ApprovalDecisionRun $run The pending, authorized-to-act-on decision.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	private function routeApproval(ApprovalDecisionRun $run): JSONResponse {
		return match ($this->resumeKind(data: $run->data)) {
			'endpointId' => $this->approveEndpointSuspension(run: $run),
			'synchronizationId' => $this->approveSynchronizationGate(approvalRequest: $run->approvalRequest, data: $run->data, user: $run->user, comment: $run->comment),
			'flowRunId' => $this->approveFlowSuspension(run: $run),
			'engineRunUuid' => $this->approveEngineSuspension(run: $run),
			default => $this->refuseMalformed(run: $run),
		};
	}//end routeApproval()

	/**
	 * The first run reference an approval_request carries, in resume order.
	 *
	 * @param array<string,mixed> $data The approval_request's object data.
	 *
	 * @return string|null The key of the run reference, or null when it carries none.
	 */
	private function resumeKind(array $data): ?string {
		foreach (self::RESUME_KINDS as $key) {
			if (empty($data[$key]) === false) {
				return $key;
			}
		}

		return null;

	}//end resumeKind()

	/**
	 * Answer an approval_request that names no run to resume.
	 *
	 * @param ApprovalDecisionRun $run The decision.
	 *
	 * @return JSONResponse The 500 answer.
	 */
	private function refuseMalformed(ApprovalDecisionRun $run): JSONResponse {
		$this->logger->error(
			'ApprovalDecisionService: approval_request has neither endpointId, synchronizationId, flowRunId nor engineRunUuid',
			['id' => $run->approvalRequest->getUuid()]
		);
		return new JSONResponse(['error' => $this->l->t('Malformed approval request')], Http::STATUS_INTERNAL_SERVER_ERROR);

	}//end refuseMalformed()

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
	 * @param ApprovalDecisionRun $run The just-rejected decision.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function propagateRejection(ApprovalDecisionRun $run): void {
		if (empty($run->data['flowRunId']) === false) {
			$this->flowRunnerService->stopFromApprovalOutcome(approvalRequest: $run->approvalRequest);
		}

		if (empty($run->data['engineRunUuid']) === false) {
			$this->signalEngineRun(run: $run, decision: 'rejected');
		}

	}//end propagateRejection()

	/**
	 * Resume a suspended endpoint rule-pipeline run and finalize the
	 * approval_request with the resumed chain's outcome.
	 *
	 * @param ApprovalDecisionRun $run The pending, authorized-to-act-on decision.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	private function approveEndpointSuspension(ApprovalDecisionRun $run): JSONResponse {
		$endpoint = $this->endpointService->getEndpointById((string)$run->data['endpointId']);
		if ($endpoint === null) {
			return new JSONResponse(['error' => $this->l->t('The suspended endpoint no longer exists')], Http::STATUS_NOT_FOUND);
		}

		$resumed         = $this->resumeEndpointRun(run: $run, endpoint: $endpoint);
		$approvalRequest = $this->completeDecision(run: $run, resumeResult: $this->endpointResumeResult(resumed: $resumed));

		return new JSONResponse(
			$this->envelopeApprovalOutcome(resumed: $resumed, approvalRequest: $approvalRequest),
			$resumed->getStatus()
		);

	}//end approveEndpointSuspension()

	/**
	 * Re-invoke the suspended endpoint rule pipeline after the approved rule.
	 *
	 * @param ApprovalDecisionRun $run      The decision.
	 * @param ObjectEntity        $endpoint The suspended endpoint.
	 *
	 * @return Response The resumed pipeline's final response.
	 */
	private function resumeEndpointRun(ApprovalDecisionRun $run, ObjectEntity $endpoint): Response {
		$flowToken = $this->approvalService->rehydrateFlowToken(($run->data['snapshot'] ?? []));
		$path      = (string)($flowToken->getRequestAmended()['path'] ?? '');
		// Execution-trace REQ-004: reconstruct the SAME trace this run was
		// suspended under (null when the suspended run predates this change
		// or was otherwise untraced) so resume appends rather than creates.
		$trace = $this->approvalService->rehydrateTraceContext(($run->data['snapshot'] ?? []));

		return $this->endpointService->resumeFromApproval(
			endpoint: $endpoint,
			request: $this->request,
			flowToken: $flowToken,
			resumeAfterOrder: (int)($run->data['resumeOrder'] ?? 0),
			path: $path,
			trace: $trace
		);

	}//end resumeEndpointRun()

	/**
	 * The resume result of an endpoint run: success on a 2xx answer.
	 *
	 * @param Response $resumed The resumed pipeline's final response.
	 *
	 * @return string `success` or `error`.
	 */
	private function endpointResumeResult(Response $resumed): string {
		if ($resumed->getStatus() >= 200 && $resumed->getStatus() < 300) {
			return 'success';
		}

		return 'error';

	}//end endpointResumeResult()

	/**
	 * Store the approve with the resume result.
	 *
	 * @param ApprovalDecisionRun $run          The decision.
	 * @param string              $resumeResult `success` or `error`.
	 *
	 * @return ObjectEntity The finalized approval_request.
	 */
	private function completeDecision(ApprovalDecisionRun $run, string $resumeResult): ObjectEntity {
		return $this->approvalService->completeApproval(
			approvalRequest: $run->approvalRequest,
			approver: $run->user,
			resumeResult: $resumeResult,
			comment: $run->comment
		);

	}//end completeDecision()

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
			// Unscoped: re-reads the synchronization named on an approval request the caller already reached in scope.
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
			// System read: re-reads the request the caller already reached in scope.
			$approvalRequest = $this->approvalService->find(id: (string)$approvalRequest->getUuid(), asSystem: true);
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
	 * @param ApprovalDecisionRun $run The pending, authorized-to-act-on decision.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005
	 */
	private function approveFlowSuspension(ApprovalDecisionRun $run): JSONResponse {
		$flowRunData = $this->resumeFlowRun(run: $run);
		$approvalRequest = $this->completeDecision(run: $run, resumeResult: $run->resumeResult);

		$flowRunData['_approval'] = $this->approvalSummary(approvalRequest: $approvalRequest);

		return new JSONResponse($flowRunData, $run->statusCode);
	}//end approveFlowSuspension()

	/**
	 * Resume the flow run and note on the decision how that went.
	 *
	 * @param ApprovalDecisionRun $run The decision.
	 *
	 * @return array<string,mixed> The resumed flow run's data, or the error.
	 */
	private function resumeFlowRun(ApprovalDecisionRun $run): array {
		try {
			$flowRun = $this->flowRunnerService->resumeFromApproval(approvalRequest: $run->approvalRequest);
			$flowRunData = $flowRun->getObject();
			$run->resumeResult = $this->flowResumeResult(flowRunData: $flowRunData);
			return $flowRunData;
		} catch (Throwable $e) {
			$this->logger->error('ApprovalDecisionService: resumed flow run failed: ' . $e->getMessage(), ['exception' => $e]);
			$run->resumeResult = 'error';
			$run->statusCode = Http::STATUS_INTERNAL_SERVER_ERROR;
			return ['error' => $e->getMessage()];
		}

	}//end resumeFlowRun()

	/**
	 * The resume result of a flow run: error when it stopped, dead-lettered or failed.
	 *
	 * @param array<string,mixed> $flowRunData The resumed flow run's data.
	 *
	 * @return string `success` or `error`.
	 */
	private function flowResumeResult(array $flowRunData): string {
		$flowRunErrorStatuses = ['stopped', 'dead_letter', 'failed'];
		if (in_array(($flowRunData['status'] ?? ''), $flowRunErrorStatuses, true) === true) {
			return 'error';
		}

		return 'success';

	}//end flowResumeResult()

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
	 * @param ApprovalDecisionRun $run The pending, authorized-to-act-on decision.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function approveEngineSuspension(ApprovalDecisionRun $run): JSONResponse {
		$signalled = $this->signalEngineRun(run: $run, decision: 'approved');

		$resumeResult = 'error';
		if ($signalled === true) {
			$resumeResult = 'success';
		}

		$approvalRequest = $this->completeDecision(run: $run, resumeResult: $resumeResult);

		return new JSONResponse(
			[
				'engineRunUuid' => (string)($run->data['engineRunUuid'] ?? ''),
				'signalled' => $signalled,
				'_approval' => $this->approvalSummary(approvalRequest: $approvalRequest),
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
	 * @param ApprovalDecisionRun $run      The decision (`engineRunUuid`/`signalNodeId` in its data).
	 * @param string              $decision `approved` or `rejected`.
	 *
	 * @return boolean True when the signal was delivered.
	 *
	 * @spec openspec/changes/retire-integriq-flow-schema/tasks.md#1-the-missing-node
	 */
	private function signalEngineRun(ApprovalDecisionRun $run, string $decision): bool {
		if ($this->engineSignal === null) {
			$this->logger->warning(
				'ApprovalDecisionService: no EngineSignalService wired; the engine run resumes on its next heartbeat instead',
				['engineRunUuid' => ($run->data['engineRunUuid'] ?? '')]
			);
			return false;
		}

		return $this->engineSignal->deliver(data: $run->data, decision: $decision, user: $run->user, comment: $run->comment);

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

		$body['_approval'] = $this->approvalSummary(approvalRequest: $approvalRequest);

		return $body;
	}//end envelopeApprovalOutcome()

	/**
	 * The `_approval` block of a resumed run's answer.
	 *
	 * @param ObjectEntity $approvalRequest The finalized approval_request.
	 *
	 * @return array{id: string|null, status: mixed, resumedAt: mixed} The block.
	 */
	private function approvalSummary(ObjectEntity $approvalRequest): array {
		$data = $approvalRequest->getObject();
		return [
			'id' => $approvalRequest->getUuid(),
			'status' => ($data['status'] ?? 'approved'),
			'resumedAt' => ($data['approvedAt'] ?? null),
		];

	}//end approvalSummary()
}//end class
