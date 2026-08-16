<?php

/**
 * Security unit tests for source-scope enforcement on the endpoint runtime.
 *
 * Proves the wiring the ConsumerScopeService unit tests cannot: that
 * EndpointService actually calls the scope check on the request path
 * (REQ-CON-SCOPE-001), returns 403 for an unlisted source, and — because the
 * `ips`/`domains` control had NO caller at all before this change — that the
 * enforcement point is not another orphaned capability.
 *
 * Also pins the rate-limit half of the audit: a consumer resolved via the
 * apiKey path IS throttled, so a caller cannot obtain an unlimited budget by
 * choosing apiKey auth over JWT.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/consumer-management/spec.md#requirement-consumer-source-scope-enforcement-req-con-scope-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Rule\AvgBsnPolicyRule;
use OCA\OpenConnector\Rule\CompositeFanoutRule;
use OCA\OpenConnector\Rule\ReferenceNumberRule;
use OCA\OpenConnector\Service\ApprovalService;
use OCA\OpenConnector\Service\AuthorizationService;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\ConsumerScopeService;
use OCA\OpenConnector\Service\EndpointService;
use OCA\OpenConnector\Service\FlowRunnerService;
use OCA\OpenConnector\Service\Helper\FlowToken;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\RateLimit\InboundRateLimitService;
use OCA\OpenConnector\Service\RateLimit\RateLimitDecision;
use OCA\OpenConnector\Service\RuleService;
use OCA\OpenConnector\Service\StorageService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
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
 * Source-scope enforcement on the endpoint runtime (REQ-CON-SCOPE-001).
 */
class EndpointServiceConsumerScopeTest extends TestCase {

	/**
	 * @var AuthorizationService|MockObject
	 */
	private $authorizationService;

	/**
	 * @var ConsumerScopeService|MockObject
	 */
	private $consumerScopeService;

	/**
	 * @var InboundRateLimitService|MockObject
	 */
	private $rateLimitService;

	/**
	 * @var ORObjectService|MockObject
	 */
	private $orObjectService;

	/**
	 * @var EndpointService
	 */
	private $service;

	/**
	 * Wire an EndpointService with the mocks these tests steer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$logger = $this->createMock(LoggerInterface::class);
		$this->orObjectService = $this->createMock(ORObjectService::class);
		$this->authorizationService = $this->createMock(AuthorizationService::class);
		$this->consumerScopeService = $this->createMock(ConsumerScopeService::class);
		$this->rateLimitService = $this->createMock(InboundRateLimitService::class);

		// No api_product attached to the endpoint in these tests — findAll()
		// returns nothing so the runtime falls through to the consumer-level path.
		$this->orObjectService->method('findAll')->willReturn(['results' => []]);

		$this->service = new EndpointService(
			$this->createMock(ObjectService::class),
			$this->createMock(CallService::class),
			$logger,
			$this->createMock(IURLGenerator::class),
			$this->createMock(MappingService::class),
			$this->orObjectService,
			$this->createMock(IConfig::class),
			$this->createMock(StorageService::class),
			$this->authorizationService,
			$this->createMock(ContainerInterface::class),
			$this->createMock(SynchronizationService::class),
			$this->createMock(RuleService::class),
			new WebhookSignatureService($logger),
			$this->rateLimitService,
			new CompositeFanoutRule($this->orObjectService, $logger),
			new ReferenceNumberRule(),
			new AvgBsnPolicyRule(),
			$this->createMock(ApprovalService::class),
			$this->createMock(IRequestId::class),
			$this->createMock(FlowRunnerService::class),
			$this->consumerScopeService,
			$this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class),
			$this->createMock(\OCA\OpenRegister\Service\FileService::class)
		);
	}//end setUp()

	/**
	 * @return ReflectionMethod A handle onto EndpointService::enforceConsumerScope().
	 */
	private function scopeMethod(): ReflectionMethod {
		$method = new ReflectionMethod(EndpointService::class, 'enforceConsumerScope');
		$method->setAccessible(true);

		return $method;
	}//end scopeMethod()

	/**
	 * BAD PATH: a source the scope service rejects yields HTTP 403 from the runtime.
	 *
	 * @return void
	 */
	public function testUnlistedSourceIsForbiddenByTheRuntime(): void {
		$consumer = ObjectServiceMockBuilder::objectEntity(
			$this,
			['authorizationType' => 'apiKey', 'ips' => ['203.0.113.4']],
			'consumer-uuid-1'
		);

		$this->authorizationService->method('getResolvedConsumer')->willReturn($consumer);
		$this->consumerScopeService->expects($this->once())
			->method('isAllowed')
			->willReturn(false);

		$response = $this->scopeMethod()->invoke($this->service, $this->createMock(IRequest::class));

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('source_not_allowed', $response->getData()['error']);
	}//end testUnlistedSourceIsForbiddenByTheRuntime()

