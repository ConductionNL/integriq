<?php

/**
 * Characterization tests for SynchronizationService::processSynchronizationObject().
 *
 * They pin how one source item is routed: the contract lookup, the run log handed
 * to synchronizeContract(), the result tally and the item's trace step.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationRunLog;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The routing of one source item.
 */
class SynchronizationServiceProcessObjectTest extends TestCase {

	/**
	 * @var MockObject The OpenRegister object service.
	 */
	private MockObject $orObjectService;

	/**
	 * @var array<int, array> The filters of every contract lookup.
	 */
	private array $contractFilters = [];

	/**
	 * @var array<int, array> The arguments of every synchronizeContract() call.
	 */
	private array $contractCalls = [];

	/**
	 * Set up the OpenRegister mock that records contract lookups.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->orObjectService->method('findAll')->willReturnCallback(
			function (...$args) {
				$this->contractFilters[] = ($args[0]['filters'] ?? $args[0]);

				return ['results' => [], 'total' => 0];
			}
		);
	}//end setUp()

	/**
	 * Build the service with synchronizeContract() replaced.
	 *
	 * @param array $contractResult What the stubbed synchronizeContract() returns.
	 *
	 * @return SynchronizationService&MockObject
	 */
	private function service(array $contractResult): MockObject {
		$callService = $this->createMock(CallService::class);
		$callService->method('applyConfigDot')->willReturnArgument(0);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$service = $this->getMockBuilder(SynchronizationService::class)
			->setConstructorArgs(
				[
					$callService,
					$this->createMock(MappingService::class),
					$this->createMock(ContainerInterface::class),
					$this->orObjectService,
					$this->createMock(ObjectService::class),
					$this->createMock(LoggerInterface::class),
					$this->createMock(SynchronizationLogService::class),
					$appConfig,
					$this->createMock(SynchronizationApprovalGate::class),
				]
			)
			->onlyMethods(['synchronizeContract'])
			->getMock();

		$service->method('synchronizeContract')->willReturnCallback(
			function (...$args) use ($contractResult) {
				$this->contractCalls[] = $args;

				return $contractResult;
			}
		);

		return $service;
	}//end service()

	/**
	 * Call the private processSynchronizationObject().
	 *
	 * @param SynchronizationService     $service         The service.
	 * @param array                      $synchronization The synchronization.
	 * @param array                      $object          The source item.
	 * @param ExecutionTraceContext|null $trace           The trace.
	 * @param array|null                 $contractIndex   The page's contract index.
	 *
	 * @return array The item result.
	 */
	private function process(
		SynchronizationService $service,
		array $synchronization,
		array $object,
		?ExecutionTraceContext $trace = null,
		?array $contractIndex = null
	): array {
		$result = ['objects' => ['found' => 0, 'skipped' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'invalid' => 0], 'contracts' => [], 'logs' => []];
		$flowToken = new FlowToken();
		$method = new \ReflectionMethod(SynchronizationService::class, 'processSynchronizationObject');
		$args = [$synchronization, $object, $result, false, false, $this->runLog(), &$flowToken, null, $trace, $contractIndex];

		return $method->invokeArgs($service, $args);
	}//end process()

	/**
	 * A run log mock.
	 *
	 * @return SynchronizationRunLog
	 */
	private function runLog(): SynchronizationRunLog {
		return $this->createMock(SynchronizationRunLog::class);
	}//end runLog()

	/**
	 * A new contract is created without the run log and an `update` counts as created.
	 *
	 * @return void
	 */
	public function testANewContractGetsNoRunLogAndCountsAsCreated(): void {
		$service = $this->service(['contract' => ['uuid' => 'c-1', 'targetId' => 't-1'], 'log' => ['uuid' => 'l-1'], 'resultAction' => 'update']);

		$out = $this->process($service, ['id' => 's-1'], ['id' => 'o-1']);

		$this->assertNull($this->contractCalls[0][6]);
		$this->assertSame(['synchronizationId' => 's-1', 'originId' => 'o-1'], $this->contractCalls[0][0]);
		$this->assertSame(1, $out['result']['objects']['created']);
		$this->assertSame(['c-1'], $out['result']['contracts']);
		$this->assertSame(['l-1'], $out['result']['logs']);
		$this->assertSame('t-1', $out['targetId']);
		$this->assertSame([['register' => 'integriq', 'schema' => 'synchronization_contract', 'synchronizationId' => 's-1', 'originId' => 'o-1']], $this->contractFilters);
	}//end testANewContractGetsNoRunLogAndCountsAsCreated()

