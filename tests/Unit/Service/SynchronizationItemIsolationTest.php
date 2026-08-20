<?php

/**
 * Unit tests for per-item isolation + dead-letter capture in
 * SynchronizationService::synchronizeExternToIntern()'s object loop.
 *
 * Named/placed to mirror design.md's suggested
 * `tests/Integration/Service/SynchronizationItemIsolationTest.php`, but
 * placed under tests/Unit/Service/ instead: `phpunit-unit.xml` only wires
 * `tests/Unit` into the executed suite (no `tests/Integration` testsuite
 * exists in this app), so a test placed under the design's suggested path
 * would silently never run in CI/the local baseline. Drives the loop with
 * plain PHPUnit mocks (no real HTTP/DB), same spirit as `Integration`
 * despite the directory name.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\SynchronizationLogService;
use OCA\OpenConnector\Service\SynchronizationRunLog;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Service\SyncItemDeadLetterService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * TC-11 — one bad sync item does not abort the pass.
 *
 * @spec openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008
 */
class SynchronizationItemIsolationTest extends TestCase {

	/**
	 * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $orObjectService;

	/**
	 * @var CallService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $callService;

	/**
	 * @var SynchronizationLogService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $synchronizationLogService;

	/**
	 * @var SyncItemDeadLetterService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $syncItemDeadLetterService;

	/**
	 * Build a partial SynchronizationService mock whose `getAllObjectsFromSource()`
	 * is stubbed to return the given object list — every other method runs
	 * for real, exercising the actual per-item try/catch loop under test.
	 *
	 * @param array $objectList The objects `getAllObjectsFromSource()` should return.
	 *
	 * @return SynchronizationService
	 */
	private function buildServiceReturningObjects(array $objectList): SynchronizationService {
		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->orObjectService->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null, $uuid = null) {
				$entity = new ObjectEntity();
				$entity->setUuid($uuid ?? 'saved-synchronization');
				$entity->setObject(is_array($object) === true ? $object : []);
				return $entity;
			}
		);
		$this->orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$this->callService = $this->createMock(CallService::class);
		$this->callService->method('applyConfigDot')->willReturnArgument(0);

		$this->synchronizationLogService = $this->createMock(SynchronizationLogService::class);
		$this->synchronizationLogService->method('createFromArray')->willReturnCallback(
			static fn (array $object) => (new SynchronizationRunLog())->hydrate($object)
		);
		$this->synchronizationLogService->method('update')->willReturnCallback(
			static fn (SynchronizationRunLog $log) => $log
		);

		$this->syncItemDeadLetterService = $this->createMock(SyncItemDeadLetterService::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === SyncItemDeadLetterService::class) {
					return $this->syncItemDeadLetterService;
				}

				return null;
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$service = $this->getMockBuilder(SynchronizationService::class)
			->setConstructorArgs(
				[
					$this->callService,
					$this->createMock(MappingService::class),
					$container,
					$this->orObjectService,
					$objectService,
					$this->createMock(LoggerInterface::class),
					$this->synchronizationLogService,
					$appConfig,
					$this->createMock(\OCA\OpenConnector\Service\ApprovalService::class),
				]
			)
			->onlyMethods(['getAllObjectsFromSource'])
			->getMock();

		$service->method('getAllObjectsFromSource')->willReturn($objectList);

		return $service;
	}//end buildServiceReturningObjects()

	/**
	 * TC-11 — a synchronization fetching 3 objects where the middle object
	 * has no resolvable origin id (a naturally-thrown Exception deep inside
	 * `processSynchronizationObject()`) does not abort the pass: the other
	 * two objects are still visited (skipped via the configured JsonLogic
	 * condition — kept deliberately simple so the "surviving" path does not
	 * need to mock the full create/update contract machinery), the failing
	 * object is dead-lettered via SyncItemDeadLetterService, and the
	 * synchronization_log is still persisted at the end.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008
	 */
	public function testOneBadItemDoesNotAbortTheSyncPass(): void {
		$objectList = [
			['process' => false, 'id' => 'obj-1'],
			// No 'id' key — getOriginId() throws a real Exception for this one.
			['process' => true],
			['process' => false, 'id' => 'obj-3'],
		];

		$service = $this->buildServiceReturningObjects($objectList);

		$this->syncItemDeadLetterService->expects($this->once())
			->method('recordFailure')
			->with(
				$this->anything(),
				$this->callback(static fn ($payload) => $payload === ['process' => true]),
				$this->stringContains('Could not find origin id'),
				$this->isNull()
			);

		$synchronization = [
			'uuid' => 'sync-1',
			'id' => 'sync-1',
			'name' => 'isolation-test',
			'sourceId' => 'source-1',
			'conditions' => ['==' => [['var' => 'process'], true]],
		];

		$result = $service->synchronize(synchronization: $synchronization);

		$this->assertIsArray($result);
		$this->assertSame(1, $result['result']['objects']['invalid']);
		$this->assertSame(2, $result['result']['objects']['skipped']);
		$this->assertSame(3, $result['result']['objects']['found']);
	}//end testOneBadItemDoesNotAbortTheSyncPass()

}//end class