	/**
	 * NO REGRESSION: a consumer the scope service allows passes through.
	 *
	 * @return void
	 */
	public function testAllowedSourceProceeds(): void {
		$consumer = ObjectServiceMockBuilder::objectEntity($this, ['authorizationType' => 'apiKey'], 'consumer-uuid-1');

		$this->authorizationService->method('getResolvedConsumer')->willReturn($consumer);
		$this->consumerScopeService->method('isAllowed')->willReturn(true);

		$this->assertNull(
			$this->scopeMethod()->invoke($this->service, $this->createMock(IRequest::class)),
			'An allowed source MUST NOT be blocked.'
		);
	}//end testAllowedSourceProceeds()

	/**
	 * A request that resolved no consumer has no allowlist to apply and proceeds
	 * without the scope service being consulted at all.
	 *
	 * @return void
	 */
	public function testNoResolvedConsumerSkipsScopeCheck(): void {
		$this->authorizationService->method('getResolvedConsumer')->willReturn(null);
		$this->consumerScopeService->expects($this->never())->method('isAllowed');

		$this->assertNull(
			$this->scopeMethod()->invoke($this->service, $this->createMock(IRequest::class))
		);
	}//end testNoResolvedConsumerSkipsScopeCheck()

	/**
	 * The scope gate is actually WIRED into the request path — it is reached
	 * from dispatchAfterBeforeRules(), not merely defined.
	 *
	 * Guards against the orphaned-capability defect this change exists to fix:
	 * `ips`/`domains` were fully specified and schema-advertised while no code
	 * path ever consulted them.
	 *
	 * @return void
	 */
	public function testScopeGateIsReachedFromTheDispatchPath(): void {
		$endpoint = ObjectServiceMockBuilder::objectEntity($this, ['method' => 'GET'], 'endpoint-uuid-1');
		$consumer = ObjectServiceMockBuilder::objectEntity(
			$this,
			['authorizationType' => 'apiKey', 'ips' => ['203.0.113.4']],
			'consumer-uuid-1'
		);

		$this->authorizationService->method('getResolvedConsumer')->willReturn($consumer);
		$this->consumerScopeService->expects($this->once())
			->method('isAllowed')
			->willReturn(false);

		// The scope gate runs BEFORE the rate limiter, so an out-of-scope
		// request must never reach (or spend budget in) the limiter.
		$this->rateLimitService->expects($this->never())->method('enforce');

		$dispatch = new ReflectionMethod(EndpointService::class, 'dispatchAfterBeforeRules');
		$dispatch->setAccessible(true);

		$response = $dispatch->invoke(
			$this->service,
			$endpoint,
			$this->createMock(IRequest::class),
			'/api/test',
			new FlowToken(),
			[],
			true
		);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$response->getStatus(),
			'The dispatch path MUST consult the source-scope gate — an unlisted source gets 403.'
		);
	}//end testScopeGateIsReachedFromTheDispatchPath()

	/**
	 * RATE-LIMIT HALF OF THE AUDIT: a consumer resolved via the apiKey path is
	 * throttled exactly like a JWT-resolved one.
	 *
	 * The rate limiter keys off the resolved consumer and is auth-type agnostic,
	 * so choosing apiKey auth does NOT yield an unlimited budget.
	 *
	 * @return void
	 */
	public function testApiKeyResolvedConsumerOverItsRateLimitIsThrottled(): void {
		$endpoint = ObjectServiceMockBuilder::objectEntity($this, ['method' => 'GET'], 'endpoint-uuid-1');
		$consumer = ObjectServiceMockBuilder::objectEntity(
			$this,
			[
				'authorizationType' => 'apiKey',
				'rateLimit' => ['requestsPerWindow' => 2, 'windowSeconds' => 60],
			],
			'consumer-uuid-1'
		);

		$this->authorizationService->method('getResolvedConsumer')->willReturn($consumer);

		$capturedKey = null;
		$this->rateLimitService->expects($this->once())
			->method('enforce')
			->willReturnCallback(
				function (string $consumerKey, ?array $rateLimit, ?array $quota) use (&$capturedKey) {
					$capturedKey = $consumerKey;
					TestCase::assertSame(2, $rateLimit['requestsPerWindow'] ?? null);

					return new RateLimitDecision(
						allowed: false,
						hasRateLimit: true,
						limit: 2,
						remaining: 0,
						resetSeconds: 30,
						retryAfter: 30,
						reason: RateLimitDecision::REASON_RATE_LIMIT
					);
				}
			);

		$method = new ReflectionMethod(EndpointService::class, 'enforceInboundRateLimit');
		$method->setAccessible(true);

		$response = $method->invoke($this->service, $this->createMock(IRequest::class), $endpoint);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(
			Http::STATUS_TOO_MANY_REQUESTS,
			$response->getStatus(),
			'An apiKey-resolved consumer over its rate limit MUST be throttled, not unlimited.'
		);
		$this->assertSame(
			'consumer:consumer-uuid-1',
			$capturedKey,
			'The limiter MUST key on the resolved consumer regardless of auth type.'
		);
	}//end testApiKeyResolvedConsumerOverItsRateLimitIsThrottled()
}//end class
