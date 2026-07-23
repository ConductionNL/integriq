<?php

/**
 * Unit tests for ApprovalService — the HITL approval_request state machine,
 * two-layer authorization, snapshot stripping/rehydration, and expiry sweep.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
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

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Exception\ApprovalStateException;
use OCA\OpenConnector\Service\ApprovalService;
use OCA\OpenConnector\Service\Helper\FlowToken;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IURLGenerator;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Notification\IAction;
use OCP\Notification\INotification;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the approval_request state machine and authorization model.
 *
 * @spec openspec/changes/hitl-approval-rule-action/specs/approval-workflow/spec.md
 */
class ApprovalServiceTest extends TestCase
{

    /**
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;

    /**
     * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
     */
    private $userSession;

    /**
     * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $groupManager;

    /**
     * @var INotificationManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $notificationManager;

    /**
     * @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject
     */
    private $urlGenerator;

    /**
     * @var ApprovalService
     */
    private ApprovalService $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectService       = ObjectServiceMockBuilder::make($this);
        $this->userSession         = $this->createMock(IUserSession::class);
        $this->groupManager        = $this->createMock(IGroupManager::class);
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $this->urlGenerator        = $this->createMock(IURLGenerator::class);
        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.org/apps/openconnector/');

        $this->service = new ApprovalService(
            $this->objectService,
            $this->userSession,
            $this->groupManager,
            $this->notificationManager,
            $this->urlGenerator,
            $this->createMock(LoggerInterface::class),
        );

    }//end setUp()

    /**
     * Build a real ObjectEntity from a body + uuid.
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
     * Wire the notification manager to return a self-returning notification
     * stub so `notifyApprovers()` does not fatal on chained setters.
     *
     * @return void
     */
    private function stubNotificationChain(): void
    {
        $action = $this->createMock(IAction::class);
        $action->method('setLabel')->willReturnSelf();
        $action->method('setLink')->willReturnSelf();

        $notification = $this->createMock(INotification::class);
        foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setLink'] as $method) {
            $notification->method($method)->willReturnSelf();
        }

        $notification->method('createAction')->willReturn($action);
        $notification->method('addAction')->willReturnSelf();

        $this->notificationManager->method('createNotification')->willReturn($notification);

    }//end stubNotificationChain()

    /**
     * suspend() persists a pending request with a sensitive-header-stripped
     * snapshot — REQ-001 (and its security scenario).
     *
     * @return void
     */
    public function testSuspendPersistsPendingAndStripsSensitiveHeaders(): void
    {
        $this->stubNotificationChain();
        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getUsers')->willReturn([]);
        $this->groupManager->method('get')->willReturn($group);

        $endpoint = $this->entity(['name' => 'WOO Publish'], 'endpoint-1');
        $rule     = $this->entity(
            [
                'order'         => 20,
                'configuration' => [
                    'approval' => [
                        'approverGroup' => 'woo-approvers',
                        'onReject'      => 'error',
                        'onTimeout'     => 'dead_letter',
                        'ttlSeconds'    => 3600,
                    ],
                ],
            ],
            'rule-1'
        );

        $flowToken = new FlowToken();
        $flowToken->setRequestOriginal(['method' => 'POST', 'headers' => ['authorization' => 'Bearer secret-token', 'content-type' => 'application/json'], 'path' => '/woo', 'parameters' => ['a' => 1]]);
        $flowToken->setRequestAmended($flowToken->getRequestOriginal());

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured) {
                $captured = $object;
                return $this->entity($object, 'approval-created');
            }
        );

        $result = $this->service->suspend(endpoint: $endpoint, rule: $rule, flowToken: $flowToken);

        $this->assertSame('approval-created', $result->getUuid());
        $this->assertSame('pending', $captured['status']);
        $this->assertSame(20, $captured['resumeOrder']);
        $this->assertSame('woo-approvers', $captured['approverGroup']);
        $this->assertSame('before', $captured['timing']);
        $this->assertNotEmpty($captured['expiresAt']);
        // Authorization header must be redacted, content-type preserved.
        $this->assertSame('***redacted***', $captured['snapshot']['requestOriginal']['headers']['authorization']);
        $this->assertSame('application/json', $captured['snapshot']['requestOriginal']['headers']['content-type']);

    }//end testSuspendPersistsPendingAndStripsSensitiveHeaders()

    /**
     * suspendForFlow() mirrors suspend()'s own persistence shape exactly —
     * `flowRunId`/`resumeStepOrder` set instead of `endpointId`/`ruleId`,
     * same header-stripped snapshot, same notify call — visual-flow-orchestration
     * design.md Decision 4 / flow-orchestration REQ-005.
     *
     * @return void
     */
    public function testSuspendForFlowPersistsFlowRunIdAndResumeStepOrder(): void
    {
        $this->stubNotificationChain();
        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getUsers')->willReturn([]);
        $this->groupManager->method('get')->willReturn($group);

        $flowRun   = $this->entity(['status' => 'running'], 'flow-run-1');
        $flowToken = new FlowToken();
        $flowToken->setRequestOriginal(['method' => 'POST', 'headers' => ['authorization' => 'Bearer secret-token'], 'path' => '/flow']);
        $flowToken->setRequestAmended($flowToken->getRequestOriginal());

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured) {
                $captured = $object;
                return $this->entity($object, 'approval-created');
            }
        );

        $result = $this->service->suspendForFlow(
            flowRun: $flowRun,
            resumeStepOrder: 30,
            config: ['approverGroup' => 'ops-approvers', 'onReject' => 'error', 'onTimeout' => 'dead_letter', 'ttlSeconds' => 3600],
            flowToken: $flowToken
        );

        $this->assertSame('approval-created', $result->getUuid());
        $this->assertSame('pending', $captured['status']);
        $this->assertSame('flow-run-1', $captured['flowRunId']);
        $this->assertSame(30, $captured['resumeStepOrder']);
        $this->assertSame('ops-approvers', $captured['approverGroup']);
        $this->assertArrayNotHasKey('endpointId', $captured);
        $this->assertSame('***redacted***', $captured['snapshot']['requestOriginal']['headers']['authorization']);

    }//end testSuspendForFlowPersistsFlowRunIdAndResumeStepOrder()

    /**
     * notifyApprovers(): every member of the configured approver group
     * receives an actionable notification carrying approve/reject deep
     * links into the Pending Approvals UI — REQ-002 / TC-4. Unlike
     * testSuspendPersistsPendingAndStripsSensitiveHeaders() (which stubs an
     * empty group so the notify loop body never runs), this test wires a
     * two-member group and asserts the loop's actual side effects: one
     * `notify()` call per member, the `approval_pending` subject with the
     * approver group parameter, and two actions (approve/reject) per
     * notification, each deep-linking to `/approvals/{id}`.
     *
     * @return void
     */
    public function testNotifyApproversNotifiesEachGroupMemberWithActionableDeepLinks(): void
    {
        $userA = $this->mockUser('alice');
        $userB = $this->mockUser('bob');

        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getUsers')->willReturn([$userA, $userB]);
        $this->groupManager->method('get')->willReturn($group);

        $this->urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.org/apps/openconnector/dashboard');

        $recordedUsers    = [];
        $recordedSubjects = [];
        $recordedActions  = [];

        $makeNotification = function () use (&$recordedUsers, &$recordedSubjects, &$recordedActions) {
            $notification = $this->createMock(INotification::class);
            $notification->method('setApp')->willReturnSelf();
            $notification->method('setUser')->willReturnCallback(
                function (string $uid) use ($notification, &$recordedUsers) {
                    $recordedUsers[] = $uid;
                    return $notification;
                }
            );
            $notification->method('setDateTime')->willReturnSelf();
            $notification->method('setObject')->willReturnSelf();
            $notification->method('setSubject')->willReturnCallback(
                function (string $subject, array $params=[]) use ($notification, &$recordedSubjects) {
                    $recordedSubjects[] = ['subject' => $subject, 'params' => $params];
                    return $notification;
                }
            );
            $notification->method('setLink')->willReturnSelf();
            $notification->method('createAction')->willReturnCallback(
                function () {
                    return new class implements IAction
                    {
                        private string $label = '';
                        private string $link = '';
                        private string $requestType = '';
                        private bool $primary = false;
                        public function setLabel(string $label): IAction
                        {
                            $this->label = $label;
                            return $this;
                        }
                        public function getLabel(): string
                        {
                            return $this->label;
                        }
                        public function setParsedLabel(string $label): IAction
                        {
                            return $this;
                        }
                        public function getParsedLabel(): string
                        {
                            return '';
                        }
                        public function setPrimary(bool $primary): IAction
                        {
                            $this->primary = $primary;
                            return $this;
                        }
                        public function isPrimary(): bool
                        {
                            return $this->primary;
                        }
                        public function setLink(string $link, string $requestType): IAction
                        {
                            $this->link        = $link;
                            $this->requestType = $requestType;
                            return $this;
                        }
                        public function getLink(): string
                        {
                            return $this->link;
                        }
                        public function getRequestType(): string
                        {
                            return $this->requestType;
                        }
                        public function isValid(): bool
                        {
                            return true;
                        }
                        public function isValidParsed(): bool
                        {
                            return true;
                        }
                    };
                }
            );
            $notification->method('addAction')->willReturnCallback(
                function (IAction $action) use ($notification, &$recordedActions) {
                    $recordedActions[] = ['label' => $action->getLabel(), 'link' => $action->getLink()];
                    return $notification;
                }
            );
            return $notification;
        };

        $this->notificationManager->method('createNotification')->willReturnCallback($makeNotification);
        $this->notificationManager->expects($this->exactly(2))->method('notify');

        $approvalRequest = $this->entity(['approverGroup' => 'woo-approvers'], 'approval-notify-1');

        $this->service->notifyApprovers(approvalRequest: $approvalRequest);

        $this->assertSame(['alice', 'bob'], $recordedUsers);
        $this->assertCount(2, $recordedSubjects);
        $this->assertSame('approval_pending', $recordedSubjects[0]['subject']);
        $this->assertSame('woo-approvers', $recordedSubjects[0]['params']['approverGroup']);

        // 2 actions (approve + reject) per notified user = 4 total.
        $this->assertCount(4, $recordedActions);
        $this->assertSame('approve', $recordedActions[0]['label']);
        $this->assertStringContainsString('/approvals/approval-notify-1', $recordedActions[0]['link']);
        $this->assertStringContainsString('?action=approve', $recordedActions[0]['link']);
        $this->assertSame('reject', $recordedActions[1]['label']);
        $this->assertStringContainsString('?action=reject', $recordedActions[1]['link']);

    }//end testNotifyApproversNotifiesEachGroupMemberWithActionableDeepLinks()

    /**
     * rehydrateFlowToken() reconstructs the token via the public setters
     * (no native unserialize) — REQ-003.
     *
     * @return void
     */
    public function testRehydrateFlowTokenUsesPublicSetters(): void
    {
        $snapshot = [
            'requestOriginal'  => ['method' => 'POST', 'path' => '/x'],
            'requestAmended'   => ['method' => 'POST', 'path' => '/x', 'parameters' => ['k' => 'v']],
            'responseOriginal' => ['data' => ['ok' => true]],
            'responseAmended'  => [],
            'syncInputOriginal' => [],
            'syncInputAmended'  => [],
            'syncOutputOriginal' => [],
            'syncOutputAmended' => [],
        ];

        $flowToken = $this->service->rehydrateFlowToken($snapshot);

        $this->assertSame(['k' => 'v'], $flowToken->getRequestAmended()['parameters']);
        $this->assertSame(['ok' => true], $flowToken->getResponseOriginal()['data']);

    }//end testRehydrateFlowTokenUsesPublicSetters()

    /**
     * assertActionable() throws 409 on a non-pending request — REQ-003/005.
     *
     * @return void
     */
    public function testAssertActionableRejectsNonPending(): void
    {
        $request = $this->entity(['status' => 'approved', 'expiresAt' => (new \DateTime('+1 day'))->format('c')]);

        $this->expectException(ApprovalStateException::class);
        try {
            $this->service->assertActionable($request);
        } catch (ApprovalStateException $e) {
            $this->assertSame(409, $e->getHttpStatus());
            throw $e;
        }

    }//end testAssertActionableRejectsNonPending()

    /**
     * assertActionable() throws 409 on an already-expired pending request,
     * even before the sweep job has run — REQ-005 (race close).
     *
     * @return void
     */
    public function testAssertActionableRejectsExpiredPending(): void
    {
        $request = $this->entity(['status' => 'pending', 'expiresAt' => (new \DateTime('-1 hour'))->format('c')]);

        $this->expectException(ApprovalStateException::class);
        try {
            $this->service->assertActionable($request);
        } catch (ApprovalStateException $e) {
            $this->assertSame(409, $e->getHttpStatus());
            throw $e;
        }

    }//end testAssertActionableRejectsExpiredPending()

    /**
     * assertActionable() passes for a pending, non-expired request.
     *
     * @return void
     */
    public function testAssertActionablePassesForPendingNonExpired(): void
    {
        $request = $this->entity(['status' => 'pending', 'expiresAt' => (new \DateTime('+1 day'))->format('c')]);
        $this->service->assertActionable($request);
        $this->addToAssertionCount(1);

    }//end testAssertActionablePassesForPendingNonExpired()

    /**
     * completeApproval() records approved + approver + resumeResult — REQ-003.
     *
     * @return void
     */
    public function testCompleteApprovalRecordsAudit(): void
    {
        $request  = $this->entity(['status' => 'pending', 'onReject' => 'error']);
        $approver = $this->mockUser('alice');

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured) {
                $captured = $object;
                return $this->entity($object, 'approval-1');
            }
        );

        $this->service->completeApproval(approvalRequest: $request, approver: $approver, resumeResult: 'success', comment: 'ok');

        $this->assertSame('approved', $captured['status']);
        $this->assertSame('alice', $captured['approverUserId']);
        $this->assertSame('success', $captured['resumeResult']);
        $this->assertSame('ok', $captured['comment']);
        $this->assertNotEmpty($captured['approvedAt']);

    }//end testCompleteApprovalRecordsAudit()

    /**
     * reject() with an empty comment throws 400 — REQ-004.
     *
     * @return void
     */
    public function testRejectRequiresComment(): void
    {
        $request  = $this->entity(['status' => 'pending', 'onReject' => 'error']);
        $approver = $this->mockUser('bob');

        $this->expectException(ApprovalStateException::class);
        try {
            $this->service->reject(approvalRequest: $request, approver: $approver, comment: '  ');
        } catch (ApprovalStateException $e) {
            $this->assertSame(400, $e->getHttpStatus());
            throw $e;
        }

    }//end testRejectRequiresComment()

    /**
     * reject() with onReject:error records status rejected + audit — REQ-004.
     *
     * @return void
     */
    public function testRejectErrorOutcomeRecordsRejected(): void
    {
        $request  = $this->entity(['status' => 'pending', 'onReject' => 'error']);
        $approver = $this->mockUser('carol');

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured) {
                $captured = $object;
                return $this->entity($object, 'approval-1');
            }
        );

        $this->service->reject(approvalRequest: $request, approver: $approver, comment: 'Missing legal basis field');

        $this->assertSame('rejected', $captured['status']);
        $this->assertSame('carol', $captured['approverUserId']);
        $this->assertSame('Missing legal basis field', $captured['comment']);
        $this->assertNotEmpty($captured['rejectedAt']);

    }//end testRejectErrorOutcomeRecordsRejected()

    /**
     * reject() with onReject:dead_letter sets status dead_letter — REQ-004/005.
     *
     * @return void
     */
    public function testRejectDeadLetterOutcomeSetsDeadLetter(): void
    {
        $request  = $this->entity(['status' => 'pending', 'onReject' => 'dead_letter']);
        $approver = $this->mockUser('dave');

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured) {
                $captured = $object;
                return $this->entity($object, 'approval-1');
            }
        );

        $this->service->reject(approvalRequest: $request, approver: $approver, comment: 'nope');

        $this->assertSame('dead_letter', $captured['status']);

    }//end testRejectDeadLetterOutcomeSetsDeadLetter()

    /**
     * sweepExpired() dead-letters an expired pending request with
     * onTimeout:dead_letter, and leaves a future-expiry request untouched — REQ-005.
     *
     * @return void
     */
    public function testSweepExpiredAppliesTimeoutOutcome(): void
    {
        $expired = $this->entity(['status' => 'pending', 'onTimeout' => 'dead_letter', 'expiresAt' => (new \DateTime('-1 hour'))->format('c')], 'expired-1');
        $future  = $this->entity(['status' => 'pending', 'onTimeout' => 'error', 'expiresAt' => (new \DateTime('+1 day'))->format('c')], 'future-1');

        $this->objectService->method('findAll')->willReturn(['results' => [$expired, $future], 'total' => 2]);

        $captured = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object, string $register, string $schema, ?string $uuid=null) use (&$captured) {
                $captured[$uuid] = $object;
                return $this->entity($object, (string) $uuid);
            }
        );

        $result = $this->service->sweepExpired();

        $this->assertSame(1, $result['swept']);
        $this->assertSame(1, $result['deadLettered']);
        $this->assertSame('dead_letter', $captured['expired-1']['status']);
        // The future-expiry request was never saved (left pending).
        $this->assertArrayNotHasKey('future-1', $captured);

    }//end testSweepExpiredAppliesTimeoutOutcome()

    /**
     * isAuthorizedApprover(): admin passes regardless of group — REQ-006.
     *
     * @return void
     */
    public function testAuthorizedApproverAdminAlwaysPasses(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(true);
        $request = $this->entity(['approverGroup' => 'woo-approvers']);
        $this->assertTrue($this->service->isAuthorizedApprover($request, $this->mockUser('root')));

    }//end testAuthorizedApproverAdminAlwaysPasses()

    /**
     * isAuthorizedApprover(): a member of approverGroup passes — REQ-006.
     *
     * @return void
     */
    public function testAuthorizedApproverGroupMemberPasses(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(true);
        $request = $this->entity(['approverGroup' => 'woo-approvers']);
        $this->assertTrue($this->service->isAuthorizedApprover($request, $this->mockUser('eve')));

    }//end testAuthorizedApproverGroupMemberPasses()

    /**
     * isAuthorizedApprover(): a non-member non-admin is denied — REQ-006
     * (the "unauthorized user cannot approve" object-level layer).
     *
     * @return void
     */
    public function testAuthorizedApproverNonMemberDenied(): void
    {
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);
        $request = $this->entity(['approverGroup' => 'woo-approvers']);
        $this->assertFalse($this->service->isAuthorizedApprover($request, $this->mockUser('mallory')));

    }//end testAuthorizedApproverNonMemberDenied()

    /**
     * findApprovedUnconsumedForSynchronization() skips a consumed request and
     * returns the first approved+unconsumed one — REQ-015.
     *
     * @return void
     */
    public function testFindApprovedUnconsumedSkipsConsumed(): void
    {
        $consumed   = $this->entity(['status' => 'approved', 'consumedAt' => 'yesterday', 'synchronizationId' => 'sync-1'], 'consumed-1');
        $unconsumed = $this->entity(['status' => 'approved', 'synchronizationId' => 'sync-1'], 'live-1');

        $this->objectService->method('findAll')->willReturn(['results' => [$consumed, $unconsumed], 'total' => 2]);

        $result = $this->service->findApprovedUnconsumedForSynchronization('sync-1');

        $this->assertNotNull($result);
        $this->assertSame('live-1', $result->getUuid());

    }//end testFindApprovedUnconsumedSkipsConsumed()

    /**
     * markConsumed() stamps consumedAt — REQ-015.
     *
     * @return void
     */
    public function testMarkConsumedStampsConsumedAt(): void
    {
        $request = $this->entity(['status' => 'approved'], 'approval-1');

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured) {
                $captured = $object;
                return $this->entity($object, 'approval-1');
            }
        );

        $this->service->markConsumed($request);

        $this->assertNotEmpty($captured['consumedAt']);

    }//end testMarkConsumedStampsConsumedAt()

    /**
     * find() maps a missing request to a 404 ApprovalStateException.
     *
     * @return void
     */
    public function testFindMissingThrows404(): void
    {
        $this->objectService->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('nope'));

        $this->expectException(ApprovalStateException::class);
        try {
            $this->service->find('missing-id');
        } catch (ApprovalStateException $e) {
            $this->assertSame(404, $e->getHttpStatus());
            throw $e;
        }

    }//end testFindMissingThrows404()

    /**
     * Build a mock IUser returning the given uid.
     *
     * @param string $uid The user id.
     *
     * @return IUser
     */
    private function mockUser(string $uid): IUser
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;

    }//end mockUser()
}//end class
