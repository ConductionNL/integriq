<?php

/**
 * Integriq Approvals Controller.
 *
 * REST controller for the human-in-the-loop (HITL) Pending Approvals
 * surface: list/detail, approve, reject. Orchestrates `ApprovalService`
 * (state machine + two-layer authorization), `EndpointService`
 * (`resumeFromApproval()` — the endpoint rule-pipeline resume path) and
 * `SynchronizationService` (`synchronize()` — the batch-gate resume path).
 * Composing the two resume paths here (rather than inside `ApprovalService`)
 * keeps the service graph acyclic: `EndpointService` and
 * `SynchronizationService` both depend on `ApprovalService` (to suspend),
 * so `ApprovalService` cannot depend back on either without a cycle — see
 * openspec/changes/archive/2026-07-15-hitl-approval-rule-action/design.md and this controller's
 * class docblock for the full rationale.
 *
 * @category Controller
 * @package  OCA\Integriq\Controller
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
 * @spec openspec/changes/archive/2026-07-15-hitl-approval-rule-action/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Integriq\Controller;

use OCA\Integriq\Exception\ApprovalStateException;
use OCA\Integriq\Service\ActionAuthService;
use OCA\Integriq\Service\ApprovalDecisionService;
use OCA\Integriq\Service\ApprovalService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Pending Approvals REST surface: index/show/approve/reject.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.LongVariable)
 *
 * @spec openspec/specs/approval-workflow/spec.md
 */
class ApprovalsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The current request.
	 * @param ApprovalService $approvalService The approval state-machine + authorization service.
	 * @param ApprovalDecisionService $decisionService Resumes or stops the suspended run after a decision.
	 * @param ActionAuthService $actionAuth ADR-023 action-matrix (coarse) authorization gate.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l The localization service.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ApprovalService $approvalService,
		private readonly ApprovalDecisionService $decisionService,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
		private readonly IL10N $l,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * List approval_request rows visible to the caller (design.md `GET /api/approvals`).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		$status = $this->request->getParam('status');
		$rows = $this->approvalService->listFor(user: $user, statusFilter: $status);

		return new JSONResponse(['results' => array_map([$this, 'summarize'], $rows)]);
	}//end index()

	/**
	 * Show one approval_request's detail (design.md `GET /api/approvals/{id}`).
	 * Object-level authorization: an admin, a member of the request's
	 * `approverGroup`, or the requester may view it.
	 *
	 * @param string $id The approval_request id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-07-15-hitl-approval-rule-action/design.md#api-design
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$approvalRequest = $this->approvalService->find(id: $id);
		} catch (ApprovalStateException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
		}

		$data = $approvalRequest->getObject();
		$isRequester = (($data['requesterUserId'] ?? null) === $user->getUID());

		if ($this->approvalService->isAuthorizedApprover(approvalRequest: $approvalRequest, user: $user) === false
			&& $isRequester === false
		) {
			return new JSONResponse(['error' => $this->l->t('Not authorized to view this approval request')], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($this->detail(row: $approvalRequest));
	}//end show()

	/**
	 * Approve a `pending`, non-expired approval_request: two-layer
	 * authorization (design.md Decision 5), then resume the suspended
	 * chain synchronously (design.md Decision 3).
	 *
	 * @param string $id The approval_request id.
	 *
	 * @return JSONResponse The resumed pipeline's final result, envelope-wrapped with `_approval`.
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	#[NoAdminRequired]
	public function approve(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'approval.approve');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		try {
			$approvalRequest = $this->approvalService->find(id: $id);
		} catch (ApprovalStateException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
		}

		// Per-object layer (design.md Decision 5): passing the app-wide
		// matrix check above is not sufficient — the caller must also be in
		// THIS request's configured approverGroup (or be an admin).
		if ($this->approvalService->isAuthorizedApprover(approvalRequest: $approvalRequest, user: $user) === false) {
			return new JSONResponse(['error' => $this->l->t('You are not a member of this request\'s approver group')], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->approvalService->assertActionable(approvalRequest: $approvalRequest);
		} catch (ApprovalStateException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
		}

		return $this->decisionService->approve(
			approvalRequest: $approvalRequest,
			user: $user,
			comment: $this->request->getParam('comment')
		);

	}//end approve()

	/**
	 * Reject a `pending`, non-expired approval_request. Self-contained in
	 * `ApprovalService::reject()` — the original caller's status poll
	 * reflects the rejection; no pipeline is re-invoked.
	 *
	 * @param string $id The approval_request id.
	 *
	 * @return JSONResponse `{ id, status, comment, rejectedAt }` (design.md).
	 *
	 * @spec openspec/specs/approval-workflow/spec.md
	 */
	#[NoAdminRequired]
	public function reject(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: 'approval.reject');
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		try {
			$approvalRequest = $this->approvalService->find(id: $id);
		} catch (ApprovalStateException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
		}

		if ($this->approvalService->isAuthorizedApprover(approvalRequest: $approvalRequest, user: $user) === false) {
			return new JSONResponse(['error' => $this->l->t('You are not a member of this request\'s approver group')], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->approvalService->assertActionable(approvalRequest: $approvalRequest);
		} catch (ApprovalStateException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
		}

		$comment = (string)$this->request->getParam('comment', '');

		try {
			$approvalRequest = $this->decisionService->reject(approvalRequest: $approvalRequest, user: $user, comment: $comment);
		} catch (ApprovalStateException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
		}

		$data = $approvalRequest->getObject();

		return new JSONResponse(
			[
				'id' => $approvalRequest->getUuid(),
				'status' => ($data['status'] ?? 'rejected'),
				'comment' => ($data['comment'] ?? $comment),
				'rejectedAt' => ($data['rejectedAt'] ?? null),
			]
		);

	}//end reject()

	/**
	 * Summarize an approval_request row for the list endpoint (design.md
	 * `GET /api/approvals` response shape).
	 *
	 * @param ObjectEntity $row The approval_request.
	 *
	 * @return array
	 */
	private function summarize(ObjectEntity $row): array {
		$data = $row->getObject();

		return [
			'id' => $row->getUuid(),
			'status' => ($data['status'] ?? 'pending'),
			'endpointId' => ($data['endpointId'] ?? null),
			'ruleId' => ($data['ruleId'] ?? null),
			'synchronizationId' => ($data['synchronizationId'] ?? null),
			'flowRunId' => ($data['flowRunId'] ?? null),
			'requester' => ($data['requesterUserId'] ?? null),
			'approverGroup' => ($data['approverGroup'] ?? null),
			'createdAt' => ($data['createdAt'] ?? null),
			'expiresAt' => ($data['expiresAt'] ?? null),
		];

	}//end summarize()

	/**
	 * Full detail payload for the show endpoint: everything `summarize()`
	 * carries plus the audit fields and a redacted snapshot preview
	 * (method/path — never raw FlowToken internals, per design.md API
	 * Design's `GET /api/approvals/{id}` response shape).
	 *
	 * @param ObjectEntity $row The approval_request.
	 *
	 * @return array
	 */
	private function detail(ObjectEntity $row): array {
		$data = $row->getObject();
		$snapshot = ($data['snapshot'] ?? []);
		$requestOriginal = ($snapshot['requestOriginal'] ?? []);

		return array_merge(
			$this->summarize(row: $row),
			[
				'onReject' => ($data['onReject'] ?? null),
				'onTimeout' => ($data['onTimeout'] ?? null),
				'approverUserId' => ($data['approverUserId'] ?? null),
				'comment' => ($data['comment'] ?? null),
				'approvedAt' => ($data['approvedAt'] ?? null),
				'rejectedAt' => ($data['rejectedAt'] ?? null),
				'resumeResult' => ($data['resumeResult'] ?? null),
				'supersededBy' => ($data['supersededBy'] ?? null),
				'changeSet' => ($snapshot['changeSet'] ?? null),
				'snapshotPreview' => [
					'method' => ($requestOriginal['method'] ?? null),
					'path' => ($requestOriginal['path'] ?? null),
				],
			]
		);

	}//end detail()
}//end class
