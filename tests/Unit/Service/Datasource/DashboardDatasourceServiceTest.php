<?php

/**
 * Unit tests for DashboardDatasourceService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service\Datasource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service\Datasource;

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\Datasource\DashboardDatasourceService;
use OCA\OpenConnector\Service\Datasource\JsonPathLiteEvaluator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Trivial in-process ICache double — a plain array store with no real TTL
 * expiry (tests that need "not yet expired" simply never remove the key;
 * tests that need "expired"/"no cache" simply never seed it).
 */
class InMemoryDashboardDatasourceCache implements ICache
{
    private array $store = [];

    public function get($key): mixed
    {
        return ($this->store[$key] ?? null);
    }

    public function set($key, $value, $ttl=0): bool
    {
        $this->store[$key] = $value;
        return true;
    }

    public function hasKey($key): bool
    {
        return isset($this->store[$key]);
    }

    public function remove($key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function clear($prefix=''): bool
    {
        $this->store = [];
        return true;
    }

    public static function isAvailable(): bool
    {
        return true;
    }
}

/**
 * Tests for the `resolve()` façade: successful resolve, missing-path,
 * caller-supplied url/host ignored, credential-free response shape, cache
 * hit within TTL, stale-on-error, and 403 propagation for an unauthorized
 * source read.
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md
 */
class DashboardDatasourceServiceTest extends TestCase
{

    /**
     * @var OrObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var CallService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $callService;

    /**
     * @var InMemoryDashboardDatasourceCache
     */
    private InMemoryDashboardDatasourceCache $cache;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;

    private DashboardDatasourceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = $this->createMock(OrObjectService::class);
        $this->callService     = $this->createMock(CallService::class);
        $this->cache           = new InMemoryDashboardDatasourceCache();
        $this->logger          = $this->createMock(LoggerInterface::class);

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($this->cache);

        $this->service = new DashboardDatasourceService(
            orObjectService: $this->orObjectService,
            callService: $this->callService,
            evaluator: new JsonPathLiteEvaluator(),
            cacheFactory: $cacheFactory,
            logger: $this->logger,
            rateLimitPerWindow: 2,
            rateLimitWindowSeconds: 60,
        );
    }//end setUp()

    /**
     * Build a source ObjectEntity as `orObjectService->find()` would return it.
     */
    private function buildSource(string $uuid='source-uuid-1'): ObjectEntity
    {
        $source = new ObjectEntity();
        $source->setUuid($uuid);
        $source->setObject(['name' => 'Test Source', 'location' => 'https://example.test/api']);
        return $source;
    }

    /**
     * Build a synthetic call_log ObjectEntity the way CallService::call() would.
     */
    private function buildCallLog(int $statusCode, array $decodedBody, array $requestEcho=[]): ObjectEntity
    {
        $callLog = new ObjectEntity();
        $callLog->setUuid('call-log-1');
        $callLog->setObject(
            [
                'request'  => array_merge(['url' => 'https://example.test/api', 'method' => 'GET'], $requestEcho),
                'response' => [
                    'statusCode' => $statusCode,
                    'headers'    => ['X-Api-Key' => 'super-secret-should-never-leak'],
                    'body'       => json_encode($decodedBody),
                ],
            ]
        );
        return $callLog;
    }//end buildCallLog()

    /**
     * Successful resolve: the JSONPath-lite expression resolves against the
     * decoded response and stale is false.
     */
    public function testSuccessfulResolveReturnsValue(): void
    {
        $source = $this->buildSource();
        $this->orObjectService->method('find')->willReturn($source);
        $this->callService->method('call')->willReturn($this->buildCallLog(200, ['data' => ['open_count' => 7]]));

        $result = $this->service->resolve(sourceId: 'source-uuid-1', valueExpr: '$.data.open_count');

        $this->assertSame(7, $result['value']);
        $this->assertFalse($result['stale']);
        $this->assertArrayHasKey('fetchedAt', $result);
    }//end testSuccessfulResolveReturnsValue()

    /**
     * Value expression finds nothing: returns null, not an error, stale false.
     */
    public function testMissingPathReturnsNullWithoutError(): void
    {
        $source = $this->buildSource();
        $this->orObjectService->method('find')->willReturn($source);
        $this->callService->method('call')->willReturn($this->buildCallLog(200, ['data' => ['open_count' => 7]]));

        $result = $this->service->resolve(sourceId: 'source-uuid-1', valueExpr: '$.data.missing_key');

        $this->assertNull($result['value']);
        $this->assertFalse($result['stale']);
    }//end testMissingPathReturnsNullWithoutError()

