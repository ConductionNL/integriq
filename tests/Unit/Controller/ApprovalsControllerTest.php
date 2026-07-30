<?php

/**
 * Unit tests for ApprovalsController — the two-layer authorization model,
 * the state guards (409/404/400), and the approve/reject wiring.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Controller;

use OCA\OpenConnector\Controller\ApprovalsController;
use OCA\OpenConnector\Exception\ApprovalStateException;
use OCA\OpenConnector\Service\ActionAuthService;
use OCA\OpenConnector\Service\ApprovalService;
use OCA\OpenConnector\Service\EndpointService;
use OCA\OpenConnector\Service\FlowRunnerService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the Pending Approvals REST surface.
 *
 * @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md
 */
class ApprovalsControllerTest extends TestCase
{

    /**
     * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
     */
    private $request;

    /**
     * @var ApprovalService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $approvalService;

    /**
     * @var EndpointService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $endpointService;

    /**
     * @var SynchronizationService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $synchronizationService;

    /**
     * @var FlowRunnerService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $flowRunnerService;

    /**
     * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var ActionAuthService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $actionAuth;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private $userSession;

    /**
     * @var ApprovalsController
     */
    private ApprovalsController $controller;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request                = $this->createMock(IRequest::class);
        $this->approvalService        = $this->createMock(ApprovalService::class);
        $this->endpointService        = $this->createMock(EndpointService::class);
        $this->synchronizationService = $this->createMock(SynchronizationService::class);
        $this->flowRunnerService      = $this->createMock(FlowRunnerService::class);
        $this->orObjectService        = $this->createMock(OrObjectService::class);
        $this->actionAuth             = $this->createMock(ActionAuthService::class);
        $this->userSession            = $this->createMock(IUserSession::class);
        $l                            = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new ApprovalsController(
            'openconnector',
            $this->request,
            $this->approvalService,
            $this->endpointService,
            $this->synchronizationService,
            $this->flowRunnerService,
            $this->orObjectService,
            $this->actionAuth,
            $this->userSession,
            $l,
            $this->createMock(LoggerInterface::class),
        );

    }//end setUp()

    /**
     * Build a real ObjectEntity.
     *
     * @param array  $body The object data.
     * @param string $uuid The entity uuid.
     *
     * @return ObjectEntity
     */
    private function entity(array $body, string $uuid='approval-1'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setObject($body);
        return $entity;

    }//end entity()

    /**
     * TC-15: a user outside the app-wide action matrix cannot approve, even
     * if in the approver group — the matrix check fails first — REQ-006.
     *
     * @return void
     */
    public function testApproveDeniedByActionMatrixLayer(): void
    {
        $this->actionAuth->method('requireAction')->willThrowException(new OCSForbiddenException('nope'));
        $this->approvalService->expects($this->never())->method('find');

        $response = $this->controller->approve('approval-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testApproveDeniedByActionMatrixLayer()

    /**
     * TC-14: a user who passes the matrix but is NOT in the request's
     * approverGroup is denied by the per-object layer — REQ-006.
     *
     * @return void
     */
    public function testApproveDeniedByPerObjectGroupLayer(): void
    {
        $request = $this->entity(['status' => 'pending', 'approverGroup' => 'woo-approvers', 'endpointId' => 'ep-1']);
        $this->approvalService->method('find')->willReturn($request);
        $this->approvalService->method('isAuthorizedApprover')->willReturn(false);

        $this->endpointService->expects($this->never())->method('resumeFromApproval');

        $response = $this->controller->approve('approval-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testApproveDeniedByPerObjectGroupLayer()

    /**
     * TC-8: an already-resolved request returns 409 and the chain is not
     * re-run — REQ-003.
     *
     * @return void
     */
    public function testApproveAlreadyResolvedReturns409(): void
    {
        $request = $this->entity(['status' => 'approved', 'approverGroup' => 'woo-approvers']);
        $this->approvalService->method('find')->willReturn($request);
        $this->approvalService->method('isAuthorizedApprover')->willReturn(true);
        $this->approvalService->method('assertActionable')
            ->willThrowException(new ApprovalStateException('not pending', 409));

        $this->endpointService->expects($this->never())->method('resumeFromApproval');

        $response = $this->controller->approve('approval-1');

        $this->assertSame(409, $response->getStatus());

    }//end testApproveAlreadyResolvedReturns409()

    /**
     * TC-12: an already-expired pending request returns 409 (race close) —
     * REQ-005.
     *
     * @return void
     */
    public function testApproveExpiredReturns409(): void
    {
        $request = $this->entity(['status' => 'pending', 'approverGroup' => 'woo-approvers']);
        $this->approvalService->method('find')->willReturn($request);
        $this->approvalService->method('isAuthorizedApprover')->willReturn(true);
        $this->approvalService->method('assertActionable')
            ->willThrowException(new ApprovalStateException('expired', 409));

        $response = $this->controller->approve('approval-1');

        $this->assertSame(409, $response->getStatus());

    }//end testApproveExpiredReturns409()

    /**
     * TC-6: a happy-path endpoint approval resumes the pipeline and finalizes
     * the request — REQ-003.
     *
     * @return void
     */
    public function testApproveEndpointHappyPathResumesAndCompletes(): void
    {
        $request = $this->entity(['status' => 'pending', 'approverGroup' => 'woo-approvers', 'endpointId' => 'ep-1', 'resumeOrder' => 20, 'snapshot' => []]);
        $this->approvalService->method('find')->willReturn($request);
        $this->approvalService->method('isAuthorizedApprover')->willReturn(true);
        $this->approvalService->method('rehydrateFlowToken')->willReturn(new \OCA\OpenConnector\Service\Helper\FlowToken());

        $endpoint = $this->entity(['name' => 'WOO Publish'], 'ep-1');
        $this->endpointService->method('getEndpointById')->willReturn($endpoint);
        $this->endpointService->method('resumeFromApproval')
            ->willReturn(new \OCP\AppFramework\Http\JSONResponse(['id' => 'obj-1'], 201));

        $this->approvalService->expects($this->once())->method('completeApproval')
            ->willReturn($this->entity(['status' => 'approved', 'approvedAt' => 'now'], 'approval-1'));

        $response = $this->controller->approve('approval-1');

        $this->assertSame(201, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('approved', $data['_approval']['status']);

    }//end testApproveEndpointHappyPathResumesAndCompletes()

    /**
     * TC-10: approving a flow-sourced approval_request (flowRunId set)
     * resumes via FlowRunnerService::resumeFromApproval() — flow-orchestration
     * REQ-005.
     *
     * @return void
     */
    public function testApproveFlowHappyPathResumesAndCompletes(): void
    {
        $request = $this->entity(['status' => 'pending', 'approverGroup' => 'woo-approvers', 'flowRunId' => 'flow-run-1', 'resumeStepOrder' => 30, 'snapshot' => []]);
        $this->approvalService->method('find')->willReturn($request);
        $this->approvalService->method('isAuthorizedApprover')->willReturn(true);

        $flowRun = $this->entity(['status' => 'completed'], 'flow-run-1');
        $this->flowRunnerService->expects($this->once())->method('resumeFromApproval')->willReturn($flowRun);

        $this->approvalService->expects($this->once())->method('completeApproval')
            ->willReturn($this->entity(['status' => 'approved', 'approvedAt' => 'now'], 'approval-1'));

        $response = $this->controller->approve('approval-1');

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('completed', $data['status']);
        $this->assertSame('approved', $data['_approval']['status']);

    }//end testApproveFlowHappyPathResumesAndCompletes()

    /**
     * TC-11: rejecting a flow-sourced approval_request stops the flow_run
     * via FlowRunnerService::stopFromApprovalOutcome() — flow-orchestration
     * REQ-005.
     *
     * @return void
     */
    public function testRejectFlowStopsFlowRun(): void
    {
        $request = $this->entity(['status' => 'pending', 'approverGroup' => 'woo-approvers', 'flowRunId' => 'flow-run-1']);
        $this->approvalService->method('find')->willReturn($request);
        $this->approvalService->method('isAuthorizedApprover')->willReturn(true);
        $this->request->method('getParam')->willReturn('not-empty-comment');
        $this->approvalService->method('reject')
            ->willReturn($this->entity(['status' => 'rejected', 'flowRunId' => 'flow-run-1', 'rejectedAt' => 'now'], 'approval-1'));

        $this->flowRunnerService->expects($this->once())->method('stopFromApprovalOutcome');

        $response = $this->controller->reject('approval-1');

        $this->assertSame(200, $response->getStatus());

    }//end testRejectFlowStopsFlowRun()

    /**
     * TC-9: reject with an empty comment returns 400 and leaves the request
     * pending — REQ-004.
     *
     * @return void
     */
    public function testRejectEmptyCommentReturns400(): void
    {
        $request = $this->entity(['status' => 'pending', 'approverGroup' => 'woo-approvers']);
        $this->approvalService->method('find')->willReturn($request);
        $this->approvalService->method('isAuthorizedApprover')->willReturn(true);
        $this->request->method('getParam')->willReturn('');
        $this->approvalService->method('reject')
            ->willThrowException(new ApprovalStateException('comment required', 400));

        $response = $this->controller->reject('approval-1');

        $this->assertSame(400, $response->getStatus());

    }//end testRejectEmptyCommentReturns400()

    /**
     * show(): a 404 from the service maps to a 404 response.
     *
     * @return void
     */
    public function testShowMissingReturns404(): void
    {
        $this->approvalService->method('find')
            ->willThrowException(new ApprovalStateException('no such', 404));

        $response = $this->controller->show('missing');

        $this->assertSame(404, $response->getStatus());

    }//end testShowMissingReturns404()

    /**
     * index(): lists rows for the caller (REQ-007 list surface).
     *
     * @return void
     */
    public function testIndexListsForCaller(): void
    {
        $this->request->method('getParam')->willReturn('pending');
        $this->approvalService->method('listFor')->willReturn(
            [$this->entity(['status' => 'pending', 'approverGroup' => 'woo-approvers'], 'a-1')]
        );

        $response = $this->controller->index();

        $this->assertSame(200, $response->getStatus());
        $data = $response->getData();
        $this->assertCount(1, $data['results']);
        $this->assertSame('a-1', $data['results'][0]['id']);

    }//end testIndexListsForCaller()
}//end class
