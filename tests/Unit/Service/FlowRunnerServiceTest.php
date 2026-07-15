<?php

/**
 * Unit tests for FlowRunnerService — sequential dispatch, context threading,
 * condition skip, branch selection, onError policy, approval suspend/resume,
 * and event dispatch (flow-orchestration REQ-001..REQ-008).
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
 * @spec openspec/specs/flow-orchestration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Exception\FlowRunException;
use OCA\OpenConnector\Service\ApprovalService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\Service\FlowRunnerService;
use OCA\OpenConnector\Service\Helper\FlowToken;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for FlowRunnerService::run()/resumeFromApproval().
 *
 * @spec openspec/specs/flow-orchestration/spec.md
 */
class FlowRunnerServiceTest extends TestCase
{

    /**
     * @var CallService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $callService;

    /**
     * @var MappingService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $mappingService;

    /**
     * @var SynchronizationService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $synchronizationService;

    /**
     * @var ApprovalService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $approvalService;

    /**
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $containerInterface;

    /**
     * Captured flow_run_log entries (in save order) from the saveObject() spy.
     *
     * @var array<int, array>
     */
    private array $loggedEntries = [];

    /**
     * @var FlowRunnerService
     */
    private FlowRunnerService $service;

    /**
     * Set up fixtures. `saveObject()` is stubbed with a callback that
     * (a) returns a real ObjectEntity so magic getters work, keeping a
     * STABLE uuid for `flow_run` saves (create/suspend/finalize all
     * target the same record) and (b) captures every `flow_run_log`
     * write into `$this->loggedEntries` for order/content assertions.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->callService            = $this->createMock(CallService::class);
        $this->mappingService         = $this->createMock(MappingService::class);
        $this->synchronizationService = $this->createMock(SynchronizationService::class);
        $this->approvalService        = $this->createMock(ApprovalService::class);
        $this->orObjectService        = ObjectServiceMockBuilder::make($this);
        $this->containerInterface     = $this->createMock(ContainerInterface::class);

        $this->loggedEntries = [];
        $this->orObjectService->method('saveObject')->willReturnCallback(
            function ($object, ?string $register=null, ?string $schema=null, ?string $uuid=null, bool $_rbac=true, bool $_multitenancy=true) {
                if ($schema === 'flow_run_log') {
                    $this->loggedEntries[] = $object;
                }

                if ($schema === 'flow_run') {
                    return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid ?? 'flow-run-1');
                }

                return ObjectServiceMockBuilder::objectEntity($this, $object, ($uuid ?? 'saved-1'));
            }
        );

        $this->service = new FlowRunnerService(
            $this->callService,
            $this->mappingService,
            $this->synchronizationService,
            $this->approvalService,
            $this->orObjectService,
            $this->containerInterface,
            $this->createMock(LoggerInterface::class),
        );

    }//end setUp()

    /**
     * Build a `flow` ObjectEntity from a `steps[]` array.
     *
     * @param array $steps The flow's steps.
     *
     * @return ObjectEntity
     */
    private function flow(array $steps): ObjectEntity
    {
        return ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Test flow', 'steps' => $steps], 'flow-1');

    }//end flow()

    /**
     * TC-1/TC-2: a 3-step flow (call -> mapping -> synchronization) runs in
     * `order` sequence, not array position — the array below deliberately
     * lists the order-30 step first.
     *
     * @return void
     */
    public function testRunExecutesStepsInOrderNotArrayPosition(): void
    {
        $callLog = ObjectServiceMockBuilder::objectEntity($this, ['response' => ['statusCode' => 200, 'body' => '{"ok":true}']], 'call-log-1');
        $this->orObjectService->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Source'], 'source-1'));
        $this->callService->method('call')->willReturn($callLog);
        $this->mappingService->method('executeMapping')->willReturn(['mapped' => true]);
        $this->synchronizationService->method('getSynchronization')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Sync'], 'sync-1'));
        $this->synchronizationService->method('synchronize')->willReturn(['synced' => true]);

        $flow = $this->flow(
            [
                ['order' => 30, 'type' => 'synchronization', 'configRef' => 'sync-1', 'onError' => 'stop'],
                ['order' => 10, 'type' => 'call', 'configRef' => 'source-1', 'onError' => 'stop'],
                ['order' => 20, 'type' => 'mapping', 'configRef' => 'mapping-1', 'onError' => 'stop'],
            ]
        );

        $flowRun = $this->service->run(flow: $flow);

        $this->assertSame('completed', $flowRun->getObject()['status']);
        $this->assertCount(3, $this->loggedEntries);
        $this->assertSame([10, 20, 30], array_column($this->loggedEntries, 'stepOrder'));
        $this->assertSame(['completed', 'completed', 'completed'], array_column($this->loggedEntries, 'status'));

    }//end testRunExecutesStepsInOrderNotArrayPosition()

