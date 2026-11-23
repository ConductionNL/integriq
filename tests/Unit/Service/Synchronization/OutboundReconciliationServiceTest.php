<?php

/**
 * The nightly repair of an outbound synchronization against the target's own list.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Synchronization;

use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\Synchronization\OutboundReconciliationService;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCA\Integriq\Service\SynchronizationLogServiceInterface;
use OCA\Integriq\Service\SynchronizationRunLog;
use OCA\Integriq\Service\SynchronizationServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Drives OutboundReconciliationService over a mocked engine, contract store and register.
 */
#[CoversClass(OutboundReconciliationService::class)]
class OutboundReconciliationServiceTest extends TestCase {

	/**
	 * The engine.
	 *
	 * @var SynchronizationServiceInterface&MockObject
	 */
	private SynchronizationServiceInterface&MockObject $engine;

	/**
	 * The contract store.
	 *
	 * @var SynchronizationContractServiceInterface&MockObject
	 */
	private SynchronizationContractServiceInterface&MockObject $contracts;

	/**
	 * The run log store.
	 *
	 * @var SynchronizationLogServiceInterface&MockObject
	 */
	private SynchronizationLogServiceInterface&MockObject $logs;

	/**
	 * The register.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objects;

	/**
	 * The stored run logs.
	 *
	 * @var SynchronizationRunLog[]
	 */
	private array $persistedLogs = [];

	/**
	 * The synchronizeContract calls, as [contract, object].
	 *
	 * @var array<int, array{0: array, 1: array}>
	 */
	private array $pushes = [];

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->engine = $this->createMock(SynchronizationServiceInterface::class);
		$this->contracts = $this->createMock(SynchronizationContractServiceInterface::class);
		$this->logs = $this->createMock(SynchronizationLogServiceInterface::class);
		$this->objects = $this->createMock(ObjectService::class);

