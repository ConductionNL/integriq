<?php

/**
 * EndpointService response cache tests.
 *
 * A source endpoint with its cache on answers a repeated question from the
 * cache: the source is called once, the second answer carries `X-Cache: HIT`
 * and the call log marks the hit. An upstream answer marked `private` is
 * never kept, an endpoint without a cache never says HIT, and a clear makes
 * the next call reach the source again.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/changes/gateway-response-cache-and-problem-errors/specs/endpoint-runtime/spec.md#requirement-an-endpoint-can-cache-its-answers-req-rcpe-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Rule\AvgBsnPolicyRule;
use OCA\Integriq\Rule\CompositeFanoutRule;
use OCA\Integriq\Rule\ReferenceNumberRule;
use OCA\Integriq\Service\CallService;
use OCA\Integriq\Service\Consumer\OpenRegisterCredentialBridge;
use OCA\Integriq\Service\ConsumerScopeService;
use OCA\Integriq\Service\Endpoint\EndpointResponseCache;
use OCA\Integriq\Service\EndpointService;
use OCA\Integriq\Service\FlowRunnerService;
use OCA\Integriq\Service\MappingService;
use OCA\Integriq\Service\ObjectService;
use OCA\Integriq\Service\RateLimit\InboundRateLimitService;
use OCA\Integriq\Service\RuleService;
use OCA\Integriq\Service\StorageService;
use OCA\Integriq\Service\SynchronizationService;
use OCA\Integriq\Service\WebhookSignatureService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\FileService as ORFileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IRequestId;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The response cache on the source request path of EndpointService.
 *
 * @covers \OCA\Integriq\Service\EndpointService
 * @uses \OCA\Integriq\Service\Endpoint\EndpointResponseCache
 */
class EndpointServiceResponseCacheTest extends TestCase {

	/**
	 * The call engine double.
	 *
	 * @var CallService&MockObject
	 */
	private $callService;

	/**
	 * The OpenRegister object service double.
	 *
	 * @var ORObjectService&MockObject
	 */
	private $objects;

	/**
	 * The call logs written past the call engine.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $logs = [];

	/**
	 * What the distributed cache holds.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = [];

	/**
	 * The upstream headers each call answers with.
	 *
	 * @var array<string, mixed>
	 */
	private array $upstreamHeaders = ['Content-Type' => 'application/json'];

	/**
	 * The response cache.
	 *
	 * @var EndpointResponseCache
	 */
	private EndpointResponseCache $cache;

	/**
	 * The service under test.
	 *
	 * @var EndpointService
	 */
	private EndpointService $service;

	/**
	 * Build the service with an in-memory distributed cache.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logs = [];
		$this->stored = [];

		$source = ObjectServiceMockBuilder::objectEntity($this, ['location' => 'https://bag.example.org', 'isEnabled' => true], 'source-bag');
		$this->objects = ObjectServiceMockBuilder::make($this);
		$this->objects->method('find')->willReturn($source);
		$this->objects->method('saveObject')->willReturnCallback(
			function ($object, ?string $register = null, ?string $schema = null) use ($source): ObjectEntity {
				$this->logs[] = ['schema' => $schema, 'object' => $object];
				return $source;
			}
		);

		$this->callService = $this->createMock(CallService::class);
		$this->callService->method('renderEndpointPath')->willReturn('/adressen');
		$this->callService->method('call')->willReturnCallback(
			fn (): ObjectEntity => ObjectServiceMockBuilder::objectEntity(
				$this,
				['statusCode' => 200, 'response' => ['body' => '{"adressen":[1]}', 'headers' => $this->upstreamHeaders, 'remoteIp' => '10.0.0.1']],
				'call-log-1'
			)
		);

		$memory = $this->createMock(ICache::class);
		$memory->method('get')->willReturnCallback(fn (string $key): mixed => ($this->stored[$key] ?? null));
		$memory->method('set')->willReturnCallback(
			function (string $key, mixed $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($memory);
		$this->cache = new EndpointResponseCache(cacheFactory: $factory);

		$logger = $this->createMock(LoggerInterface::class);
		$scope = $this->createMock(ConsumerScopeService::class);
		$scope->method('isAllowed')->willReturn(true);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('https://example.org');

		$this->service = new EndpointService(
			$this->createMock(ObjectService::class),
			$this->callService,
			$logger,
			$urlGenerator,
			$this->createMock(MappingService::class),
			$this->objects,
			$this->createMock(IConfig::class),
			$this->createMock(StorageService::class),
			$this->createMock(OpenRegisterCredentialBridge::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(SynchronizationService::class),
			$this->createMock(RuleService::class),
			new WebhookSignatureService($logger),
			$this->createMock(InboundRateLimitService::class),
			new CompositeFanoutRule($this->objects, $logger),
			new ReferenceNumberRule(),
			new AvgBsnPolicyRule(),
			$this->createMock(\OCA\Integriq\Service\ApprovalService::class),
			$this->createMock(IRequestId::class),
			$this->createMock(FlowRunnerService::class),
			$scope,
			$this->createMock(SchemaMapper::class),
			$this->createMock(ORFileService::class),
			responseCache: $this->cache,
		);
	}//end setUp()

	/**
	 * A source endpoint, with the given cache block.
	 *
	 * @param array<string, mixed>|null $cache The cache block, or null for none.
	 *
	 * @return ObjectEntity
	 */
	private function endpoint(?array $cache): ObjectEntity {
		$data = ['method' => 'GET', 'path' => '/api/adressen', 'targetType' => 'api', 'targetId' => 'source-bag'];
		if ($cache !== null) {
			$data['cache'] = $cache;
		}

		return ObjectServiceMockBuilder::objectEntity($this, $data, 'endpoint-adressen');
	}//end endpoint()

