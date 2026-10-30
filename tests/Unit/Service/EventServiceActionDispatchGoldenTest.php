<?php

/**
 * Golden characterization of the action dispatch of EventService::attemptDeliveryDispatch().
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
 * @spec openspec/specs/events-cloudevents/spec.md#requirement-a-subscription-s-action-dispatch-must-support-webhook-synchronization-or-job-kinds-req-008
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\EventService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\JobService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\Http\Client\IClientService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Every action kind a subscription can carry, dispatched through
 * attemptDeliveryDispatch() and recorded against a golden file: each
 * collaborator call with its arguments, each saved message and the result.
 *
 * Regenerate the golden file on the code it must describe with
 * EVENT_ACTION_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\EventService
 */
class EventServiceActionDispatchGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/event/action-dispatch.json';

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
	 * Mask values that change between runs.
	 *
	 * @param mixed $value The value.
	 * @param string $key The key it sits under.
	 *
	 * @return mixed
	 */
	private function mask(mixed $value, string $key = ''): mixed {
		if (is_array($value) === true) {
			$masked = [];
			foreach ($value as $innerKey => $inner) {
				$masked[$innerKey] = $this->mask(value: $inner, key: (string)$innerKey);
			}

			return $masked;
		}

		if (preg_match('/At$|time|date|duration/i', $key) === 1 && $value !== null) {
			return '<' . gettype($value) . '>';
		}

		if (is_string($value) === true) {
			return preg_replace('/\d{4}-\d{2}-\d{2}T[0-9:.+\-Z]+/', '<date>', $value);
		}

		return $value;
	}//end mask()

	/**
	 * How the case answers one collaborator.
	 *
	 * @param string $name The collaborator call.
	 *
	 * @return string
	 */
	private function answer(string $name): string {
		return (string)($this->case['answers'][$name] ?? 'ok');
	}//end answer()

	/**
	 * The service over recording collaborators.
	 *
	 * @return EventService
	 */
	private function service(): EventService {
		$objects = $this->createMock(ORObjectService::class);
		$objects->method('find')->willReturnCallback(
			function ($id, $register = null, $schema = null) {
				$this->log[] = ['or.find', $id, $schema];
				$answer = $this->answer('find.' . $schema);
				if ($answer === 'throws') {
					throw new RuntimeException('not found');
				}

				if ($answer === 'null') {
					return null;
				}

				return ObjectServiceMockBuilder::objectEntity($this, ['name' => $schema . '-' . $id, 'location' => 'https://x.invalid'], $schema . '-' . $id);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, $register = null, $schema = null, $uuid = null) {
				$this->log[] = ['or.saveObject', $this->mask(value: $object), $schema, $uuid];
				return ObjectServiceMockBuilder::objectEntity($this, (array)$object, (string)$uuid);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message, array $context = []) use ($level) {
					$this->log[] = ['logger.' . $level, $message, array_keys($context)];
				}
			);
		}

		$sync = $this->createMock(SynchronizationService::class);
		$sync->method('synchronize')->willReturnCallback(
			function ($synchronization, ...$rest) {
				$this->log[] = ['sync.synchronize', $synchronization->getUuid(), count($rest) > 0];
				if ($this->answer('synchronize') === 'throws') {
					throw new RuntimeException('sync broke');
				}

				return [];
			}
		);

		$jobs = $this->createMock(JobService::class);
		$jobs->method('executeJob')->willReturnCallback(
			function ($job, ...$rest) {
				$this->log[] = ['jobs.executeJob', $job->getUuid(), $rest];
				$answer = $this->answer('executeJob');
				if ($answer === 'throws') {
					throw new RuntimeException('job broke');
				}

				if ($answer === 'null') {
					return null;
				}

				return ObjectServiceMockBuilder::objectEntity($this, ['status' => $answer], 'joblog-1');
			}
		);

		$flows = $this->createMock(FlowRunnerService::class);
		$flows->method('findFlow')->willReturnCallback(
			function ($id) {
				$this->log[] = ['flows.findFlow', $id];
				if ($this->answer('findFlow') === 'throws') {
					throw new RuntimeException('no flow');
				}

				return ObjectServiceMockBuilder::objectEntity($this, ['name' => 'flow'], 'flow-' . $id);
			}
		);
		$flows->method('run')->willReturnCallback(
			function ($flow, $input = [], $triggerSource = null, $requestContext = []) {
				$this->log[] = ['flows.run', $flow->getUuid(), $this->mask(value: $input), $triggerSource];
				$answer = $this->answer('run');
				if ($answer === 'throws') {
					throw new RuntimeException('run broke');
				}

				return ObjectServiceMockBuilder::objectEntity($this, ['status' => $answer], 'run-1');
			}
		);

		$call = $this->createMock(CallService::class);
		$call->method('call')->willReturnCallback(
			function ($source, $endpoint = '', $method = 'GET', $config = []) {
				$this->log[] = ['call.call', $source->getUuid(), $endpoint, $method, $this->mask(value: $config)];
				$answer = $this->answer('call');
				if ($answer === 'throws') {
					throw new RuntimeException('call broke');
				}

				$status = 200;
				if ($answer !== 'ok') {
					$status = (int)$answer;
				}

				return ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => $status, 'response' => ['statusCode' => $status, 'body' => '{}']], 'log-1');
			}
		);

		return new EventService(
			$objects,
			$this->createMock(IClientService::class),
			$logger,
			$this->createMock(WebhookSignatureService::class),
			$sync,
			$jobs,
			$call,
			$flows,
		);
	}//end service()

	/**
	 * The cases: subscription action, message data and collaborator answers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		return [
			'no-subscription' => ['subscription' => null, 'answers' => ['find.subscription' => 'null']],
			'unknown-kind' => ['action' => ['kind' => 'carrier-pigeon']],
			'kind-not-array' => ['action' => 'webhookish', 'answers' => ['find.subscription' => 'null']],
			'sync-no-id' => ['action' => ['kind' => 'synchronization']],
			'sync-missing' => ['action' => ['kind' => 'synchronization', 'synchronizationId' => 's-1'], 'answers' => ['find.synchronization' => 'null']],
			'sync-find-throws' => ['action' => ['kind' => 'synchronization', 'synchronizationId' => 's-1'], 'answers' => ['find.synchronization' => 'throws']],
			'sync-runs' => ['action' => ['kind' => 'synchronization', 'synchronizationId' => 's-1']],
			'sync-fails' => ['action' => ['kind' => 'synchronization', 'synchronizationId' => 's-1'], 'answers' => ['synchronize' => 'throws']],
			'sync-retry-policy' => ['action' => ['kind' => 'synchronization'], 'retryPolicy' => ['maxRetries' => 1, 'initialDelaySeconds' => 5], 'message' => ['retryCount' => 1]],
			'job-no-id' => ['action' => ['kind' => 'job']],
			'job-missing' => ['action' => ['kind' => 'job', 'jobId' => 'j-1'], 'answers' => ['find.job' => 'null']],
			'job-runs' => ['action' => ['kind' => 'job', 'jobId' => 'j-1']],
			'job-null-log' => ['action' => ['kind' => 'job', 'jobId' => 'j-1'], 'answers' => ['executeJob' => 'null']],
			'job-fails' => ['action' => ['kind' => 'job', 'jobId' => 'j-1'], 'answers' => ['executeJob' => 'throws']],
			'notificaties-no-channel' => ['action' => ['kind' => 'notificaties']],
			'notificaties-no-source' => ['action' => ['kind' => 'notificaties', 'channel' => 'zaken']],
			'notificaties-source-missing' => ['action' => ['kind' => 'notificaties', 'channel' => 'zaken', 'sourceId' => 'src-1'], 'answers' => ['find.source' => 'null']],
			'notificaties-sends' => ['action' => ['kind' => 'notificaties', 'channel' => 'zaken', 'sourceId' => 'src-1', 'endpoint' => '/notificaties'], 'message' => ['eventId' => 'ev-1']],
			'notificaties-event-missing' => ['action' => ['kind' => 'notificaties', 'channel' => 'zaken', 'sourceId' => 'src-1'], 'message' => ['eventId' => 'ev-1'], 'answers' => ['find.event' => 'null']],
			'notificaties-500' => ['action' => ['kind' => 'notificaties', 'channel' => 'zaken', 'sourceId' => 'src-1'], 'message' => ['eventId' => 'ev-1'], 'answers' => ['call' => '500']],
			'notificaties-429' => ['action' => ['kind' => 'notificaties', 'channel' => 'zaken', 'sourceId' => 'src-1'], 'message' => ['eventId' => 'ev-1'], 'answers' => ['call' => '429']],
			'notificaties-call-throws' => ['action' => ['kind' => 'notificaties', 'channel' => 'zaken', 'sourceId' => 'src-1'], 'message' => ['eventId' => 'ev-1'], 'answers' => ['call' => 'throws']],
			'flow-no-id' => ['action' => ['kind' => 'flow']],
			'flow-missing' => ['action' => ['kind' => 'flow', 'flowId' => 'f-1'], 'answers' => ['findFlow' => 'throws']],
			'flow-runs' => ['action' => ['kind' => 'flow', 'flowId' => 'f-1'], 'message' => ['eventId' => 'ev-1']],
			'flow-run-fails' => ['action' => ['kind' => 'flow', 'flowId' => 'f-1'], 'answers' => ['run' => 'throws']],
			'flow-run-failed-status' => ['action' => ['kind' => 'flow', 'flowId' => 'f-1'], 'answers' => ['run' => 'failed']],
			'mapping-no-ids' => ['action' => ['kind' => 'mapping']],
			'broker-no-id' => ['action' => ['kind' => 'broker']],
			'broker-no-registry' => ['action' => ['kind' => 'broker', 'brokerId' => 'b-1', 'topic' => 't']],
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
		$this->case = $case;
		$this->log = [];
		$service = $this->service();

		$message = new ObjectEntity();
		$message->setUuid('msg-1');
		$message->setObject(array_merge(['status' => 'pending', 'subscription' => 'sub-1'], ($case['message'] ?? [])));

		$subscription = null;
		if (array_key_exists('subscription', $case) === false) {
			$subscription = new ObjectEntity();
			$subscription->setUuid('sub-1');
			$data = ['name' => 'sub', 'action' => ($case['action'] ?? null)];
			if (isset($case['retryPolicy']) === true) {
				$data['retryPolicy'] = $case['retryPolicy'];
			}

			$subscription->setObject($data);
		}

		$trace = new ExecutionTraceContext('golden');
		try {
			$method = new ReflectionMethod(EventService::class, 'attemptDeliveryDispatch');
			$outcome = ['return' => $method->invoke($service, $message, $subscription, $trace)];
		} catch (\Throwable $exception) {
			$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
		}

		return ['outcome' => $outcome, 'log' => $this->log];
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
		if (getenv('EVENT_ACTION_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
