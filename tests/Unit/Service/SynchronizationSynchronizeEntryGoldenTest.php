<?php

/**
 * Golden characterization of synchronize()'s entry and exit wiring.
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
 * @spec openspec/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ExecutionTraceService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationRunLog;
use OCA\Integriq\Service\SynchronizationRunProgressService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\ISession;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs synchronize() through its intern-to-extern shortcut, an extern delete,
 * a paused (gated) extern batch and an invalid mutation type, with and without
 * a caller's trace. Records the run-progress start and finish calls, the
 * run-log writes, the trace persistence and the returned result against a
 * golden file.
 *
 * Regenerate the golden file on the code it must describe with
 * SYNC_ENTRY_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\SynchronizationService
 * @uses   \OCA\Integriq\Service\Synchronization\ExternSyncRun
 * @uses   \OCA\Integriq\Service\Helper\ExecutionTraceContext
 * @uses   \OCA\Integriq\Service\Helper\FlowToken
 * @uses   \OCA\Integriq\Service\MessageValidation\SynchronizationMessageGate
 * @uses   \OCA\Integriq\Service\ResponseDecoder
 * @uses   \OCA\Integriq\Service\Security\SensitiveFieldRegistry
 * @uses   \OCA\Integriq\Service\SynchronizationLogService
 * @uses   \OCA\Integriq\Service\SynchronizationRunLog
 * @uses   \OCA\Integriq\Service\SynchronizationRunProgressService
 * @uses   \OCA\Integriq\Service\Synchronization\ApiFetch
 * @uses   \OCA\Integriq\Service\Synchronization\ChangeSetBuilder
 * @uses   \OCA\Integriq\Service\Synchronization\SourceItemRequest
 * @uses   \OCA\Integriq\Service\Synchronization\SourcePage
 */
class SynchronizationSynchronizeEntryGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/synchronization/synchronize-entry.json';

	/**
	 * Every recorded call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Drop clock readings and generated ids, which differ per run.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function stable(mixed $value): mixed {
		if (is_object($value) === true) {
			return ['object' => get_class($value)];
		}

		if (is_array($value) === false) {
			return $value;
		}

		$out = [];
		foreach ($value as $key => $item) {
			if (is_string($key) === true && preg_match('/time|At$|Microtime|duration|Ms$|^id$|^uuid$|^traceId$|^date|Date$|^expires$/i', $key) === 1) {
				$out[$key] = ($item === null ? null : '(set)');
				continue;
			}

			$out[$key] = $this->stable($item);
		}

		return $out;
	}//end stable()

	/**
	 * Describe a trace by what synchronize() decides about it.
	 *
	 * @param ExecutionTraceContext $trace The trace.
	 *
	 * @return array<string, mixed>
	 */
	private function traceShape(ExecutionTraceContext $trace): array {
		return [
			'entryPoint' => $trace->getEntryPoint(),
			'entryPointId' => $trace->getEntryPointId(),
			'triggeredBy' => $trace->getTriggeredBy(),
		];
	}//end traceShape()

	/**
	 * The service under test, with its collaborators recording.
	 *
	 * @param bool $gated Whether the approval gate pauses the run.
	 *
	 * @return SynchronizationService
	 */
	private function service(bool $gated): SynchronizationService {
		$callService = $this->createMock(CallService::class);
		$callService->method('applyConfigDot')->willReturnArgument(0);
		$callService->method('call')->willReturn(
			ObjectServiceMockBuilder::objectEntity(
				$this,
				['response' => ['statusCode' => 200, 'body' => json_encode(['items' => [['id' => 'origin-1']]]), 'encoding' => 'UTF-8', 'headers' => []]],
				'call-log-1'
			)
		);

		$orObjectService = ObjectServiceMockBuilder::make($this);
		$orObjectService->method('find')->willReturn(
			ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://example.test', 'enabled' => true], 'source-1')
		);
		$orObjectService->method('findAll')->willReturn(['results' => [], 'total' => 0]);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);

		$progress = $this->createMock(SynchronizationRunProgressService::class);
		$progress->method('start')->willReturnCallback(
			function (...$args) {
				$this->calls[] = ['progress.start', $this->stable($args)];
			}
		);
		$progress->method('finish')->willReturnCallback(
			function (...$args) {
				$this->calls[] = ['progress.finish', $this->stable($args)];
			}
		);

		$traces = $this->createMock(ExecutionTraceService::class);
		$traces->method('persist')->willReturnCallback(
			function (ExecutionTraceContext $trace, string $status) {
				$this->calls[] = ['trace.persist', $this->traceShape($trace), $status];
				return ObjectServiceMockBuilder::objectEntity($this, [], 'trace-row');
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				IEventDispatcher::class => $this->createMock(IEventDispatcher::class),
				SynchronizationRunProgressService::class => $progress,
				ExecutionTraceService::class => $traces,
				default => null,
			}
		);

		$logService = $this->getMockBuilder(SynchronizationLogService::class)
			->setConstructorArgs([ObjectServiceMockBuilder::make($this), $this->createMock(IUserSession::class), $this->createMock(ISession::class)])
			->onlyMethods(['persist', 'update'])
			->getMock();
		$record = function (string $name) {
			return function (SynchronizationRunLog $log) use ($name) {
				$this->calls[] = ['log.' . $name, $this->stable($log->jsonSerialize())];
				return $log;
			};
		};
		$logService->method('persist')->willReturnCallback($record('persist'));
		$logService->method('update')->willReturnCallback($record('update'));

		$gate = $this->createMock(SynchronizationApprovalGate::class);
		if ($gated === true) {
			$gate->method('findApprovedUnconsumedForSynchronization')->willReturn(null);
			$gate->method('suspendForSynchronization')
				->willReturn(ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending'], 'approval-created'));
		}

		$service = $this->getMockBuilder(SynchronizationService::class)
			->setConstructorArgs(
				[
					$callService,
					$this->createMock(MappingService::class),
					$container,
					$orObjectService,
					$this->createMock(ObjectService::class),
					$this->createMock(LoggerInterface::class),
					$logService,
					$appConfig,
					$gate,
				]
			)
			->onlyMethods(['synchronizeContract', 'deleteInvalidObjects', 'updateTarget'])
			->getMock();
		$service->method('synchronizeContract')->willReturnCallback(
			function (array $contract) {
				return ['contract' => ($contract + ['uuid' => 'c-1', 'targetId' => 't-1']), 'log' => ['uuid' => 'l-1'], 'resultAction' => 'update'];
			}
		);
		$service->method('deleteInvalidObjects')->willReturn(0);

		return $service;
	}//end service()

	/**
	 * The cases: synchronize() arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$intern = ['sourceType' => 'register/schema', 'conditions' => ['==' => [1, 2]]];
		return [
			'invalid-mutation' => ['mutationType' => 'explode'],
			'intern-owned-trace' => ['sync' => $intern, 'object' => ['name' => 'a']],
			'intern-callers-trace' => ['sync' => $intern, 'object' => ['name' => 'a'], 'trace' => 'cron', 'isTest' => true],
			'intern-without-object-runs-extern' => ['sync' => $intern, 'data' => ['id' => 'origin-9'], 'mutationType' => 'delete'],
			'extern-delete-owned-trace' => ['data' => ['id' => 'origin-9'], 'mutationType' => 'delete'],
			'extern-delete-cron-trace' => ['data' => ['id' => 'origin-9'], 'mutationType' => 'delete', 'trace' => 'cron', 'force' => true],
			'extern-delete-manual-trace' => ['data' => ['id' => 'origin-9'], 'mutationType' => 'delete', 'trace' => 'manual'],
			'extern-delete-rerun' => ['data' => ['id' => 'origin-9'], 'mutationType' => 'delete', 'triggeredBy' => 'rerun', 'sync' => ['sourceConfig' => ['recordRunProgress' => false]]],
			'extern-delete-unknown-trigger' => ['data' => ['id' => 'origin-9'], 'mutationType' => 'delete', 'triggeredBy' => 'bogus'],
			'extern-delete-uuid-only' => ['data' => ['id' => 'origin-9'], 'mutationType' => 'delete', 'unset' => ['id']],
			'extern-delete-empty-source' => ['data' => ['id' => 'origin-9'], 'mutationType' => 'delete', 'sync' => ['sourceId' => '']],
			'extern-delete-object-entity' => ['data' => ['id' => 'origin-9'], 'mutationType' => 'delete', 'entity' => true],
			'extern-gated-paused' => ['gated' => true, 'sync' => ['sourceConfig' => ['endpoint' => '/items', 'resultsPosition' => 'items', 'usesPagination' => false, 'requiresApproval' => true]]],
			'extern-gated-paused-callers-trace' => ['gated' => true, 'trace' => 'manual', 'sync' => ['sourceConfig' => ['endpoint' => '/items', 'resultsPosition' => 'items', 'usesPagination' => false, 'requiresApproval' => true]]],
		];
	}//end cases()

	/**
	 * Run one case and describe what happened.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function runCase(array $case): array {
		$service = $this->service(gated: ($case['gated'] ?? false));
		$this->calls = [];

		$synchronization = array_merge(
			[
				'id' => 'sync-1',
				'uuid' => 'sync-uuid-1',
				'sourceId' => 'source-1',
				'sourceType' => 'api',
				'targetType' => 'register/schema',
				'targetId' => '1/2',
				'sourceConfig' => ['endpoint' => '/items', 'resultsPosition' => 'items', 'usesPagination' => false],
			],
			($case['sync'] ?? [])
		);
		foreach (($case['unset'] ?? []) as $key) {
			unset($synchronization[$key]);
		}

		if (($case['entity'] ?? false) === true) {
			$synchronization = ObjectServiceMockBuilder::objectEntity($this, $synchronization, 'sync-entity');
		}

		$trace = null;
		if (isset($case['trace']) === true) {
			$trace = new ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: 'ep-1', triggeredBy: $case['trace']);
		}

		$object = ($case['object'] ?? null);
		$flowToken = null;

		try {
			$result = $service->synchronize(
				synchronization: $synchronization,
				isTest: ($case['isTest'] ?? false),
				force: ($case['force'] ?? false),
				object: $object,
				mutationType: ($case['mutationType'] ?? null),
				data: ($case['data'] ?? null),
				flowToken: $flowToken,
				trace: $trace,
				triggeredBy: ($case['triggeredBy'] ?? null)
			);
			$outcome = $this->stable($result);
		} catch (\Throwable $exception) {
			$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
		}

		return [
			'outcome' => $outcome,
			'flowTokenMinted' => ($flowToken instanceof FlowToken),
			'calls' => $this->calls,
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
		if (getenv('SYNC_ENTRY_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