	/**
	 * A GET request.
	 *
	 * @return IRequest
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getMethod')->willReturn('GET');
		$request->method('getParams')->willReturn(['postcode' => '3511AW']);
		$request->method('getHeader')->willReturn('');

		return $request;
	}//end request()

	/**
	 * The X-Cache header a response carries, read without the server (getHeaders() needs it).
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response.
	 *
	 * @return string|null
	 */
	private function header(\OCP\AppFramework\Http\Response $response): ?string {
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');

		return ($property->getValue($response)['X-Cache'] ?? null);
	}//end header()

	/**
	 * The call logs marking a cache hit.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function hitLogs(): array {
		return array_values(array_filter($this->logs, fn (array $log): bool => (($log['object']['cacheHit'] ?? false) === true)));
	}//end hitLogs()

	/**
	 * The same question twice within the lifetime reaches the source once; the second answer is a marked hit.
	 *
	 * @return void
	 */
	public function testTheSameQuestionTwiceReachesTheSourceOnce(): void {
		$this->callService->expects($this->once())->method('call');
		$endpoint = $this->endpoint(['enabled' => true, 'ttlSeconds' => 60]);

		$first = $this->service->handleRequest($endpoint, $this->request(), '/api/adressen');
		$second = $this->service->handleRequest($endpoint, $this->request(), '/api/adressen');

		$this->assertInstanceOf(JSONResponse::class, $second);
		$this->assertSame('MISS', $this->header($first));
		$this->assertSame('HIT', $this->header($second));
		$this->assertSame(200, $second->getStatus());
		$this->assertSame($first->getData(), $second->getData());
		$this->assertSame(['body' => '{"adressen":[1]}', 'headers' => ['Content-Type' => 'application/json']], $second->getData(), 'The cached answer must be the one the caller may see.');

		$hits = $this->hitLogs();
		$this->assertCount(1, $hits);
		$this->assertSame('call_log', $hits[0]['schema']);
		$this->assertSame('endpoint-adressen', $hits[0]['object']['endpoint']);
		$this->assertSame('source-bag', $hits[0]['object']['source']);
		$this->assertSame('inbound', $hits[0]['object']['direction']);
		$this->assertSame(200, $hits[0]['object']['statusCode']);
	}//end testTheSameQuestionTwiceReachesTheSourceOnce()

	/**
	 * An upstream answer marked private is asked from the source every time.
	 *
	 * @return void
	 */
	public function testAPrivateUpstreamAnswerReachesTheSourceEveryTime(): void {
		$this->upstreamHeaders = ['Content-Type' => 'application/json', 'Cache-Control' => 'private'];
		$this->callService->expects($this->exactly(2))->method('call');
		$endpoint = $this->endpoint(['enabled' => true]);

		$this->service->handleRequest($endpoint, $this->request(), '/api/adressen');
		$second = $this->service->handleRequest($endpoint, $this->request(), '/api/adressen');

		$this->assertSame('MISS', $this->header($second));
		$this->assertSame([], $this->hitLogs());
	}//end testAPrivateUpstreamAnswerReachesTheSourceEveryTime()

	/**
	 * An endpoint without a cache reaches the source every time and says nothing about a cache.
	 *
	 * @return void
	 */
	public function testAnEndpointWithoutACacheReachesTheSourceEveryTime(): void {
		$this->callService->expects($this->exactly(2))->method('call');
		$endpoint = $this->endpoint(null);

		$this->service->handleRequest($endpoint, $this->request(), '/api/adressen');
		$second = $this->service->handleRequest($endpoint, $this->request(), '/api/adressen');

		$this->assertNull($this->header($second));
		$this->assertSame([], $this->stored);
	}//end testAnEndpointWithoutACacheReachesTheSourceEveryTime()

	/**
	 * After a clear, the next call reaches the source again.
	 *
	 * @return void
	 */
	public function testAfterAClearTheNextCallReachesTheSource(): void {
		$this->callService->expects($this->exactly(2))->method('call');
		$endpoint = $this->endpoint(['enabled' => true]);

		$this->service->handleRequest($endpoint, $this->request(), '/api/adressen');
		$this->cache->clear(endpointId: 'endpoint-adressen');
		$after = $this->service->handleRequest($endpoint, $this->request(), '/api/adressen');

		$this->assertSame('MISS', $this->header($after));
	}//end testAfterAClearTheNextCallReachesTheSource()
}//end class
