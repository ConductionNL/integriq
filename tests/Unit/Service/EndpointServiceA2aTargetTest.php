<?php

/**
 * Unit tests for the `a2a` endpoint target.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/gateway-a2a-agent-card/specs/endpoint-runtime/spec.md#requirement-an-a2a-agents-card-points-at-the-gateway-req-a2a-001
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
 * An `a2a` endpoint proxies like an `api` endpoint, answers its agent card
 * pointing at the gateway, and a refused call never reaches the agent.
 *
 * @covers \OCA\Integriq\Service\EndpointService
 * @uses   \OCA\Integriq\Service\A2aAgentCardRewriter
 * @uses   \OCA\Integriq\Service\EndpointProxiedAnswer
 * @uses   \OCA\Integriq\Service\Helper\FlowToken
 * @uses   \OCA\Integriq\Service\WebhookSignatureService
 * @uses   \OCA\Integriq\Rule\CompositeFanoutRule
 *
 * @spec openspec/changes/gateway-a2a-agent-card/specs/endpoint-runtime/spec.md#requirement-an-a2a-agents-card-points-at-the-gateway-req-a2a-001
 */
class EndpointServiceA2aTargetTest extends TestCase {

	/**
	 * The call service double.
	 *
	 * @var CallService|MockObject
	 */
	private $callService;

	/**
	 * Every [path, method] the call service was asked to call.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private array $calls = [];

	/**
	 * Set up the call service double.
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
				$this->calls[] = [$endpoint, $method];
				$body = '{"name":"Weeragent","url":"https://agent.internal/a2a/v1","provider":{"url":"https://gemeente.example"}}';

				return ObjectServiceMockBuilder::objectEntity(
					$this,
					['statusCode' => 200, 'response' => ['body' => $body, 'headers' => ['content-type' => 'application/json'], 'remoteIp' => '10.0.0.5']],
					'call-1'
				);
			}
		);

	}//end setUp()

	/**
	 * Another agent that fetches the card through the endpoint reads the
	 * gateway's address, not the agent's own.
	 *
	 * @return void
	 */
	public function testTheCardFetchedThroughTheEndpointNamesTheGateway(): void {
		$response = $this->dispatch(targetType: 'a2a', path: 'agents/weer/.well-known/agent-card.json', ruleResult: []);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([['/agents/weer', 'GET']], $this->calls);
		$card = json_decode($response->getData()['body'], true);
		$this->assertSame('https://nc.example/apps/integriq/api/endpoint/agents/weer/a2a/v1', $card['url']);
		$this->assertSame('https://gemeente.example', $card['provider']['url']);
		$this->assertArrayNotHasKey('remoteIp', $response->getData());

	}//end testTheCardFetchedThroughTheEndpointNamesTheGateway()

	/**
	 * A call to the agent that is not the card is handed on as it came.
	 *
	 * @return void
	 */
	public function testAnotherAgentCallIsHandedOnUnchanged(): void {
		$response = $this->dispatch(targetType: 'a2a', path: 'agents/weer/a2a/v1', ruleResult: []);

		$this->assertSame('https://agent.internal/a2a/v1', json_decode($response->getData()['body'], true)['url']);

	}//end testAnotherAgentCallIsHandedOnUnchanged()

	/**
	 * An `api` endpoint never rewrites a body, even at a card path.
	 *
	 * @return void
	 */
	public function testAnApiEndpointDoesNotRewriteTheCard(): void {
		$response = $this->dispatch(targetType: 'api', path: 'agents/weer/.well-known/agent-card.json', ruleResult: []);

		$this->assertSame('https://agent.internal/a2a/v1', json_decode($response->getData()['body'], true)['url']);

	}//end testAnApiEndpointDoesNotRewriteTheCard()

	/**
	 * A call the authentication rule refused is answered 401 and never
	 * reaches the agent.
	 *
	 * @return void
	 */
	public function testACallWithoutAKeyIsRefusedAndNeverReachesTheAgent(): void {
		$response = $this->dispatch(
			targetType: 'a2a',
			path: 'agents/weer/.well-known/agent-card.json',
			ruleResult: new JSONResponse(['message' => 'Unauthorized', 'error' => 'No valid API key'], 401)
		);

		$this->assertSame(401, $response->getStatus());
		$this->assertSame('No valid API key', $response->getData()['detail']);
		$this->assertSame([], $this->calls);

	}//end testACallWithoutAKeyIsRefusedAndNeverReachesTheAgent()

	/**
	 * Dispatch one call past the `before` rules.
	 *
	 * @param string             $targetType The endpoint's target type.
	 * @param string             $path       The path the endpoint was called on.
	 * @param array|JSONResponse $ruleResult What the `before` rules answered.
	 *
	 * @return Response The answer.
	 */
	private function dispatch(string $targetType, string $path, array|JSONResponse $ruleResult): Response {
		$endpoint = ObjectServiceMockBuilder::objectEntity(
			$this,
			['targetType' => $targetType, 'targetId' => 'src-agent', 'endpoint' => '/agents/weer'],
			'endpoint-1'
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getId')->willReturn('req-1');
		$request->method('getMethod')->willReturn('GET');
		$request->method('getParams')->willReturn([]);
		$flowToken = new FlowToken(
			requestOriginal: ['method' => 'GET', 'parameters' => [], 'headers' => [], 'path' => '/' . $path],
			responseOriginal: ['data' => [], 'headers' => [], 'status' => 200, 'cookies' => []]
		);

		$method = new ReflectionMethod(EndpointService::class, 'dispatchAfterBeforeRules');

		return $method->invoke($this->service(), $endpoint, $request, $path, $flowToken, $ruleResult, false, null, false, []);

	}//end dispatch()

	/**
	 * The engine over doubles, with a source holding the agent.
	 *
	 * @return EndpointService
	 */
	private function service(): EndpointService {
		$logger = $this->createMock(LoggerInterface::class);

		$orObjectService = $this->createMock(ORObjectService::class);
		$orObjectService->method('findAll')->willReturn(['results' => []]);
		$orObjectService->method('find')->willReturn(
			ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://agent.internal/'], 'src-agent')
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
