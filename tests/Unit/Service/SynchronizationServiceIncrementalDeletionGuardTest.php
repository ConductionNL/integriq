<?php

/**
 * Unit tests for the incremental-mode deletion block (REQ-018).
 *
 * Change: cdc-incremental-sync. An incremental fetch is, by construction, a
 * cursor-filtered subset of the source — the absence of a target id from a
 * given run is not evidence that the corresponding source record was
 * deleted, so `deleteInvalidObjects()` must never run for a `syncMode:
 * incremental` Synchronization, unconditionally (never bypassed by
 * `forceDeletion`), at both the `synchronizeExternToIntern()` call site and
 * (defense-in-depth) inside `deleteInvalidObjects()` itself.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Event\SynchronizationDeletionGuardedEvent;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests REQ-018: deletion garbage-collection never runs for an incremental sync.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-deletion-garbage-collection-never-runs-for-an-incremental-sync-req-018
 */
class SynchronizationServiceIncrementalDeletionGuardTest extends TestCase {

	private const SYNC_ID = 'sync-uuid-incguard';

	/**
	 * @var ORObjectService&MockObject
	 */
	private $orObjectService;

	/**
	 * @var CallService&MockObject
	 */
	private $callService;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * @var IEventDispatcher&MockObject
	 */
	private $eventDispatcher;

