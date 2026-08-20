<?php

/**
 * Unit tests for api-product-gateway's per-tier rate-limit resolution,
 * subscription-gate 403, and RFC 8594 deprecation headers on EndpointService.
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
 * @spec openspec/specs/api-product-gateway/spec.md#requirement-per-tier-rate-limit-enforcement-extends-the-inbound-rate-limiter-req-apg-005
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
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Service\RateLimit\InboundRateLimitService;
use OCA\OpenConnector\Service\RateLimit\RateLimitDecision;
use OCA\OpenConnector\Service\RuleService;
use OCA\OpenConnector\Service\StorageService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Service\WebhookSignatureService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
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
 * Tests for EndpointService's api-product-gateway extensions: tier-policy
 * resolution ahead of InboundRateLimitService::enforce(), the subscription
 * 403 gate, and RFC 8594 Sunset/Deprecation header emission.
 *
 * @spec openspec/specs/api-product-gateway/spec.md
 */
class EndpointServiceTierPolicyTest extends TestCase {

	/**
	 * @var EndpointService
	 */
	private EndpointService $service;

	/**
	 * @var ORObjectService|MockObject
	 */
	private $orObjectService;

	/**
	 * @var AuthorizationService|MockObject
	 */
	private $authorizationService;

	/**
	 * @var InboundRateLimitService|MockObject
	 */
	private $rateLimitService;

	/**
	 * Set up an EndpointService with independently-controllable
	 * orObjectService / authorizationService / rateLimitService mocks (the
	 * shared EndpointServiceTest does not expose these, so this suite owns
	 * its own wiring).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->orObjectService = ObjectServiceMockBuilder::make($this);
		$this->authorizationService = $this->createMock(AuthorizationService::class);
		$this->rateLimitService = $this->createMock(InboundRateLimitService::class);

		$objectService = $this->createMock(ObjectService::class);
		$callService = $this->createMock(CallService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://example.org');
		$mappingService = $this->createMock(MappingService::class);
		$config = $this->createMock(IConfig::class);
		$storageService = $this->createMock(StorageService::class);
		$container = $this->createMock(ContainerInterface::class);
		$syncService = $this->createMock(SynchronizationService::class);
		$ruleService = $this->createMock(RuleService::class);
		$signatureService = new WebhookSignatureService($logger);
		$compositeFanoutRule = new CompositeFanoutRule($this->orObjectService, $logger);
		$referenceNumberRule = new ReferenceNumberRule();
		$avgBsnPolicyRule = new AvgBsnPolicyRule();
		$approvalService = $this->createMock(ApprovalService::class);
		$requestId = $this->createMock(IRequestId::class);
		$flowRunnerService = $this->createMock(FlowRunnerService::class);

		// These tests assert tier-policy rate limiting, not source scope
		// (REQ-CON-SCOPE-001) — default to "allowed" so the scope gate, which
		// runs first, does not 403 them. A bare mock would return false.
		$consumerScopeService = $this->createMock(ConsumerScopeService::class);
		$consumerScopeService->method('isAllowed')->willReturn(true);

		$this->service = new EndpointService(
			$objectService,
			$callService,
			$logger,
			$urlGenerator,
			$mappingService,
			$this->orObjectService,
			$config,
			$storageService,
			$this->authorizationService,
			$container,
			$syncService,
			$ruleService,
			$signatureService,
			$this->rateLimitService,
			$compositeFanoutRule,
			$referenceNumberRule,
			$avgBsnPolicyRule,
			$approvalService,
			$requestId,
			$flowRunnerService,
			$consumerScopeService,
			$this->createMock(\OCA\OpenRegister\Db\SchemaMapper::class),
			$this->createMock(\OCA\OpenRegister\Service\FileService::class),
		);
	}//end setUp()

	/**
	 * Configure orObjectService::findAll() to serve a single api_product
	 * (containing $endpointUuid) and, optionally, a matching active
	 * api_product_subscription.
	 *
	 * @param string $endpointUuid The endpoint uuid the product's `endpoints` array must contain.
	 * @param array $tiers The product's `tiers` map.
	 * @param ObjectEntity|null $subscription The active subscription to return, or null (no active subscription).
	 * @param string $productUuid The product's uuid.
	 *
	 * @return void
	 */
	private function withProductAndSubscription(
		string $endpointUuid,
		array $tiers,
		?ObjectEntity $subscription,
		string $productUuid = 'product-uuid-1',
	): void {
		$product = ObjectServiceMockBuilder::objectEntity(
			$this,
			['endpoints' => [$endpointUuid], 'tiers' => $tiers, 'status' => 'active'],
			$productUuid
		);

		$this->orObjectService->method('findAll')->willReturnCallback(
			function (array $config) use ($product, $subscription) {
				$schema = ($config['filters']['schema'] ?? null);

				if ($schema === 'api_product') {
					return ['results' => [$product]];
				}

				if ($schema === 'api_product_subscription') {
					return ['results' => ($subscription !== null ? [$subscription] : [])];
				}

				return ['results' => []];
			}
		);
	}//end withProductAndSubscription()

