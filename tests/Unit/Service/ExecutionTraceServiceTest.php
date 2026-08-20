<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\EventService;
use OCA\OpenConnector\Service\ExecutionTraceService;
use OCA\OpenConnector\Service\Helper\ExecutionTraceContext;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Covers execution-trace REQ-004 (trace persistence), REQ-005 (dry-run
 * replay makes no writes), and REQ-006 (forced replay reuses the original
 * dispatch path).
 *
 * @spec openspec/specs/execution-trace/spec.md
 */
final class ExecutionTraceServiceTest extends TestCase {

	/**
	 * persist() saves an execution_trace using the traceId as the object's
	 * own uuid, carrying every context field into the payload.
	 *
	 * @return void
	 */
	public function testPersistSavesUsingTraceIdAsUuid(): void {
		$capturedPayload = null;
		$capturedArgs = null;

		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(
				function (...$args) use (&$capturedPayload, &$capturedArgs) {
					$capturedArgs = $args;
					$capturedPayload = $args[0];
					return ObjectServiceMockBuilder::objectEntity($this, $capturedPayload, 'trace-abc');
				}
			);

		$service = new ExecutionTraceService($orObjectService, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));

		$trace = new ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: 'ep-1', traceId: 'trace-abc');
		$trace->addStep(type: 'rule', name: 'r1', timing: 'before', status: 'success');

		$result = $service->persist(trace: $trace, status: 'success');

		$this->assertSame('trace-abc', $result->getUuid());
		$this->assertNotNull($capturedPayload);
		$this->assertSame('trace-abc', $capturedPayload['traceId']);
		$this->assertSame('endpoint', $capturedPayload['entryPoint']);
		$this->assertSame('success', $capturedPayload['status']);
		$this->assertCount(1, $capturedPayload['steps']);
		// register/schema/uuid land at whichever positions the named-argument
		// call resolves to on ORObjectService::saveObject()'s real signature
		// (object, extend, register, schema, uuid, ...) — assert by value,
		// not by position, so this stays robust to that resolution.
		$this->assertContains('openconnector', $capturedArgs);
		$this->assertContains('execution_trace', $capturedArgs);
		$this->assertContains('trace-abc', $capturedArgs);
	}//end testPersistSavesUsingTraceIdAsUuid()

	/**
	 * find() returns null (not an exception) when the OR lookup fails —
	 * used by the controller to produce a 404.
	 *
	 * @return void
	 */
	public function testFindReturnsNullWhenNotFound(): void {
		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->method('find')->willThrowException(new DoesNotExistException('nope'));

		$service = new ExecutionTraceService($orObjectService, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));

		$this->assertNull($service->find('missing-id'));
	}//end testFindReturnsNullWhenNotFound()

	/**
	 * replay() throws DoesNotExistException (the controller maps this to a
	 * 404 JSONResponse) when no such trace exists.
	 *
	 * @return void
	 */
	public function testReplayThrowsWhenTraceMissing(): void {
		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->method('find')->willThrowException(new DoesNotExistException('nope'));

		$service = new ExecutionTraceService($orObjectService, $this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class));

		$this->expectException(DoesNotExistException::class);
		$service->replay(traceId: 'missing-id', actorUid: 'admin');
	}//end testReplayThrowsWhenTraceMissing()

	/**
	 * A dry-run replay of a `sync`-entryPoint trace invokes
	 * SynchronizationService::replaySynchronizationItem() with
	 * isTest: true (execution-trace REQ-005) and persists a new trace
	 * linked via replayOf, isReplay: true, dryRun: true.
	 *
	 * @return void
	 */
	public function testDryRunReplayOfSyncTraceForwardsIsTestTrue(): void {
		$originalTrace = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'traceId' => 'orig-1',
				'entryPoint' => 'sync',
				'entryPointId' => 'sync-uuid-1',
				'status' => 'failed',
				'steps' => [
					['order' => 1, 'type' => 'synchronization', 'name' => 'item', 'timing' => null, 'status' => 'error', 'durationMs' => 0, 'startedAt' => 'x', 'input' => ['id' => 'obj-1'], 'output' => []],
				],
			],
			'orig-1'
		);

		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->method('find')->willReturn($originalTrace);

		$savedNewTrace = null;
		$orObjectService->method('saveObject')->willReturnCallback(
			function (array $payload) use (&$savedNewTrace) {
				$savedNewTrace = $payload;
				return ObjectServiceMockBuilder::objectEntity($this, $payload, ($payload['traceId'] ?? 'new-trace'));
			}
		);

		$synchronizationEntity = ObjectServiceMockBuilder::objectEntity($this, ['uuid' => 'sync-uuid-1'], 'sync-uuid-1');
		$synchronizationService = $this->createMock(SynchronizationService::class);
		$synchronizationService->method('getSynchronization')->willReturn($synchronizationEntity);
		$synchronizationService->expects($this->once())
			->method('replaySynchronizationItem')
			->with(
				$this->anything(),
				$this->callback(static fn (array $payload): bool => $payload === ['id' => 'obj-1']),
				true,
				$this->isInstanceOf(ExecutionTraceContext::class)
			)
			->willReturn(['result' => [], 'targetId' => null]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($synchronizationService) {
				return match ($class) {
					SynchronizationService::class => $synchronizationService,
					default => null,
				};
			}
		);

		$service = new ExecutionTraceService($orObjectService, $container, $this->createMock(LoggerInterface::class));

		$service->replay(traceId: 'orig-1', actorUid: 'admin', force: false);

		$this->assertNotNull($savedNewTrace);
		$this->assertTrue($savedNewTrace['isReplay']);
		$this->assertTrue($savedNewTrace['dryRun']);
		$this->assertSame('orig-1', $savedNewTrace['replayOf']);
		$this->assertSame('success', $savedNewTrace['status']);
	}//end testDryRunReplayOfSyncTraceForwardsIsTestTrue()

	/**
	 * A forced replay of a `sync`-entryPoint trace invokes
	 * replaySynchronizationItem() with isTest: false (execution-trace
	 * REQ-006).
	 *
	 * @return void
	 */
	public function testForcedReplayOfSyncTraceForwardsIsTestFalse(): void {
		$originalTrace = ObjectServiceMockBuilder::objectEntity(
			$this,
			['traceId' => 'orig-2', 'entryPoint' => 'sync', 'entryPointId' => 'sync-uuid-2', 'status' => 'failed', 'steps' => []],
			'orig-2'
		);

		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->method('find')->willReturn($originalTrace);
		$orObjectService->method('saveObject')->willReturnCallback(
			fn (array $payload) => ObjectServiceMockBuilder::objectEntity($this, $payload, ($payload['traceId'] ?? 'new-trace'))
		);

		$synchronizationEntity = ObjectServiceMockBuilder::objectEntity($this, ['uuid' => 'sync-uuid-2'], 'sync-uuid-2');
		$synchronizationService = $this->createMock(SynchronizationService::class);
		$synchronizationService->method('getSynchronization')->willReturn($synchronizationEntity);
		$synchronizationService->expects($this->once())
			->method('replaySynchronizationItem')
			->with($this->anything(), $this->anything(), false, $this->isInstanceOf(ExecutionTraceContext::class))
			->willReturn(['result' => [], 'targetId' => null]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($synchronizationService);

		$service = new ExecutionTraceService($orObjectService, $container, $this->createMock(LoggerInterface::class));

		$service->replay(traceId: 'orig-2', actorUid: 'admin', force: true);
	}//end testForcedReplayOfSyncTraceForwardsIsTestFalse()

	/**
	 * A dry-run replay of an `event`-entryPoint webhook trace calls
	 * EventService::previewWebhookDelivery() and MUST NOT call
	 * replayMessage() (which would dispatch for real) — REQ-005.
	 *
	 * @return void
	 */
	public function testDryRunReplayOfEventTraceNeverDispatches(): void {
		$originalTrace = ObjectServiceMockBuilder::objectEntity(
			$this,
			['traceId' => 'orig-3', 'entryPoint' => 'event', 'entryPointId' => 'msg-1', 'status' => 'failed', 'steps' => []],
			'orig-3'
		);

		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->method('find')->willReturnCallback(
			function (string $id, ...$rest) use ($originalTrace) {
				unset($rest);
				if ($id === 'orig-3') {
					return $originalTrace;
				}

				return ObjectServiceMockBuilder::objectEntity($this, [], $id);
			}
		);
		$orObjectService->method('saveObject')->willReturnCallback(
			fn (array $payload) => ObjectServiceMockBuilder::objectEntity($this, $payload, ($payload['traceId'] ?? 'new-trace'))
		);

		$eventService = $this->createMock(EventService::class);
		$eventService->expects($this->never())->method('replayMessage');
		$eventService->expects($this->once())
			->method('previewWebhookDelivery')
			->willReturn(['url' => 'https://sink.example', 'method' => 'POST', 'headers' => []]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($eventService);

		$service = new ExecutionTraceService($orObjectService, $container, $this->createMock(LoggerInterface::class));

		$service->replay(traceId: 'orig-3', actorUid: 'admin', force: false);
	}//end testDryRunReplayOfEventTraceNeverDispatches()

	/**
	 * A forced replay of an `event`-entryPoint trace delegates to the
	 * existing, UNCHANGED EventService::replayMessage() dead-letter-replay
	 * path (REQ-006).
	 *
	 * @return void
	 */
	public function testForcedReplayOfEventTraceDelegatesToReplayMessage(): void {
		$originalTrace = ObjectServiceMockBuilder::objectEntity(
			$this,
			['traceId' => 'orig-4', 'entryPoint' => 'event', 'entryPointId' => 'msg-2', 'status' => 'failed', 'steps' => []],
			'orig-4'
		);

		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->method('find')->willReturn($originalTrace);
		$orObjectService->method('saveObject')->willReturnCallback(
			fn (array $payload) => ObjectServiceMockBuilder::objectEntity($this, $payload, ($payload['traceId'] ?? 'new-trace'))
		);

		$eventService = $this->createMock(EventService::class);
		$eventService->expects($this->once())
			->method('replayMessage')
			->with('msg-2', 'ops-admin')
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, [], 'msg-2'));
		$eventService->expects($this->never())->method('previewWebhookDelivery');

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($eventService);

		$service = new ExecutionTraceService($orObjectService, $container, $this->createMock(LoggerInterface::class));

		$service->replay(traceId: 'orig-4', actorUid: 'ops-admin', force: true);
	}//end testForcedReplayOfEventTraceDelegatesToReplayMessage()
}//end class
