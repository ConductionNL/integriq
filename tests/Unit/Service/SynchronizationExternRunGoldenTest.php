<?php

/**
 * Golden characterization of the run options synchronize() hands to an extern-to-intern run.
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
use OCA\Integriq\Service\SynchronizationApprovalGate;
use OCA\Integriq\Service\SynchronizationLogService;
use OCA\Integriq\Service\SynchronizationRunLog;
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
 * A single-object delete run and a full batch run through synchronize(), with
 * every combination of the test, force and deletion-override flags, a mutation
 * type and a caller's trace. Recorded against a golden file: every argument
 * handed to synchronizeContract() and deleteInvalidObjects(), the trace steps,
 * the caller's flow token and the run result.
 *
 * Regenerate the golden file on the code it must describe with
 * SYNC_EXTERN_RUN_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\SynchronizationService
 */
class SynchronizationExternRunGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/synchronization/extern-run-options.json';

	/**
	 * Every recorded call, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	private array $log = [];

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
	 * Drop clock readings and generated ids, which differ per run.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function stable(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		$out = [];
		foreach ($value as $key => $item) {
			if (is_string($key) === true && preg_match('/time|^timing$|At$|Microtime|duration|Ms$|^id$|^uuid$|^traceId$|date|created|updated|expires/i', $key) === 1) {
				continue;
			}

			$out[$key] = $this->stable($item);
		}

		return $out;
	}//end stable()

	/**
	 * The service, with synchronizeContract() and deleteInvalidObjects() recorded.
	 *
	 * @return SynchronizationService&MockObject
	 */
	private function service(): MockObject {
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

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => ($id === IEventDispatcher::class ? $this->createMock(IEventDispatcher::class) : null)
		);

		$logService = new SynchronizationLogService(ObjectServiceMockBuilder::make($this), $this->createMock(IUserSession::class), $this->createMock(ISession::class));

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
					$this->createMock(SynchronizationApprovalGate::class),
				]
			)
			->onlyMethods(['synchronizeContract', 'deleteInvalidObjects'])
			->getMock();

		$service->method('synchronizeContract')->willReturnCallback(
			function (array $contract, FlowToken &$flowToken, ?array $synchronization = null, array &$object = [], ?bool $isTest = false, ?bool $force = false, ?SynchronizationRunLog $log = null, ?string $mutationType = null, ?ExecutionTraceContext $trace = null) {
				$this->log[] = ['synchronizeContract', $this->d([$contract, $flowToken, $object, $isTest, $force, $log === null, $mutationType, $trace])];
				return ['contract' => ($contract + ['uuid' => 'c-1', 'targetId' => 't-1']), 'log' => ['uuid' => 'l-1'], 'resultAction' => 'update'];
			}
		);
		$service->method('deleteInvalidObjects')->willReturnCallback(
			function (...$args) {
				$this->log[] = ['deleteInvalidObjects', $this->d(array_slice($args, 1))];
				return 0;
			}
		);

		return $service;
	}//end service()

	/**
	 * The cases: synchronize() arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		return [
			'delete-plain' => ['data' => ['id' => 'origin-9'], 'mutationType' => 'delete'],
			'delete-test-force-traced' => ['data' => ['id' => 'origin-9'], 'mutationType' => 'delete', 'isTest' => true, 'force' => true, 'traced' => true],
			'delete-force-only' => ['data' => ['id' => 'origin-9'], 'mutationType' => 'delete', 'force' => true],
			'batch-plain' => [],
			'batch-force-deletion' => ['forceDeletion' => true],
			'batch-force-traced' => ['force' => true, 'traced' => true],
			'batch-test' => ['isTest' => true],
			'batch-update-mutation' => ['mutationType' => 'update'],
			'batch-own-flow-token' => ['flowToken' => true],
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
		$service = $this->service();
		$this->log = [];
		$trace = null;
		if (($case['traced'] ?? false) === true) {
			$trace = new ExecutionTraceContext('endpoint');
		}

		$flowToken = null;
		if (($case['flowToken'] ?? false) === true) {
			$flowToken = new FlowToken(requestOriginal: ['method' => 'POST']);
		}

		$given = $flowToken;
		$this->known = ['trace' => $trace, 'callerFlowToken' => $given];

		$synchronization = [
			'id' => 'sync-1',
			'uuid' => 'sync-1',
			'sourceId' => 'source-1',
			'sourceType' => 'api',
			'targetType' => 'register/schema',
			'targetId' => '1/2',
			'sourceConfig' => ['endpoint' => '/items', 'resultsPosition' => 'items', 'usesPagination' => false],
		];

		try {
			$result = $service->synchronize(
				synchronization: $synchronization,
				isTest: ($case['isTest'] ?? false),
				force: ($case['force'] ?? false),
				mutationType: ($case['mutationType'] ?? null),
				data: ($case['data'] ?? null),
				flowToken: $flowToken,
				forceDeletion: ($case['forceDeletion'] ?? false),
				trace: $trace
			);
			$outcome = $this->stable($this->d($result));
		} catch (\Throwable $exception) {
			$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
		}

		$steps = [];
		if ($trace !== null) {
			$steps = array_map(fn (array $step) => ($step['type'] ?? '') . ':' . ($step['status'] ?? ''), $trace->getSteps());
		}

		return [
			'outcome' => $outcome,
			'flowTokenKept' => ($given === null ? null : $flowToken === $given),
			'steps' => $steps,
			'log' => $this->stable($this->log),
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
		if (getenv('SYNC_EXTERN_RUN_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
