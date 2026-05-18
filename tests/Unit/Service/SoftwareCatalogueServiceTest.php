<?php

/**
 * SoftwareCatalogueServiceTest
 *
 * Unit tests for the SoftwareCatalogueService class.
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
 * @spec openspec/changes/openconnector-adopt-or-abstractions/tasks.md#task-7.3
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\SoftwareCatalogueService;
use OCA\OpenConnector\Service\SourceMappingService;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests that SoftwareCatalogueService reads its suffix from admin-config.
 */
class SoftwareCatalogueServiceTest extends TestCase
{

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var SourceMappingService&MockObject */
    private SourceMappingService $objectService;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var IAppConfig&MockObject */
    private IAppConfig $appConfig;

    protected function setUp(): void
    {
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->objectService = $this->createMock(SourceMappingService::class);
        $this->schemaMapper  = $this->createMock(SchemaMapper::class);
        $this->appConfig     = $this->createMock(IAppConfig::class);
    }

    private function makeService(string $configuredSuffix = '-sc'): SoftwareCatalogueService
    {
        $this->appConfig
            ->method('getValueString')
            ->with('openconnector', 'software_catalogue.suffix', '-sc')
            ->willReturn($configuredSuffix);

        return new SoftwareCatalogueService(
            logger: $this->logger,
            objectService: $this->objectService,
            schemaMapper: $this->schemaMapper,
            appConfig: $this->appConfig,
        );
    }

    /**
     * getSuffix returns the default '-sc' when no config is set.
     */
    public function testGetSuffixReturnsDefault(): void
    {
        $service = $this->makeService('-sc');
        self::assertSame('-sc', $service->getSuffix());
    }

    /**
     * getSuffix returns the admin-configured value when set.
     */
    public function testGetSuffixReturnsAdminConfigValue(): void
    {
        $service = $this->makeService('-sc-test');
        self::assertSame('-sc-test', $service->getSuffix());
    }

    /**
     * SUFFIX constant no longer exists on the class.
     */
    public function testSuffixConstantRemoved(): void
    {
        self::assertFalse(
            defined('OCA\\OpenConnector\\Service\\SoftwareCatalogueService::SUFFIX')
        );
    }

}
