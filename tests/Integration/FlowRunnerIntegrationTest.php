<?php

/**
 * Integration test: a 3-step flow (call -> mapping -> synchronization) runs
 * end-to-end through a REAL `FlowRunnerService` instance, asserting the
 * cross-service invocation ORDER (not just each collaborator's own call
 * count, which the Unit suite already covers) and the resulting
 * `flow_run_log` contents.
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
 * This repo has no live-Nextcloud-instance test harness (see
 * `NextcloudEventDeliveryTest`'s docblock for the documented precedent) —
 * "integration" here means a REAL `FlowRunnerService` wired against mocked
 * leaf collaborators (`CallService`/`MappingService`/`SynchronizationService`/
 * `ApprovalService`/OpenRegister's `ObjectService`), asserting the real
 * sequencing/threading/trace-recording behaviour end-to-end.
 *
 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001
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
 * End-to-end (mocked-boundary) test for a 3-step flow.
 *
 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001
 */
class FlowRunnerIntegrationTest extends TestCase {
	/**
	 * A 3-step flow (`call` -> `mapping` -> `synchronization`) invokes each
	 * collaborator's public entrypoint in `order` sequence, threads each
	 * step's output into the next step's input, and produces an ordered,
	 * all-`completed` `flow_run_log` — flow-orchestration REQ-001 Scenario
	 * 1 / TC-1.
	 *
	 * @return void
	 */
	public function testThreeStepCallMappingSynchronizationFlowRunsEndToEnd(): void {
		$callService = $this->createMock(CallService::class);
		$mappingService = $this->createMock(MappingService::class);
		$synchronizationService = $this->createMock(SynchronizationService::class);
		$approvalService = $this->createMock(ApprovalService::class);
		$orObjectService = ObjectServiceMockBuilder::make($this);
		$container = $this->createMock(ContainerInterface::class);

		$invocationOrder = [];
		$loggedEntries = [];

		$callLog = ObjectServiceMockBuilder::objectEntity($this, ['response' => ['statusCode' => 200, 'body' => '{"raw":"data"}']], 'call-log-1');
		$callService->method('call')->willReturnCallback(
			function () use (&$invocationOrder, $callLog) {
				$invocationOrder[] = 'call';
				return $callLog;
			}
		);

		$mappingService->method('executeMapping')->willReturnCallback(
			function ($mapping, array $input) use (&$invocationOrder) {
				$invocationOrder[] = 'mapping';
				// Proves REQ-002 threading: the call step's response envelope
				// (statusCode/body, with the JSON body decoded into an array)
				// arrived here as-is — FlowRunnerService::dispatchCall()'s
				// documented result shape.
				self::assertSame(['statusCode' => 200, 'body' => ['raw' => 'data']], $input);
				return ['mapped' => true];
			}
		);

		$synchronizationService->method('getSynchronization')->willReturn(
			ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Target sync'], 'sync-1')
		);
		$synchronizationService->method('synchronize')->willReturnCallback(
			function ($synchronization, ...$rest) use (&$invocationOrder) {
				$invocationOrder[] = 'synchronization';
				// $rest[5] is `data` (position 6 overall, 0-indexed rest starts after $synchronization).
				self::assertSame(['mapped' => true], $rest[5]);
				return ['synchronized' => true];
			}
		);

		$orObjectService->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null) use (&$loggedEntries) {
				if ($schema === 'flow_run_log') {
					$loggedEntries[] = $object;
				}

				if ($schema === 'flow_run') {
					return ObjectServiceMockBuilder::objectEntity($this, $object, ($uuid ?? 'flow-run-1'));
				}

				return ObjectServiceMockBuilder::objectEntity($this, $object, ($uuid ?? 'saved-1'));
			}
		);

		$orObjectService->method('find')->willReturnCallback(
			function (string $id, ?string $register = null, ?string $schema = null) {
				return ObjectServiceMockBuilder::objectEntity($this, ['name' => $id], $id);
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
				'name' => '3-step flow',
				'steps' => [
					['order' => 10, 'type' => 'call', 'configRef' => 'source-1', 'onError' => 'stop'],
					['order' => 20, 'type' => 'mapping', 'configRef' => 'mapping-1', 'onError' => 'stop'],
					['order' => 30, 'type' => 'synchronization', 'configRef' => 'sync-1', 'onError' => 'stop'],
				],
			],
			'flow-1'
		);

		$flowRun = $service->run(flow: $flow);

		$this->assertSame(['call', 'mapping', 'synchronization'], $invocationOrder);
		$this->assertSame('completed', $flowRun->getObject()['status']);
		$this->assertSame([10, 20, 30], array_column($loggedEntries, 'stepOrder'));
		$this->assertSame(['completed', 'completed', 'completed'], array_column($loggedEntries, 'status'));

	}//end testThreeStepCallMappingSynchronizationFlowRunsEndToEnd()
}//end class
