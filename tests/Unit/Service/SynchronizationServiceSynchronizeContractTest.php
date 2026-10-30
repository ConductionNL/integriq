<?php

/**
 * Characterization tests for SynchronizationService::synchronizeContract().
 *
 * They pin what one contract run does on each of its paths: the per-contract log,
 * the skip of an unchanged object, a test run, the target write, the contract
 * persists before and after the `after` rules, and the write buffer.
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

use Exception;
use OCA\Integriq\Service\SynchronizationRunLog;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationContractLogService;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The paths of synchronizeContract().
 */
class SynchronizationServiceSynchronizeContractTest extends TestCase {

	/**
	 * @var MockObject The OpenRegister object service.
	 */
	private MockObject $orObjectService;

	/**
	 * @var MockObject The contract log service.
	 */
	private MockObject $contractLogService;

	/**
	 * @var array<int, array> Every array passed to the contract log service, in order, as [method, array].
	 */
	private array $logCalls = [];

	/**
	 * @var array<int, array> Every object passed to saveObject, in order.
	 */
	private array $saved = [];

	/**
	 * @var array<int, string> Every id looked up with schema `rule`.
	 */
	private array $ruleLookups = [];

	/**
	 * @var array<int, string> Every info message logged.
	 */
	private array $infoLogs = [];

	/**
	 * @var array The caller's object after the last run.
	 */
	private array $lastObject = [];

	/**
	 * Set up the mocks that record what a contract run writes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->orObjectService->method('saveObject')->willReturnCallback(
			function (...$args) {
				$object = ($args['object'] ?? $args[0]);
				$this->saved[] = $object;

				return ObjectServiceMockBuilder::objectEntity($this, $object, 'saved-' . count($this->saved));
			}
		);
		$this->orObjectService->method('find')->willReturnCallback(
			function (...$args) {
				$id = ($args['id'] ?? $args[0]);
				$schema = ($args['schema'] ?? $args[2] ?? null);
				if ($schema === 'rule') {
					$this->ruleLookups[] = $id;
					$timing = 'never';
					$type = 'mapping';
					$conditions = [];
					if ($id === 'error-before') {
						$timing = 'before';
						$type = 'error';
					}

					if ($id === 'after-sees-original') {
						$timing = 'after';
						$type = 'error';
						$conditions = ['==' => [['var' => '_objectBeforeMapping.a'], 2]];
					}

					return ObjectServiceMockBuilder::objectEntity(
						$this,
						['name' => $id, 'type' => $type, 'timing' => $timing, 'conditions' => $conditions, 'configuration' => ['error' => ['code' => 400, 'name' => 'Rule failed', 'message' => 'A before rule failed']]],
						$id
					);
				}

				if ($schema === 'mapping' && $id === 'm-1') {
					return ObjectServiceMockBuilder::objectEntity($this, ['name' => 'm-1'], 'm-1');
				}

				throw new DoesNotExistException('no ' . $schema . ' ' . $id);
			}
		);

		$this->contractLogService = $this->createMock(SynchronizationContractLogService::class);
		$this->contractLogService->method('createFromArray')->willReturnCallback(
			function (array $object) {
				$this->logCalls[] = ['create', $object];

				return array_merge(['id' => 'contract-log-1'], $object);
			}
		);
		$this->contractLogService->method('update')->willReturnCallback(
			function (array $log) {
				$this->logCalls[] = ['update', $log];

				return array_merge($log, ['updated' => true]);
			}
		);
	}//end setUp()

	/**
	 * Build the service with updateTarget() replaced, so the target write is observable.
	 *
	 * @param array $targetWrite What the stubbed updateTarget() merges into the contract.
	 *
	 * @return SynchronizationService&MockObject
	 */
	private function service(array $targetWrite = ['targetId' => 'target-1', 'targetLastAction' => 'create']): MockObject {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => ($id === SynchronizationContractLogService::class) ? $this->contractLogService : null
		);

		$callService = $this->createMock(CallService::class);
		$callService->method('applyConfigDot')->willReturnArgument(0);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$service = $this->getMockBuilder(SynchronizationService::class)
			->setConstructorArgs(
				[
					$callService,
					$this->mappingService(),
					$container,
					$this->orObjectService,
					$this->createMock(ObjectService::class),
					$this->logger(),
					$this->createMock(SynchronizationLogService::class),
					$appConfig,
					$this->createMock(SynchronizationApprovalGate::class),
				]
			)
			->onlyMethods(['updateTarget'])
			->getMock();

		$service->method('updateTarget')->willReturnCallback(
			static fn (...$args) => array_merge(($args['synchronizationContract'] ?? $args[0]), $targetWrite)
		);

