<?php

/**
 * SourceMappingServiceTest
 *
 * Unit tests for the SourceMappingService class.
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
 * @spec openspec/changes/openconnector-adopt-or-abstractions/tasks.md#task-7.1
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Db\EndpointMapper;
use OCA\OpenConnector\Db\EventSubscriptionMapper;
use OCA\OpenConnector\Db\JobMapper;
use OCA\OpenConnector\Db\MappingMapper;
use OCA\OpenConnector\Db\RuleMapper;
use OCA\OpenConnector\Db\SourceMapper;
use OCA\OpenConnector\Db\SynchronizationMapper;
use OCA\OpenConnector\Service\SourceMappingService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for SourceMappingService (renamed from ObjectService).
 */
class SourceMappingServiceTest extends TestCase
{

    private SourceMappingService $service;

    /** @var IAppManager&MockObject */
    private IAppManager $appManager;

    /** @var ContainerInterface&MockObject */
    private ContainerInterface $container;

    protected function setUp(): void
    {
        $this->appManager            = $this->createMock(IAppManager::class);
        $this->container             = $this->createMock(ContainerInterface::class);
        $endpointMapper              = $this->createMock(EndpointMapper::class);
        $eventSubscriptionMapper     = $this->createMock(EventSubscriptionMapper::class);
        $jobMapper                   = $this->createMock(JobMapper::class);
        $mappingMapper               = $this->createMock(MappingMapper::class);
        $ruleMapper                  = $this->createMock(RuleMapper::class);
        $sourceMapper                = $this->createMock(SourceMapper::class);
        $synchronizationMapper       = $this->createMock(SynchronizationMapper::class);

        $this->service = new SourceMappingService(
            appManager: $this->appManager,
            container: $this->container,
            endpointMapper: $endpointMapper,
            eventSubscriptionMapper: $eventSubscriptionMapper,
            jobMapper: $jobMapper,
            mappingMapper: $mappingMapper,
            ruleMapper: $ruleMapper,
            sourceMapper: $sourceMapper,
            synchronizationMapper: $synchronizationMapper,
        );
    }

    /**
     * getOpenRegisters returns null when openregister is not installed.
     */
    public function testGetOpenRegistersReturnsNullWhenAppNotInstalled(): void
    {
        $this->appManager
            ->method('getInstalledApps')
            ->willReturn([]);

        $result = $this->service->getOpenRegisters();

        self::assertNull($result);
    }

    /**
     * getMapper throws InvalidArgumentException for unknown object type.
     */
    public function testGetMapperThrowsForUnknownObjectType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->getMapper(objectType: 'nonexistent-type');
    }

    /**
     * BASE_OBJECT constant contains expected database and collection keys.
     */
    public function testBaseObjectConstantShape(): void
    {
        self::assertArrayHasKey('database', SourceMappingService::BASE_OBJECT);
        self::assertArrayHasKey('collection', SourceMappingService::BASE_OBJECT);
        self::assertSame('objects', SourceMappingService::BASE_OBJECT['database']);
        self::assertSame('json', SourceMappingService::BASE_OBJECT['collection']);
    }

    /**
     * ObjectService deprecated alias extends SourceMappingService.
     */
    public function testDeprecatedAliasExtendsSourceMappingService(): void
    {
        self::assertTrue(
            is_a(\OCA\OpenConnector\Service\ObjectService::class, SourceMappingService::class, true)
        );
    }

}
