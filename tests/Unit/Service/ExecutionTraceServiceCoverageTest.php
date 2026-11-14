<?php

/**
 * Unit tests for ExecutionTraceService's persistence edge cases and its job
 * and failing event replays.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/execution-trace/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\ExecutionTraceService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * An unparsable start time and a resume continuation still persist one trace;
 * a job replay always runs for real; a failing replay is logged and recorded as failed.
 *
 * @covers \OCA\Integriq\Service\ExecutionTraceService
 * @uses   \OCA\Integriq\Service\Helper\ExecutionTraceContext
 * @uses   \OCA\Integriq\Service\TraceReplayRequest
 */
class ExecutionTraceServiceCoverageTest extends TestCase {

	/**
	 * The OpenRegister object service.
	 *
	 * @var ORObjectService&MockObject
	 */
	private ORObjectService&MockObject $objects;

	/**
	 * The container the replay services are resolved from.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The payload of the last saved trace.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $saved = null;

	/**
	 * The service under test.
	 *
	 * @var ExecutionTraceService
	 */
	private ExecutionTraceService $service;

	/**
	 * Build the service; saving a trace records its payload.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ORObjectService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->saved = null;

		$this->objects->method('saveObject')->willReturnCallback(
			function (array $payload): ObjectEntity {
				$this->saved = $payload;
				return ObjectServiceMockBuilder::objectEntity($this, $payload, $payload['traceId']);
			}
		);

		$this->service = new ExecutionTraceService(
			orObjectService: $this->objects,
			containerInterface: $this->container,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * The original trace find() answers for a replay.
	 *
	 * @param string $entryPoint The original entry point.
	 * @param string $entryPointId The original entry point's id.
	 * @param array<int,array<string,mixed>> $steps The original steps.
	 *
	 * @return ObjectEntity The original trace.
	 */
	private function original(string $entryPoint, string $entryPointId, array $steps = []): ObjectEntity {
		return ObjectServiceMockBuilder::objectEntity(
			$this,
			['traceId' => 'orig-1', 'entryPoint' => $entryPoint, 'entryPointId' => $entryPointId, 'status' => 'failed', 'steps' => $steps],
			'orig-1'
		);
	}//end original()

	/**
	 * A start time strtotime() cannot read counts as "now": the duration is
	 * zero rather than measured from the Unix epoch.
	 *
	 * @return void
	 */
	public function testAnUnparsableStartTimeYieldsAZeroDuration(): void {
		$trace = new ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: 'ep-1', traceId: 'trace-1');
		(new ReflectionProperty(ExecutionTraceContext::class, 'startedAt'))->setValue($trace, 'not a date');

		$result = $this->service->persist(trace: $trace, status: 'success');

