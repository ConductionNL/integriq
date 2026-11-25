<?php

/**
 * Unit tests for an endpoint of targetType mcp, dispatched through EndpointService.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use GuzzleHttp\Psr7\Response as PsrResponse;
use OCA\Integriq\Rule\AvgBsnPolicyRule;
use OCA\Integriq\Rule\CompositeFanoutRule;
use OCA\Integriq\Rule\ReferenceNumberRule;
use OCA\Integriq\Service\ApprovalService;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\ConsumerScopeService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\Endpoint\McpProxyResponse;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\Helper\FlowToken;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\StorageService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * An mcp endpoint relays to its tool server; an api endpoint is unchanged; a refused caller reaches nothing.
 *
 * @spec openspec/changes/archive/2026-11-25-gateway-mcp-proxy/specs/endpoint-runtime/spec.md#requirement-an-outside-mcp-server-is-published-as-a-gateway-endpoint-req-mcpx-001
 */
class EndpointServiceMcpTargetTest extends TestCase {

	/**
	 * The CallService double.
	 *
	 * @var MockObject&CallService
	 */
	private $callService;

	/**
	 * The calls made, as [kind, endpoint, method].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string}>
	 */
	private array $calls = [];

	/**
	 * A CallService that records buffered calls and relays.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->calls = [];
		$this->callService = $this->createMock(CallService::class);
		$this->callService->method('renderEndpointPath')->willReturnCallback(
			static fn (string $endpoint, array $context = []): string => $endpoint
		);
		$this->callService->method('call')->willReturnCallback(
			function ($source, string $endpoint = '', string $method = 'GET') {
				$this->calls[] = ['call', $endpoint, $method];
				return ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 200, 'response' => ['body' => '{}', 'headers' => []]], 'call-1');
			}
		);
		$this->callService->method('relay')->willReturnCallback(
			function ($source, string $endpoint, string $method, array $config, $sink, callable $onHeaders) {
				$this->calls[] = ['relay', $endpoint, $method];
				$onHeaders(new PsrResponse(200, ['Content-Type' => 'application/json', 'Mcp-Session-Id' => 'sess-9']));
				$sink->write('{"jsonrpc":"2.0","id":1,"result":{}}');
				return ObjectServiceMockBuilder::objectEntity($this, ['statusCode' => 200], 'call-2');
			}
		);
	}//end setUp()

	/**
	 * An mcp endpoint answers with a relay that reaches the tool server only when sent.
	 *
	 * @return void
	 */
	public function testAnMcpEndpointRelaysToItsToolServer(): void {
		$response = $this->dispatch(targetType: 'mcp', ruleResult: []);

		$this->assertInstanceOf(McpProxyResponse::class, $response);
		$this->assertSame([], $this->calls);

		$headers = [];
		$output = $this->createMock(IOutput::class);
		$output->method('setHeader')->willReturnCallback(static function (string $header) use (&$headers): void {
			$headers[] = $header;
		});
		$response->callback($output);

		// The source location ends in a slash, so the path goes without one: https://tools.internal/mcp.
		$this->assertSame(expected: [['relay', 'mcp', 'POST']], actual: $this->calls);
		$this->assertContains('Mcp-Session-Id: sess-9', $headers);
	}//end testAnMcpEndpointRelaysToItsToolServer()

	/**
	 * An mcp endpoint published under its own path reaches the tool server's `upstreamPath`, as an api endpoint does.
	 *
	 * @return void
	 */
	public function testAnMcpEndpointReachesItsUpstreamPath(): void {
		$response = $this->dispatch(targetType: 'mcp', ruleResult: [], endpointExtra: ['endpoint' => 'tools/documents', 'upstreamPath' => 'mcp']);
		$response->callback($this->createMock(originalClassName: IOutput::class));

		$this->assertSame(expected: [['relay', 'mcp', 'POST']], actual: $this->calls);
	}//end testAnMcpEndpointReachesItsUpstreamPath()

	/**
	 * An api endpoint still takes the buffered path.
	 *
	 * @return void
	 */
	public function testAnApiEndpointIsUnchanged(): void {
		$response = $this->dispatch(targetType: 'api', ruleResult: []);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['call', '/mcp', 'POST']], $this->calls);
	}//end testAnApiEndpointIsUnchanged()

	/**
	 * A call without a key is refused before anything reaches the tool server.
	 *
	 * @return void
	 */
	public function testACallWithoutAKeyNeverReachesTheToolServer(): void {
		$response = $this->dispatch(targetType: 'mcp', ruleResult: new JSONResponse(['message' => 'Unauthorized', 'error' => 'No valid API key'], 401));

		$this->assertSame(401, $response->getStatus());
		$this->assertSame([], $this->calls);
	}//end testACallWithoutAKeyNeverReachesTheToolServer()

	/**
	 * Dispatch a POST to an endpoint of the given target type after the before rules.
	 *
	 * @param string             $targetType The endpoint's target type.
	 * @param array|JSONResponse $ruleResult What the before rules answered.
	 * @param array              $endpointExtra More endpoint fields.
	 *
	 * @return Response The answer.
	 */
	private function dispatch(string $targetType, array|JSONResponse $ruleResult, array $endpointExtra = []): Response {
		$endpoint = ObjectServiceMockBuilder::objectEntity(
			$this,
			['targetType' => $targetType, 'targetId' => 'src-tools', 'endpoint' => '/mcp'] + $endpointExtra,
			'endpoint-mcp'
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getId')->willReturn('req-1');
		$request->method('getMethod')->willReturn('POST');
		$request->method('getParams')->willReturn([]);
		$request->method('getHeader')->willReturn('');
		$request->method('getRemoteAddress')->willReturn('192.0.2.10');
		$flowToken = new FlowToken(
			requestOriginal: ['method' => 'POST', 'parameters' => [], 'headers' => [], 'path' => '/mcp'],
			responseOriginal: ['data' => [], 'headers' => [], 'status' => 200, 'cookies' => []]
		);

		$method = new ReflectionMethod(EndpointService::class, 'dispatchAfterBeforeRules');

		return $method->invoke($this->service(), $endpoint, $request, 'mcp', $flowToken, $ruleResult, false, null, false, []);
	}//end dispatch()

	private function service(): EndpointService {
		$logger = $this->createMock(LoggerInterface::class);

		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->method('findAll')->willReturn(['results' => []]);
		$orObjectService->method('find')->willReturn(
			ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://tools.internal/'], 'src-tools')
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://nc.example/');

		return new EndpointService(
			$this->createMock(ObjectService::class),
			$this->callService,
			$logger,
			$urlGenerator,
			$this->createMock(MappingService::class),
			$orObjectService,
			$this->createMock(IConfig::class),
			$this->createMock(StorageService::class),
			$this->createMock(OpenRegisterCredentialBridge::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(SynchronizationService::class),
			$this->createMock(RuleService::class),
			new WebhookSignatureService($logger),
			$this->createMock(InboundRateLimitService::class),
			new CompositeFanoutRule($orObjectService, $logger),
			new ReferenceNumberRule(),
			new AvgBsnPolicyRule(),
			$this->createMock(ApprovalService::class),
			$this->createMock(IRequestId::class),
			$this->createMock(FlowRunnerService::class),
			$this->createMock(ConsumerScopeService::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(FileService::class),
		);

	}//end service()
}//end class