    /**
     * TC-3: a step's output threads into the next step's input via
     * `syncInputAmended` (REQ-002).
     *
     * @return void
     */
    public function testStepOutputThreadsIntoNextStepInput(): void
    {
        $this->mappingService->method('executeMapping')->willReturn(['id' => 'abc']);
        $this->synchronizationService->method('getSynchronization')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Sync'], 'sync-1'));

        // SynchronizationService::synchronize()'s real positional signature is
        // (synchronization, isTest, force, object, mutationType, source, data,
        // flowToken, forceDeletion, approvalRequestId). Captured via a
        // callback (rather than a strict with() constraint list) because
        // $object/$flowToken are by-reference parameters. Capturing
        // `$approvalRequestId` (the 10th positional arg) doubles as Task 20's
        // sync-safety check: it MUST be null, proving a flow step never
        // passes a bypass token to the batch-approval gate.
        $capturedData              = null;
        $capturedApprovalRequestId = 'NOT_CAPTURED';
        $this->synchronizationService->expects($this->once())
            ->method('synchronize')
            ->willReturnCallback(
                function ($synchronization, $isTest, $force, $object, $mutationType, $source, $data, $flowToken, $forceDeletion, $approvalRequestId) use (&$capturedData, &$capturedApprovalRequestId) {
                    $capturedData              = $data;
                    $capturedApprovalRequestId = $approvalRequestId;
                    return ['ok' => true];
                }
            );

        $flow = $this->flow(
            [
                ['order' => 10, 'type' => 'mapping', 'configRef' => 'mapping-1', 'onError' => 'stop'],
                ['order' => 20, 'type' => 'synchronization', 'configRef' => 'sync-1', 'onError' => 'stop'],
            ]
        );

        $flowRun = $this->service->run(flow: $flow);
        $this->assertSame('completed', $flowRun->getObject()['status']);
        $this->assertSame(['id' => 'abc'], $capturedData);
        $this->assertNull($capturedApprovalRequestId);

    }//end testStepOutputThreadsIntoNextStepInput()

    /**
     * TC-4: a step whose `condition` evaluates false is skipped — its
     * service is never called, and `flow_run_log` records `status: skipped`.
     *
     * @return void
     */
    public function testConditionSkipsStep(): void
    {
        $this->mappingService->expects($this->once())->method('executeMapping')->willReturn(['status' => 'inactive']);
        $this->synchronizationService->expects($this->never())->method('synchronize');

        $flow = $this->flow(
            [
                ['order' => 10, 'type' => 'mapping', 'configRef' => 'mapping-1', 'onError' => 'stop'],
                [
                    'order'     => 20,
                    'type'      => 'synchronization',
                    'configRef' => 'sync-1',
                    'onError'   => 'stop',
                    'condition' => ['==' => [['var' => 'syncInputAmended.status'], 'active']],
                ],
            ]
        );

        $flowRun = $this->service->run(flow: $flow);

        $this->assertSame('completed', $flowRun->getObject()['status']);
        $this->assertSame(['completed', 'skipped'], array_column($this->loggedEntries, 'status'));

    }//end testConditionSkipsStep()

