<?php

/**
 * Golden characterization of how SynchronizationService routes one source item.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/synchronization-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\Synchronization\ExternSyncRun;
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
use ReflectionMethod;

/**
 * Every way a source item reaches processSynchronizationObject(): the delete run,
 * the batch item and the dead-letter replay. Recorded against a golden file: each
 * contract lookup, every argument handed to synchronizeContract(), the trace steps
 * and what the caller is left with.
 *
 * Regenerate the golden file on the code it must describe with
 * SYNC_PROCESS_OBJECT_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\SynchronizationService
 * @uses   \OCA\Integriq\Service\Synchronization\SourceItemRequest
 */
class SynchronizationProcessObjectGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/synchronization/process-object.json';

	/**
	 * Every collaborator call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

	/**
	 * The current case.
	 *
	 * @var array<string, mixed>
	 */
	private array $case = [];

	/**
	 * The identities the log refers to by name.
	 *
	 * @var array<string, object|null>
	 */
	private array $known = [];

	/**
	 * Describe a value for the log: known objects by name, the rest as is.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function d(mixed $value): mixed {
		if (is_object($value) === true) {
			$name = array_search($value, $this->known, true);
			return ['object' => ($name === false ? get_class($value) : $name)];
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->d($item), $value);
		}

		return $value;
	}//end d()

	/**
	 * The service, with synchronizeContract() recorded and answered by the case.
	 *
	 * @return SynchronizationService&MockObject
	 */
	private function service(): MockObject {
		$callService = $this->createMock(CallService::class);
		$callService->method('applyConfigDot')->willReturnArgument(0);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('findAll')->willReturnCallback(
			function (...$args) {
				$this->log[] = ['or.findAll', ($args[0]['filters'] ?? $args[0])];
				$found = array_map(fn (array $row) => ObjectServiceMockBuilder::objectEntity($this, $row, $row['id']), ($this->case['found'] ?? []));
				return ['results' => $found, 'total' => count($found)];
			}
		);

		$service = $this->getMockBuilder(SynchronizationService::class)
			->setConstructorArgs(
				[
					$callService,
					$this->createMock(MappingService::class),
					$this->createMock(ContainerInterface::class),
					$orObjectService,
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
			function (array $contract, FlowToken &$flowToken, ?array $synchronization = null, array &$object = [], ?bool $isTest = false, ?bool $force = false, ?SynchronizationRunLog $log = null, ?string $mutationType = null, ?ExecutionTraceContext $trace = null) {
				$this->log[] = ['synchronizeContract', $this->d(func_get_args())];
				$object['touched'] = true;
				$answer = ($this->case['contractResult'] ?? ['action' => 'update']);
				return ['contract' => ($contract + ['uuid' => 'c-1', 'targetId' => 't-1']), 'log' => ['uuid' => 'l-1'], 'resultAction' => $answer['action']];
			}
		);

		return $service;
	}//end service()

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$sync = ['id' => 's-1', 'name' => 'Zaken'];
		$existing = [['id' => 'c-9', 'originId' => 'o-1', 'synchronizationId' => 's-1']];
		return [
			'replay-new' => ['entry' => 'replay', 'sync' => $sync, 'object' => ['id' => 'o-1']],
			'replay-existing-test-traced' => ['entry' => 'replay', 'sync' => $sync, 'object' => ['id' => 'o-1'], 'isTest' => true, 'traced' => true, 'found' => $existing, 'contractResult' => ['action' => 'create']],
			'replay-skipped-by-conditions' => ['entry' => 'replay', 'sync' => ($sync + ['conditions' => ['==' => [1, 2]]]), 'object' => ['id' => 'o-1'], 'traced' => true],
			'delete' => ['entry' => 'delete', 'sync' => $sync, 'object' => ['id' => 'o-2'], 'mutationType' => 'delete', 'isTest' => false, 'force' => true],
			'delete-test-traced-existing' => ['entry' => 'delete', 'sync' => $sync, 'object' => ['id' => 'o-1'], 'mutationType' => 'delete', 'isTest' => true, 'force' => false, 'traced' => true, 'found' => $existing, 'contractResult' => ['action' => 'delete']],
			'batch-indexed-hit' => ['entry' => 'batch', 'sync' => $sync, 'object' => ['id' => 'o-1'], 'mutationType' => 'update', 'force' => true, 'traced' => true, 'index' => ['o-1' => $existing]],
			'batch-indexed-miss' => ['entry' => 'batch', 'sync' => $sync, 'object' => ['id' => 'o-3'], 'isTest' => true, 'index' => ['o-1' => $existing], 'contractResult' => ['action' => 'skip']],
			'batch-unindexed' => ['entry' => 'batch', 'sync' => $sync, 'object' => ['id' => 'o-1'], 'found' => $existing],
			'batch-scalar' => ['entry' => 'batch', 'sync' => $sync, 'object' => 'plain', 'traced' => true],
		];
	}//end cases()

	/**
	 * The trace steps without their clock readings.
	 *
	 * @param ExecutionTraceContext|null $trace The trace.
	 *
	 * @return array
	 */
	private function steps(?ExecutionTraceContext $trace): array {
		if ($trace === null) {
			return [];
		}

		return array_map(
			fn (array $step) => array_filter($step, fn ($key) => preg_match('/At|Microtime|duration|Ms$|id$/i', (string)$key) !== 1, ARRAY_FILTER_USE_KEY),
			$trace->getSteps()
		);
	}//end steps()

	/**
	 * Run one case and describe what happened.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$this->case = $case;
		$service = $this->service();
		$this->log = [];
		$trace = null;
		if (($case['traced'] ?? false) === true) {
			$trace = new ExecutionTraceContext('sync');
		}

		$run = new ExternSyncRun();
		$run->synchronization = $case['sync'];
		$run->log = $this->createMock(SynchronizationRunLog::class);
		$run->flowToken = new FlowToken();
		$run->isTest = ($case['isTest'] ?? false);
		$run->force = ($case['force'] ?? false);
		$run->data = (is_array($case['object']) === true ? $case['object'] : null);
		$run->mutationType = ($case['mutationType'] ?? null);
		$run->trace = $trace;
		$run->objectList = [$case['object']];
		$run->result = ['objects' => ['found' => 1, 'skipped' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'invalid' => 0], 'contracts' => [], 'logs' => []];
		$this->known = ['runLog' => $run->log, 'runFlowToken' => $run->flowToken, 'trace' => $trace];

		$outcome = null;
		try {
			if ($case['entry'] === 'replay') {
				$outcome = $service->replaySynchronizationItem($case['sync'], $case['object'], ($case['isTest'] ?? false), $trace);
			} elseif ($case['entry'] === 'delete') {
				(new ReflectionMethod(SynchronizationService::class, 'processExternDelete'))->invoke($service, $run);
			} else {
				(new ReflectionMethod(SynchronizationService::class, 'processExternObject'))->invoke($service, $run, $case['object'], ($case['index'] ?? null), 1);
			}
		} catch (\Throwable $exception) {
			$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
		}

		return [
			'outcome' => $this->d($outcome),
			'run' => ['result' => $run->result, 'targetIds' => $run->targetIds, 'timed' => count($run->objectTimes)],
			'steps' => $this->d($this->steps($trace)),
			'log' => $this->log,
		];
	}//end runCase()

	/**
	 * Every case behaves as the golden file records.
	 *
	 * @return void
	 */
	public function testEveryCaseMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase($case);
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n";
		if (getenv('SYNC_PROCESS_OBJECT_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
