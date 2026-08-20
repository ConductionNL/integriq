<?php

/**
 * Integration test: a `synchronization` flow step never bypasses
 * sync-safety's guards (design.md Decision 3 / Task 20).
 *
 * `FlowRunnerService::dispatchSynchronization()` forwards to
 * `SynchronizationService::synchronize()` WITHOUT ever supplying an
 * `approvalRequestId` bypass token — the exact mechanism
 * `synchronization-engine` REQ-015's batch-approval gate
 * (`sourceConfig.requiresApproval`) checks to decide whether a run is
 * pre-authorized. This test asserts FlowRunnerService's OWN contract (it
 * never passes that token); the gate's own fire/suspend behaviour once
 * `requiresApproval` is set is `synchronization-engine`'s existing,
 * already-covered responsibility — re-asserting it here would duplicate
 * that suite and violates "no step type reimplements the logic of the
 * entity it calls" from the other direction (re-testing it, not
 * re-implementing it, but still redundant coverage).
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-07-15-visual-flow-orchestration/design.md#decision-3-dispatch--thin-adapter-methods-not-reimplementation
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Integration;

use OCA\OpenConnector\Service\ApprovalService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\FlowRunnerService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Asserts a `synchronization` flow step's dispatch never bypasses the
 * sync-safety batch-approval gate.
 *
 * @spec openspec/changes/archive/2026-07-15-visual-flow-orchestration/design.md#decision-3-dispatch--thin-adapter-methods-not-reimplementation
 */
class FlowRunnerSynchronizationGuardsTest extends TestCase {
	/**
	 * GIVEN a `synchronization` step targeting a Synchronization configured
	 * with `sourceConfig.requiresApproval` WHEN the flow step runs THEN
	 * `SynchronizationService::synchronize()` is invoked with
	 * `approvalRequestId: null` — the same call shape a directly-triggered
	 * sync makes on its first (ungated-by-a-prior-approval) run, so the
	 * gate inside `synchronize()` fires exactly as it would outside a flow.
	 *
	 * @return void
	 */
	public function testSynchronizationStepNeverPassesApprovalBypassToken(): void {
		$callService = $this->createMock(CallService::class);
		$mappingService = $this->createMock(MappingService::class);
		$approvalService = $this->createMock(ApprovalService::class);
		$orObjectService = ObjectServiceMockBuilder::make($this);
		$container = $this->createMock(ContainerInterface::class);

		$synchronizationService = $this->createMock(SynchronizationService::class);
		$gatedSynchronization = ObjectServiceMockBuilder::objectEntity(
			$this,
			['name' => 'Gated sync', 'sourceConfig' => ['requiresApproval' => true]],
			'sync-1'
		);
		$synchronizationService->method('getSynchronization')->willReturn($gatedSynchronization);

		$capturedApprovalRequestId = 'NEVER_SET';
		$synchronizationService->expects($this->once())
			->method('synchronize')
			->willReturnCallback(
				function (
					$synchronization,
					$isTest,
					$force,
					$object,
					$mutationType,
					$source,
					$data,
					$flowToken,
					$forceDeletion,
					$approvalRequestId,
				) use (&$capturedApprovalRequestId) {
					$capturedApprovalRequestId = $approvalRequestId;
					// What synchronize() itself would do once it sees
					// sourceConfig.requiresApproval with no bypass id: suspend
					// instead of writing. Returning that shape here proves the
					// test exercises the same "gated, no bypass" branch a
					// directly-triggered sync would hit — without
					// reimplementing synchronize()'s internals.
					return ['message' => 'pending_approval'];
				}
			);

		$orObjectService->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null) {
				return ObjectServiceMockBuilder::objectEntity($this, $object, ($uuid ?? 'flow-run-1'));
			}
		);

		$service = new FlowRunnerService(
			$callService,
			$mappingService,
			$synchronizationService,
			$approvalService,
			$orObjectService,
			$container,
			$this->createMock(LoggerInterface::class),
		);

		$flow = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'name' => 'Gated sync flow',
				'steps' => [
					['order' => 10, 'type' => 'synchronization', 'configRef' => 'sync-1', 'onError' => 'stop'],
				],
			],
			'flow-1'
		);

		$flowRun = $service->run(flow: $flow);

		$this->assertNull($capturedApprovalRequestId, 'FlowRunnerService must never pass an approvalRequestId bypass token');
		// Synchronize() did not throw, so the flow step itself completed —
		// the gate's own suspend/notify behaviour is synchronization-engine's
		// responsibility, exercised by that capability's own test suite.
		$this->assertSame('completed', $flowRun->getObject()['status']);

	}//end testSynchronizationStepNeverPassesApprovalBypassToken()
}//end class