    /**
     * TC-6: a `branch` step selects the first matching `branches[]` target
     * and skips any steps between it and the branch — the skipped step
     * does not appear in `flow_run_log`.
     *
     * @return void
     */
    public function testBranchSelectsFirstMatchingTarget(): void
    {
        $this->mappingService->method('executeMapping')->willReturn(['mode' => 'incremental']);
        $this->synchronizationService->method('getSynchronization')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Sync'], 'sync-1'));
        $this->synchronizationService->expects($this->once())->method('synchronize')->willReturn(['ok' => true]);

        $flow = $this->flow(
            [
                ['order' => 10, 'type' => 'mapping', 'configRef' => 'mapping-1', 'onError' => 'stop'],
                [
                    'order'    => 20,
                    'type'     => 'branch',
                    'onError'  => 'stop',
                    'branches' => [
                        ['condition' => ['==' => [['var' => 'syncInputAmended.mode'], 'full']], 'nextStepOrder' => 30],
                        ['condition' => ['==' => [['var' => 'syncInputAmended.mode'], 'incremental']], 'nextStepOrder' => 40],
                    ],
                ],
                ['order' => 30, 'type' => 'synchronization', 'configRef' => 'full-sync', 'onError' => 'stop'],
                ['order' => 40, 'type' => 'synchronization', 'configRef' => 'incremental-sync', 'onError' => 'stop'],
            ]
        );

        $flowRun = $this->service->run(flow: $flow);

        $this->assertSame('completed', $flowRun->getObject()['status']);
        $stepOrders = array_column($this->loggedEntries, 'stepOrder');
        $this->assertSame([10, 20, 40], $stepOrders);
        $this->assertNotContains(30, $stepOrders);

    }//end testBranchSelectsFirstMatchingTarget()

    /**
     * TC-7: no `branches[].condition` matches -> falls back to
     * `defaultNextStepOrder`.
     *
     * @return void
     */
    public function testBranchFallsBackToDefaultNextStepOrder(): void
    {
        $this->mappingService->method('executeMapping')->willReturn(['mode' => 'unknown']);
        $this->synchronizationService->method('getSynchronization')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Sync'], 'sync-1'));
        $this->synchronizationService->expects($this->once())->method('synchronize')->willReturn(['ok' => true]);

        // No order-40 step exists in this flow at all — REQ-004 Scenario 2
        // only asserts execution "proceeds to the order-30 step" when no
        // branch matches; it does not (and, with a 4th step present, cannot)
        // assert the run stops there, since normal sequential continuation
        // would carry on to any step that followed order-30 in `order`.
        $flow = $this->flow(
            [
                ['order' => 10, 'type' => 'mapping', 'configRef' => 'mapping-1', 'onError' => 'stop'],
                [
                    'order'                => 20,
                    'type'                 => 'branch',
                    'onError'              => 'stop',
                    'branches'             => [
                        ['condition' => ['==' => [['var' => 'syncInputAmended.mode'], 'full']], 'nextStepOrder' => 40],
                    ],
                    'defaultNextStepOrder' => 30,
                ],
                ['order' => 30, 'type' => 'synchronization', 'configRef' => 'sync-1', 'onError' => 'stop'],
            ]
        );

        $flowRun = $this->service->run(flow: $flow);

        $this->assertSame([10, 20, 30], array_column($this->loggedEntries, 'stepOrder'));
        $this->assertSame('completed', $flowRun->getObject()['status']);

    }//end testBranchFallsBackToDefaultNextStepOrder()

    /**
     * TC-8: an unresolvable `branch` target fails the run with
     * `flow_run.status: failed`, regardless of any step's `onError` policy.
     *
     * @return void
     */
    public function testBranchUnresolvableTargetFailsRunFatally(): void
    {
        $flow = $this->flow(
            [
                [
                    'order'    => 20,
                    'type'     => 'branch',
                    'onError'  => 'continue',
                    'branches' => [
                        ['condition' => ['==' => [1, 1]], 'nextStepOrder' => 99],
                    ],
                ],
            ]
        );

        $flowRun = $this->service->run(flow: $flow);

        $this->assertSame('failed', $flowRun->getObject()['status']);

    }//end testBranchUnresolvableTargetFailsRunFatally()

