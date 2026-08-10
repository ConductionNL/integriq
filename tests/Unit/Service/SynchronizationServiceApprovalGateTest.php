<?php

/**
 * Unit tests for the Synchronization batch-level approval gate
 * (synchronization-engine REQ-015).
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
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-batch-level-approval-gate-before-target-writes-req-015
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\ApprovalService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SynchronizationLogService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the batch-level `requiresApproval` gate.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-batch-level-approval-gate-before-target-writes-req-015
 */
class SynchronizationServiceApprovalGateTest extends TestCase
{

    private const SYNC_ID = 'sync-approval-1';

    /**
     * @var CallService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $callService;

    /**
     * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var ApprovalService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $approvalService;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    /**
     * @var SynchronizationService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $service;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->callService     = $this->createMock(CallService::class);
        $this->callService->method('applyConfigDot')->willReturnArgument(0);
        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->approvalService = $this->createMock(ApprovalService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $mappingService = $this->createMock(MappingService::class);
        $container      = $this->createMock(ContainerInterface::class);
        $objectService  = $this->createMock(ObjectService::class);
        $appConfig      = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        $logOrService = ObjectServiceMockBuilder::make($this);
        $userSession  = $this->createMock(\OCP\IUserSession::class);
        $session      = $this->createMock(\OCP\ISession::class);
        $logService   = new SynchronizationLogService($logOrService, $userSession, $session);

        $this->service = $this->getMockBuilder(SynchronizationService::class)
            ->setConstructorArgs(
                [
                    $this->callService,
                    $mappingService,
                    $container,
                    $this->orObjectService,
                    $objectService,
                    $this->logger,
                    $logService,
                    $appConfig,
                    $this->approvalService,
                ]
            )
            ->onlyMethods(['updateTarget'])
            ->getMock();

    }//end setUp()

    /**
     * Build the gated synchronization payload.
     *
     * @param array $sourceConfigExtra Extra sourceConfig keys.
     *
     * @return array
     */
    private function makeSyncPayload(array $sourceConfigExtra=[]): array
    {
        return [
            'id'           => self::SYNC_ID,
            'uuid'         => self::SYNC_ID,
            'sourceId'     => 'source-uuid-ag',
            'sourceType'   => 'api',
            'targetType'   => 'register/schema',
            'targetId'     => '1/2',
            'sourceConfig' => array_merge(
                [
                    'endpoint'        => '/items',
                    'resultsPosition' => 'items',
                    'usesPagination'  => false,
                ],
                $sourceConfigExtra
            ),
        ];
    }//end makeSyncPayload()

    /**
     * Stub the source find + a single successful page carrying one object.
     *
     * @return void
     */
    private function stubSuccessfulSinglePageFetch(): void
    {
        $sourceEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['location' => 'https://example.test', 'enabled' => true],
            'source-uuid-ag'
        );
        $this->orObjectService->method('find')->willReturn($sourceEntity);

