<?php

/**
 * EndpointCacheServiceTest
 *
 * Unit tests for the EndpointCacheService class.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openconnector-adopt-or-abstractions/tasks.md#task-7.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Db\EndpointMapper;
use OCA\OpenConnector\Service\EndpointCacheService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that EndpointCacheService reads its TTL from admin-config.
 */
class EndpointCacheServiceTest extends TestCase
{

    /** @var ICacheFactory&MockObject */
    private ICacheFactory $cacheFactory;

    /** @var EndpointMapper&MockObject */
    private EndpointMapper $endpointMapper;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var IAppConfig&MockObject */
    private IAppConfig $appConfig;

    protected function setUp(): void
    {
        $this->cacheFactory   = $this->createMock(ICacheFactory::class);
        $this->endpointMapper = $this->createMock(EndpointMapper::class);
        $this->logger         = $this->createMock(LoggerInterface::class);
        $this->appConfig      = $this->createMock(IAppConfig::class);
    }

    private function makeService(string $configuredTtl = '3600'): EndpointCacheService
    {
        $this->appConfig
            ->method('getValueString')
            ->with('openconnector', 'endpoint_cache.ttl_seconds', '3600')
            ->willReturn($configuredTtl);

        return new EndpointCacheService(
            cacheFactory: $this->cacheFactory,
            endpointMapper: $this->endpointMapper,
            logger: $this->logger,
            appConfig: $this->appConfig,
        );
    }

    /**
     * Service instantiates without errors using the default TTL.
     */
    public function testInstantiatesWithDefaultTtl(): void
    {
        $service = $this->makeService('3600');
        self::assertInstanceOf(EndpointCacheService::class, $service);
    }

    /**
     * Service respects a custom TTL from admin-config.
     */
    public function testReadsCustomTtlFromAdminConfig(): void
    {
        $this->appConfig
            ->expects($this->once())
            ->method('getValueString')
            ->with('openconnector', 'endpoint_cache.ttl_seconds', '3600')
            ->willReturn('7200');

        $service = new EndpointCacheService(
            cacheFactory: $this->cacheFactory,
            endpointMapper: $this->endpointMapper,
            logger: $this->logger,
            appConfig: $this->appConfig,
        );

        self::assertInstanceOf(EndpointCacheService::class, $service);
    }

    /**
     * CACHE_TTL constant no longer exists on the class.
     */
    public function testCacheTtlConstantRemoved(): void
    {
        self::assertFalse(
            defined('OCA\\OpenConnector\\Service\\EndpointCacheService::CACHE_TTL')
        );
    }

}