		$this->logs->method('createFromArray')->willReturnCallback(
			fn (array $object): SynchronizationRunLog => (new SynchronizationRunLog())->hydrate(object: $object + ['id' => 'log-1'])
		);
		$this->logs->method('persist')->willReturnCallback(
			function (SynchronizationRunLog $log): SynchronizationRunLog {
				$this->persistedLogs[] = $log;
				return $log;
			}
		);
		$this->contracts->method('createFromArray')->willReturnCallback(
			fn (array $object): array => $object + ['id' => 'contract-' . $object['originId']]
		);
		$this->engine->method('synchronizeContract')->willReturnCallback(
			function (array $synchronizationContract, FlowToken &$flowToken, ?array $synchronization = null, array &$object = []): array {
				$this->pushes[] = [$synchronizationContract, $object];
				$contract = $synchronizationContract;
				$contract['targetId'] = ($contract['targetId'] ?? 'new-' . $contract['originId']);
				return ['contract' => $contract, 'log' => [], 'resultAction' => 'create'];
			}
		);
	}//end setUp()

	/**
	 * An outbound synchronization that opted in.
	 *
	 * @return array The synchronization.
	 */
	private function synchronization(): array {
		return [
			'id' => 'sync-1',
			'slug' => 'staff-to-target',
			'sourceType' => 'register/schema',
			'sourceId' => 'humaniq/Employee',
			'targetType' => 'api',
			'targetId' => 'target-source',
			'targetConfig' => [
				'endpoint' => '/teachers',
				'reconcile' => [
					'endpoint' => '/teachers',
					'resultsPosition' => 'teachers',
					'idPosition' => 'id',
					'match' => [['target' => 'externKey', 'source' => 'nextcloudUserId']],
				],
			],
		];
	}//end synchronization()

	/**
	 * The service under test.
	 *
	 * @return OutboundReconciliationService The service.
	 */
	private function service(): OutboundReconciliationService {
		return new OutboundReconciliationService(
			synchronizationService: $this->engine,
			contractService: $this->contracts,
			logService: $this->logs,
			objectService: $this->objects,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * The target answers these items, with this completeness.
	 *
	 * @param array $items    The listed items.
	 * @param bool  $complete Whether every page was read.
	 *
	 * @return void
	 */
	private function targetLists(array $items, bool $complete = true): void {
		$this->engine->method('getAllObjectsFromApi')->willReturnCallback(
			function (array $synchronization, ?bool $isTest = false, ?array $data = null, ?array &$fetchInfo = null) use ($items, $complete): array {
				$this->assertSame('target-source', $synchronization['sourceId']);
				$this->assertSame('api', $synchronization['sourceType']);
				$this->assertSame('/teachers', $synchronization['sourceConfig']['endpoint']);
				$this->assertArrayNotHasKey('match', $synchronization['sourceConfig']);
				$fetchInfo = ['complete' => $complete, 'pagesFetched' => 1, 'failureReason' => ($complete === true ? null : 'http_error')];
				return $items;
			}
		);
	}//end targetLists()

	/**
	 * The register holds these employees and the store these contracts.
	 *
	 * @param array $employees The employees, each with an `id`.
	 * @param array $contracts The contracts.
	 *
	 * @return void
	 */
	private function registerHolds(array $employees, array $contracts = []): void {
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config) use ($employees): array {
				$this->assertSame(['register' => 'humaniq', 'schema' => 'Employee'], $config['filters']);
				return ['results' => $employees];
			}
		);
		$this->contracts->method('findAllObjects')->with(['synchronizationId' => 'sync-1'])->willReturn($contracts);
	}//end registerHolds()

	/**
	 * An employee added while the target was unreachable is created by the nightly run.
	 *
	 * @return void
	 */
	public function testAnEmployeeMissingAtTheTargetIsCreated(): void {
		$this->targetLists(items: [['id' => 't-1', 'externKey' => 'ann']]);
		$this->registerHolds(
			employees: [['id' => 'e-ann', 'nextcloudUserId' => 'ann'], ['id' => 'e-new', 'nextcloudUserId' => 'new']],
			contracts: [['id' => 'c-ann', 'originId' => 'e-ann', 'targetId' => 't-1']]
		);
		$this->contracts->expects($this->once())->method('persist')
			->with($this->callback(fn (array $contract): bool => $contract['originId'] === 'e-new' && $contract['targetId'] === 'new-e-new'), true);

		$report = $this->service()->reconcile(synchronization: $this->synchronization());

		$this->assertTrue($report['complete']);
		$this->assertSame(['e-new'], $report['created']);
		$this->assertSame([], $report['adopted']);
		$this->assertSame([], $report['unmatched']);
		$this->assertCount(1, $this->pushes);
		$this->assertNull($this->pushes[0][0]['targetId']);
		$this->assertSame('new', $this->pushes[0][1]['nextcloudUserId']);
		$this->assertCount(1, $this->persistedLogs);
		$this->assertStringContainsString('1 created', (string)$this->persistedLogs[0]->getMessage());
		$this->assertSame(['e-new'], $this->persistedLogs[0]->getResult()['reconciliation']['created']);
	}//end testAnEmployeeMissingAtTheTargetIsCreated()

	/**
	 * A read that stopped halfway writes nothing and says so in the run log.
	 *
	 * @return void
	 */
	public function testAnIncompleteReadChangesNothing(): void {
		$this->targetLists(items: [['id' => 't-1', 'externKey' => 'ann']], complete: false);
		$this->registerHolds(
			employees: [['id' => 'e-ann'], ['id' => 'e-bob']],
			contracts: [['id' => 'c-bob', 'originId' => 'e-bob', 'targetId' => 't-2']]
		);
		$this->engine->expects($this->never())->method('synchronizeContract');
		$this->contracts->expects($this->never())->method('createFromArray');
		$this->contracts->expects($this->never())->method('persist');

		$report = $this->service()->reconcile(synchronization: $this->synchronization());

		$this->assertFalse($report['complete']);
		$this->assertSame([], $report['created']);
		$this->assertSame([], $report['unmatched']);
		$this->assertCount(1, $this->persistedLogs);
		$this->assertStringContainsString('incomplete', (string)$this->persistedLogs[0]->getMessage());
	}//end testAnIncompleteReadChangesNothing()

	/**
	 * A target read that throws counts as incomplete.
	 *
	 * @return void
	 */
	public function testAFailedReadIsIncomplete(): void {
		$this->engine->method('getAllObjectsFromApi')->willThrowException(new RuntimeException('gateway down'));
		$this->engine->expects($this->never())->method('synchronizeContract');

		$report = $this->service()->reconcile(synchronization: $this->synchronization());

		$this->assertFalse($report['complete']);
		$this->assertStringContainsString('incomplete', $report['message']);
	}//end testAFailedReadIsIncomplete()

	/**
	 * A target item matching an employee without a contract is adopted, not duplicated;
	 * one matching nobody is listed in the run log and left alone.
	 *
	 * @return void
	 */
	public function testAMatchingTargetItemIsAdoptedAndTheRestListed(): void {
		$this->targetLists(items: [['id' => 't-bob', 'externKey' => 'bob'], ['id' => 't-zed', 'externKey' => 'zed']]);
		$this->registerHolds(employees: [['id' => 'e-bob', 'nextcloudUserId' => 'bob']]);

		$report = $this->service()->reconcile(synchronization: $this->synchronization());

		$this->assertSame([], $report['created']);
		$this->assertSame(['e-bob' => 't-bob'], $report['adopted']);
		$this->assertSame(['t-zed'], $report['unmatched']);
		$this->assertCount(1, $this->pushes);
		$this->assertSame('t-bob', $this->pushes[0][0]['targetId']);
		$this->assertSame(['t-zed'], $this->persistedLogs[0]->getResult()['reconciliation']['unmatched']);
	}//end testAMatchingTargetItemIsAdoptedAndTheRestListed()

	/**
	 * A target item is adopted once: a second employee with the same key is created instead.
	 *
	 * @return void
	 */
	public function testATargetItemIsAdoptedOnlyOnce(): void {
		$this->targetLists(items: [['id' => 't-bob', 'externKey' => 'bob']]);
		$this->registerHolds(employees: [['id' => 'e-1', 'nextcloudUserId' => 'bob'], ['id' => 'e-2', 'nextcloudUserId' => 'bob']]);

		$report = $this->service()->reconcile(synchronization: $this->synchronization());

		$this->assertSame(['e-1' => 't-bob'], $report['adopted']);
		$this->assertSame(['e-2'], $report['created']);
	}//end testATargetItemIsAdoptedOnlyOnce()

	/**
	 * A contract whose target item is gone is pushed again as new, on its own contract.
	 *
	 * @return void
	 */
	public function testAContractWhoseTargetIsGoneIsPushedAgain(): void {
		$this->targetLists(items: []);
		$this->registerHolds(
			employees: [['id' => 'e-ann']],
			contracts: [['id' => 'c-ann', 'originId' => 'e-ann', 'targetId' => 't-gone']]
		);
		$this->contracts->expects($this->never())->method('createFromArray');

		$report = $this->service()->reconcile(synchronization: $this->synchronization());

		$this->assertSame(['e-ann'], $report['created']);
		$this->assertSame('c-ann', $this->pushes[0][0]['id']);
		$this->assertNull($this->pushes[0][0]['targetId']);
	}//end testAContractWhoseTargetIsGoneIsPushedAgain()

	/**
	 * A target item nobody claims is never deleted or deactivated: no push names it.
	 *
	 * @return void
	 */
	public function testNothingIsPushedForInSyncObjects(): void {
		$this->targetLists(items: [['id' => 't-1'], ['id' => 't-2']]);
		$this->registerHolds(employees: [['id' => 'e-1']], contracts: [['id' => 'c-1', 'originId' => 'e-1', 'targetId' => 't-1']]);
		$this->engine->expects($this->never())->method('synchronizeContract');

		$report = $this->service()->reconcile(synchronization: $this->synchronization());

		$this->assertSame(['t-2'], $report['unmatched']);
		$this->assertStringContainsString('1 target item(s) without a match', $report['message']);
	}//end testNothingIsPushedForInSyncObjects()

	/**
	 * An object that fails the synchronization's conditions is not pushed: unapproved leave never leaves.
	 *
	 * @return void
	 */
	public function testAnObjectFailingTheConditionsIsNotPushed(): void {
		$this->targetLists(items: []);
		$this->registerHolds(employees: [['id' => 'l-1', 'status' => 'approved'], ['id' => 'l-2', 'status' => 'submitted']]);
		$synchronization = $this->synchronization();
		$synchronization['conditions'] = [['==' => [['var' => 'status'], 'approved']]];

		$report = $this->service()->reconcile(synchronization: $synchronization);

		$this->assertSame(['l-1'], $report['created']);
		$this->assertCount(1, $this->pushes);
	}//end testAnObjectFailingTheConditionsIsNotPushed()

	/**
	 * A push that throws or answers an exception is reported as failed and the run goes on.
	 *
	 * @return void
	 */
	public function testAFailedPushIsReportedAndTheRunGoesOn(): void {
		$engine = $this->createMock(SynchronizationServiceInterface::class);
		$engine->method('getAllObjectsFromApi')->willReturnCallback(
			function (array $synchronization, ?bool $isTest = false, ?array $data = null, ?array &$fetchInfo = null): array {
				$fetchInfo = ['complete' => true];
				return [];
			}
		);
		$calls = 0;
		$engine->method('synchronizeContract')->willReturnCallback(
			function (array $synchronizationContract) use (&$calls): array|\Exception {
				$calls++;
				if ($synchronizationContract['originId'] === 'e-1') {
					throw new RuntimeException('refused');
				}

				if ($synchronizationContract['originId'] === 'e-2') {
					return new \Exception('mapping missing');
				}

				return ['contract' => $synchronizationContract + ['targetId' => 'x']];
			}
		);
		$this->engine = $engine;
		$this->registerHolds(employees: [['id' => 'e-1'], ['id' => 'e-2'], ['id' => 'e-3']]);

		$report = $this->service()->reconcile(synchronization: $this->synchronization());

		$this->assertSame(3, $calls);
		$this->assertSame(['e-1', 'e-2'], $report['failed']);
		$this->assertSame(['e-3'], $report['created']);
	}//end testAFailedPushIsReportedAndTheRunGoesOn()

	/**
	 * Register objects as OpenRegister entities are read by their uuid and serialized body.
	 *
	 * @return void
	 */
	public function testEntitiesAreReadByUuid(): void {
		$this->targetLists(items: [['id' => 't-bob', 'externKey' => 'bob']]);
		$entity = new class {
			/**
			 * The uuid.
			 *
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return 'e-bob';
			}

			/**
			 * The body.
			 *
			 * @return array The body.
			 */
			public function jsonSerialize(): array {
				return ['nextcloudUserId' => 'bob'];
			}
		};
		$this->registerHolds(employees: [$entity]);

		$report = $this->service()->reconcile(synchronization: $this->synchronization());

		$this->assertSame(['e-bob' => 't-bob'], $report['adopted']);
		$this->assertSame('e-bob', $this->pushes[0][1]['id']);
	}//end testEntitiesAreReadByUuid()

	/**
	 * Only a register-to-api synchronization with a reconcile endpoint takes part.
	 *
	 * @return void
	 */
	public function testOnlyOptedInOutboundSynchronizationsAreReconcilable(): void {
		$service = $this->service();
		$synchronization = $this->synchronization();

		$this->assertTrue($service->isReconcilable(synchronization: $synchronization));

		$noReconcile = $synchronization;
		unset($noReconcile['targetConfig']['reconcile']);
		$this->assertFalse($service->isReconcilable(synchronization: $noReconcile));

		$inbound = array_merge($synchronization, ['sourceType' => 'api']);
		$this->assertFalse($service->isReconcilable(synchronization: $inbound));

		$toRegister = array_merge($synchronization, ['targetType' => 'register/schema']);
		$this->assertFalse($service->isReconcilable(synchronization: $toRegister));

		$badSource = array_merge($synchronization, ['sourceId' => 'Employee']);
		$this->assertFalse($service->isReconcilable(synchronization: $badSource));
	}//end testOnlyOptedInOutboundSynchronizationsAreReconcilable()

	/**
	 * findReconcilable returns only the opted-in synchronizations, with their id.
	 *
	 * @return void
	 */
	public function testFindReconcilableFiltersTheStore(): void {
		$entity = new class {
			/**
			 * The uuid.
			 *
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return 'sync-2';
			}

			/**
			 * The body.
			 *
			 * @return array The body.
			 */
			public function jsonSerialize(): array {
				return [
					'sourceType' => 'register/schema',
					'sourceId' => 'humaniq/Employee',
					'targetType' => 'api',
					'targetConfig' => ['reconcile' => ['endpoint' => '/x']],
				];
			}
		};
		$plain = $this->synchronization();
		unset($plain['targetConfig']['reconcile']);
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'synchronization', 'targetType' => 'api']], false, false)
			->willReturn(['results' => [$entity, $plain]]);

		$found = $this->service()->findReconcilable();

		$this->assertCount(1, $found);
		$this->assertSame('sync-2', $found[0]['id']);
	}//end testFindReconcilableFiltersTheStore()
}//end class
