<?php

/**
 * EndpointCacheService unit tests
 *
 * The endpoint cache resolves an incoming path and method to the one endpoint
 * whose route matches. It reads endpoints from the distributed cache, fills
 * that cache from OpenRegister when it is empty, refreshes once when nothing
 * matches, refuses an ambiguous route by naming the endpoints, and falls back
 * to OpenRegister when the cache itself fails.
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use Exception;
use OCA\Integriq\Service\EndpointCacheService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for the endpoint routing cache.
 *
 * @covers \OCA\Integriq\Service\EndpointCacheService
 */
class EndpointCacheServiceTest extends TestCase {

	/**
	 * What the distributed cache holds.
	 *
	 * @var array<string, mixed>
	 */
	private array $stored = [];

	/**
	 * The TTLs the cache was written with.
	 *
	 * @var array<int, int>
	 */
	private array $ttls = [];

	/** @var ObjectService&MockObject */
	private ObjectService $objects;

	/** @var ICacheFactory&MockObject */
	private ICacheFactory $factory;

	protected function setUp(): void {
		$this->stored = [];
		$this->ttls = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->stored[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl = 0): bool {
				$this->stored[$key] = $value;
				$this->ttls[] = $ttl;
				return true;
			}
		);
		$cache->method('remove')->willReturnCallback(
			function (string $key): bool {
				unset($this->stored[$key]);
				return true;
			}
		);
		$this->factory = $this->createMock(ICacheFactory::class);
		$this->factory->method('createDistributed')->with('openconnector')->willReturn($cache);
		$this->objects = $this->createMock(ObjectService::class);
	}//end setUp()

	public function testAnEmptyCacheIsFilledFromOpenRegisterAndTheRouteResolved(): void {
		$this->objects->expects($this->once())->method('findAll')->with(
			['filters' => ['register' => 'integriq', 'schema' => 'endpoint']],
			false,
			false
		)->willReturn(['results' => [$this->endpoint('zaken', 'api/zaken/{{id}}', 'GET'), $this->endpoint('post', 'api/zaken', 'POST')]]);

		$match = $this->service(ttl: '120')->findByPathRegex(path: 'api/zaken/42', method: 'GET');

		$this->assertInstanceOf(ObjectEntity::class, $match);
		$this->assertSame('zaken', $match->getObject()['name']);
		$this->assertSame('#^api/zaken(?:/([^/]+))?$#', $match->getObject()['endpointRegex']);
		$this->assertSame(['api', 'zaken', '{{id}}'], $match->getObject()['endpointArray']);
		$this->assertSame([120], $this->ttls);
		$this->assertSame(['uuid' => 'zaken', 'name' => 'zaken', 'endpoint' => 'api/zaken/{{id}}', 'method' => 'GET'], $this->stored['integriq_endpoints_cache'][0]);
	}//end testAnEmptyCacheIsFilledFromOpenRegisterAndTheRouteResolved()

	public function testACachedRowResolvesWithoutAskingOpenRegisterAndBecomesAnEntity(): void {
		$this->stored['integriq_endpoints_cache'] = [
			['@self' => ['id' => 'x'], 'name' => 'cached', 'endpoint' => 'api/klanten', 'method' => 'GET'],
		];
		$this->objects->expects($this->never())->method('findAll');
		$service = $this->service();

		$match = $service->findByPathRegex(path: 'api/klanten', method: 'GET');

		$this->assertSame(['name' => 'cached', 'endpoint' => 'api/klanten', 'method' => 'GET', 'endpointRegex' => '#^api/klanten$#', 'endpointArray' => ['api', 'klanten']], $match->getObject());
		$this->assertNull($service->findByPathRegex(path: 'api/klanten', method: 'DELETE', isRetry: true));
	}//end testACachedRowResolvesWithoutAskingOpenRegisterAndBecomesAnEntity()

	public function testNoMatchRefreshesOnceAndThenGivesUp(): void {
		$this->stored['integriq_endpoints_cache'] = [['name' => 'stale', 'endpoint' => 'api/oud', 'method' => 'GET']];
		$this->objects->expects($this->once())->method('findAll')->willReturn([$this->endpoint('nieuw', 'api/nieuw', 'GET')]);
		$service = $this->service();

		$this->assertNull($service->findByPathRegex(path: 'api/elders', method: 'GET'));
		$this->assertSame('nieuw', $service->findByPathRegex(path: 'api/nieuw', method: 'GET')->getObject()['name']);
	}//end testNoMatchRefreshesOnceAndThenGivesUp()

	public function testAnAmbiguousRouteIsRefusedNamingTheEndpoints(): void {
		$this->stored['integriq_endpoints_cache'] = [
			['name' => 'een', 'endpointRegex' => '#^api/x$#', 'method' => 'GET'],
			['endpointRegex' => '#^api/.*$#', 'method' => 'GET'],
			['name' => 'zonder-patroon', 'method' => 'GET'],
		];

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Multiple endpoints found for path and method: api/x GET. Matching endpoints: een, unnamed');

		$this->service()->findByPathRegex(path: 'api/x', method: 'GET');
	}//end testAnAmbiguousRouteIsRefusedNamingTheEndpoints()

	public function testABrokenCacheFallsBackToOpenRegister(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willThrowException(new RuntimeException('redis gone'));
		$this->objects->method('findAll')->willReturn([$this->endpoint('direct', 'api/direct', 'GET')]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Endpoint cache error, falling back to database: redis gone');

		$endpoints = $this->service(factory: $factory, logger: $logger)->getAllEndpoints();

		$this->assertCount(1, $endpoints);
		$this->assertSame('direct', $endpoints[0]->getObject()['name']);
	}//end testABrokenCacheFallsBackToOpenRegister()

	public function testAFailedRefreshIsLoggedAndRethrown(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('OpenRegister down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Failed to refresh endpoint cache: OpenRegister down');

		$this->expectException(RuntimeException::class);

		$this->service(logger: $logger)->refreshCache();
	}//end testAFailedRefreshIsLoggedAndRethrown()

	public function testClearingEmptiesBothCachesAndTheStatsSaySo(): void {
		$this->objects->method('findAll')->willReturn(['results' => [$this->endpoint('a', 'api/a', 'GET'), 'not-an-endpoint']]);
		$service = $this->service(ttl: '60');
		$service->getAllEndpoints();

		$this->assertSame(
			['cached' => true, 'memory_cached' => true, 'endpoint_count' => 2, 'cache_key' => 'integriq_endpoints_cache', 'cache_ttl' => 60, 'cache_dirty' => false],
			$service->getCacheStats()
		);
		$this->assertSame([], $this->stored['integriq_endpoints_cache'][1]);

		$service->clearCache();

		$this->assertArrayNotHasKey('integriq_endpoints_cache', $this->stored);
		$this->assertSame(['cached' => false, 'memory_cached' => false, 'endpoint_count' => 0, 'cache_key' => 'integriq_endpoints_cache', 'cache_ttl' => 60, 'cache_dirty' => false], $service->getCacheStats());
	}//end testClearingEmptiesBothCachesAndTheStatsSaySo()

	public function testAnUnreachableCacheIsReportedInTheStatsAndClearIsQuiet(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willThrowException(new RuntimeException('redis gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Failed to clear endpoint cache: redis gone');
		$service = $this->service(factory: $factory, logger: $logger);

		$service->clearCache();

		$this->assertSame(['error' => 'redis gone', 'cached' => false, 'memory_cached' => false, 'cache_dirty' => false], $service->getCacheStats());
	}//end testAnUnreachableCacheIsReportedInTheStatsAndClearIsQuiet()

	public function testTheRouteOfAnEndpointPathIsARegexAndItsSegments(): void {
		$service = $this->service();

		$this->assertSame(['endpointRegex' => '#^api/zaken/([^/]+)/documenten$#', 'endpointArray' => ['api', 'zaken', '{{id}}', 'documenten']], $service->routingFor(endpoint: 'api/zaken/{{id}}/documenten'));
		$this->assertSame(1, preg_match($service->routingFor(endpoint: 'api/zaken/{{id}}')['endpointRegex'], 'api/zaken'));
		$this->assertSame(0, preg_match($service->routingFor(endpoint: 'api/zaken')['endpointRegex'], 'api/zaken/1'));
	}//end testTheRouteOfAnEndpointPathIsARegexAndItsSegments()

	/**
	 * An endpoint entity.
	 *
	 * @param string $name     Its name (and uuid).
	 * @param string $endpoint Its path.
	 * @param string $method   Its method.
	 *
	 * @return ObjectEntity
	 */
	private function endpoint(string $name, string $endpoint, string $method): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($name);
		$entity->setObject(['name' => $name, 'endpoint' => $endpoint, 'method' => $method]);

		return $entity;
	}//end endpoint()

	/**
	 * The service over the doubles.
	 *
	 * @param string               $ttl     The configured TTL.
	 * @param ICacheFactory|null   $factory A cache factory to use instead.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return EndpointCacheService
	 */
	private function service(string $ttl = '3600', ?ICacheFactory $factory = null, ?LoggerInterface $logger = null): EndpointCacheService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'endpoint_cache.ttl_seconds' ? $ttl : $default)
		);

		return new EndpointCacheService(
			cacheFactory: ($factory ?? $this->factory),
			orObjectService: $this->objects,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
			appConfig: $config,
		);
	}//end service()
}//end class