	/**
	 * An existing contract from the index gets the run log and an `update` counts as updated.
	 *
	 * @return void
	 */
	public function testAnIndexedContractGetsTheRunLogAndCountsAsUpdated(): void {
		$service = $this->service(['contract' => ['uuid' => 'c-1'], 'log' => [], 'resultAction' => 'update']);

		$out = $this->process($service, ['id' => 's-1'], ['id' => 'o-1'], null, ['o-1' => [['uuid' => 'c-1', 'originId' => 'o-1']]]);

		$this->assertInstanceOf(SynchronizationRunLog::class, $this->contractCalls[0][6]);
		$this->assertSame(['uuid' => 'c-1', 'originId' => 'o-1'], $this->contractCalls[0][0]);
		$this->assertSame(1, $out['result']['objects']['updated']);
		$this->assertSame([null], $out['result']['logs']);
		$this->assertSame([], $this->contractFilters);
	}//end testAnIndexedContractGetsTheRunLogAndCountsAsUpdated()

	/**
	 * `findContractByOriginIdOnly` looks the contract up by origin id alone.
	 *
	 * @return void
	 */
	public function testFindByOriginIdOnlyDropsTheSynchronizationFilter(): void {
		$service = $this->service(['contract' => [], 'resultAction' => 'skip']);

		$this->process($service, ['id' => 's-1', 'sourceConfig' => ['findContractByOriginIdOnly' => 'true']], ['id' => 'o-1']);

		$this->assertSame([['register' => 'integriq', 'schema' => 'synchronization_contract', 'originId' => 'o-1']], $this->contractFilters);
	}//end testFindByOriginIdOnlyDropsTheSynchronizationFilter()

	/**
	 * An unknown result action counts as invalid and its trace step is `skipped` when it is null.
	 *
	 * @return void
	 */
	public function testANullActionIsInvalidAndTracedAsSkipped(): void {
		$service = $this->service(['contract' => ['targetId' => 't-1']]);
		$trace = new ExecutionTraceContext('test');

		$out = $this->process($service, ['id' => 's-1', 'name' => 'Sync'], ['id' => 'o-1', 'password' => 'hunter2'], $trace);

		$this->assertSame(1, $out['result']['objects']['invalid']);
		$steps = $trace->getSteps();
		$this->assertCount(1, $steps);
		$this->assertSame('skipped', $steps[0]['status']);
		$this->assertSame('Sync', $steps[0]['name']);
		$this->assertNotSame('hunter2', $steps[0]['input']['password']);
		$this->assertSame(['targetId' => 't-1'], $steps[0]['output']);
	}//end testANullActionIsInvalidAndTracedAsSkipped()

	/**
	 * A known action is traced as `success`.
	 *
	 * @return void
	 */
	public function testAKnownActionIsTracedAsSuccess(): void {
		$service = $this->service(['contract' => [], 'resultAction' => 'skip']);
		$trace = new ExecutionTraceContext('test');

		$out = $this->process($service, ['id' => 's-1'], ['id' => 'o-1'], $trace);

		$this->assertSame(1, $out['result']['objects']['skipped']);
		$this->assertSame('success', $trace->getSteps()[0]['status']);
		$this->assertSame('synchronization', $trace->getSteps()[0]['name']);
	}//end testAKnownActionIsTracedAsSuccess()

	/**
	 * An item that fails the conditions is skipped, traced with its redacted input, and not synchronized.
	 *
	 * @return void
	 */
	public function testAnItemFailingTheConditionsIsSkipped(): void {
		$service = $this->service(['contract' => [], 'resultAction' => 'update']);
		$trace = new ExecutionTraceContext('test');

		$out = $this->process($service, ['id' => 's-1', 'conditions' => ['==' => [1, 2]]], ['id' => 'o-1', 'password' => 'hunter2'], $trace);

		$this->assertSame(1, $out['result']['objects']['skipped']);
		$this->assertSame([], $this->contractCalls);
		$this->assertNull($out['targetId']);
		$this->assertSame('skipped', $trace->getSteps()[0]['status']);
		$this->assertNotSame('hunter2', $trace->getSteps()[0]['input']['password']);
	}//end testAnItemFailingTheConditionsIsSkipped()
}//end class