	/**
	 * @return ReflectionMethod A reflection handle onto EndpointService::enforceInboundRateLimit().
	 */
	private function enforceMethod(): ReflectionMethod {
		$method = new ReflectionMethod(EndpointService::class, 'enforceInboundRateLimit');
		$method->setAccessible(true);

		return $method;
	}//end enforceMethod()

	/**
	 * REQ-APG-005 scenario "over-tier request returns 429" — the tier's
	 * rateLimit is passed to InboundRateLimitService::enforce() (not the
	 * consumer's own), and a disallowed decision yields HTTP 429 with
	 * Retry-After.
	 *
	 * @return void
	 */
	public function testOverTierRequestReturns429WithRetryAfter(): void {
		$endpoint = ObjectServiceMockBuilder::objectEntity($this, ['method' => 'GET'], 'endpoint-uuid-1');
		$consumer = ObjectServiceMockBuilder::objectEntity(
			$this,
			['authorizationType' => 'apikey', 'rateLimit' => ['requestsPerWindow' => 1000, 'windowSeconds' => 60]],
			'consumer-uuid-1'
		);
		$subscription = ObjectServiceMockBuilder::objectEntity(
			$this,
			['product' => 'product-uuid-1', 'consumer' => 'consumer-uuid-1', 'tier' => 'free', 'status' => 'active'],
			'sub-uuid-1'
		);

		$this->withProductAndSubscription(
			endpointUuid: 'endpoint-uuid-1',
			tiers: ['free' => ['rateLimit' => ['requestsPerWindow' => 2, 'windowSeconds' => 60]]],
			subscription: $subscription
		);

		$this->authorizationService->method('getResolvedConsumer')->willReturn($consumer);

		$capturedKey = null;
		$this->rateLimitService->method('enforce')->willReturnCallback(
			function (string $consumerKey, ?array $rateLimit, ?array $quota) use (&$capturedKey) {
				$capturedKey = $consumerKey;
				// Assert the TIER's rateLimit reached enforce(), not the consumer's own 1000.
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

		$request = $this->createMock(IRequest::class);

		$response = $this->enforceMethod()->invoke($this->service, $request, $endpoint);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
		// Response::getHeaders() merges in a live \OC::$server-resolved
		// IRequest (unavailable in this standalone unit-test environment);
		// read the raw constructor-supplied headers via reflection instead.
		$this->assertArrayHasKey('Retry-After', $this->rawHeaders(response: $response));
		// Product-tier key is namespaced separately from a plain consumer key (Risk 3).
		$this->assertStringContainsString('product:product-uuid-1:consumer:consumer-uuid-1', (string)$capturedKey);
	}//end testOverTierRequestReturns429WithRetryAfter()

	/**
	 * Read a Response's raw, constructor-supplied `headers` property via
	 * reflection — {@see \OCP\AppFramework\Http\Response::getHeaders()}
	 * merges in a live `\OC::$server`-resolved `IRequest`, which is
	 * unavailable in this standalone unit-test environment.
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response to inspect.
	 *
	 * @return array<string, mixed>
	 */
	private function rawHeaders(\OCP\AppFramework\Http\Response $response): array {
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		$property->setAccessible(true);

		return $property->getValue($response);
	}//end rawHeaders()

	/**
	 * REQ-APG-004 / design.md Decision 2 — a product-attached endpoint with
	 * NO active subscription for the resolved consumer is rejected with
	 * HTTP 403, without ever calling InboundRateLimitService::enforce().
	 *
	 * @return void
	 */
	public function testNoActiveSubscriptionReturns403WithoutCallingRateLimiter(): void {
		$endpoint = ObjectServiceMockBuilder::objectEntity($this, ['method' => 'GET'], 'endpoint-uuid-1');
		$consumer = ObjectServiceMockBuilder::objectEntity(
			$this,
			['authorizationType' => 'apikey', 'rateLimit' => ['requestsPerWindow' => 1000, 'windowSeconds' => 60]],
			'consumer-uuid-1'
		);

		$this->withProductAndSubscription(
			endpointUuid: 'endpoint-uuid-1',
			tiers: ['free' => ['rateLimit' => ['requestsPerWindow' => 2, 'windowSeconds' => 60]]],
			subscription: null
		);

		$this->authorizationService->method('getResolvedConsumer')->willReturn($consumer);
		$this->rateLimitService->expects($this->never())->method('enforce');

		$request = $this->createMock(IRequest::class);

		$response = $this->enforceMethod()->invoke($this->service, $request, $endpoint);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testNoActiveSubscriptionReturns403WithoutCallingRateLimiter()

	/**
	 * REQ-APG-005 scenario "a non-product endpoint is unaffected" — when the
	 * endpoint belongs to no api_product, behaviour is byte-for-byte the
	 * pre-change Consumer-level path (REQ-CON-RL-002): the consumer's own
	 * rateLimit reaches enforce(), keyed plainly (no product: prefix).
	 *
	 * @return void
	 */
	public function testNonProductEndpointUsesConsumerLevelPolicyUnchanged(): void {
		$endpoint = ObjectServiceMockBuilder::objectEntity($this, ['method' => 'GET'], 'endpoint-uuid-not-in-any-product');
		$consumer = ObjectServiceMockBuilder::objectEntity(
			$this,
			['authorizationType' => 'apikey', 'rateLimit' => ['requestsPerWindow' => 1000, 'windowSeconds' => 60]],
			'consumer-uuid-1'
		);

		// No api_product references this endpoint uuid.
		$this->orObjectService->method('findAll')->willReturn(['results' => []]);
		$this->authorizationService->method('getResolvedConsumer')->willReturn($consumer);

		$capturedKey = null;
		$capturedRateLimit = null;
		$this->rateLimitService->method('enforce')->willReturnCallback(
			function (string $consumerKey, ?array $rateLimit, ?array $quota) use (&$capturedKey, &$capturedRateLimit) {
				$capturedKey = $consumerKey;
				$capturedRateLimit = $rateLimit;

				return new RateLimitDecision(allowed: true, hasRateLimit: true, limit: 1000, remaining: 999, resetSeconds: 60);
			}
		);

		$request = $this->createMock(IRequest::class);

		$response = $this->enforceMethod()->invoke($this->service, $request, $endpoint);

		$this->assertNull($response);
		$this->assertSame(1000, $capturedRateLimit['requestsPerWindow'] ?? null);
		$this->assertSame('consumer:consumer-uuid-1', $capturedKey);
	}//end testNonProductEndpointUsesConsumerLevelPolicyUnchanged()

	/**
	 * REQ-APG-006 — a deprecated product's endpoint response carries
	 * Deprecation: true and a correctly RFC-7231-formatted Sunset header.
	 *
	 * @return void
	 */
	public function testBuildDeprecationHeadersForDeprecatedProduct(): void {
		$product = ObjectServiceMockBuilder::objectEntity(
			$this,
			['status' => 'deprecated', 'sunsetDate' => '2026-10-01T00:00:00+00:00'],
			'product-uuid-1'
		);

		$headers = $this->service->buildDeprecationHeaders(product: $product);

		$this->assertSame('true', $headers['Deprecation']);
		$this->assertSame('Thu, 01 Oct 2026 00:00:00 GMT', $headers['Sunset']);
	}//end testBuildDeprecationHeadersForDeprecatedProduct()

	/**
	 * REQ-APG-006 — an active product's endpoint response carries neither
	 * header.
	 *
	 * @return void
	 */
	public function testBuildDeprecationHeadersEmptyForActiveProduct(): void {
		$product = ObjectServiceMockBuilder::objectEntity($this, ['status' => 'active'], 'product-uuid-1');

		$headers = $this->service->buildDeprecationHeaders(product: $product);

		$this->assertSame([], $headers);
	}//end testBuildDeprecationHeadersEmptyForActiveProduct()

	/**
	 * REQ-APG-001 — resolveProductForEndpoint() finds the api_product whose
	 * `endpoints` array contains the given endpoint's uuid.
	 *
	 * @return void
	 */
	public function testResolveProductForEndpointFindsContainingProduct(): void {
		$endpoint = ObjectServiceMockBuilder::objectEntity($this, [], 'endpoint-uuid-1');

		$this->withProductAndSubscription(
			endpointUuid: 'endpoint-uuid-1',
			tiers: [],
			subscription: null
		);

		$product = $this->service->resolveProductForEndpoint(endpoint: $endpoint);

		$this->assertNotNull($product);
		$this->assertSame('product-uuid-1', $product->getUuid());
	}//end testResolveProductForEndpointFindsContainingProduct()

	/**
	 * REQ-APG-001 — an endpoint not referenced by any api_product resolves
	 * to null.
	 *
	 * @return void
	 */
	public function testResolveProductForEndpointReturnsNullWhenUnreferenced(): void {
		$endpoint = ObjectServiceMockBuilder::objectEntity($this, [], 'endpoint-uuid-orphan');

		$this->orObjectService->method('findAll')->willReturn(['results' => []]);

		$product = $this->service->resolveProductForEndpoint(endpoint: $endpoint);

		$this->assertNull($product);
	}//end testResolveProductForEndpointReturnsNullWhenUnreferenced()

	/**
	 * REQ-EP-009 — a product-scoped request's inbound call_log row carries
	 * direction: inbound, the product/endpoint uuids, statusCode, and a
	 * responseTime derived from the given duration.
	 *
	 * @return void
	 */
	public function testRecordInboundCallLogPersistsRowWithProductAndEndpoint(): void {
		$endpoint = ObjectServiceMockBuilder::objectEntity($this, [], 'endpoint-uuid-1');
		$product = ObjectServiceMockBuilder::objectEntity($this, [], 'product-uuid-1');

		$captured = null;
		$this->orObjectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema) use (&$captured) {
				$captured = $object;
				return ObjectServiceMockBuilder::objectEntity($this, $object, 'call-log-1');
			}
		);

		$this->service->recordInboundCallLog(endpoint: $endpoint, product: $product, statusCode: 200, durationMs: 42.4);

		$this->assertSame('inbound', $captured['direction']);
		$this->assertSame('product-uuid-1', $captured['product']);
		$this->assertSame('endpoint-uuid-1', $captured['endpoint']);
		$this->assertSame(200, $captured['statusCode']);
		$this->assertSame(42, $captured['responseTime']);
	}//end testRecordInboundCallLogPersistsRowWithProductAndEndpoint()

	/**
	 * REQ-EP-009 — a call_log write failure never blocks the response
	 * (best-effort logging); recordInboundCallLog() must not throw.
	 *
	 * @return void
	 */
	public function testRecordInboundCallLogFailureNeverThrows(): void {
		$endpoint = ObjectServiceMockBuilder::objectEntity($this, [], 'endpoint-uuid-1');
		$product = ObjectServiceMockBuilder::objectEntity($this, [], 'product-uuid-1');

		$this->orObjectService->method('saveObject')->willThrowException(new \RuntimeException('OR unavailable'));

		$this->service->recordInboundCallLog(endpoint: $endpoint, product: $product, statusCode: 500, durationMs: 10.0);

		// No exception propagated — assertion is that execution reached here.
		$this->assertTrue(true);
	}//end testRecordInboundCallLogFailureNeverThrows()

}//end class