    /**
     * TC-12: `onError: stop` halts the run on the failing step — no later
     * step executes.
     *
     * @return void
     */
    public function testOnErrorStopHaltsRun(): void
    {
        $this->orObjectService->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Source'], 'source-1'));
        $this->callService->method('call')->willThrowException(new \RuntimeException('boom'));
        $this->mappingService->expects($this->never())->method('executeMapping');

        $flow = $this->flow(
            [
                ['order' => 10, 'type' => 'call', 'configRef' => 'source-1', 'onError' => 'stop'],
                ['order' => 20, 'type' => 'mapping', 'configRef' => 'mapping-1', 'onError' => 'stop'],
            ]
        );

        $flowRun = $this->service->run(flow: $flow);

        $this->assertSame('stopped', $flowRun->getObject()['status']);
        $this->assertSame(['failed'], array_column($this->loggedEntries, 'status'));
        $this->assertSame('boom', $this->loggedEntries[0]['error']);

    }//end testOnErrorStopHaltsRun()

    /**
     * TC-13: `onError: continue` proceeds to the next step despite the
     * failure; the run can still reach `completed`.
     *
     * @return void
     */
    public function testOnErrorContinueProceedsPastFailure(): void
    {
        $this->orObjectService->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Source'], 'source-1'));
        $this->callService->method('call')->willThrowException(new \RuntimeException('boom'));
        $this->mappingService->expects($this->once())->method('executeMapping')->willReturn(['ok' => true]);

        $flow = $this->flow(
            [
                ['order' => 10, 'type' => 'call', 'configRef' => 'source-1', 'onError' => 'continue'],
                ['order' => 20, 'type' => 'mapping', 'configRef' => 'mapping-1', 'onError' => 'stop'],
            ]
        );

        $flowRun = $this->service->run(flow: $flow);

        $this->assertSame('completed', $flowRun->getObject()['status']);
        $this->assertSame(['failed', 'completed'], array_column($this->loggedEntries, 'status'));

    }//end testOnErrorContinueProceedsPastFailure()

    /**
     * TC-14: `onError: dead_letter` marks the run distinctly from `stopped`.
     *
     * @return void
     */
    public function testOnErrorDeadLetterMarksRunDistinctly(): void
    {
        $this->orObjectService->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Source'], 'source-1'));
        $this->callService->method('call')->willThrowException(new \RuntimeException('boom'));

        $flow = $this->flow(
            [
                ['order' => 10, 'type' => 'call', 'configRef' => 'source-1', 'onError' => 'dead_letter'],
                ['order' => 20, 'type' => 'mapping', 'configRef' => 'mapping-1', 'onError' => 'stop'],
            ]
        );

        $flowRun = $this->service->run(flow: $flow);

        $this->assertSame('dead_letter', $flowRun->getObject()['status']);

    }//end testOnErrorDeadLetterMarksRunDistinctly()

    /**
     * TC-9: an `approval` step suspends the run: an `approval_request` is
     * created with `resumeStepOrder` set to the NEXT step's order, and
     * `run()` returns immediately with `flow_run.status: suspended` — no
     * later step executes.
     *
     * @return void
     */
    public function testApprovalStepSuspendsRun(): void
    {
        $approvalRequest = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending'], 'approval-1');
        $this->approvalService->expects($this->once())
            ->method('suspendForFlow')
            ->with($this->anything(), 30, $this->anything(), $this->anything())
            ->willReturn($approvalRequest);

        $this->synchronizationService->expects($this->never())->method('synchronize');

        $flow = $this->flow(
            [
                ['order' => 20, 'type' => 'approval', 'onError' => 'stop', 'config' => ['approverGroup' => 'ops']],
                ['order' => 30, 'type' => 'synchronization', 'configRef' => 'sync-1', 'onError' => 'stop'],
            ]
        );

        $flowRun = $this->service->run(flow: $flow);

        $this->assertSame('suspended', $flowRun->getObject()['status']);
        $this->assertSame(['completed'], array_column($this->loggedEntries, 'status'));

    }//end testApprovalStepSuspendsRun()

