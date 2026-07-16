<?php

/**
 * Unit tests for ApprovalService::suspendForSubscription() — the
 * api-product-gateway subscription-approval creation seam (design.md
 * Decision 4).
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
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\ApprovalService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the subscription-approval creation method.
 *
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004
 */
class ApprovalServiceSubscriptionTest extends TestCase
{

    /**
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $objectService;

    /**
     * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $groupManager;

    /**
     * @var INotificationManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $notificationManager;

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
        $userSession                = $this->createMock(IUserSession::class);
        $this->groupManager         = $this->createMock(IGroupManager::class);
        $this->notificationManager  = $this->createMock(INotificationManager::class);
        $urlGenerator               = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.org/apps/openconnector/');

        $this->service = new ApprovalService(
            $this->objectService,
            $userSession,
            $this->groupManager,
            $this->notificationManager,
            $urlGenerator,
            $this->createMock(LoggerInterface::class),
        );

    }//end setUp()


    /**
     * REQ-APG-004 — suspendForSubscription() creates a `pending`
     * approval_request carrying the subscriptionId, no FlowToken snapshot
     * (an empty array, mirroring suspendForSynchronization()), and notifies
     * the configured approverGroup.
     *
     * @return void
     */
    public function testSuspendForSubscriptionPersistsPendingWithSubscriptionIdAndNotifiesApprovers(): void
    {
        // No group members -> notifyApprovers()'s foreach body never runs,
        // but get() must still resolve the group for the warning-free path.
        $group = $this->createMock(\OCP\IGroup::class);
        $group->method('getUsers')->willReturn([]);
        $this->groupManager->method('get')->willReturn($group);

        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array $object) use (&$captured) {
                $captured = $object;
                return ObjectServiceMockBuilder::objectEntity($this, $object, 'approval-created');
            }
        );

        $result = $this->service->suspendForSubscription(
            subscriptionId: 'sub-1',
            approverGroup: 'gateway-approvers',
            onReject: 'error',
            ttlSeconds: 3600
        );

        $this->assertSame('approval-created', $result->getUuid());
        $this->assertSame('pending', $captured['status']);
        $this->assertSame('sub-1', $captured['subscriptionId']);
        $this->assertSame('gateway-approvers', $captured['approverGroup']);
        $this->assertSame('before', $captured['timing']);
        $this->assertSame([], $captured['snapshot']);
        $this->assertSame('error', $captured['onReject']);
        $this->assertNotEmpty($captured['expiresAt']);
        $this->assertArrayNotHasKey('endpointId', $captured);
        $this->assertArrayNotHasKey('synchronizationId', $captured);
    }//end testSuspendForSubscriptionPersistsPendingWithSubscriptionIdAndNotifiesApprovers()

}//end class
