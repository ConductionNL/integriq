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

use Exception;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\Synchronization\OutboundReconciliationService;
use OCA\Integriq\Service\Synchronization\ReconciliationMatcher;
use OCA\Integriq\Service\Synchronization\ReconciliationRun;
use OCA\Integriq\Service\SynchronizationContractServiceInterface;
use OCA\Integriq\Service\SynchronizationLogServiceInterface;
use OCA\Integriq\Service\SynchronizationRunLog;
use OCA\Integriq\Service\SynchronizationServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Drives OutboundReconciliationService over a mocked engine, contract store and register.
 */
#[CoversClass(OutboundReconciliationService::class)]
#[CoversClass(ReconciliationMatcher::class)]
#[CoversClass(ReconciliationRun::class)]
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
		$this->engine = $this->createMock(originalClassName: SynchronizationServiceInterface::class);
		$this->contracts = $this->createMock(originalClassName: SynchronizationContractServiceInterface::class);
		$this->logs = $this->createMock(originalClassName: SynchronizationLogServiceInterface::class);
		$this->objects = $this->createMock(originalClassName: ObjectService::class);

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
			engine: $this->engine,
			contracts: $this->contracts,
			logs: $this->logs,
			objects: $this->objects,
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
				$this->assertSame(expected: 'target-source', actual: $synchronization['sourceId']);
				$this->assertSame(expected: 'api', actual: $synchronization['sourceType']);
				$this->assertSame(expected: '/teachers', actual: $synchronization['sourceConfig']['endpoint']);
				$this->assertArrayNotHasKey(key: 'match', array: $synchronization['sourceConfig']);
				$fetchInfo = ['complete' => $complete, 'pagesFetched' => 1, 'failureReason' => null];
				if ($complete === false) {
					$fetchInfo['failureReason'] = 'http_error';
				}

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
				$this->assertSame(expected: ['register' => 'humaniq', 'schema' => 'Employee'], actual: $config['filters']);
				return ['results' => $employees];
			}
		);
		$this->contracts->method('findAllObjects')->with(['synchronizationId' => 'sync-1'])->willReturn($contracts);
	}//end registerHolds()

	/**
	 * Run the service over the synchronization.
	 *
	 * @param array|null $synchronization The synchronization; the default one when null.
	 *
	 * @return array The report.
	 */
	private function reconcile(?array $synchronization = null): array {
		return $this->service()->reconcile(synchronization: ($synchronization ?? $this->synchronization()));
	}//end reconcile()

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
			->with($this->callback(callback: fn (array $contract): bool => $contract['originId'] === 'e-new' && $contract['targetId'] === 'new-e-new'), true);

		$report = $this->reconcile();

		$this->assertTrue(condition: $report['complete']);
		$this->assertSame(expected: ['e-new'], actual: $report['created']);
		$this->assertSame(expected: [], actual: $report['adopted']);
		$this->assertSame(expected: [], actual: $report['unmatched']);
		$this->assertCount(expectedCount: 1, haystack: $this->pushes);
		$this->assertNull(actual: $this->pushes[0][0]['targetId']);
		$this->assertSame(expected: 'contract-e-new', actual: $this->pushes[0][0]['id']);
		$this->assertSame(expected: 'new', actual: $this->pushes[0][1]['nextcloudUserId']);
		$this->assertCount(expectedCount: 1, haystack: $this->persistedLogs);
		$this->assertStringContainsString(needle: '1 created', haystack: (string)$this->persistedLogs[0]->getMessage());
		$this->assertSame(expected: ['e-new'], actual: $this->persistedLogs[0]->getResult()['reconciliation']['created']);
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

		$report = $this->reconcile();

		$this->assertFalse(condition: $report['complete']);
		$this->assertSame(expected: [], actual: $report['created']);
		$this->assertSame(expected: [], actual: $report['unmatched']);
		$this->assertCount(expectedCount: 1, haystack: $this->persistedLogs);
		$this->assertStringContainsString(needle: 'incomplete (http_error)', haystack: (string)$this->persistedLogs[0]->getMessage());
	}//end testAnIncompleteReadChangesNothing()

	/**
	 * A target read that throws counts as incomplete.
	 *
	 * @return void
	 */
	public function testAFailedReadIsIncomplete(): void {
		$this->engine->method('getAllObjectsFromApi')->willThrowException(new RuntimeException(message: 'gateway down'));
		$this->engine->expects($this->never())->method('synchronizeContract');

		$report = $this->reconcile();

		$this->assertFalse(condition: $report['complete']);
		$this->assertStringContainsString(needle: 'incomplete (gateway down)', haystack: $report['message']);
	}//end testAFailedReadIsIncomplete()

	/**
	 * A target item matching an employee without a contract is adopted, not duplicated;
	 * one matching nobody is listed in the run log and left alone.
	 *
	 * @return void
	 */
	public function testAMatchingTargetItemIsAdoptedAndTheRestListed(): void {
		$this->targetLists(items: [['id' => 't-bob', 'externKey' => 'bob'], ['id' => 't-zed', 'externKey' => 'zed'], ['name' => 'no id']]);
		$this->registerHolds(employees: [['id' => 'e-bob', 'nextcloudUserId' => 'bob']]);

		$report = $this->reconcile();

		$this->assertSame(expected: [], actual: $report['created']);
		$this->assertSame(expected: ['e-bob' => 't-bob'], actual: $report['adopted']);
		$this->assertSame(expected: ['t-zed'], actual: $report['unmatched']);
		$this->assertCount(expectedCount: 1, haystack: $this->pushes);
		$this->assertSame(expected: 't-bob', actual: $this->pushes[0][0]['targetId']);
		$this->assertSame(expected: ['t-zed'], actual: $this->persistedLogs[0]->getResult()['reconciliation']['unmatched']);
	}//end testAMatchingTargetItemIsAdoptedAndTheRestListed()

	/**
	 * A target item is adopted once: a second employee with the same key is created instead.
	 *
	 * @return void
	 */
	public function testATargetItemIsAdoptedOnlyOnce(): void {
		$this->targetLists(items: [['id' => 't-bob', 'externKey' => 'bob']]);
		$this->registerHolds(employees: [['id' => 'e-1', 'nextcloudUserId' => 'bob'], ['id' => 'e-2', 'nextcloudUserId' => 'bob'], ['id' => 'e-3']]);

		$report = $this->reconcile();

		$this->assertSame(expected: ['e-1' => 't-bob'], actual: $report['adopted']);
		$this->assertSame(expected: ['e-2', 'e-3'], actual: $report['created']);
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

		$report = $this->reconcile();

		$this->assertSame(expected: ['e-ann'], actual: $report['created']);
		$this->assertSame(expected: 'c-ann', actual: $this->pushes[0][0]['id']);
		$this->assertNull(actual: $this->pushes[0][0]['targetId']);
	}//end testAContractWhoseTargetIsGoneIsPushedAgain()

	/**
	 * In-sync objects are not pushed, and a target item nobody claims is only listed.
	 *
	 * @return void
	 */
	public function testNothingIsPushedForInSyncObjects(): void {
		$this->targetLists(items: [['id' => 't-1'], ['id' => 't-2']]);
		$this->registerHolds(employees: [['id' => 'e-1']], contracts: [['id' => 'c-1', 'originId' => 'e-1', 'targetId' => 't-1'], ['id' => 'c-x']]);
		$this->engine->expects($this->never())->method('synchronizeContract');

		$report = $this->reconcile();

		$this->assertSame(expected: ['t-2'], actual: $report['unmatched']);
		$this->assertStringContainsString(needle: '1 target item(s) without a match', haystack: $report['message']);
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

		$report = $this->reconcile(synchronization: $synchronization);

		$this->assertSame(expected: ['l-1'], actual: $report['created']);
		$this->assertCount(expectedCount: 1, haystack: $this->pushes);
	}//end testAnObjectFailingTheConditionsIsNotPushed()

	/**
	 * A push that throws or answers no contract is reported as failed and the run goes on.
	 *
	 * @return void
	 */
	public function testAFailedPushIsReportedAndTheRunGoesOn(): void {
		$engine = $this->createMock(originalClassName: SynchronizationServiceInterface::class);
		$engine->method('getAllObjectsFromApi')->willReturnCallback(
			function (array $synchronization, ?bool $isTest = false, ?array $data = null, ?array &$fetchInfo = null): array {
				$fetchInfo = ['complete' => true];
				return [];
			}
		);
		$engine->method('synchronizeContract')->willReturnCallback(
			function (array $synchronizationContract): array|Exception {
				if ($synchronizationContract['originId'] === 'e-1') {
					throw new RuntimeException(message: 'refused');
				}

				if ($synchronizationContract['originId'] === 'e-2') {
					return new Exception(message: 'mapping missing');
				}

				return ['contract' => $synchronizationContract + ['targetId' => 'x']];
			}
		);
		$this->engine = $engine;
		$this->registerHolds(employees: [['id' => 'e-1'], ['id' => 'e-2'], ['id' => 'e-3']]);

		$report = $this->reconcile();

		$this->assertSame(expected: ['e-1' => 'refused', 'e-2' => 'the engine answered no contract'], actual: $report['failed']);
		$this->assertSame(expected: ['e-3'], actual: $report['created']);
	}//end testAFailedPushIsReportedAndTheRunGoesOn()

	/**
	 * A run log that cannot be stored is named in the report; the run's work stands.
	 *
	 * @return void
	 */
	public function testARunLogThatCannotBeStoredIsReported(): void {
		$logs = $this->createMock(originalClassName: SynchronizationLogServiceInterface::class);
		$logs->method('createFromArray')->willReturn(new SynchronizationRunLog());
		$logs->method('persist')->willThrowException(new RuntimeException(message: 'append-only'));
		$this->logs = $logs;
		$this->targetLists(items: []);
		$this->registerHolds(employees: [['id' => 'e-1']]);

		$report = $this->reconcile();

		$this->assertSame(expected: ['e-1'], actual: $report['created']);
		$this->assertStringContainsString(needle: 'The run log was not stored: append-only', haystack: $report['message']);
	}//end testARunLogThatCannotBeStoredIsReported()

	/**
	 * Register objects as OpenRegister entities are read by their uuid and serialized body.
	 *
	 * @return void
	 */
	public function testEntitiesAreReadByUuid(): void {
		$this->targetLists(items: [['id' => 't-bob', 'externKey' => 'bob']]);
		$this->registerHolds(employees: [$this->entity(uuid: 'e-bob', body: ['nextcloudUserId' => 'bob'])]);

		$report = $this->reconcile();

		$this->assertSame(expected: ['e-bob' => 't-bob'], actual: $report['adopted']);
		$this->assertSame(expected: 'e-bob', actual: $this->pushes[0][1]['id']);
	}//end testEntitiesAreReadByUuid()

	/**
	 * Only a register-to-api synchronization with a reconcile endpoint takes part.
	 *
	 * @return void
	 */
	public function testOnlyOptedInOutboundSynchronizationsAreReconcilable(): void {
		$service = $this->service();
		$synchronization = $this->synchronization();

		$this->assertTrue(condition: $service->isReconcilable(synchronization: $synchronization));

		$noReconcile = $synchronization;
		unset($noReconcile['targetConfig']['reconcile']);
		$this->assertFalse(condition: $service->isReconcilable(synchronization: $noReconcile));

		$this->assertFalse(condition: $service->isReconcilable(synchronization: array_merge($synchronization, ['sourceType' => 'api'])));
		$this->assertFalse(condition: $service->isReconcilable(synchronization: array_merge($synchronization, ['targetType' => 'register/schema'])));
		$this->assertFalse(condition: $service->isReconcilable(synchronization: array_merge($synchronization, ['sourceId' => 'Employee'])));
	}//end testOnlyOptedInOutboundSynchronizationsAreReconcilable()

	/**
	 * findReconcilable returns only the opted-in synchronizations, with their id.
	 *
	 * @return void
	 */
	public function testFindReconcilableFiltersTheStore(): void {
		$entity = $this->entity(
			uuid: 'sync-2',
			body: [
				'sourceType' => 'register/schema',
				'sourceId' => 'humaniq/Employee',
				'targetType' => 'api',
				'targetConfig' => ['reconcile' => ['endpoint' => '/x']],
			]
		);
		$plain = $this->synchronization();
		unset($plain['targetConfig']['reconcile']);
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'synchronization', 'targetType' => 'api']], false, false)
			->willReturn(['results' => [$entity, $plain]]);

		$found = $this->service()->findReconcilable();

		$this->assertCount(expectedCount: 1, haystack: $found);
		$this->assertSame(expected: 'sync-2', actual: $found[0]['id']);
	}//end testFindReconcilableFiltersTheStore()

	/**
	 * An object shaped like an OpenRegister entity.
	 *
	 * @param string $uuid The uuid.
	 * @param array  $body The serialized body.
	 *
	 * @return object The entity.
	 */
	private function entity(string $uuid, array $body): object {
		return new class ($uuid, $body) {
			/**
			 * Constructor.
			 *
			 * @param string $uuid The uuid.
			 * @param array  $body The body.
			 */
			public function __construct(private readonly string $uuid, private readonly array $body) {
			}//end __construct()

			/**
			 * The uuid.
			 *
			 * @return string The uuid.
			 */
			public function getUuid(): string {
				return $this->uuid;
			}//end getUuid()

			/**
			 * The body.
			 *
			 * @return array The body.
			 */
			public function jsonSerialize(): array {
				return $this->body;
			}//end jsonSerialize()
		};
	}//end entity()
}//end class
