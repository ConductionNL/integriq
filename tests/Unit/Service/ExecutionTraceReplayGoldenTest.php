<?php

/**
 * Characterisation test for ExecutionTraceService::replay.
 *
 * Pins, per entry point, which engine a replay reaches and with what, what
 * the new trace records, and what is logged when the engine fails.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\ExecutionTraceService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http\Response;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden record of the replay paths.
 */
final class ExecutionTraceReplayGoldenTest extends TestCase {

	/**
	 * The record, taken before the replay paths were split into steps.
	 *
	 * @var string
	 */
	private const GOLDEN = '{"sync dry":[["sync",{"id":"obj-1"},true,"orig-1"],["saved",{"entryPoint":"sync","entryPointId":"sync-1","status":"success","replayOf":"orig-1","isReplay":true,"dryRun":true,"triggeredBy":"manual"},[]]],"sync forced":[["sync",{"id":"obj-1"},false,"orig-1"],["saved",{"entryPoint":"sync","entryPointId":"sync-1","status":"success","replayOf":"orig-1","isReplay":true,"dryRun":false,"triggeredBy":"manual"},[]]],"sync without id":[["saved",{"entryPoint":"sync","entryPointId":null,"status":"failed","replayOf":"orig-1","isReplay":true,"dryRun":false,"triggeredBy":"manual"},[]]],"sync throws":[["warning","ExecutionTraceService: sync-entryPoint replay failed.",{"entryPointId":"sync-boom","exception":"no such synchronization"}],["saved",{"entryPoint":"sync","entryPointId":"sync-boom","status":"failed","replayOf":"orig-1","isReplay":true,"dryRun":false,"triggeredBy":"manual"},[]]],"sync without steps":[["sync",[],true,"orig-1"],["saved",{"entryPoint":"sync","entryPointId":"sync-1","status":"success","replayOf":"orig-1","isReplay":true,"dryRun":true,"triggeredBy":"manual"},[]]],"endpoint dry":[["endpoint",{"path":"\\/zaken","parameters":{"q":1}},true,"orig-1"],["saved",{"entryPoint":"endpoint","entryPointId":"ep-200","status":"success","replayOf":"orig-1","isReplay":true,"dryRun":true,"triggeredBy":"manual"},[]]],"endpoint forced 202":[["endpoint",{"path":"\\/zaken","parameters":{"q":1}},false,"orig-1"],["saved",{"entryPoint":"endpoint","entryPointId":"ep-202","status":"success","replayOf":"orig-1","isReplay":true,"dryRun":false,"triggeredBy":"manual"},[]]],"endpoint 500":[["endpoint",{"path":"\\/zaken","parameters":{"q":1}},false,"orig-1"],["saved",{"entryPoint":"endpoint","entryPointId":"ep-500","status":"failed","replayOf":"orig-1","isReplay":true,"dryRun":false,"triggeredBy":"manual"},[]]],"endpoint 199":[["endpoint",[],false,"orig-1"],["saved",{"entryPoint":"endpoint","entryPointId":"ep-199","status":"failed","replayOf":"orig-1","isReplay":true,"dryRun":false,"triggeredBy":"manual"},[]]],"endpoint gone":[["saved",{"entryPoint":"endpoint","entryPointId":"ep-gone","status":"failed","replayOf":"orig-1","isReplay":true,"dryRun":false,"triggeredBy":"manual"},[]]],"endpoint throws":[["warning","ExecutionTraceService: endpoint-entryPoint replay failed.",{"entryPointId":"ep-boom","exception":"endpoint store down"}],["saved",{"entryPoint":"endpoint","entryPointId":"ep-boom","status":"failed","replayOf":"orig-1","isReplay":true,"dryRun":true,"triggeredBy":"manual"},[]]],"endpoint without id":[["saved",{"entryPoint":"endpoint","entryPointId":null,"status":"failed","replayOf":"orig-1","isReplay":true,"dryRun":false,"triggeredBy":"manual"},[]]],"no entry point":[["saved",{"entryPoint":"endpoint","entryPointId":"ep-200","status":"failed","replayOf":"orig-1","isReplay":true,"dryRun":false,"triggeredBy":"manual"},[]]],"unknown entry point":[["saved",{"entryPoint":"cron","entryPointId":"x","status":"failed","replayOf":"orig-1","isReplay":true,"dryRun":false,"triggeredBy":"manual"},[]]],"job without id":[["saved",{"entryPoint":"job","entryPointId":null,"status":"failed","replayOf":"orig-1","isReplay":true,"dryRun":false,"triggeredBy":"manual"},[]]],"event without id":[["saved",{"entryPoint":"event","entryPointId":null,"status":"failed","replayOf":"orig-1","isReplay":true,"dryRun":true,"triggeredBy":"manual"},[]]]}';

