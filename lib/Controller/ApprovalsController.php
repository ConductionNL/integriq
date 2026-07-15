<?php

/**
 * OpenConnector Approvals Controller.
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
 * openspec/changes/hitl-approval-rule-action/design.md and this controller's
 * class docblock for the full rationale.
 *
 * @category Controller
 * @package  OCA\OpenConnector\Controller
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
 * @spec openspec/changes/hitl-approval-rule-action/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Controller;

use OCA\OpenConnector\Exception\ApprovalStateException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\ApprovalService;
use OCA\OpenConnector\Service\EndpointService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pending Approvals REST surface: index/show/approve/reject.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)
 * @SuppressWarnings(PHPMD.LongVariable)
 *
 * @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md
 */
class ApprovalsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                 $appName                The app id.
     * @param IRequest               $request                The current request.
     * @param ApprovalService        $approvalService        The approval state-machine + authorization service.
     * @param EndpointService        $endpointService        Resumes a suspended endpoint rule-pipeline run.
     * @param SynchronizationService $synchronizationService Resumes a gated Synchronization batch run.
     * @param OrObjectService        $orObjectService        OpenRegister object service (loads the gated synchronization).
     * @param ActionAuthService      $actionAuth             ADR-023 action-matrix (coarse) authorization gate.
     * @param IUserSession           $userSession            The user session.
     * @param IL10N                  $l                      The localization service.
     * @param LoggerInterface        $logger                 Logger for non-fatal diagnostics.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ApprovalService $approvalService,
        private readonly EndpointService $endpointService,
        private readonly SynchronizationService $synchronizationService,
        private readonly OrObjectService $orObjectService,
        private readonly ActionAuthService $actionAuth,
        private readonly IUserSession $userSession,
        private readonly IL10N $l,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * List approval_request rows visible to the caller (design.md `GET /api/approvals`).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-007-pending-approvals-ui
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        $status = $this->request->getParam('status');
        $rows   = $this->approvalService->listFor(user: $user, statusFilter: $status);

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
     * @spec openspec/changes/hitl-approval-rule-action/design.md#api-design
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l->t('Not authenticated')], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $approvalRequest = $this->approvalService->find(id: $id);
        } catch (ApprovalStateException $e) {
            return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
        }

        $data        = $approvalRequest->getObject();
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
     * @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-003-resume-on-approval
     * @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-006-two-layer-authorization-for-approvereject
     */
    #[NoAdminRequired]
    public function approve(string $id): JSONResponse
    {
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

        $comment = $this->request->getParam('comment');
        $data    = $approvalRequest->getObject();

        if (empty($data['endpointId']) === false) {
            return $this->approveEndpointSuspension(approvalRequest: $approvalRequest, data: $data, user: $user, comment: $comment);
        }

        if (empty($data['synchronizationId']) === false) {
            return $this->approveSynchronizationGate(approvalRequest: $approvalRequest, data: $data, user: $user, comment: $comment);
        }

        $this->logger->error('ApprovalsController: approval_request has neither endpointId nor synchronizationId', ['id' => $id]);
        return new JSONResponse(['error' => $this->l->t('Malformed approval request')], Http::STATUS_INTERNAL_SERVER_ERROR);

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
     * @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-004-rejection-with-mandatory-audit-comment
     */
    #[NoAdminRequired]
    public function reject(string $id): JSONResponse
    {
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

        $comment = (string) $this->request->getParam('comment', '');

        try {
            $approvalRequest = $this->approvalService->reject(approvalRequest: $approvalRequest, approver: $user, comment: $comment);
        } catch (ApprovalStateException $e) {
            return new JSONResponse(['error' => $e->getMessage()], $e->getHttpStatus());
        }

        $data = $approvalRequest->getObject();

        return new JSONResponse(
            [
                'id'         => $approvalRequest->getUuid(),
                'status'     => ($data['status'] ?? 'rejected'),
                'comment'    => ($data['comment'] ?? $comment),
                'rejectedAt' => ($data['rejectedAt'] ?? null),
            ]
        );

    }//end reject()

    /**
     * Resume a suspended endpoint rule-pipeline run and finalize the
     * approval_request with the resumed chain's outcome.
     *
     * @param ObjectEntity $approvalRequest The pending, authorized-to-act-on request.
     * @param array        $data            The approval_request's object data.
     * @param IUser        $user            The approving user.
     * @param string|null  $comment         Optional approve comment.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md#req-003-resume-on-approval
     */
    private function approveEndpointSuspension(ObjectEntity $approvalRequest, array $data, IUser $user, ?string $comment): JSONResponse
    {
        $endpoint = $this->endpointService->getEndpointById((string) $data['endpointId']);
        if ($endpoint === null) {
            return new JSONResponse(['error' => $this->l->t('The suspended endpoint no longer exists')], Http::STATUS_NOT_FOUND);
        }

        $flowToken = $this->approvalService->rehydrateFlowToken(($data['snapshot'] ?? []));
        $path      = (string) ($flowToken->getRequestAmended()['path'] ?? '');

        $resumed = $this->endpointService->resumeFromApproval(
            endpoint: $endpoint,
            request: $this->request,
            flowToken: $flowToken,
            resumeAfterOrder: (int) ($data['resumeOrder'] ?? 0),
            path: $path
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
     * @param array        $data            The approval_request's object data.
     * @param IUser        $user            The approving user.
     * @param string|null  $comment         Optional approve comment.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/hitl-approval-rule-action/specs/synchronization-engine/spec.md#req-015-batch-level-approval-gate-before-target-writes
     */
    private function approveSynchronizationGate(ObjectEntity $approvalRequest, array $data, IUser $user, ?string $comment): JSONResponse
    {
        try {
            $synchronization = $this->orObjectService->find(
                id: (string) $data['synchronizationId'],
                register: 'openconnector',
                schema: 'synchronization',
                _rbac: false,
                _multitenancy: false
            );
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => $this->l->t('The gated synchronization no longer exists')], Http::STATUS_NOT_FOUND);
        }

        $resumeResult = 'success';
        $statusCode   = Http::STATUS_OK;
        $result       = [];

        try {
            $result = $this->synchronizationService->synchronize(
                synchronization: $synchronization,
                force: true,
                approvalRequestId: $approvalRequest->getUuid()
            );
        } catch (Throwable $e) {
            $this->logger->error('ApprovalsController: resumed synchronization failed: '.$e->getMessage(), ['exception' => $e]);
            $resumeResult = 'error';
            $statusCode   = Http::STATUS_INTERNAL_SERVER_ERROR;
            $result       = ['error' => $e->getMessage()];
        }

        $approvalRequest = $this->approvalService->completeApproval(
            approvalRequest: $approvalRequest,
            approver: $user,
            resumeResult: $resumeResult,
            comment: $comment
        );

        $body = ['data' => $result];
        if (is_array($result) === true) {
            $body = $result;
        }

        $approvalRequestData = $approvalRequest->getObject();
        $body['_approval']   = [
            'id'        => $approvalRequest->getUuid(),
            'status'    => ($approvalRequestData['status'] ?? 'approved'),
            'resumedAt' => ($approvalRequestData['approvedAt'] ?? null),
        ];

        return new JSONResponse($body, $statusCode);

    }//end approveSynchronizationGate()

    /**
     * Build the `_approval`-enveloped response body for a resumed endpoint response.
     *
     * @param Response     $resumed         The resumed pipeline's final Response.
     * @param ObjectEntity $approvalRequest The finalized approval_request.
     *
     * @return array
     */
    private function envelopeApprovalOutcome(Response $resumed, ObjectEntity $approvalRequest): array
    {
        $body = [];
        if ($resumed instanceof JSONResponse) {
            $body = $resumed->getData();
        }

        if (is_array($body) === false) {
            $body = ['data' => $body];
        }

        $data = $approvalRequest->getObject();
        $body['_approval'] = [
            'id'        => $approvalRequest->getUuid(),
            'status'    => ($data['status'] ?? 'approved'),
            'resumedAt' => ($data['approvedAt'] ?? null),
        ];

        return $body;

    }//end envelopeApprovalOutcome()

    /**
     * Summarize an approval_request row for the list endpoint (design.md
     * `GET /api/approvals` response shape).
     *
     * @param ObjectEntity $row The approval_request.
     *
     * @return array
     */
    private function summarize(ObjectEntity $row): array
    {
        $data = $row->getObject();

        return [
            'id'                => $row->getUuid(),
            'status'            => ($data['status'] ?? 'pending'),
            'endpointId'        => ($data['endpointId'] ?? null),
            'ruleId'            => ($data['ruleId'] ?? null),
            'synchronizationId' => ($data['synchronizationId'] ?? null),
            'requester'         => ($data['requesterUserId'] ?? null),
            'approverGroup'     => ($data['approverGroup'] ?? null),
            'createdAt'         => ($data['createdAt'] ?? null),
            'expiresAt'         => ($data['expiresAt'] ?? null),
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
    private function detail(ObjectEntity $row): array
    {
        $data            = $row->getObject();
        $snapshot        = ($data['snapshot'] ?? []);
        $requestOriginal = ($snapshot['requestOriginal'] ?? []);

        return array_merge(
            $this->summarize(row: $row),
            [
                'onReject'        => ($data['onReject'] ?? null),
                'onTimeout'       => ($data['onTimeout'] ?? null),
                'approverUserId'  => ($data['approverUserId'] ?? null),
                'comment'         => ($data['comment'] ?? null),
                'approvedAt'      => ($data['approvedAt'] ?? null),
                'rejectedAt'      => ($data['rejectedAt'] ?? null),
                'resumeResult'    => ($data['resumeResult'] ?? null),
                'snapshotPreview' => [
                    'method' => ($requestOriginal['method'] ?? null),
                    'path'   => ($requestOriginal['path'] ?? null),
                ],
            ]
        );

    }//end detail()
}//end class
