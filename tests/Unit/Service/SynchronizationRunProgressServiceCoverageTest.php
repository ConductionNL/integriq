<?php

/**
 * Unit tests for SynchronizationRunProgressService: the disabled path, the
 * throttle, the no-run no-ops and the swallowed-but-counted write failure.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-mid-run-progress-is-observable-without-slowing-the-run-req-022
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\SynchronizationRunProgressService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use RuntimeException;

/**
 * Covers the throttle, the no-op paths and the failure counter.
 *
 * @covers \OCA\Integriq\Service\SynchronizationRunProgressService
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-mid-run-progress-is-observable-without-slowing-the-run-req-022
 */
final class SynchronizationRunProgressServiceCoverageTest extends TestCase {

	private const RUN_UUID = '7f1c1d2e-0000-4000-8000-000000000001';

	/**
	 * Payloads handed to saveObject(), with their uuid argument.
	 *
	 * @var array<int, array{object: array<string, mixed>, uuid: string|null}>
	 */
	private array $saved = [];

	/**
	 * The mocked object service.
	 *
	 * @var OrObjectService&MockObject
	 */
	private OrObjectService&MockObject $objects;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved   = [];
		$this->objects = $this->createMock(OrObjectService::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Make saveObject() record each call and return the run entity.
	 *
	 * @return void
	 */
	private function recordSaves(): void {
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object, ?string $register, ?string $schema, ?string $uuid): ObjectEntity {
				$this->saved[] = ['object' => $object, 'uuid' => $uuid];
				$entity = new ObjectEntity();
				$entity->setUuid(self::RUN_UUID);
				return $entity;
			}
		);
	}//end recordSaves()

	/**
	 * Build the service.
	 *
	 * @return SynchronizationRunProgressService
	 */
	private function makeService(): SynchronizationRunProgressService {
		return new SynchronizationRunProgressService($this->objects, $this->logger);
	}//end makeService()

	/**
	 * Pretend the last write happened long ago, so the throttle lets the next one through.
	 *
	 * @param SynchronizationRunProgressService $service The service.
	 *
	 * @return void
	 */
	private function expireThrottle(SynchronizationRunProgressService $service): void {
		$property = new ReflectionProperty(SynchronizationRunProgressService::class, 'lastWrite');
		$property->setValue($service, 0.0);
	}//end expireThrottle()

	/**
	 * A disabled run writes nothing, and tick/finish stay no-ops afterwards.
	 *
	 * @return void
	 */
	public function testADisabledRunWritesNothing(): void {
		$this->objects->expects($this->never())->method('saveObject');
		$service = $this->makeService();

		$service->start(synchronizationId: 'sync-1', enabled: false);
		$service->tick(counters: ['processed' => 5]);
		$service->finish(status: 'success');

		$this->assertNull($service->lastRunId());
		$this->assertSame(0, $service->writeCount());
	}//end testADisabledRunWritesNothing()

	/**
	 * A tick inside the throttle window merges counters but does not write.
	 *
	 * @return void
	 */
	public function testATickInsideTheThrottleWindowDoesNotWrite(): void {
		$this->recordSaves();
		$service = $this->makeService();
		$service->start(synchronizationId: 'sync-1');

		$service->tick(counters: ['processed' => 3]);

		$this->assertCount(1, $this->saved);
		$this->assertSame(1, $service->writeCount());

		// The merged counter still reaches the final record.
		$service->finish(status: 'success');
		$this->assertCount(2, $this->saved);
		$this->assertSame(3, $this->saved[1]['object']['processed']);
	}//end testATickInsideTheThrottleWindowDoesNotWrite()

	/**
	 * A tick after the throttle window writes the merged counters to the run record.
	 *
	 * @return void
	 */
	public function testATickAfterTheThrottleWindowUpdatesTheRunRecord(): void {
		$this->recordSaves();
		$service = $this->makeService();
		$service->start(synchronizationId: 'sync-1');
		$this->expireThrottle(service: $service);

		$service->tick(counters: ['processed' => 7, 'currentPage' => 2]);

		$this->assertCount(2, $this->saved);
		$this->assertSame(2, $service->writeCount());
		$this->assertSame(self::RUN_UUID, $this->saved[1]['uuid']);
		$this->assertSame(7, $this->saved[1]['object']['processed']);
		$this->assertSame(2, $this->saved[1]['object']['currentPage']);
		$this->assertSame('running', $this->saved[1]['object']['status']);

		// The tick reset the throttle: an immediate second tick is held back.
		$service->tick(counters: ['processed' => 8]);
		$this->assertCount(2, $this->saved);
	}//end testATickAfterTheThrottleWindowUpdatesTheRunRecord()

	/**
	 * The tick write updates the run by uuid on the cheap save path.
	 *
	 * @return void
	 */
	public function testTheTickWriteUsesTheCheapSavePathOnTheRunUuid(): void {
		$entity = new ObjectEntity();
		$entity->setUuid(self::RUN_UUID);
		$this->objects->expects($this->exactly(2))
			->method('saveObject')
			->with(
				$this->callback(static fn (array $object): bool => $object['synchronizationId'] === 'sync-9'),
				'integriq',
				'synchronization_run',
				$this->logicalOr($this->isNull(), $this->identicalTo(self::RUN_UUID)),
				false,
				false,
				true,
				false
			)
			->willReturn($entity);

		$service = $this->makeService();
		$service->start(synchronizationId: 'sync-9');
		$this->expireThrottle(service: $service);
		$service->tick(counters: ['found' => 4]);

		$this->assertSame(2, $service->writeCount());
	}//end testTheTickWriteUsesTheCheapSavePathOnTheRunUuid()

	/**
	 * Finish after finish is a no-op: the run is closed once.
	 *
	 * @return void
	 */
	public function testFinishingTwiceWritesOnce(): void {
		$this->recordSaves();
		$service = $this->makeService();
		$service->start(synchronizationId: 'sync-1');
		$service->finish(status: 'success');
		$service->finish(status: 'failed');

		$this->assertCount(2, $this->saved);
		$this->assertSame('success', $this->saved[1]['object']['status']);
		// The counter is taken before the final write itself is issued.
		$this->assertSame(1, $this->saved[1]['object']['progressWrites']);
		$this->assertSame(2, $service->writeCount());
		$this->assertSame(0, $this->saved[1]['object']['progressWriteFailures']);
	}//end testFinishingTwiceWritesOnce()

	/**
	 * A failed opening write is swallowed, logged, and leaves the run unrecorded.
	 *
	 * @return void
	 */
	public function testAFailedOpeningWriteIsSwallowedAndLogged(): void {
		$this->objects->expects($this->once())
			->method('saveObject')
			->willThrowException(new RuntimeException('register down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('[SynchronizationRunProgressService] progress write failed (run continues): register down');

		$service = $this->makeService();
		$service->start(synchronizationId: 'sync-1');
		// No run uuid: these must not try to write again.
		$service->tick(counters: ['processed' => 1]);
		$service->finish(status: 'failed');

		$this->assertNull($service->lastRunId());
		$this->assertSame(1, $service->writeCount());
	}//end testAFailedOpeningWriteIsSwallowedAndLogged()

	/**
	 * A failed mid-run write is counted and reported on the final record.
	 *
	 * @return void
	 */
	public function testAFailedTickIsReportedOnTheFinalRecord(): void {
		$calls = 0;
		$this->objects->method('saveObject')->willReturnCallback(
			function (array $object) use (&$calls): ObjectEntity {
				$calls++;
				if ($calls === 2) {
					throw new RuntimeException('lock timeout');
				}

				$this->saved[] = ['object' => $object, 'uuid' => null];
				$entity = new ObjectEntity();
				$entity->setUuid(self::RUN_UUID);
				return $entity;
			}
		);
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('lock timeout'));

		$service = $this->makeService();
		$service->start(synchronizationId: 'sync-1');
		$this->expireThrottle(service: $service);
		$service->tick(counters: ['processed' => 1]);
		$service->finish(status: 'success');

		$this->assertSame(3, $service->writeCount());
		$final = end($this->saved);
		$this->assertSame(1, $final['object']['progressWriteFailures']);
		$this->assertSame(2, $final['object']['progressWrites']);
		$this->assertSame(self::RUN_UUID, $service->lastRunId());
	}//end testAFailedTickIsReportedOnTheFinalRecord()
}//end class
