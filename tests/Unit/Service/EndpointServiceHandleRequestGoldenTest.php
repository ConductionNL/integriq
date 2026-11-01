<?php

/**
 * Golden characterization of EndpointService::handleRequest() up to dispatch.
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
use OCA\Integriq\Service\ExecutionTraceService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\Helper\ExecutionTraceContext;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\MessageValidation\EndpointMessageGate;
use OCA\Integriq\Service\MessageValidation\JsonSchemaChecker;
use OCA\Integriq\Service\MessageValidation\OpenApiChecker;
use OCA\Integriq\Service\MessageValidation\XsdChecker;
use OCA\Integriq\Service\MessageValidationService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\StorageService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\Mapping;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService as ORFileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles requests through the endpoint conditions, the request message
 * check, the before-rules (whose conditions read every part of the rule data
 * envelope) and the dispatch, and records every rule, mapping, call, trace
 * and log against a golden file. Regenerate it on the code it must describe
 * with ENDPOINT_HANDLE_GOLDEN=1.
 *
 * @covers \OCA\Integriq\Service\EndpointService
 */
class EndpointServiceHandleRequestGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/endpoint/handle-request.json';

	private const SCHEMA_UUID = 'b7f1c2d3-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

	/**
	 * Everything the request read, ran, called, persisted and logged, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $trace = [];

	/**
	 * The rules a find() answers with, by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function rules(): array {
		$rule = static fn (string $name, array $conditions, int $order, string $timing = 'before'): array => [
			'name' => $name,
			'type' => 'mapping',
			'order' => $order,
			'timing' => $timing,
			'conditions' => $conditions,
			'configuration' => ['mapping' => 'mapping-' . $name],
		];

		return [
			'r-path-param' => $rule('path-param', ['==' => [['var' => 'parameters.id'], '7']], 1),
			'r-query-param' => $rule('query-param', ['==' => [['var' => 'parameters.q'], 'zoek']], 2),
			'r-query-wins' => $rule('query-wins', ['==' => [['var' => 'parameters.id'], 'from-query']], 3),
			'r-header' => $rule('header', ['==' => [['var' => 'headers.x_golden'], 'yes']], 4),
			'r-path' => $rule('path', ['==' => [['var' => 'path'], 'personen/7']], 5),
			'r-method' => $rule('method', ['==' => [['var' => 'method'], 'POST']], 6),
			'r-utility' => $rule('utility', ['!!' => [['var' => 'utility.currentDate']]], 7),
			'r-body-method' => $rule('body-method', ['==' => [['var' => 'body._method'], 'POST']], 8),
			'r-body-path' => $rule('body-path', ['==' => [['var' => 'body._path'], 'personen/7']], 9),
			'r-body-param' => $rule('body-param', ['==' => [['var' => 'body.q'], 'zoek']], 10),
			'r-body-params' => $rule('body-params', ['==' => [['var' => 'body._parameters.q'], 'zoek']], 11),
			'r-body-header' => $rule('body-header', ['==' => [['var' => 'body._headers.x_golden'], 'yes']], 12),
			'r-body-utility' => $rule('body-utility', ['!!' => [['var' => 'body._utility.currentDate']]], 13),
			'r-body-no-path-param' => $rule('body-no-path-param', ['==' => [['var' => 'body._parameters.id'], 'from-query']], 14),
			'r-after' => $rule('after', [], 15, 'after'),
		];

	}//end rules()

	/**
	 * The requests, by case name: endpoint data overrides, raw body, query parameters, headers, path.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string, 2: array, 3: array<string, string>, 4: string}>
	 */
	private function cases(): array {
		$headers = ['HTTP_X_GOLDEN' => 'yes'];
		$query = ['q' => 'zoek'];

		return [
			'conditions-refuse' => [['conditions' => ['if' => [['==' => [['var' => 'parameters.q'], 'zoek']], ['q may not be zoek'], []]]], '{"bsn":"1"}', $query, $headers, 'personen/7'],
			'conditions-pass' => [['conditions' => ['if' => [['==' => [['var' => 'parameters.q'], 'zoek']], [], ['q must be zoek']]]], '{"bsn":"1"}', $query, $headers, 'personen/7'],
			'message-refused' => [['validation' => ['mode' => 'refuse', 'request' => ['messageSchema' => self::SCHEMA_UUID]]], '{"naam":"Jansen"}', $query, $headers, 'personen/7'],
			'message-recorded' => [['validation' => ['mode' => 'record', 'request' => ['messageSchema' => self::SCHEMA_UUID]]], '{"naam":"Jansen"}', $query, $headers, 'personen/7'],
			'rules-see-the-envelope' => [['rules' => array_keys($this->rules())], '{"bsn":"1"}', $query, $headers, 'personen/7'],
			'rules-path-param-only' => [['rules' => array_keys($this->rules())], '{"bsn":"1"}', [], [], 'personen/7'],
			'rules-query-id' => [['rules' => array_keys($this->rules())], '{"bsn":"1"}', ['id' => 'from-query', 'q' => 'zoek'], $headers, 'personen/7'],
			'rules-short-path' => [['rules' => array_keys($this->rules())], '{"bsn":"1"}', $query, $headers, 'personen'],
			'no-rules' => [[], '{"bsn":"1"}', $query, $headers, 'personen/7'],
			'traceparent' => [[], '{"bsn":"1"}', [], ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'], 'personen/7'],
			'unknown-target-fails' => [['targetType' => 'nowhere'], '{"bsn":"1"}', $query, $headers, 'personen/7'],
		];

	}//end cases()

	/**
	 * Build the service for one request.
	 *
	 * @param string $rawBody The raw request body.
	 *
	 * @return EndpointService
	 */
	private function service(string $rawBody): EndpointService {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error', 'debug', 'notice'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context = []) use ($level): void {
					unset($context['exception']);
					$this->trace[] = ['log' => $level, 'message' => (string)$message, 'context' => $context];
				}
			);
		}

		$objects = ObjectServiceMockBuilder::make($this);
		$objects->method('find')->willReturnCallback(
			function ($id, ?string $register = null, ?string $schema = null): ?ObjectEntity {
				$this->trace[] = ['find' => $schema, 'id' => $id];
				return match ($schema) {
					'message_schema' => ObjectServiceMockBuilder::objectEntity($this, ['name' => 'Person', 'kind' => 'json-schema', 'version' => '1', 'document' => '{"type":"object","required":["bsn"],"properties":{"bsn":{"type":"string"}}}'], self::SCHEMA_UUID),
					'source' => ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://bron.example.nl', 'isEnabled' => true], 'source-1'),
					'rule' => ObjectServiceMockBuilder::objectEntity($this, $this->rules()[$id], (string)$id),
					default => null,
				};
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null, ?string $uuid = null): ObjectEntity {
				$this->trace[] = ['save' => $schema, 'uuid' => $uuid, 'object' => $object];
				return new ObjectEntity();
			}
		);

		$calls = $this->createMock(CallService::class);
		$calls->method('renderEndpointPath')->willReturn('/personen');
		$calls->method('call')->willReturnCallback(
			function (...$args): ObjectEntity {
				unset($args['source'], $args[0]);
				$this->trace[] = ['call' => $args];
				return ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 200, 'direction' => 'outbound', 'response' => ['statusCode' => 200, 'body' => '{"bsn":"1"}']], 'call-log-1');
			}
		);

		$mappings = $this->createMock(MappingService::class);
		$mappings->method('getMapping')->willReturnCallback(
			function ($mappingId): Mapping {
				$this->trace[] = ['mapping' => $mappingId];
				return $this->createMock(Mapping::class);
			}
		);
		$mappings->method('executeMapping')->willReturnCallback(
			fn ($mapping, $input) => $input
		);

		$consumerScope = $this->createMock(ConsumerScopeService::class);
		$consumerScope->method('isAllowed')->willReturn(true);

		$traces = $this->createMock(ExecutionTraceService::class);
		$traces->method('persist')->willReturnCallback(
			function (ExecutionTraceContext $trace, string $status, ?array $error = null, bool $resume = false): ObjectEntity {
				$this->trace[] = [
					'persistTrace' => $status,
					'error' => $error,
					'resume' => $resume,
					'entryPoint' => $trace->getEntryPoint(),
					'entryPointId' => $trace->getEntryPointId(),
					'triggeredBy' => $trace->getTriggeredBy(),
					'inboundOtelTraceId' => $trace->getInboundOtelTraceId(),
					'parentSpanId' => $trace->getParentSpanId(),
					'steps' => array_map(static fn (array $step): array => array_intersect_key($step, array_flip(['type', 'name', 'status', 'timing'])), $trace->getSteps()),
				];
				return new ObjectEntity();
			}
		);

		$json = new JsonSchemaChecker();
		$gate = new EndpointMessageGate(new MessageValidationService($json, new XsdChecker(), new OpenApiChecker($json)), $objects, $logger);

		$service = $this->getMockBuilder(EndpointService::class)
			->setConstructorArgs(
				[
					$this->createMock(ObjectService::class),
					$calls,
					$logger,
					$this->createMock(IURLGenerator::class),
					$mappings,
					$objects,
					$this->createMock(IConfig::class),
					$this->createMock(StorageService::class),
					$this->createMock(OpenRegisterCredentialBridge::class),
					$this->createMock(ContainerInterface::class),
					$this->createMock(SynchronizationService::class),
					$this->createMock(RuleService::class),
					new WebhookSignatureService($logger),
					$this->createMock(InboundRateLimitService::class),
					new CompositeFanoutRule($objects, $logger),
					new ReferenceNumberRule(),
					new AvgBsnPolicyRule(),
					$this->createMock(ApprovalService::class),
					$this->createMock(IRequestId::class),
					$this->createMock(FlowRunnerService::class),
					$consumerScope,
					$this->createMock(SchemaMapper::class),
					$this->createMock(ORFileService::class),
					$traces,
					null,
					$gate,
				]
			)
			->onlyMethods(['getRawContent'])
			->getMock();
		$service->method('getRawContent')->willReturn($rawBody);

		return $service;

	}//end service()

	/**
	 * A request mock.
	 *
	 * @param array<string, mixed>  $params  The query parameters.
	 * @param array<string, string> $headers Header values by name.
	 *
	 * @return IRequest
	 */
	private function request(array $params, array $headers): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('POST');
		$request->method('getParams')->willReturn($params);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => ($headers[strtolower($name)] ?? '')
		);

		return $request;

	}//end request()

	/**
	 * Every request matches the golden file.
	 *
	 * @return void
	 */
	public function testEveryRequestMatchesTheGoldenFile(): void {
		$server = $_SERVER;
		$actual = [];
		foreach ($this->cases() as $name => [$overrides, $rawBody, $params, $headers, $path]) {
			$this->trace = [];
			$_SERVER = $server;
			foreach ($headers as $header => $value) {
				if (str_starts_with($header, 'HTTP_') === true) {
					$_SERVER[$header] = $value;
				}
			}

			$endpoint = ObjectServiceMockBuilder::objectEntity(
				$this,
				array_merge(
					[
						'name' => 'Personen',
						'method' => 'POST',
						'endpoint' => '/personen/{{id}}',
						'endpointArray' => ['personen', '{{id}}'],
						'targetType' => 'api',
						'targetId' => 'source-1',
					],
					$overrides
				),
				'endpoint-1'
			);

			try {
				$response = $this->service($rawBody)->handleRequest($endpoint, $this->request($params, $headers), $path);
				$outcome = ['class' => get_class($response), 'status' => $response->getStatus()];
				if ($response instanceof JSONResponse) {
					$outcome['data'] = $response->getData();
				}
			} catch (\Throwable $exception) {
				$outcome = ['throws' => get_class($exception), 'message' => $exception->getMessage()];
			}

			$actual[$name] = ['trace' => $this->trace, 'outcome' => $outcome];
		}

		$_SERVER = $server;

		$encoded = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)) . "\n";
		if (getenv('ENDPOINT_HANDLE_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $encoded);
		}

		$this->assertSame((string)file_get_contents(self::GOLDEN), $encoded);

	}//end testEveryRequestMatchesTheGoldenFile()
}//end class
