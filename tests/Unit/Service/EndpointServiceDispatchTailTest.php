<?php

/**
 * Golden characterization of EndpointService::dispatchAfterBeforeRules().
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
 * @spec openspec/specs/endpoint-runtime/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Rule\AvgBsnPolicyRule;
use OCA\Integriq\Rule\CompositeFanoutRule;
use OCA\Integriq\Rule\ReferenceNumberRule;
use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\ConsumerScopeService;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\MessageValidation\EndpointMessageGate;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RateLimit\RateLimitDecision;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\StorageService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\Mapping as OrMapping;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use UnexpectedValueException;

/**
 * Every branch of dispatchAfterBeforeRules(), recorded against a golden file:
 * the answer (class, status, data, headers), the flow token's amended request
 * and every scope, rate-limit, mapper, gate and mapping call.
 *
 * The schema target's mapper answers through its validation handler, so the
 * answer is built here and the tail runs without a live server (a JSONResponse
 * built by the mapper itself would need one for its headers).
 *
 * Regenerate the golden file on the code it must describe with
 * DISPATCH_TAIL_GOLDEN=1.
 */
class EndpointServiceDispatchTailTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/endpoint/dispatch-after-before-rules.json';

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
	 * The engine over recording collaborators.
	 *
	 * @return EndpointService
	 */
	private function service(): EndpointService {
		$logger = $this->createMock(LoggerInterface::class);

		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->method('findAll')->willReturn(['results' => []]);
		$orObjectService->method('find')->willReturnCallback(
			function ($id) {
				return ObjectServiceMockBuilder::objectEntity($this, ($this->case['rules'][$id] ?? []), (string)$id);
			}
		);

		$bridge = $this->createMock(OpenRegisterCredentialBridge::class);
		$bridge->method('getResolvedConsumer')->willReturnCallback(
			function () {
				if (isset($this->case['consumer']) === false) {
					return null;
				}

				return ObjectServiceMockBuilder::objectEntity($this, $this->case['consumer'], 'consumer-1');
			}
		);

		$scope = $this->createMock(ConsumerScopeService::class);
		$scope->method('isAllowed')->willReturnCallback(
			function () {
				$this->log[] = ['scope.isAllowed'];
				return ($this->case['scopeAllowed'] ?? true);
			}
		);

		$rateLimit = $this->createMock(InboundRateLimitService::class);
		$rateLimit->method('enforce')->willReturnCallback(
			function (string $consumerKey, ?array $rateLimit, ?array $quota) {
				$this->log[] = ['rateLimit.enforce', $consumerKey, $rateLimit, $quota];
				return new RateLimitDecision(allowed: ($this->case['rateAllowed'] ?? true), hasRateLimit: true, limit: 5, remaining: 0, resetSeconds: 60, retryAfter: 60, reason: RateLimitDecision::REASON_RATE_LIMIT);
			}
		);

		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('getMapper')->willReturnCallback(
			function (?string $objectType = null, ?int $schema = null, ?int $register = null) {
				$this->log[] = ['objectService.getMapper', $objectType, $schema, $register];
				return $this->mapper();
			}
		);

		$callService = $this->createMock(CallService::class);
		$callService->method('renderEndpointPath')->willReturnCallback(
			function (string $endpoint) {
				$this->log[] = ['callService.renderEndpointPath', $endpoint];
				throw new UnexpectedValueException('refused');
			}
		);

		$mappingService = $this->createMock(MappingService::class);
		$mappingService->method('getMapping')->willReturnCallback(
			function (string $mappingId) {
				$this->log[] = ['mapping.getMapping', $mappingId];
				return $this->createMock(OrMapping::class);
			}
		);
		$mappingService->method('executeMapping')->willReturnCallback(
			function ($mapping, array $input) {
				$this->log[] = ['mapping.executeMapping', $input];
				return ['mapped' => $input];
			}
		);

		$gate = $this->createMock(EndpointMessageGate::class);
		$gate->method('record')->willReturnCallback(
			function ($callLog, array $findings, string $endpoint) {
				$this->log[] = ['gate.record', $callLog, $findings, $endpoint];
			}
		);

		return new EndpointService(
			$objectService,
			$callService,
			$logger,
			$this->createMock(IURLGenerator::class),
			$mappingService,
			$orObjectService,
			$this->createMock(IConfig::class),
			$this->createMock(StorageService::class),
			$bridge,
			$this->createMock(ContainerInterface::class),
			$this->createMock(SynchronizationService::class),
			$this->createMock(RuleService::class),
			new WebhookSignatureService($logger),
			$rateLimit,
			new CompositeFanoutRule($orObjectService, $logger),
			new ReferenceNumberRule(),
			new AvgBsnPolicyRule(),
			$this->createMock(ApprovalService::class),
			$this->createMock(IRequestId::class),
			$this->createMock(FlowRunnerService::class),
			$scope,
			$this->createMock(SchemaMapper::class),
			$this->createMock(FileService::class),
			null,
			null,
			$gate,
		);
	}//end service()

	/**
	 * A schema mapper whose every write fails validation, so its validation
	 * handler hands back the case's answer.
	 *
	 * @return object
	 */
	private function mapper(): object {
		$test = $this;
		return new class ($test) {
			/**
			 * Constructor.
			 *
			 * @param EndpointServiceDispatchTailTest $test The test that records the calls.
			 */
			public function __construct(private EndpointServiceDispatchTailTest $test) {
			}

			/**
			 * Record a create and fail it.
			 *
			 * @param array $object The object.
			 *
			 * @return never
			 */
			public function createFromArray(array $object) {
				$this->test->record(['mapper.createFromArray', $object]);
				throw new ValidationException('invalid');
			}

			/**
			 * Record an update and fail it.
			 *
			 * @param mixed $id      The id.
			 * @param array $object  The object.
			 * @param bool  $updated The updated flag.
			 * @param bool  $patch   Whether this is a patch.
			 *
			 * @return never
			 */
			public function updateFromArray($id, array $object, bool $updated, bool $patch) {
				$this->test->record(['mapper.updateFromArray', $id, $object, $patch]);
				throw new ValidationException('invalid');
			}

			/**
			 * Record a delete and fail it.
			 *
			 * @param array $object The object.
			 *
			 * @return never
			 */
			public function delete(array $object) {
				$this->test->record(['mapper.delete', $object]);
				throw new ValidationException('invalid');
			}

			/**
			 * The validation handler, answering with the case's schema answer.
			 *
			 * @return object
			 */
			public function getValidateHandler(): object {
				$test = $this->test;
				return new class ($test) {
					/**
					 * Constructor.
					 *
					 * @param EndpointServiceDispatchTailTest $test The test.
					 */
					public function __construct(private EndpointServiceDispatchTailTest $test) {
					}

					/**
					 * The case's schema answer.
					 *
					 * @param \Exception $exception The validation failure.
					 *
					 * @return JSONResponse
					 */
					public function handleValidationException(\Exception $exception): JSONResponse {
						return $this->test->schemaAnswer();
					}
				};
			}
		};
	}//end mapper()

	/**
	 * Record a collaborator call.
	 *
	 * @param array<int, mixed> $entry The call.
	 *
	 * @return void
	 */
	public function record(array $entry): void {
		$this->log[] = $entry;
	}//end record()

	/**
	 * The schema answer of the current case.
	 *
	 * @return JSONResponse
	 */
	public function schemaAnswer(): JSONResponse {
		$answer = ($this->case['schemaAnswer'] ?? ['data' => ['id' => 'o-1', 'naam' => 'x'], 'status' => 200]);
		return new JSONResponse($answer['data'], $answer['status'], ($answer['headers'] ?? []));
	}//end schemaAnswer()

	/**
	 * The cases.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		$schema = ['targetType' => 'register/schema', 'targetId' => '3/7', 'name' => 'Zaken'];
		$post = ['body' => ['_method' => 'POST', '_parameters' => ['naam' => 'x', '_route' => 'r'], '_headers' => ['h' => ['1']]]];
		$errorRule = fn (int $code, string $timing = 'after') => ['type' => 'error', 'timing' => $timing, 'name' => 'err', 'configuration' => ['error' => ['name' => 'Nope', 'message' => 'not allowed', 'code' => $code, 'includeJsonLogicResult' => false]]];

		return [
			'before-error-response' => ['endpoint' => $schema, 'ruleResult' => ['response' => ['data' => ['message' => 'm', 'error' => 'e'], 'status' => 401]]],
			'before-2xx-response' => ['endpoint' => $schema, 'ruleResult' => ['response' => ['data' => ['ok' => true], 'status' => 202]]],
			'scope-refused' => ['endpoint' => $schema, 'ruleResult' => $post, 'consumer' => ['authorizationType' => 'apiKey'], 'scopeAllowed' => false],
			'scope-refused-not-enforced' => ['endpoint' => $schema, 'ruleResult' => $post, 'consumer' => ['authorizationType' => 'apiKey'], 'scopeAllowed' => false, 'enforce' => false],
			'consumer-allowed-unlimited' => ['endpoint' => $schema, 'ruleResult' => $post, 'consumer' => ['authorizationType' => 'apiKey']],
			'anonymous-rate-limited' => ['endpoint' => ($schema + ['anonymousRateLimit' => ['requestsPerWindow' => 5, 'windowSeconds' => 60]]), 'ruleResult' => $post, 'rateAllowed' => false],
			'anonymous-rate-allowed' => ['endpoint' => ($schema + ['anonymousRateLimit' => ['requestsPerWindow' => 5, 'windowSeconds' => 60]]), 'ruleResult' => $post],
			'post-201' => ['endpoint' => $schema, 'ruleResult' => $post, 'schemaAnswer' => ['data' => ['id' => 'o-1'], 'status' => 201]],
			'post-200' => ['endpoint' => $schema, 'ruleResult' => $post],
			'post-202-refused' => ['endpoint' => $schema, 'ruleResult' => $post, 'schemaAnswer' => ['data' => ['id' => 'o-1'], 'status' => 202]],
			'post-400' => ['endpoint' => $schema, 'ruleResult' => $post, 'schemaAnswer' => ['data' => ['message' => 'bad', 'error' => 'invalid'], 'status' => 400]],
			'post-500' => ['endpoint' => $schema, 'ruleResult' => $post, 'schemaAnswer' => ['data' => ['message' => 'boom'], 'status' => 500]],
			'delete' => ['endpoint' => $schema, 'ruleResult' => ['body' => ['_method' => 'DELETE', '_parameters' => ['id' => 'o-9']]]],
			'patch' => ['endpoint' => $schema, 'ruleResult' => ['body' => ['_method' => 'PATCH', '_parameters' => ['id' => 'o-9', 'naam' => 'y']]]],
			'put' => ['endpoint' => $schema, 'ruleResult' => ['body' => ['_method' => 'PUT', '_parameters' => ['id' => 'o-9', 'naam' => 'y']]]],
			'head-unsupported' => ['endpoint' => $schema, 'ruleResult' => ['body' => ['_method' => 'HEAD', '_parameters' => []]]],
			'method-from-flow-token' => ['endpoint' => $schema, 'ruleResult' => ['body' => []], 'method' => 'DELETE', 'parameters' => ['id' => 'o-2']],
			'default-status-code' => ['endpoint' => ($schema + ['configurations' => ['defaultStatusCode' => 299]]), 'ruleResult' => $post],
			'default-status-code-string' => ['endpoint' => ($schema + ['configurations' => ['defaultStatusCode' => '207']]), 'ruleResult' => $post],
			'default-status-code-null' => ['endpoint' => ($schema + ['configurations' => ['defaultStatusCode' => null]]), 'ruleResult' => $post],
			'answer-headers' => ['endpoint' => $schema, 'ruleResult' => $post, 'responseHeaders' => ['X-Total' => '3']],
			'output-mapping-object' => ['endpoint' => ($schema + ['outputMapping' => 'map-1']), 'ruleResult' => $post],
			'output-mapping-list' => ['endpoint' => ($schema + ['outputMapping' => 'map-1']), 'ruleResult' => $post, 'responseData' => ['count' => 2, 'results' => [['a' => 1], 'skip', ['b' => 2]]]],
			'after-error-rule' => ['endpoint' => ($schema + ['rules' => ['r-1']]), 'rules' => ['r-1' => $errorRule(418)], 'ruleResult' => $post],
			'after-2xx-rule' => ['endpoint' => ($schema + ['rules' => ['r-1']]), 'rules' => ['r-1' => $errorRule(200)], 'ruleResult' => $post],
			'after-2xx-rule-on-failed-write' => ['endpoint' => ($schema + ['rules' => ['r-1']]), 'rules' => ['r-1' => $errorRule(201)], 'ruleResult' => $post, 'schemaAnswer' => ['data' => ['message' => 'bad'], 'status' => 400]],
			'before-rule-skipped-after' => ['endpoint' => ($schema + ['rules' => ['r-1']]), 'rules' => ['r-1' => $errorRule(418, 'before')], 'ruleResult' => $post],
			'findings-schema' => ['endpoint' => $schema, 'ruleResult' => $post, 'findings' => [['path' => 'naam', 'message' => 'required']]],
			'findings-api' => ['endpoint' => ['targetType' => 'api', 'targetId' => 'src-1', 'endpoint' => '/x/{id}'], 'ruleResult' => $post, 'findings' => [['path' => 'naam']]],
			'findings-no-target' => ['endpoint' => ['name' => 'none'], 'ruleResult' => $post, 'findings' => [['path' => 'naam']]],
			'api' => ['endpoint' => ['targetType' => 'api', 'targetId' => 'src-1', 'endpoint' => '/x'], 'ruleResult' => $post],
			'no-target' => ['endpoint' => ['targetType' => 'other'], 'ruleResult' => $post],
		];
	}//end cases()

	/**
	 * Describe a response: class, status, data and the headers it was given.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string, mixed>
	 */
	private function describe(Response $response): array {
		$headers = new ReflectionProperty(Response::class, 'headers');
		return [
			'class' => get_class($response),
			'status' => $response->getStatus(),
			'data' => (method_exists($response, 'getData') === true ? $response->getData() : null),
			'headers' => $headers->getValue($response),
		];
	}//end describe()

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

		$endpoint = ObjectServiceMockBuilder::objectEntity($this, $case['endpoint'], 'endpoint-1');
		$request = $this->createMock(IRequest::class);
		$request->method('getId')->willReturn('req-1');
		$request->method('getRemoteAddress')->willReturn('203.0.113.9');
		$request->method('getMethod')->willReturn('POST');

		$flowToken = new FlowToken(
			requestOriginal: ['method' => ($case['method'] ?? 'GET'), 'parameters' => ($case['parameters'] ?? ['q' => 1]), 'headers' => ['accept' => ['json']], 'path' => '/zaken'],
			responseOriginal: ['data' => ($case['responseData'] ?? ['id' => 'o-1', 'naam' => 'x']), 'headers' => ($case['responseHeaders'] ?? []), 'status' => 200, 'cookies' => []]
		);

		$ruleResult = $case['ruleResult'];
		if (isset($ruleResult['response']) === true) {
			$ruleResult = new JSONResponse($ruleResult['response']['data'], $ruleResult['response']['status']);
		}

		$method = new ReflectionMethod(EndpointService::class, 'dispatchAfterBeforeRules');
		try {
			$outcome = $this->describe(
				$method->invoke($service, $endpoint, $request, '/zaken/o-9', $flowToken, $ruleResult, ($case['enforce'] ?? true), null, false, ($case['findings'] ?? []))
			);
		} catch (\Throwable $exception) {
			$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
		}

		return ['outcome' => $outcome, 'request' => $flowToken->getRequestAmended(), 'log' => $this->log];
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
		if (getenv('DISPATCH_TAIL_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), json_decode($encoded, true));
	}//end testEveryCaseMatchesTheGoldenFile()
}//end class