    /**
     * A caller-supplied url/host in params is stripped before the call
     * engine is invoked — the source's own location is the only egress
     * target.
     */
    public function testCallerSuppliedUrlIsIgnored(): void
    {
        $source = $this->buildSource();
        $this->orObjectService->method('find')->willReturn($source);

        $this->callService->expects($this->once())
            ->method('call')
            ->with(
                $this->equalTo($source),
                $this->equalTo(''),
                $this->equalTo('GET'),
                $this->callback(
                    function (array $config): bool {
                        // url/host must never appear anywhere in the outbound config.
                        $encoded = json_encode($config);
                        return (str_contains($encoded, 'evil.example') === false)
                            && (array_key_exists('url', $config) === false)
                            && (array_key_exists('host', $config) === false);
                    }
                ),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($this->buildCallLog(200, ['ok' => true]));

        $this->service->resolve(
            sourceId: 'source-uuid-1',
            valueExpr: '$.ok',
            params: ['url' => 'https://evil.example/steal', 'host' => 'evil.example', 'q' => 'kept'],
        );
    }//end testCallerSuppliedUrlIsIgnored()

    /**
     * The resolved response never contains the source URL, headers, or any
     * credential — only value/fetchedAt/stale.
     */
    public function testResponseExcludesUrlHeadersAndCredentials(): void
    {
        $source = $this->buildSource();
        $this->orObjectService->method('find')->willReturn($source);
        $this->callService->method('call')->willReturn($this->buildCallLog(200, ['data' => ['open_count' => 1]]));

        $result = $this->service->resolve(sourceId: 'source-uuid-1', valueExpr: '$.data.open_count');

        $this->assertSame(['value', 'fetchedAt', 'stale'], array_keys($result));
        $encoded = json_encode($result);
        $this->assertStringNotContainsString('example.test', $encoded);
        $this->assertStringNotContainsString('super-secret-should-never-leak', $encoded);
        $this->assertStringNotContainsString('X-Api-Key', $encoded);
    }//end testResponseExcludesUrlHeadersAndCredentials()

    /**
     * A second resolve within the TTL window is served from cache — the
     * call engine is invoked exactly once.
     */
    public function testCacheHitWithinTtlSkipsUpstreamFetch(): void
    {
        $source = $this->buildSource();
        $this->orObjectService->method('find')->willReturn($source);

        $this->callService->expects($this->once())
            ->method('call')
            ->willReturn($this->buildCallLog(200, ['data' => ['open_count' => 3]]));

        $first  = $this->service->resolve(sourceId: 'source-uuid-1', valueExpr: '$.data.open_count', ttl: 300);
        $second = $this->service->resolve(sourceId: 'source-uuid-1', valueExpr: '$.data.open_count', ttl: 300);

        $this->assertSame(3, $first['value']);
        $this->assertSame(3, $second['value']);
        $this->assertFalse($second['stale']);
    }//end testCacheHitWithinTtlSkipsUpstreamFetch()

    /**
     * Stale-on-error: a previously cached value is served with stale:true
     * when the upstream refresh returns a non-2xx status.
     */
    public function testStaleOnErrorServesLastKnownValue(): void
    {
        $source = $this->buildSource();
        $this->orObjectService->method('find')->willReturn($source);

        // Seed the stale-fallback cache entry directly, as a prior
        // successful resolve would have (short TTL already elapsed so the
        // fresh entry is gone, but the long-lived stale entry survives).
        $staleKey = 'stale_'.$this->reflectCacheKey(sourceId: 'source-uuid-1', valueExpr: '$.data.open_count', params: []);
        $this->cache->set($staleKey, ['value' => 9, 'fetchedAt' => '2026-07-23T00:00:00+00:00'], 86400);

        $this->callService->method('call')->willReturn($this->buildCallLog(503, []));

        $result = $this->service->resolve(sourceId: 'source-uuid-1', valueExpr: '$.data.open_count');

        $this->assertSame(9, $result['value']);
        $this->assertTrue($result['stale']);
    }//end testStaleOnErrorServesLastKnownValue()

    /**
     * Stale-on-error with no prior cache entry: null value, stale true.
     */
    public function testStaleOnErrorWithNoCacheReturnsNullStale(): void
    {
        $source = $this->buildSource();
        $this->orObjectService->method('find')->willReturn($source);
        $this->callService->method('call')->willReturn($this->buildCallLog(500, []));

        $result = $this->service->resolve(sourceId: 'source-uuid-1', valueExpr: '$.data.open_count');

        $this->assertNull($result['value']);
        $this->assertTrue($result['stale']);
    }//end testStaleOnErrorWithNoCacheReturnsNullStale()

    /**
     * A source the current user may not read propagates NotAuthorizedException
     * (the controller maps this to 403) — no upstream fetch is ever attempted.
     */
    public function testUnauthorizedSourcePropagatesNotAuthorizedAndNeverCallsUpstream(): void
    {
        $this->orObjectService->method('find')->willThrowException(new NotAuthorizedException('nope'));
        $this->callService->expects($this->never())->method('call');

        $this->expectException(NotAuthorizedException::class);
        $this->service->resolve(sourceId: 'source-uuid-1', valueExpr: '$.data.open_count');
    }//end testUnauthorizedSourcePropagatesNotAuthorizedAndNeverCallsUpstream()

    /**
     * A non-existent source propagates DoesNotExistException — no upstream fetch.
     */
    public function testMissingSourcePropagatesDoesNotExist(): void
    {
        $this->orObjectService->method('find')->willThrowException($this->createMock(DoesNotExistException::class));
        $this->callService->expects($this->never())->method('call');

        $this->expectException(DoesNotExistException::class);
        $this->service->resolve(sourceId: 'missing', valueExpr: '$.data.open_count');
    }//end testMissingSourcePropagatesDoesNotExist()

    /**
     * Per-source rate limit: once the window budget is exhausted, further
     * calls are served from cache/stale and never reach the call engine.
     */
    public function testRateLimitExceededServesStaleWithoutUpstreamCall(): void
    {
        $source = $this->buildSource();
        $this->orObjectService->method('find')->willReturn($source);

        // rateLimitPerWindow=2 in setUp(). Each distinct params value keeps
        // the fresh-cache key distinct so we exercise the limiter rather
        // than the cache-hit fast path — CallService::call() is expected to
        // fire exactly twice (the budget), the third call must be refused
        // before dispatch.
        $this->callService->expects($this->exactly(2))
            ->method('call')
            ->willReturn($this->buildCallLog(200, ['n' => 1]));

        $this->service->resolve(sourceId: 'source-uuid-1', valueExpr: '$.n', params: ['p' => '1']);
        $this->service->resolve(sourceId: 'source-uuid-1', valueExpr: '$.n', params: ['p' => '2']);
        $third = $this->service->resolve(sourceId: 'source-uuid-1', valueExpr: '$.n', params: ['p' => '3']);

        $this->assertTrue($third['stale']);
    }//end testRateLimitExceededServesStaleWithoutUpstreamCall()

    /**
     * Read-only guarantee: resolve() never mutates the source (or any other
     * object) — only `CallService::call()` is invoked, always with a GET
     * method and `read: true`, and `ObjectService::saveObject()`/
     * `deleteObject()` are never called.
     */
    public function testResolveIsReadOnlyAndNeverMutatesTheSource(): void
    {
        $source = $this->buildSource();
        $this->orObjectService->method('find')->willReturn($source);
        $this->orObjectService->expects($this->never())->method('saveObject');
        $this->orObjectService->expects($this->never())->method('deleteObject');

        $this->callService->expects($this->once())
            ->method('call')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->equalTo('GET'),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->equalTo(true),
            )
            ->willReturn($this->buildCallLog(200, ['ok' => true]));

        $this->service->resolve(sourceId: 'source-uuid-1', valueExpr: '$.ok');
    }//end testResolveIsReadOnlyAndNeverMutatesTheSource()

    /**
     * Recompute the service's cache key algorithm for stale-cache seeding.
     * Mirrors DashboardDatasourceService::buildCacheKey() exactly.
     */
    private function reflectCacheKey(string $sourceId, string $valueExpr, array $params): string
    {
        ksort($params);
        $paramsJson = json_encode($params);
        return 'oc_ddc_'.hash('sha256', $sourceId.'|'.$valueExpr.'|'.$paramsJson);
    }//end reflectCacheKey()
}//end class