	/**
	 * What every scenario reached, saved and logged.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $seen = [];

	/**
	 * GOLDEN: every entry point, forced and dry, with a missing entry point
	 * id, an engine that throws, an endpoint that is gone and an endpoint
	 * that answers outside 2xx.
	 *
	 * @return void
	 */
	public function testGoldenReplayPaths(): void {
		$steps = [
			['type' => 'rule', 'input' => ['path' => '/zaken', 'parameters' => ['q' => 1]]],
			['type' => 'synchronization', 'input' => ['id' => 'obj-1']],
			['type' => 'synchronization', 'input' => ['id' => 'obj-2']],
			['type' => 'rule', 'input' => 'not-an-array'],
		];
		$scenarios = [
			'sync dry' => [['entryPoint' => 'sync', 'entryPointId' => 'sync-1', 'steps' => $steps], false],
			'sync forced' => [['entryPoint' => 'sync', 'entryPointId' => 'sync-1', 'steps' => $steps], true],
			'sync without id' => [['entryPoint' => 'sync', 'steps' => $steps], true],
			'sync throws' => [['entryPoint' => 'sync', 'entryPointId' => 'sync-boom', 'steps' => $steps], true],
			'sync without steps' => [['entryPoint' => 'sync', 'entryPointId' => 'sync-1'], false],
			'endpoint dry' => [['entryPoint' => 'endpoint', 'entryPointId' => 'ep-200', 'steps' => $steps], false],
			'endpoint forced 202' => [['entryPoint' => 'endpoint', 'entryPointId' => 'ep-202', 'steps' => $steps], true],
			'endpoint 500' => [['entryPoint' => 'endpoint', 'entryPointId' => 'ep-500', 'steps' => $steps], true],
			'endpoint 199' => [['entryPoint' => 'endpoint', 'entryPointId' => 'ep-199', 'steps' => []], true],
			'endpoint gone' => [['entryPoint' => 'endpoint', 'entryPointId' => 'ep-gone'], true],
			'endpoint throws' => [['entryPoint' => 'endpoint', 'entryPointId' => 'ep-boom', 'steps' => $steps], false],
			'endpoint without id' => [['entryPoint' => 'endpoint'], true],
			'no entry point' => [['entryPointId' => 'ep-200', 'steps' => $steps], true],
			'unknown entry point' => [['entryPoint' => 'cron', 'entryPointId' => 'x'], true],
			'job without id' => [['entryPoint' => 'job'], true],
			'event without id' => [['entryPoint' => 'event'], false],
		];
		foreach ($scenarios as $name => [$original, $force]) {
			$this->seen[$name] = [];
			$this->service(scenario: $name, original: $original)->replay(traceId: 'orig-1', actorUid: 'admin', force: $force);
		}

		$this->assertSame(self::GOLDEN, json_encode($this->seen));
	}//end testGoldenReplayPaths()

	/**
	 * A service whose engines record what reaches them.
	 *
	 * @param string               $scenario The scenario, to record under.
	 * @param array<string, mixed> $original The stored trace.
	 *
	 * @return ExecutionTraceService The service.
	 */
	private function service(string $scenario, array $original): ExecutionTraceService {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('find')->willReturn(ObjectServiceMockBuilder::objectEntity($this, $original, 'orig-1'));
		$objects->method('saveObject')->willReturnCallback(
			function (array $payload) use ($scenario): ObjectEntity {
				$this->seen[$scenario][] = [
					'saved',
					array_intersect_key($payload, array_flip(['entryPoint', 'entryPointId', 'replayOf', 'isReplay', 'dryRun', 'triggeredBy', 'status'])),
					array_map(static fn (array $step): array => [$step['type'], $step['name'], $step['status']], (array)($payload['steps'] ?? [])),
				];
				return ObjectServiceMockBuilder::objectEntity($this, $payload, 'new');
			}
		);

		$synchronizations = $this->createMock(SynchronizationService::class);
		$synchronizations->method('getSynchronization')->willReturnCallback(
			function ($id) use ($scenario): ObjectEntity {
				if ($id === 'sync-boom') {
					throw new RuntimeException('no such synchronization');
				}

				return ObjectServiceMockBuilder::objectEntity($this, ['uuid' => $id], (string)$id);
			}
		);
		$synchronizations->method('replaySynchronizationItem')->willReturnCallback(
			function (array $synchronization, array $payload, bool $isTest, ExecutionTraceContext $trace) use ($scenario): array {
				$this->seen[$scenario][] = ['sync', $payload, $isTest, $trace->getReplayOf()];
				return ['result' => [], 'targetId' => null];
			}
		);

		$endpoints = $this->createMock(EndpointService::class);
		$endpoints->method('getEndpointById')->willReturnCallback(
			function (string $id): ?ObjectEntity {
				if ($id === 'ep-boom') {
					throw new RuntimeException('endpoint store down');
				}

				return ($id === 'ep-gone') ? null : ObjectServiceMockBuilder::objectEntity($this, ['uuid' => $id], $id);
			}
		);
		$endpoints->method('replay')->willReturnCallback(
			function (ObjectEntity $endpoint, array $requestSnapshot, ExecutionTraceContext $trace, bool $dryRun) use ($scenario): Response {
				$this->seen[$scenario][] = ['endpoint', $requestSnapshot, $dryRun, $trace->getReplayOf()];
				$response = new Response();
				$response->setStatus((int)substr((string)$endpoint->getUuid(), 3));
				return $response;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $class) => match ($class) {
				SynchronizationService::class => $synchronizations,
				EndpointService::class => $endpoints,
				default => null,
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context) use ($scenario): void {
				$this->seen[$scenario][] = ['warning', $message, $context];
			}
		);

		return new ExecutionTraceService($objects, $container, $logger);
	}//end service()
}//end class
