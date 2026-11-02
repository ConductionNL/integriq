<?php

/**
 * Golden characterization of CallService::call() and CallService::callAsync().
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
 * @spec openspec/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OCA\Integriq\Outbound\Call\BodyCapturePolicy;
use OCA\Integriq\Service\AuthenticationService;
use OCA\Integriq\Service\BrokeredCallService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\Security\SensitiveFieldRegistry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Twig\Loader\ArrayLoader;

/**
 * Every case of call() and callAsync() recorded against a golden file: the
 * HTTP request the client receives, every object saved, every log line, the
 * trace steps and the returned call log (or the exception), with clock values
 * and generated ids masked.
 *
 * Regenerate the golden file on the code it must describe with
 * CALL_DISPATCH_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\CallService
 * @uses   \OCA\Integriq\Service\Call\CallRequest
 */
class CallServiceDispatchGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/call/dispatch.json';

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
	 * Mask values that change between runs: clock readings, durations and generated ids.
	 *
	 * @param mixed $value The value to mask.
	 * @param string $key The key the value sits under.
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

		if (preg_match('/time|duration|expires|lastCall|created|updated|date|At$|Us$/i', $key) === 1 && $value !== null) {
			return '<' . gettype($value) . '>';
		}

		if (is_string($value) === true) {
			$value = preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[^"\s]*/', '<date>', $value);
			$value = preg_replace('/00-[0-9a-f]{32}-[0-9a-f]{16}-0[01]/', '<traceparent>', $value);
			$value = preg_replace('/[0-9a-f]{16}/', '<hex>', $value);
			$value = preg_replace('/(?!7d3f0c1e)[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '<uuid>', $value);
		}

		if (is_float($value) === true) {
			return '<float>';
		}

		return $value;
	}//end mask()

	/**
	 * The response a case answers with.
	 *
	 * @return Response
	 */
	private function response(): Response {
		return new Response(
			($this->case['status'] ?? 200),
			($this->case['headers'] ?? ['Content-Type' => 'application/json']),
			($this->case['body'] ?? '{"ok":true}')
		);
	}//end response()

	/**
	 * The service over recording collaborators.
	 *
	 * @return CallService
	 */
	private function service(): CallService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('saveObject')->willReturnCallback(
			function ($object = [], $register = null, $schema = null) {
				$this->log[] = ['or.saveObject', $register, $schema, $this->mask(value: $object)];
				$entity = new ObjectEntity();
				$entity->setUuid('saved-' . count($this->log));
				$entity->setObject(is_array($object) === true ? $object : []);
				return $entity;
			}
		);
		$objectService->method('saveObjects')->willReturnCallback(
			function (array $objects = []) {
				$this->log[] = ['or.saveObjects', $this->mask(value: $objects)];
				return [];
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('hasKey')->willReturn(false);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$appConfig->method('getValueBool')->willReturnArgument(2);

		$clock = $this->createMock(ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-10-12T12:00:00+00:00'));

		$brokered = $this->createMock(BrokeredCallService::class);
		$brokered->method('hasCredentialRef')->willReturn(false);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info', 'notice', 'critical'] as $level) {
			$logger->method($level)->willReturnCallback(
				function ($message) use ($level): void {
					$this->log[] = ['logger.' . $level, $this->mask(value: (string)$message)];
				}
			);
		}

		$service = new CallService(
			$objectService,
			new ArrayLoader([]),
			$this->createMock(AuthenticationService::class),
			$appConfig,
			$logger,
			$brokered,
			new SensitiveFieldRegistry(),
			new BodyCapturePolicy($appConfig, $clock, $this->createMock(IUserSession::class)),
		);

		$client = $this->createMock(Client::class);
		$client->method('request')->willReturnCallback(
			function ($method, $url, $options = []) {
				$this->log[] = ['client.request', $method, $url, $this->mask(value: $this->options(options: $options))];
				return $this->answer(method: $method, url: $url);
			}
		);
		$client->method('requestAsync')->willReturnCallback(
			function ($method, $url, $options = []) {
				$this->log[] = ['client.requestAsync', $method, $url, $this->mask(value: $this->options(options: $options))];
				try {
					return new FulfilledPromise($this->answer(method: $method, url: $url));
				} catch (\Throwable $exception) {
					return new RejectedPromise($exception);
				}
			}
		);
		(new ReflectionProperty(CallService::class, 'client'))->setValue($service, $client);

		return $service;
	}//end service()

	/**
	 * The request options as recorded: callables named, not dumped.
	 *
	 * @param array $options The Guzzle options.
	 *
	 * @return array
	 */
	private function options(array $options): array {
		foreach ($options as $name => $option) {
			if (is_callable($option) === true && is_string($option) === false) {
				$options[$name] = '<callable>';
			}
		}

		return $options;
	}//end options()

	/**
	 * The answer of the client for this case: a response, or a thrown transport failure.
	 *
	 * @param string $method The method.
	 * @param string $url The url.
	 *
	 * @return Response
	 */
	private function answer(string $method, string $url): Response {
		$failure = ($this->case['failure'] ?? null);
		if ($failure === 'connect') {
			throw new ConnectException('refused', new Request($method, $url));
		}

		if ($failure === 'client') {
			throw new ClientException('bad request', new Request($method, $url), new Response(422, [], '{"error":"invalid"}'));
		}

		if ($failure === 'other') {
			throw new \RuntimeException('transport broke');
		}

		return $this->response();
	}//end answer()

	/**
	 * The source a case calls.
	 *
	 * @return ObjectEntity
	 */
	private function source(): ObjectEntity {
		$source = new ObjectEntity();
		$source->setUuid('7d3f0c1e-5b2a-4c8d-9e6f-0a1b2c3d4e5f');
		$source->setObject(
			array_merge(
				[
					'name' => 'zaken-api',
					'isEnabled' => true,
					'location' => 'https://zaken.example.invalid/api',
					'configuration' => ['headers' => ['Accept' => 'application/json']],
				],
				($this->case['source'] ?? [])
			)
		);

		return $source;
	}//end source()

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		return [
			'get' => ['call' => ['endpoint' => '/zaken']],
			'post-json' => ['call' => ['endpoint' => '/zaken', 'method' => 'POST', 'config' => ['json' => ['a' => 1]]]],
			'read-uses-read-method' => ['call' => ['endpoint' => '/zaken/1', 'read' => true], 'source' => ['configuration' => ['readMethod' => 'PUT']]],
			'list-method-config' => ['call' => ['endpoint' => '/zaken', 'config' => ['listMethod' => 'POST', 'createMethod' => 'PATCH']]],
			'not-found' => ['call' => ['endpoint' => '/zaken/9'], 'status' => 404, 'body' => '{"detail":"nope"}'],
			'server-error' => ['call' => ['endpoint' => '/zaken'], 'status' => 500, 'body' => 'boom'],
			'client-exception' => ['call' => ['endpoint' => '/zaken'], 'failure' => 'client'],
			'connect-exception' => ['call' => ['endpoint' => '/zaken'], 'failure' => 'connect'],
			'other-exception' => ['call' => ['endpoint' => '/zaken'], 'failure' => 'other'],
			'disabled-source' => ['call' => ['endpoint' => '/zaken'], 'source' => ['isEnabled' => false]],
			'no-location' => ['call' => ['endpoint' => '/zaken'], 'source' => ['location' => '']],
			'no-persist' => ['call' => ['endpoint' => '/zaken', 'persistLog' => false]],
			'withhold-bodies' => ['call' => ['endpoint' => '/zaken', 'withholdBodies' => true, 'trace' => true]],
			'traced' => ['call' => ['endpoint' => '/zaken', 'trace' => true]],
			'traced-error' => ['call' => ['endpoint' => '/zaken', 'trace' => true], 'status' => 503],
			'traced-own-traceparent' => ['call' => ['endpoint' => '/zaken', 'trace' => true, 'config' => ['headers' => ['TraceParent' => 'mine']]]],
			'sink' => ['call' => ['endpoint' => '/file', 'sink' => '/tmp/never-written']],
			'support-request' => ['call' => ['endpoint' => '/zaken', 'runningSupportRequest' => true]],
			'pre-and-post-request' => ['call' => ['endpoint' => '/zaken', 'config' => ['preRequest' => ['endpoint' => '/login', 'config' => []], 'postRequest' => ['endpoint' => '/logout', 'config' => []]]]],
			'support-request-with-hooks' => ['call' => ['endpoint' => '/zaken', 'runningSupportRequest' => true, 'config' => ['preRequest' => ['endpoint' => '/login', 'config' => []], 'postRequest' => ['endpoint' => '/logout', 'config' => []]]]],
			'async-pre-and-post-request' => ['async' => true, 'call' => ['endpoint' => '/zaken', 'config' => ['preRequest' => ['endpoint' => '/login', 'config' => []], 'postRequest' => ['endpoint' => '/logout', 'config' => []]]]],
			'overrule-auth' => ['call' => ['endpoint' => '/zaken', 'overruleAuth' => true]],
			'retry-policy-status' => ['call' => ['endpoint' => '/zaken', 'config' => ['retryPolicy' => ['maxAttempts' => 1, 'retryableStatusCodes' => [503]]]], 'status' => 503],
			'logged-body' => ['call' => ['endpoint' => '/zaken'], 'source' => ['logBody' => true], 'body' => '{"secret":"x"}'],
			'async-get' => ['async' => true, 'call' => ['endpoint' => '/zaken']],
			'async-post' => ['async' => true, 'call' => ['endpoint' => '/zaken', 'method' => 'POST', 'config' => ['json' => ['b' => 2]]]],
			'async-not-found' => ['async' => true, 'call' => ['endpoint' => '/zaken/9'], 'status' => 404],
			'async-client-exception' => ['async' => true, 'call' => ['endpoint' => '/zaken'], 'failure' => 'client'],
			'async-connect-exception' => ['async' => true, 'call' => ['endpoint' => '/zaken'], 'failure' => 'connect'],
			'async-other-exception' => ['async' => true, 'call' => ['endpoint' => '/zaken'], 'failure' => 'other'],
			'async-disabled' => ['async' => true, 'call' => ['endpoint' => '/zaken'], 'source' => ['isEnabled' => false]],
			'async-no-persist' => ['async' => true, 'call' => ['endpoint' => '/zaken', 'persistLog' => false]],
			'async-traced' => ['async' => true, 'call' => ['endpoint' => '/zaken', 'trace' => true]],
			'async-sink-and-headers' => ['async' => true, 'call' => ['endpoint' => '/file', 'sink' => '/tmp/never-written', 'onHeaders' => true]],
			'async-support-request' => ['async' => true, 'call' => ['endpoint' => '/zaken', 'runningSupportRequest' => true, 'read' => true]],
			'async-resource-sink' => ['async' => true, 'call' => ['endpoint' => '/file', 'sink' => 'resource']],
			'sync-asked-async' => ['call' => ['endpoint' => '/zaken', 'asynchronous' => true]],
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

		$args = $case['call'];
		$trace = null;
		if (($args['trace'] ?? false) === true) {
			$trace = new ExecutionTraceContext('golden');
			$args['trace'] = $trace;
		}

		if (($args['onHeaders'] ?? false) === true) {
			$args['onHeaders'] = static function (): void {
			};
		}

		if (($args['sink'] ?? null) === 'resource') {
			$args['sink'] = fopen('php://memory', 'r+');
		}

		try {
			if (($case['async'] ?? false) === true) {
				$result = $service->callAsync(...array_merge(['source' => $this->source()], $args))->wait();
			} else {
				$result = $service->call(...array_merge(['source' => $this->source()], $args));
			}

			$outcome = ['return' => $this->mask(value: ['uuid' => $result->getUuid(), 'object' => $result->getObject()])];
		} catch (\Throwable $exception) {
			$outcome = ['throws' => get_class($exception), 'message' => $this->mask(value: $exception->getMessage())];
		}

		$steps = [];
		if ($trace !== null) {
			$steps = $this->mask(value: $trace->getSteps());
		}

		return ['outcome' => $outcome, 'steps' => $steps, 'log' => $this->log];
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
		if (getenv('CALL_DISPATCH_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
