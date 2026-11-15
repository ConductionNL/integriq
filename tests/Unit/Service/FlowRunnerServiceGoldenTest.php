<?php

/**
 * Golden characterization of the FlowRunnerService step loop.
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
 * @spec openspec/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Runs and resumes flows through every step-loop path and records every
 * write, dispatch and outcome against a golden file. Regenerate it on the
 * code it must describe with FLOW_RUNNER_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\FlowRunnerService
 * @uses   \OCA\Integriq\Service\Helper\FlowToken
 * @uses   \OCA\Integriq\Service\Helper\FlowExecution
 */
class FlowRunnerServiceGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/flow/flow-runner-step-loop.json';

	/**
	 * Everything the run wrote or dispatched, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * Flows run from the start, by case name.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function runs(): array {
		$map = static fn (int $order, string $ref, array $extra = []): array => (['order' => $order, 'type' => 'mapping', 'configRef' => $ref] + $extra);
		$isB = ['==' => [['var' => 'syncInputAmended.seen'], 'b']];
		$isZ = ['==' => [['var' => 'syncInputAmended.seen'], 'z']];

		return [
			'empty' => [],
			'sequential-unsorted' => [$map(30, 'c'), $map(10, 'a'), $map(20, 'b')],
			'condition-skips' => [$map(10, 'a'), $map(20, 'b', ['condition' => $isZ]), $map(30, 'c', ['condition' => $isB])],
			'condition-passes' => [$map(10, 'b'), $map(20, 'c', ['condition' => $isB])],
			'branch-match-forward' => [
				$map(10, 'b'),
				['order' => 20, 'type' => 'branch', 'branches' => [['condition' => []], ['condition' => $isZ, 'nextStepOrder' => 30], ['condition' => $isB, 'nextStepOrder' => 40]]],
				$map(30, 'skipped-by-branch'),
				$map(40, 'd'),
			],
			'branch-match-without-target' => [
				$map(10, 'b'),
				['order' => 20, 'type' => 'branch', 'branches' => [['condition' => $isB]], 'defaultNextStepOrder' => 40],
				$map(30, 'c'),
				$map(40, 'd'),
			],
			'branch-default' => [
				$map(10, 'a'),
				['order' => 20, 'type' => 'branch', 'branches' => [['condition' => $isB, 'nextStepOrder' => 30]], 'defaultNextStepOrder' => 40],
				$map(30, 'c'),
				$map(40, 'd'),
			],
			'branch-no-match-no-default' => [
				['order' => 20, 'type' => 'branch', 'branches' => [['condition' => $isB, 'nextStepOrder' => 40]]],
				$map(30, 'c'),
				$map(40, 'd'),
			],
			'branch-unresolvable' => [
				['order' => 20, 'type' => 'branch', 'defaultNextStepOrder' => 99],
				$map(30, 'c'),
			],
			'branch-condition-skips' => [
				['order' => 20, 'type' => 'branch', 'condition' => $isB, 'defaultNextStepOrder' => 40],
				$map(30, 'c'),
				$map(40, 'd'),
			],
			'approval-middle' => [$map(10, 'a'), ['order' => 20, 'type' => 'approval', 'config' => ['approverGroup' => 'ops']], $map(30, 'c')],
			'approval-last' => [$map(10, 'a'), ['order' => 20, 'type' => 'approval']],
			'approval-condition-skips' => [['order' => 20, 'type' => 'approval', 'condition' => $isB], $map(30, 'c')],
			'error-default-stops' => [$map(10, 'fail'), $map(20, 'b')],
			'error-stop' => [$map(10, 'a'), $map(20, 'fail', ['onError' => 'stop']), $map(30, 'c')],
			'error-continue' => [$map(10, 'a'), $map(20, 'fail', ['onError' => 'continue']), $map(30, 'c')],
			'error-dead-letter' => [$map(10, 'fail', ['onError' => 'dead_letter']), $map(20, 'b')],
			'error-unknown-policy' => [$map(10, 'fail', ['onError' => 'retry']), $map(20, 'b')],
			'unsupported-type' => [['order' => 10, 'type' => 'teleport', 'onError' => 'continue'], ['order' => 20], $map(30, 'c')],
			'duplicate-orders' => [$map(10, 'a'), $map(10, 'b')],
		];

	}//end runs()

	/**
	 * Flows resumed after an approval, by case name: steps and resume order.
	 *
	 * @return array<string, array{0: array<int, array<string, mixed>>, 1: int}>
	 */
	private function resumes(): array {
		$steps = [
			['order' => 10, 'type' => 'mapping', 'configRef' => 'a'],
			['order' => 20, 'type' => 'approval'],
			['order' => 30, 'type' => 'mapping', 'configRef' => 'c'],
			['order' => 40, 'type' => 'mapping', 'configRef' => 'd'],
		];

		return [
			'resume-at-step' => [$steps, 30],
			'resume-at-first' => [$steps, 10],
			'resume-at-unknown' => [$steps, 25],
			'resume-empty-flow' => [[], 30],
		];

	}//end resumes()

	/**
	 * Build the service with mocks that record every call into the trace.
	 *
	 * @param array<int, array<string, mixed>> $steps The steps of the flow a resume finds.
	 *
	 * @return FlowRunnerService
	 */
	private function service(array $steps): FlowRunnerService {
		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null): ObjectEntity {
				unset($object['startedAt'], $object['finishedAt']);
				$this->trace[] = ['save' => $schema, 'uuid' => $uuid, 'object' => $object];
				return ObjectServiceMockBuilder::objectEntity($this, $object, ($uuid ?? 'run-1'));
			}
		);
		$objects->method('find')->willReturnCallback(
			function (string $id, ?string $register = null, ?string $schema = null) use ($steps): ObjectEntity {
				$this->trace[] = ['find' => $schema, 'id' => $id];
				if ($schema === 'flow_run') {
					return ObjectServiceMockBuilder::objectEntity($this, ['flowId' => 'flow-1', 'status' => 'suspended'], $id);
				}

				return ObjectServiceMockBuilder::objectEntity($this, ['steps' => $steps], $id);
			}
		);

		$mapping = $this->createMock(MappingService::class);
		$mapping->method('executeMapping')->willReturnCallback(
			function (string $mapping, array $input): array {
				$this->trace[] = ['mapping' => $mapping, 'input' => $input];
				if ($mapping === 'fail') {
					throw new RuntimeException('mapping failed');
				}

				return ['seen' => $mapping, 'previous' => ($input['seen'] ?? null)];
			}
		);

		$approval = $this->createMock(ApprovalService::class);
		$approval->method('suspendForFlow')->willReturnCallback(
			function (ObjectEntity $flowRun, int $resumeStepOrder, array $config, FlowToken $flowToken): ObjectEntity {
				$this->trace[] = ['suspend' => $flowRun->getUuid(), 'resumeStepOrder' => $resumeStepOrder, 'config' => $config, 'input' => $flowToken->getSyncInputAmended()];
				return ObjectServiceMockBuilder::objectEntity($this, ['status' => 'pending'], 'approval-1');
			}
		);
		$approval->method('rehydrateFlowToken')->willReturnCallback(
			static function (array $snapshot): FlowToken {
				$token = new FlowToken();
				$token->setSyncInputAmended(syncInputAmended: $snapshot);
				return $token;
			}
		);

		return new FlowRunnerService(
			$this->createMock(CallService::class),
			$mapping,
			$this->createMock(SynchronizationService::class),
			$approval,
			$objects,
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
		);

	}//end service()

	/**
	 * Run one case and return its trace and outcome.
	 *
	 * @param FlowRunnerService $service The service under test.
	 * @param callable          $action  Runs the case and returns the flow_run.
	 *
	 * @return array<string, mixed>
	 */
	private function record(FlowRunnerService $service, callable $action): array {
		$this->trace = [];
		try {
			$outcome = ['return' => $action($service)->getObject()];
		} catch (\Throwable $exception) {
			$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
		}

		unset($outcome['return']['startedAt'], $outcome['return']['finishedAt']);

		return ['trace' => $this->trace, 'outcome' => $outcome];

	}//end record()

	/**
	 * Every run and resume matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryFlowMatchesTheGoldenFile(): void {
		$actual = [];
		foreach ($this->runs() as $name => $steps) {
			$flow = ObjectServiceMockBuilder::objectEntity($this, ['steps' => $steps], 'flow-1');
			$actual[$name] = $this->record(
				$this->service([]),
				static fn (FlowRunnerService $service): ObjectEntity => $service->run(flow: $flow, input: ['seen' => 'input'], triggerSource: 'manual')
			);
		}

		foreach ($this->resumes() as $name => [$steps, $resumeStepOrder]) {
			$request = ObjectServiceMockBuilder::objectEntity($this, ['flowRunId' => 'run-7', 'resumeStepOrder' => $resumeStepOrder, 'snapshot' => ['seen' => 'snapshot']], 'approval-1');
			$actual[$name] = $this->record(
				$this->service($steps),
				static fn (FlowRunnerService $service): ObjectEntity => $service->resumeFromApproval(approvalRequest: $request)
			);
		}

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (getenv('FLOW_RUNNER_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame((string)file_get_contents(self::GOLDEN), $encoded);

	}//end testEveryFlowMatchesTheGoldenFile()
}//end class