		return $service;
	}//end service()

	/**
	 * A run log with a fixed id.
	 *
	 * @return SynchronizationRunLog
	 */
	private function runLog(): SynchronizationRunLog {
		$log = $this->createMock(SynchronizationRunLog::class);
		$log->method('getId')->willReturn('run-77');

		return $log;
	}//end runLog()

	/**
	 * Run synchronizeContract() with the extra-data fetch switched off.
	 *
	 * @param SynchronizationService $service         The service.
	 * @param array                  $contract        The contract.
	 * @param array                  $synchronization The synchronization, without its source config.
	 * @param array                  $options         `object`, `isTest`, `force`, `sourceConfig`.
	 *
	 * @return array|Exception The result.
	 */
	private function runContract(SynchronizationService $service, array $contract, array $synchronization, array $options = []): array|Exception {
		$flowToken = new FlowToken();
		$object = ($options['object'] ?? ['b' => 1, 'a' => 2]);
		$synchronization['sourceConfig'] = array_merge(
			[SynchronizationService::EXTRA_DATA_BEFORE_CONDITIONS_LOCATION => true],
			($options['sourceConfig'] ?? [])
		);

		$result = $service->synchronizeContract(
			synchronizationContract: $contract,
			flowToken: $flowToken,
			synchronization: $synchronization,
			object: $object,
			isTest: ($options['isTest'] ?? false),
			force: ($options['force'] ?? false),
			log: $this->runLog()
		);
		$this->lastObject = $object;

		return $result;
	}//end runContract()

	/**
	 * A mapping service that maps every object to `['mapped' => <its keys>]`.
	 *
	 * @return MappingService
	 */
	private function mappingService(): MappingService {
		$mapping = $this->createMock(MappingService::class);
		$mapping->method('executeMapping')->willReturnCallback(
			static fn (...$args): array => ['mapped' => array_keys(($args['input'] ?? $args[1]))]
		);

		return $mapping;
	}//end mappingService()

	/**
	 * A logger that records its info messages.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (...$args): void {
				$this->infoLogs[] = (string)$args[0];
			}
		);

		return $logger;
	}//end logger()

	/**
	 * The origin hash synchronizeContract() computes for an object without hash mapping.
	 *
	 * @param array $object The object.
	 *
	 * @return string The hash.
	 */
	private function originHash(array $object): string {
		ksort($object);

		return md5(serialize($object));
	}//end originHash()

	/**
	 * Hours from now until an ISO 8601 expiry, rounded.
	 *
	 * @param string $expires The expiry.
	 *
	 * @return int
	 */
	private function hoursAhead(string $expires): int {
		return (int)round(((new \DateTime($expires))->getTimestamp() - time()) / 3600);
	}//end hoursAhead()

	/**
	 * A test run opens the contract log with its fields in order, closes it as `test`, and writes nothing.
	 *
	 * @return void
	 */
	public function testATestRunLogsAndWritesNothing(): void {
		$service = $this->service();
		$service->expects($this->never())->method('updateTarget');

		$result = $this->runContract($service, ['id' => 'c-1'], ['id' => 's-1', 'targetType' => 'register/schema', 'targetId' => '1/2'], ['isTest' => true]);

		$this->assertSame('skip', $result['resultAction']);
		$this->assertSame([], $this->saved);
		$this->assertSame('create', $this->logCalls[0][0]);
		$this->assertSame(
			['synchronizationId', 'synchronizationContractId', 'source', 'test', 'force', 'expires'],
			array_keys($this->logCalls[0][1])
		);
		$this->assertSame(['s-1', 'c-1', ['b' => 1, 'a' => 2], true, false], array_slice(array_values($this->logCalls[0][1]), 0, 5));
		$this->assertSame(720, $this->hoursAhead($this->logCalls[0][1]['expires']));

		$this->assertSame('update', $this->logCalls[1][0]);
		$this->assertSame('run-77', $this->logCalls[1][1]['synchronizationLogId']);
		$this->assertSame('test', $this->logCalls[1][1]['targetResult']);
		$this->assertSame(1, $this->hoursAhead($this->logCalls[1][1]['expires']));
		$this->assertSame(['b' => 1, 'a' => 2], $this->logCalls[1][1]['target']);
		$this->assertTrue($result['log']['updated']);

		$this->assertSame($this->originHash(['b' => 1, 'a' => 2]), $result['contract']['originHash']);
		$this->assertSame(md5(serialize(['b' => 1, 'a' => 2])), $result['contract']['targetHash']);
		foreach (['sourceLastChanged', 'sourceLastChecked', 'targetLastChanged', 'targetLastSynced', 'sourceLastSynced'] as $key) {
			$this->assertArrayHasKey($key, $result['contract']);
		}
	}//end testATestRunLogsAndWritesNothing()

	/**
	 * `logs: false` skips the contract log; a test run then returns a null log.
	 *
	 * @return void
	 */
	public function testLogsFalseSkipsTheContractLog(): void {
		$result = $this->runContract($this->service(), ['id' => 'c-1'], ['id' => 's-1'], ['isTest' => true, 'sourceConfig' => ['logs' => false]]);

		$this->assertSame([], $this->logCalls);
		$this->assertNull($result['log']);
	}//end testLogsFalseSkipsTheContractLog()

	/**
	 * A contract without an id gets no contract log, and an update run returns an empty log.
	 *
	 * @return void
	 */
	public function testAContractWithoutIdGetsNoLog(): void {
		$result = $this->runContract($this->service(), [], ['id' => 's-1']);

		$this->assertSame([], $this->logCalls);
		$this->assertSame([], $result['log']);
		$this->assertSame('update', $result['resultAction']);
	}//end testAContractWithoutIdGetsNoLog()

	/**
	 * An unchanged object with a current contract is skipped, and only its log is updated.
	 *
	 * @return void
	 */
	public function testAnUnchangedObjectIsSkipped(): void {
		$service = $this->service();
		$service->expects($this->never())->method('updateTarget');
		$contract = [
			'id' => 'c-1',
			'originHash' => $this->originHash(['b' => 1, 'a' => 2]),
			'sourceLastChecked' => '2999-01-01T00:00:00+00:00',
			'targetId' => 't-1',
			'targetHash' => 'h',
		];

		$result = $this->runContract($service, $contract, ['id' => 's-1', 'updated' => '2020-01-01T00:00:00+00:00']);

		$this->assertSame('skip', $result['resultAction']);
		$this->assertNotSame('2999-01-01T00:00:00+00:00', $result['contract']['sourceLastChecked']);
		$this->assertSame('h', $result['contract']['targetHash']);
		$this->assertSame(['create', 'update'], array_column($this->logCalls, 0));
		$this->assertArrayNotHasKey('targetResult', $this->logCalls[1][1]);
		$this->assertSame(1, $this->hoursAhead($this->logCalls[1][1]['expires']));
		$this->assertTrue($result['log']['updated']);
		$this->assertSame([], $this->saved);
	}//end testAnUnchangedObjectIsSkipped()

	/**
	 * `force` writes a contract that would otherwise be skipped.
	 *
	 * @return void
	 */
	public function testForceWritesACurrentContract(): void {
		$contract = [
			'id' => 'c-1',
			'originHash' => $this->originHash(['b' => 1, 'a' => 2]),
			'sourceLastChecked' => '2999-01-01T00:00:00+00:00',
			'targetId' => 't-1',
			'targetHash' => 'h',
		];

		$result = $this->runContract($this->service(), $contract, ['id' => 's-1'], ['force' => true]);

		$this->assertSame('update', $result['resultAction']);
	}//end testForceWritesACurrentContract()

	/**
	 * Each condition of the skip check, when it fails, makes the run write.
	 *
	 * @return void
	 */
	public function testEverySkipConditionIsChecked(): void {
		$current = [
			'id' => 'c-1',
			'originHash' => $this->originHash(['b' => 1, 'a' => 2]),
			'sourceLastChecked' => '2999-01-01T00:00:00+00:00',
			'targetId' => 't-1',
			'targetHash' => 'h',
		];
		$variants = [
			'hash' => ['originHash' => 'other'],
			'checked' => ['sourceLastChecked' => '2000-01-01T00:00:00+00:00'],
			'targetId' => ['targetId' => null],
			'targetHash' => ['targetHash' => null],
		];
		foreach ($variants as $name => $change) {
			$result = $this->runContract($this->service(), array_merge($current, $change), ['id' => 's-1', 'updated' => '2020-01-01T00:00:00+00:00']);
			$this->assertSame('update', $result['resultAction'], $name);
		}
	}//end testEverySkipConditionIsChecked()

	/**
	 * An update without `after` rules writes the target, closes the log with its action and persists once.
	 *
	 * @return void
	 */
	public function testAnUpdateWithoutRulesPersistsOnce(): void {
		$service = $this->service();
		$service->expects($this->once())->method('updateTarget');

		$result = $this->runContract($service, ['id' => 'c-1'], ['id' => 's-1', 'targetType' => 'register/schema', 'targetId' => '1/2']);

		$this->assertSame('update', $result['resultAction']);
		$this->assertSame('create', end($this->logCalls)[1]['targetResult']);
		$this->assertSame(1, $this->hoursAhead(end($this->logCalls)[1]['expires']));
		$this->assertTrue($result['log']['updated']);
		$this->assertCount(1, $this->saved);
		$this->assertSame('target-1', $this->saved[0]['targetId']);
		$this->assertSame([], $this->ruleLookups);
	}//end testAnUpdateWithoutRulesPersistsOnce()

	/**
	 * With `after` rules and a target id, the contract is persisted before and after them,
	 * and the rules run once before and once after for a register/schema target.
	 *
	 * @return void
	 */
	public function testAfterRulesPersistTwiceAndRunForARegisterTarget(): void {
		$this->runContract($this->service(), ['id' => 'c-1'], ['id' => 's-1', 'actions' => ['r-1'], 'targetType' => 'register/schema', 'targetId' => '1/2']);

		$this->assertCount(2, $this->saved);
		$this->assertSame(['r-1', 'r-1'], $this->ruleLookups);
	}//end testAfterRulesPersistTwiceAndRunForARegisterTarget()

	/**
	 * An api target with a register/schema source also runs the `after` rules.
	 *
	 * @return void
	 */
	public function testAfterRulesRunForARegisterSourceToApi(): void {
		$this->runContract($this->service(), ['id' => 'c-1'], ['id' => 's-1', 'actions' => ['r-1'], 'targetType' => 'api', 'sourceType' => 'register/schema', 'sourceId' => '1/2']);

		$this->assertSame(['r-1', 'r-1'], $this->ruleLookups);
	}//end testAfterRulesRunForARegisterSourceToApi()

	/**
	 * Other target types run only the `before` rules.
	 *
	 * @return void
	 */
	public function testAfterRulesAreSkippedForOtherTargets(): void {
		$this->runContract($this->service(), ['id' => 'c-1'], ['id' => 's-1', 'actions' => ['r-1'], 'targetType' => 'api', 'sourceType' => 'api']);

		$this->assertSame(['r-1'], $this->ruleLookups);
	}//end testAfterRulesAreSkippedForOtherTargets()

	/**
	 * Without a target id the early persist is skipped.
	 *
	 * @return void
	 */
	public function testNoEarlyPersistWithoutATargetId(): void {
		$this->runContract($this->service(['targetLastAction' => 'create']), ['id' => 'c-1'], ['id' => 's-1', 'actions' => ['r-1'], 'targetType' => 'register/schema', 'targetId' => '1/2']);

		$this->assertCount(1, $this->saved);
	}//end testNoEarlyPersistWithoutATargetId()

	/**
	 * A `before` rule that returns an error response aborts the run with a message naming the synchronization.
	 *
	 * @return void
	 */
	public function testABeforeRuleErrorAborts(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Synchronization aborted: a "before" rule returned an error response for synchronization s-uuid');

		$this->runContract($this->service(), ['id' => 'c-1'], ['id' => 's-1', 'uuid' => 's-uuid', 'actions' => ['error-before']]);
	}//end testABeforeRuleErrorAborts()

	/**
	 * A source-to-target mapping that does not exist is returned as an Exception.
	 *
	 * @return void
	 */
	public function testAMissingMappingIsReturnedAsException(): void {
		$result = $this->runContract($this->service(), ['id' => 'c-1'], ['id' => 's-1', 'sourceTargetMapping' => 'm-404']);

		$this->assertInstanceOf(Exception::class, $result);
		$this->assertSame('no mapping m-404', $result->getMessage());
	}//end testAMissingMappingIsReturnedAsException()

	/**
	 * While the target write is buffered, the contract joins the contract buffer instead of being saved.
	 *
	 * @return void
	 */
	public function testABufferedTargetWriteBuffersTheContract(): void {
		$service = $this->service();
		$class = new \ReflectionClass(SynchronizationService::class);
		$class->getProperty('lastTargetWriteBuffered')->setValue($service, true);

		$result = $this->runContract($service, ['id' => 'c-1'], ['id' => 's-1']);

		$this->assertSame([], $this->saved);
		$buffer = $class->getProperty('contractWriteBuffer')->getValue($service);
		$this->assertCount(1, $buffer);
		$this->assertSame($result['contract'], $buffer[0]);
		$this->assertNotEmpty($buffer[0]['uuid']);
	}//end testABufferedTargetWriteBuffersTheContract()

	/**
	 * The caller's object ends up mapped, and the `after` rules see the object before mapping.
	 *
	 * @return void
	 */
	public function testTheCallerSeesTheMappedObjectAndAfterRulesSeeTheOriginal(): void {
		$this->runContract(
			$this->service(),
			['id' => 'c-1'],
			['id' => 's-1', 'sourceTargetMapping' => 'm-1', 'actions' => ['after-sees-original'], 'targetType' => 'register/schema', 'targetId' => '1/2']
		);

		$this->assertSame(['mapped' => ['b', 'a']], $this->lastObject);
		$applied = array_filter($this->infoLogs, static fn (string $line): bool => str_starts_with($line, 'Applying rule'));
		$this->assertCount(1, $applied);
	}//end testTheCallerSeesTheMappedObjectAndAfterRulesSeeTheOriginal()
}//end class