	/**
	 * Set up shared mocks (the service itself is built per-test so each test
	 * can pick which methods to isolate).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->callService = $this->createMock(CallService::class);
		$this->callService->method('applyConfigDot')->willReturnArgument(0);
	}//end setUp()

	/**
	 * Build the service as a partial mock isolating the given methods, with
	 * a container that resolves the lazily-fetched IEventDispatcher.
	 *
	 * @param string[] $onlyMethods The methods to replace with mocks.
	 *
	 * @return SynchronizationService&MockObject
	 */
	private function makeService(array $onlyMethods): MockObject {
		$mappingService = $this->createMock(MappingService::class);
		$objectService = $this->createMock(ObjectService::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => ($id === IEventDispatcher::class) ? $this->eventDispatcher : null
		);

		$logOrService = ObjectServiceMockBuilder::make($this);
		$userSession = $this->createMock(\OCP\IUserSession::class);
		$session = $this->createMock(\OCP\ISession::class);
		$logService = new SynchronizationLogService($logOrService, $userSession, $session);

		return $this->getMockBuilder(SynchronizationService::class)
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
					$this->createMock(\OCA\Integriq\Service\ApprovalService::class),
				]
			)
			->onlyMethods($onlyMethods)
			->getMock();
	}//end makeService()

	/**
	 * Build a synchronization payload with an api source, one page, and a
	 * register/schema target.
	 *
	 * @param array $extra Extra top-level synchronization keys to merge in.
	 *
	 * @return array
	 */
	private function makeSyncPayload(array $extra = []): array {
		return array_merge(
			[
				'id' => self::SYNC_ID,
				'uuid' => self::SYNC_ID,
				'sourceId' => 'source-uuid-incguard',
				'sourceType' => 'api',
				'targetType' => 'register/schema',
				'targetId' => '1/2',
				'sourceConfig' => [
					'endpoint' => '/items',
					'resultsPosition' => 'items',
					'usesPagination' => false,
				],
			],
			$extra
		);
	}//end makeSyncPayload()

	/**
	 * Stub source resolution + one page of a single record + an empty
	 * existing-contracts result.
	 *
	 * @return void
	 */
	private function stubSourceOnePageNoContracts(): void {
		$sourceEntity = ObjectServiceMockBuilder::objectEntity(
			$this,
			['location' => 'https://example.test', 'enabled' => true],
			'source-uuid-incguard'
		);
		$this->orObjectService->method('find')->willReturn($sourceEntity);

		$this->callService->method('call')->willReturn(
			ObjectServiceMockBuilder::objectEntity(
				$this,
				[
					'response' => [
						'statusCode' => 200,
						'body' => json_encode(['items' => [['id' => 'origin-1']]]),
						'encoding' => 'UTF-8',
						'headers' => [],
					],
				],
				'call-log-1'
			)
		);

		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		// The create-path (no matching existing contract) exercises the
		// real, unmocked synchronizeContract()/persistContract(), which
		// saves the new contract through this same OR mock — give it back a
		// real uuid so downstream `$result['contracts']` never carries a
		// null id into findContract().
		$this->orObjectService->method('saveObject')->willReturnCallback(
			fn (array $object, ...$rest) => ObjectServiceMockBuilder::objectEntity($this, $object, 'contract-uuid-new')
		);
	}//end stubSourceOnePageNoContracts()

	/**
	 * TC-9: incremental mode blocks deletion even on a complete fetch —
	 * `deleteInvalidObjects()` is never invoked and the run's
	 * `deletionGuard` records `reason: incremental_mode`.
	 *
	 * @return void
	 */
	public function testIncrementalModeBlocksDeletionEvenOnCompleteFetch(): void {
		$service = $this->makeService(['deleteInvalidObjects', 'updateTarget']);
		$this->stubSourceOnePageNoContracts();

		$service->method('updateTarget')->willReturnCallback(
			static fn (array $contract, ...$rest) => $contract
		);
		$service->expects($this->never())->method('deleteInvalidObjects');

		$result = $service->synchronize(synchronization: $this->makeSyncPayload(['syncMode' => 'incremental']));

		$this->assertSame(0, $result['result']['objects']['deleted']);
		$this->assertTrue($result['result']['objects']['deletionGuard']['guarded']);
		$this->assertSame('incremental_mode', $result['result']['objects']['deletionGuard']['reason']);
	}//end testIncrementalModeBlocksDeletionEvenOnCompleteFetch()

	/**
	 * TC-10: `forceDeletion: true` cannot override the incremental-mode
	 * block — `deleteInvalidObjects()` is still never invoked.
	 *
	 * @return void
	 */
	public function testForceDeletionCannotOverrideIncrementalModeBlock(): void {
		$service = $this->makeService(['deleteInvalidObjects', 'updateTarget']);
		$this->stubSourceOnePageNoContracts();

		$service->method('updateTarget')->willReturnCallback(
			static fn (array $contract, ...$rest) => $contract
		);
		$service->expects($this->never())->method('deleteInvalidObjects');

		$result = $service->synchronize(
			synchronization: $this->makeSyncPayload(['syncMode' => 'incremental']),
			forceDeletion: true
		);

		$this->assertSame(0, $result['result']['objects']['deleted']);
		$this->assertSame('incremental_mode', $result['result']['objects']['deletionGuard']['reason']);
	}//end testForceDeletionCannotOverrideIncrementalModeBlock()

	/**
	 * TC-11: `deleteInvalidObjects()` called directly (bypassing
	 * `synchronizeExternToIntern()`) against an incremental Synchronization
	 * still refuses — even with `fetchComplete: true` and
	 * `forceDeletion: true` — returns 0, logs a warning, and dispatches
	 * `SynchronizationDeletionGuardedEvent` with `reason: incremental_mode`.
	 *
	 * @return void
	 */
	public function testDeleteInvalidObjectsDirectCallRefusesForIncrementalSync(): void {
		$service = $this->makeService(['updateTarget']);

		// 100 existing contracts — if the guard were missing, this would be
		// a mass-deletion candidate set; the incremental-mode block must
		// refuse before the ratio guard (or anything else) is evaluated.
		$contracts = [];
		for ($i = 1; $i <= 100; $i++) {
			$contracts[] = ObjectServiceMockBuilder::objectEntity(
				$this,
				['synchronizationId' => self::SYNC_ID, 'originId' => 'origin-' . $i, 'targetId' => 'target-' . $i],
				'contract-uuid-' . $i
			);
		}
		$this->orObjectService->method('findAll')->willReturn(['results' => $contracts, 'total' => count($contracts)]);

		$service->expects($this->never())->method('updateTarget');

		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('incremental sync mode'));

		$this->eventDispatcher->expects($this->once())
			->method('dispatchTyped')
			->with(
				$this->callback(
					function ($event) {
						return $event instanceof SynchronizationDeletionGuardedEvent
							&& $event->getReason() === 'incremental_mode'
							&& $event->getSynchronizationId() === self::SYNC_ID;
					}
				)
			);

		$guardInfo = null;
		$deleted = $service->deleteInvalidObjects(
			$this->makeSyncPayload(['syncMode' => 'incremental']),
			[],
			false,
			[],
			true,
			true,
			$guardInfo
		);

		$this->assertSame(0, $deleted);
		$this->assertTrue($guardInfo['guarded']);
		$this->assertSame('incremental_mode', $guardInfo['reason']);
	}//end testDeleteInvalidObjectsDirectCallRefusesForIncrementalSync()

	/**
	 * TC-12 (regression): the event-driven single-object `deleteRestriction`
	 * path (`data !== null && mutationType === 'delete'`) never calls the
	 * bulk source-diff `deleteInvalidObjects()` branch regardless of
	 * `syncMode` — REQ-018 introduces no new behavior here.
	 *
	 * @return void
	 */
	public function testEventDrivenSingleObjectDeleteUnaffectedByIncrementalMode(): void {
		$service = $this->makeService(['deleteInvalidObjects', 'updateTarget']);

		$contract = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'synchronizationId' => self::SYNC_ID,
				'originId' => 'origin-1',
				'targetId' => 'target-1',
			],
			'contract-uuid-1'
		);
		$this->orObjectService->method('findAll')->willReturn(['results' => [$contract], 'total' => 1]);

		$service->expects($this->never())->method('deleteInvalidObjects');
		$service->expects($this->once())
			->method('updateTarget')
			->willReturnCallback(static fn (array $c, ...$rest) => $c);

		$synchronization = $this->makeSyncPayload(
			['syncMode' => 'incremental', 'sourceConfig' => ['restrictDeletion' => true]]
		);

		$service->synchronize(
			synchronization: $synchronization,
			data: ['id' => 'origin-1'],
			mutationType: 'delete'
		);
	}//end testEventDrivenSingleObjectDeleteUnaffectedByIncrementalMode()
}//end class
