<?php

/**
 * Unit tests for SyncItemDeadLetterService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/dead-letter-replay/spec.md#requirement-audited-manual-replay-of-a-dead-lettered-sync-item-req-dlr-009
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Exception\InvalidMessageStateException;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\SyncItemDeadLetterService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the sync-item dead-letter capture/replay/discard machinery.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008
 */
class SyncItemDeadLetterServiceTest extends TestCase {

	/**
	 * Captured saveObject() invocations.
	 *
	 * @var array
	 */
	private array $saved = [];

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objectService;

	/**
	 * @var ContainerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $container;

	/**
	 * @var SyncItemDeadLetterService
	 */
	private SyncItemDeadLetterService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->saved = [];
		$this->objectService = $this->createMock(ORObjectService::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null, $uuid = null) {
				$this->saved[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? ('saved-' . count($this->saved)));
				$entity->setObject(is_array($object) === true ? $object : []);
				return $entity;
			}
		);

		$this->container = $this->createMock(ContainerInterface::class);

		$this->service = new SyncItemDeadLetterService(
			$this->objectService,
			$this->container,
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * REQ-008 — recordFailure() persists a `sync_item_dead_letter` entry
	 * carrying the raw payload, error, and `phase: 'item-processing'`.
	 *
	 * @return void
	 */
	public function testRecordFailurePersistsDeadLetterEntry(): void {
		$entry = $this->service->recordFailure(
			synchronization: ['uuid' => 'sync-1'],
			payload: ['foo' => 'bar'],
			error: 'mapping exploded',
			originId: 'origin-42',
		);

		$this->assertCount(1, $this->saved);
		$this->assertSame('sync_item_dead_letter', $this->saved[0]['schema']);

		$data = $this->saved[0]['object'];
		$this->assertSame('sync-1', $data['synchronization']);
		$this->assertSame('origin-42', $data['originId']);
		$this->assertSame(['foo' => 'bar'], $data['payload']);
		$this->assertSame('mapping exploded', $data['error']);
		$this->assertSame('item-processing', $data['phase']);
		$this->assertSame('failed', $data['status']);
		$this->assertSame(0, $data['retryCount']);
		$this->assertCount(1, $data['attempts']);
		$this->assertInstanceOf(ObjectEntity::class, $entry);
	}//end testRecordFailurePersistsDeadLetterEntry()

	/**
	 * REQ-DLR-009 — replaying a failed entry against a successfully-resolved
	 * SynchronizationService sets status='replayed', stamps replayedBy/At,
	 * and preserves the existing attempts[] history.
	 *
	 * @return void
	 */
	public function testReplaySuccessMarksEntryReplayedAndPreservesAttempts(): void {
		$existing = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'synchronization' => 'sync-1',
				'payload' => ['id' => 'origin-1'],
				'status' => 'failed',
				'retryCount' => 0,
				'attempts' => [['at' => '2026-01-01T00:00:00+00:00', 'error' => 'first failure']],
			],
			'dl-1'
		);
		$this->objectService->method('find')->willReturn($existing);

		$syncEntity = ObjectServiceMockBuilder::objectEntity($this, ['uuid' => 'sync-1', 'name' => 'my-sync'], 'sync-1');
		$synchronizationService = $this->createMock(SynchronizationService::class);
		$synchronizationService->method('getSynchronization')->willReturn($syncEntity);
		$synchronizationService->expects($this->once())
			->method('replaySynchronizationItem')
			->with($this->anything(), ['id' => 'origin-1'])
			->willReturn(['result' => [], 'targetId' => 'target-1']);

		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($synchronizationService) {
				if ($id === SynchronizationService::class) {
					return $synchronizationService;
				}

				return null;
			}
		);

		$updated = $this->service->replayMessage(id: 'dl-1', actorUid: 'alice');
		$data = $updated->getObject();

		$this->assertSame('replayed', $data['status']);
		$this->assertSame('alice', $data['replayedBy']);
		$this->assertArrayHasKey('replayedAt', $data);
		// attempts[] preserved (still exactly the 1 original entry).
		$this->assertCount(1, $data['attempts']);
	}//end testReplaySuccessMarksEntryReplayedAndPreservesAttempts()

	/**
	 * REQ-DLR-009 — a renewed failure on replay stays `failed`, increments
	 * retryCount, and appends a new attempts[] entry (does not abandon).
	 *
	 * @return void
	 */
	public function testReplayRenewedFailureStaysFailedAndAppendsAttempt(): void {
		$existing = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'synchronization' => 'sync-1',
				'payload' => ['id' => 'origin-1'],
				'status' => 'failed',
				'retryCount' => 0,
				'attempts' => [['at' => '2026-01-01T00:00:00+00:00', 'error' => 'first failure']],
			],
			'dl-1'
		);
		$this->objectService->method('find')->willReturn($existing);

		$syncEntity = ObjectServiceMockBuilder::objectEntity($this, ['uuid' => 'sync-1'], 'sync-1');
		$synchronizationService = $this->createMock(SynchronizationService::class);
		$synchronizationService->method('getSynchronization')->willReturn($syncEntity);
		$synchronizationService->method('replaySynchronizationItem')->willThrowException(new \Exception('still broken'));

		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($synchronizationService) {
				if ($id === SynchronizationService::class) {
					return $synchronizationService;
				}

				return null;
			}
		);

		$updated = $this->service->replayMessage(id: 'dl-1', actorUid: 'alice');
		$data = $updated->getObject();

		$this->assertSame('failed', $data['status']);
		$this->assertSame(1, $data['retryCount']);
		$this->assertCount(2, $data['attempts']);
		$this->assertArrayNotHasKey('replayedBy', $data);
	}//end testReplayRenewedFailureStaysFailedAndAppendsAttempt()

	/**
	 * REQ-DLR-009 — replaying an already-replayed entry is rejected with a
	 * 409-equivalent InvalidMessageStateException; the entry is unchanged
	 * (saveObject is never called).
	 *
	 * @return void
	 */
	public function testReplayOnAlreadyReplayedEntryThrows(): void {
		$existing = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'replayed'], 'dl-2');
		$this->objectService->method('find')->willReturn($existing);

		$this->expectException(InvalidMessageStateException::class);
		$this->service->replayMessage(id: 'dl-2', actorUid: 'alice');

		$this->assertCount(0, $this->saved);
	}//end testReplayOnAlreadyReplayedEntryThrows()

	/**
	 * REQ-DLR-010 — discardMessage() sets status='discarded' with an audit
	 * stamp; never hard-deleted.
	 *
	 * @return void
	 */
	public function testDiscardMarksEntryDiscarded(): void {
		$existing = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'failed'], 'dl-3');
		$this->objectService->method('find')->willReturn($existing);

		$updated = $this->service->discardMessage(id: 'dl-3', actorUid: 'bob');
		$data = $updated->getObject();

		$this->assertSame('discarded', $data['status']);
		$this->assertSame('bob', $data['discardedBy']);
		$this->assertArrayHasKey('discardedAt', $data);
	}//end testDiscardMarksEntryDiscarded()

	/**
	 * REQ-DLR-010 — discard on an already-discarded/replayed entry is rejected.
	 *
	 * @return void
	 */
	public function testDiscardOnDiscardedEntryThrows(): void {
		$existing = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'discarded'], 'dl-4');
		$this->objectService->method('find')->willReturn($existing);

		$this->expectException(InvalidMessageStateException::class);
		$this->service->discardMessage(id: 'dl-4', actorUid: 'bob');
	}//end testDiscardOnDiscardedEntryThrows()

}//end class