    /**
     * TC-10: `resumeFromApproval()` rehydrates the FlowToken and resumes
     * execution at `resumeStepOrder`, reaching `completed` once the
     * remaining steps finish.
     *
     * @return void
     */
    public function testResumeFromApprovalContinuesAtResumeStepOrder(): void
    {
        $flowRunEntity = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'suspended', 'flowId' => 'flow-1'], 'flow-run-1');
        $flow          = $this->flow(
            [
                ['order' => 20, 'type' => 'approval', 'onError' => 'stop'],
                ['order' => 30, 'type' => 'synchronization', 'configRef' => 'sync-1', 'onError' => 'stop'],
            ]
        );

        $this->orObjectService->method('find')->willReturnCallback(
            function (string $id, ?string $register=null, ?string $schema=null, bool $_rbac=true, bool $_multitenancy=true) use ($flowRunEntity, $flow) {
                if ($schema === 'flow_run') {
                    return $flowRunEntity;
                }

                return $flow;
            }
        );

        $this->approvalService->method('rehydrateFlowToken')->willReturn(new FlowToken());
        $this->synchronizationService->method('getSynchronization')->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Sync'], 'sync-1'));
        $this->synchronizationService->expects($this->once())->method('synchronize')->willReturn(['ok' => true]);

        $approvalRequest = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['flowRunId' => 'flow-run-1', 'resumeStepOrder' => 30, 'snapshot' => []],
            'approval-1'
        );

        $resumed = $this->service->resumeFromApproval(approvalRequest: $approvalRequest);

        $this->assertSame('completed', $resumed->getObject()['status']);

    }//end testResumeFromApprovalContinuesAtResumeStepOrder()

    /**
     * An `event` step forwards to `EventService::emitCloudEvent()`,
     * resolved lazily via the DI container (design.md Decision 5 / class
     * docblock — avoids the FlowRunnerService<->EventService constructor
     * cycle).
     *
     * @return void
     */
    public function testEventStepDispatchesToEventServiceEmitCloudEvent(): void
    {
        $eventService = $this->createMock(EventService::class);
        $eventService->expects($this->once())
            ->method('emitCloudEvent')
            ->with('nl.test.flow', 'openconnector', 'flow-subject', [])
            ->willReturn([]);

        $this->containerInterface->method('get')->with(EventService::class)->willReturn($eventService);

        $flow = $this->flow(
            [
                [
                    'order'   => 10,
                    'type'    => 'event',
                    'onError' => 'stop',
                    'config'  => ['type' => 'nl.test.flow', 'source' => 'openconnector', 'subject' => 'flow-subject'],
                ],
            ]
        );

        $flowRun = $this->service->run(flow: $flow);

        $this->assertSame('completed', $flowRun->getObject()['status']);

    }//end testEventStepDispatchesToEventServiceEmitCloudEvent()

    /**
     * Task 1 acceptance: a flow whose `steps[]` carry duplicate `order`
     * values is rejected before any step runs.
     *
     * @return void
     */
    public function testDuplicateStepOrderThrowsFlowRunException(): void
    {
        $this->callService->expects($this->never())->method('call');

        $flow = $this->flow(
            [
                ['order' => 10, 'type' => 'call', 'configRef' => 'source-1', 'onError' => 'stop'],
                ['order' => 10, 'type' => 'mapping', 'configRef' => 'mapping-1', 'onError' => 'stop'],
            ]
        );

        $this->expectException(FlowRunException::class);
        $this->service->run(flow: $flow);

    }//end testDuplicateStepOrderThrowsFlowRunException()

    /**
     * An empty `steps[]` array completes immediately (no steps to run).
     *
     * @return void
     */
    public function testEmptyStepsCompletesImmediately(): void
    {
        $flow    = $this->flow([]);
        $flowRun = $this->service->run(flow: $flow);

        $this->assertSame('completed', $flowRun->getObject()['status']);

    }//end testEmptyStepsCompletesImmediately()
}//end class