		$this->assertSame('trace-1', $result->getUuid());
		$this->assertSame('not a date', $this->saved['startedAt']);
		$this->assertSame(0, $this->saved['durationMs']);
	}//end testAnUnparsableStartTimeYieldsAZeroDuration()

	/**
	 * The approval-resume continuation is logged and saved under the same traceId.
	 *
	 * @return void
	 */
	public function testAResumeContinuationIsLoggedAndSavedUnderTheSameId(): void {
		$trace = new ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: 'ep-1', traceId: 'trace-2');

		$this->logger->expects($this->once())
			->method('debug')
			->with('ExecutionTraceService: persisting approval-resume continuation.', ['traceId' => 'trace-2']);

		$result = $this->service->persist(trace: $trace, status: 'running', resume: true);

		$this->assertSame('trace-2', $result->getUuid());
		$this->assertSame('running', $this->saved['status']);
	}//end testAResumeContinuationIsLoggedAndSavedUnderTheSameId()

	/**
	 * A synchronization step whose input is not an array replays with an empty payload.
	 *
	 * @return void
	 */
	public function testANonArrayStepInputReplaysWithAnEmptyPayload(): void {
		$this->objects->method('find')->willReturn(
			$this->original(
				entryPoint: 'sync',
				entryPointId: 'sync-1',
				steps: [
					['type' => 'rule', 'input' => ['ignored' => true]],
					['type' => 'synchronization', 'input' => 'redacted'],
				]
			)
		);

		$synchronizationService = $this->createMock(SynchronizationService::class);
		$synchronizationService->expects($this->once())
			->method('getSynchronization')
			->with('sync-1')
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['uuid' => 'sync-1'], 'sync-1'));
		$synchronizationService->expects($this->once())
			->method('replaySynchronizationItem')
			->with($this->anything(), [], true, $this->isInstanceOf(ExecutionTraceContext::class))
			->willReturn(['result' => [], 'targetId' => null]);
		$this->container->method('get')->with(SynchronizationService::class)->willReturn($synchronizationService);

		$result = $this->service->replay(traceId: 'orig-1', actorUid: 'admin');

		$this->assertSame($this->saved['traceId'], $result->getUuid());
		$this->assertSame('success', $this->saved['status']);
	}//end testANonArrayStepInputReplaysWithAnEmptyPayload()

	/**
	 * A job replay loads the job without access checks and runs it for real
	 * (forceRun) into the new trace, even when the replay is a dry run.
	 *
	 * @return void
	 */
	public function testAJobReplayRunsTheJobIntoTheNewTrace(): void {
		$job = ObjectServiceMockBuilder::objectEntity($this, ['name' => 'nightly'], 'job-1');
		$this->objects->expects($this->exactly(2))
			->method('find')
			->willReturnCallback(
				function (string $id, $register, $schema, $rbac = true, $multitenancy = true) use ($job): ObjectEntity {
					if ($id === 'orig-1') {
						return $this->original(entryPoint: 'job', entryPointId: 'job-1');
					}

					$this->assertSame(['job-1', 'integriq', 'job', false, false], [$id, $register, $schema, $rbac, $multitenancy]);
					return $job;
				}
			);

		$jobService = $this->createMock(JobService::class);
		$jobService->expects($this->once())
			->method('executeJob')
			->with(
				$job,
				true,
				$this->callback(static fn (ExecutionTraceContext $trace): bool => $trace->getReplayOf() === 'orig-1' && $trace->isDryRun() === true)
			)
			->willReturn($job);
		$this->container->expects($this->once())->method('get')->with(JobService::class)->willReturn($jobService);
		$this->logger->expects($this->never())->method('warning');

		$this->service->replay(traceId: 'orig-1', actorUid: 'admin');

		$this->assertSame('job', $this->saved['entryPoint']);
		$this->assertSame('job-1', $this->saved['entryPointId']);
		$this->assertSame('success', $this->saved['status']);
		$this->assertTrue($this->saved['isReplay']);
	}//end testAJobReplayRunsTheJobIntoTheNewTrace()

	/**
	 * A job that fails to run is logged and recorded as a failed replay.
	 *
	 * @return void
	 */
	public function testAFailingJobReplayIsLoggedAndRecordedAsFailed(): void {
		$job = ObjectServiceMockBuilder::objectEntity($this, [], 'job-1');
		$this->objects->method('find')->willReturnCallback(
			fn (string $id): ObjectEntity => $id === 'orig-1' ? $this->original(entryPoint: 'job', entryPointId: 'job-1') : $job
		);

		$jobService = $this->createMock(JobService::class);
		$jobService->expects($this->once())->method('executeJob')->willThrowException(new RuntimeException('job broke'));
		$this->container->method('get')->willReturn($jobService);

		$this->logger->expects($this->once())
			->method('warning')
			->with('ExecutionTraceService: job-entryPoint replay failed.', ['entryPointId' => 'job-1', 'exception' => 'job broke']);

		$this->service->replay(traceId: 'orig-1', actorUid: 'admin', force: true);

		$this->assertSame('failed', $this->saved['status']);
		$this->assertFalse($this->saved['dryRun']);
	}//end testAFailingJobReplayIsLoggedAndRecordedAsFailed()

	/**
	 * An event preview that fails is logged and recorded as a failed replay,
	 * without falling through to a real delivery.
	 *
	 * @return void
	 */
	public function testAFailingEventPreviewIsLoggedAndRecordedAsFailed(): void {
		$message = ObjectServiceMockBuilder::objectEntity($this, [], 'msg-1');
		$this->objects->method('find')->willReturnCallback(
			fn (string $id): ObjectEntity => $id === 'orig-1' ? $this->original(entryPoint: 'event', entryPointId: 'msg-1') : $message
		);

		$eventService = $this->createMock(EventService::class);
		$eventService->expects($this->once())
			->method('previewWebhookDelivery')
			->with($message)
			->willThrowException(new RuntimeException('no webhook'));
		$eventService->expects($this->never())->method('replayMessage');
		$this->container->method('get')->with(EventService::class)->willReturn($eventService);

		$this->logger->expects($this->once())
			->method('warning')
			->with('ExecutionTraceService: event-entryPoint replay failed.', ['entryPointId' => 'msg-1', 'exception' => 'no webhook']);

		$this->service->replay(traceId: 'orig-1', actorUid: 'admin');

		$this->assertSame('failed', $this->saved['status']);
		$this->assertSame([], $this->saved['steps']);
	}//end testAFailingEventPreviewIsLoggedAndRecordedAsFailed()
}//end class