        $this->callService->method('call')->willReturn(
            ObjectServiceMockBuilder::objectEntity(
                $this,
                ['response' => ['statusCode' => 200, 'body' => json_encode(['items' => [['id' => 'origin-1']]]), 'encoding' => 'UTF-8', 'headers' => []]],
                'call-log-1'
            )
        );
    }//end stubSuccessfulSinglePageFetch()

    /**
     * TC-19: a gated synchronization with no existing approval pauses before
     * any writes — one approval_request is created, the log records
     * `pending_approval`, and updateTarget is never called.
     *
     * @return void
     */
    public function testGatedSyncPausesBeforeWrites(): void
    {
        $this->stubSuccessfulSinglePageFetch();

        $this->approvalService->method('findApprovedUnconsumedForSynchronization')->willReturn(null);
        $this->approvalService->expects($this->once())->method('suspendForSynchronization')
            ->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending'], 'approval-created'));

        // The write path must never be reached (REQ-015).
        $this->service->expects($this->never())->method('updateTarget');
        $this->approvalService->expects($this->never())->method('markConsumed');

        $result = $this->service->synchronize(
            synchronization: $this->makeSyncPayload(['requiresApproval' => true, 'approval' => ['approverGroup' => 'woo-approvers']])
        );

        $this->assertSame('pending_approval', $result['message']);
        $this->assertSame(0, $result['result']['objects']['created']);
        $this->assertSame(0, $result['result']['objects']['deleted']);
    }//end testGatedSyncPausesBeforeWrites()

    /**
     * TC-21 (regression): an ungated synchronization never touches the
     * approval service — behavior is unchanged from before this change.
     *
     * @return void
     */
    public function testUngatedSyncNeverTouchesApprovalService(): void
    {
        // An empty source page keeps the run out of the (heavy) per-object
        // write path — the point here is only that the gate block is skipped
        // and the approval service is never consulted when requiresApproval
        // is absent.
        $sourceEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['location' => 'https://example.test', 'enabled' => true],
            'source-uuid-ag'
        );
        $this->orObjectService->method('find')->willReturn($sourceEntity);
        $this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);
        $this->callService->method('call')->willReturn(
            ObjectServiceMockBuilder::objectEntity(
                $this,
                ['response' => ['statusCode' => 200, 'body' => json_encode(['items' => []]), 'encoding' => 'UTF-8', 'headers' => []]],
                'call-log-empty'
            )
        );

        $this->approvalService->expects($this->never())->method('findApprovedUnconsumedForSynchronization');
        $this->approvalService->expects($this->never())->method('suspendForSynchronization');

        $result = $this->service->synchronize(synchronization: $this->makeSyncPayload());

        // Regression: a normal (ungated) run completes with the usual
        // 'Success' message and no approval_request is ever consulted.
        $this->assertSame('Success', $result['message']);
    }//end testUngatedSyncNeverTouchesApprovalService()

    /**
     * TC-20 (gate resolution): a bypass token resolving to an approved,
     * unconsumed approval_request for THIS synchronization satisfies the
     * gate; a token for a different sync, a non-approved status, or a
     * consumed request does not.
     *
     * @return void
     */
    public function testResolveApprovalForSynchronizationBypassToken(): void
    {
        $method = new \ReflectionMethod(SynchronizationService::class, 'resolveApprovalForSynchronization');
        $method->setAccessible(true);

        // Valid: approved + unconsumed + matching sync id.
        $valid = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['status' => 'approved', 'synchronizationId' => self::SYNC_ID],
            'approval-valid'
        );
        $this->approvalService->method('find')->willReturnCallback(
            function (string $id) use ($valid) {
                if ($id === 'approval-valid') {
                    return $valid;
                }
                if ($id === 'approval-consumed') {
                    return ObjectServiceMockBuilder::objectEntity($this, ['status' => 'approved', 'synchronizationId' => self::SYNC_ID, 'consumedAt' => 'yesterday'], 'approval-consumed');
                }
                if ($id === 'approval-othersync') {
                    return ObjectServiceMockBuilder::objectEntity($this, ['status' => 'approved', 'synchronizationId' => 'other'], 'approval-othersync');
                }
                if ($id === 'approval-pending') {
                    return ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending', 'synchronizationId' => self::SYNC_ID], 'approval-pending');
                }
                throw new \Exception('not found');
            }
        );

        $this->assertSame('approval-valid', $method->invoke($this->service, self::SYNC_ID, 'approval-valid')?->getUuid());
        $this->assertNull($method->invoke($this->service, self::SYNC_ID, 'approval-consumed'));
        $this->assertNull($method->invoke($this->service, self::SYNC_ID, 'approval-othersync'));
        $this->assertNull($method->invoke($this->service, self::SYNC_ID, 'approval-pending'));
        $this->assertNull($method->invoke($this->service, self::SYNC_ID, 'missing-id'));

        // No bypass token → delegates to findApprovedUnconsumedForSynchronization.
        $this->approvalService->method('findApprovedUnconsumedForSynchronization')->willReturn($valid);
        $this->assertSame('approval-valid', $method->invoke($this->service, self::SYNC_ID, null)?->getUuid());
    }//end testResolveApprovalForSynchronizationBypassToken()
}//end class
