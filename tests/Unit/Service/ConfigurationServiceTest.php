<?php
/**
 * Unit tests for ConfigurationService.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\Service;

use OCA\OpenConnector\Service\ConfigurationHandlers\EndpointHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\JobHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\MappingHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\RuleHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\SourceHandler;
use OCA\OpenConnector\Service\ConfigurationHandlers\SynchronizationHandler;
use OCA\OpenConnector\Service\ConfigurationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the configuration export/import service (rewritten for OR cutover).
 *
 * The original ConfigurationServiceTest imported 12 deleted Db types
 * (Source, Endpoint, Mapping, Rule, Job, Synchronization + their Mappers).
 * This replacement uses ObjectServiceMockBuilder and the new handler-based
 * constructor that takes ORObjectService + RegisterMapper + SchemaMapper.
 */
class ConfigurationServiceTest extends TestCase
{

    /**
     * @var ConfigurationService
     */
    private ConfigurationService $service;

    /**
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var RegisterMapper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $registerMapper;

    /**
     * @var SchemaMapper|\PHPUnit\Framework\MockObject\MockObject
     */
    private $schemaMapper;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->registerMapper  = $this->createMock(RegisterMapper::class);
        $this->schemaMapper    = $this->createMock(SchemaMapper::class);

        // All handlers take ORObjectService in their constructor.
        $endpointHandler        = new EndpointHandler($this->orObjectService);
        $synchronizationHandler = new SynchronizationHandler($this->orObjectService);
        $mappingHandler         = new MappingHandler($this->orObjectService);
        $jobHandler             = new JobHandler($this->orObjectService);
        $sourceHandler          = new SourceHandler($this->orObjectService);
        $ruleHandler            = new RuleHandler($this->orObjectService);

        $this->service = new ConfigurationService(
            $this->orObjectService,
            $this->registerMapper,
            $this->schemaMapper,
            $endpointHandler,
            $synchronizationHandler,
            $mappingHandler,
            $jobHandler,
            $sourceHandler,
            $ruleHandler,
        );

        // Default: findAll returns empty results.
        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        // Default: registerMapper + schemaMapper return empty lists.
        $this->registerMapper->method('findAll')->willReturn([]);
        $this->schemaMapper->method('findAll')->willReturn([]);
    }//end setUp()


    /**
     * Test that the constructor instantiates ConfigurationService without errors.
     *
     * @return void
     */
    public function testConstructorWiresDependencies(): void
    {
        $this->assertInstanceOf(ConfigurationService::class, $this->service);
    }//end testConstructorWiresDependencies()


    /**
     * Test that getEntitiesByConfiguration returns an array keyed by entity type.
     *
     * When no objects match the configurationId, each key must be an empty array.
     *
     * @return void
     */
    public function testGetEntitiesByConfigurationReturnsKeyedArray(): void
    {
        // Arrange — OR returns no matching objects
        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        // Act
        $result = $this->service->getEntitiesByConfiguration('config-id-1');

        // Assert
        $this->assertArrayHasKey('sources', $result);
        $this->assertArrayHasKey('endpoints', $result);
        $this->assertArrayHasKey('mappings', $result);
        $this->assertArrayHasKey('rules', $result);
        $this->assertArrayHasKey('jobs', $result);
        $this->assertArrayHasKey('synchronizations', $result);
    }//end testGetEntitiesByConfigurationReturnsKeyedArray()


    /**
     * Test that getEntitiesByConfiguration filters by configurationId.
     *
     * An entity whose 'configurations' array does NOT contain the requested ID
     * must be excluded from the result.
     *
     * @return void
     */
    public function testGetEntitiesByConfigurationFiltersOutUnrelatedEntities(): void
    {
        // Arrange — one source with a different configuration ID
        $sourceEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['slug' => 'my-source', 'configurations' => ['other-config-id']],
            'source-uuid-1'
        );

        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [$sourceEntity], 'total' => 1]);

        // Act
        $result = $this->service->getEntitiesByConfiguration('config-id-2');

        // Assert — 'my-source' must not appear because it belongs to 'other-config-id'
        $this->assertArrayNotHasKey('my-source', $result['sources']);
    }//end testGetEntitiesByConfigurationFiltersOutUnrelatedEntities()


    /**
     * Test that exportConfiguration returns a JSON-serialisable array with known keys.
     *
     * @return void
     */
    public function testExportConfigurationReturnsStructuredArray(): void
    {
        // Arrange — no objects, so export is essentially empty
        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [], 'total' => 0]);

        // Act
        $result = $this->service->exportConfiguration('export-config-id');

        // Assert — minimal structural check
        $this->assertIsArray($result);
        // The export should include at minimum the entity type buckets
        $this->assertArrayHasKey('sources', $result);
        $this->assertArrayHasKey('synchronizations', $result);
    }//end testExportConfigurationReturnsStructuredArray()


    /**
     * Test that getEntitiesByConfiguration indexes matching entities by slug.
     *
     * @return void
     */
    public function testGetEntitiesByConfigurationIndexesBySlug(): void
    {
        // Arrange — a source whose 'configurations' array contains our config ID
        $targetConfigId = 'config-id-3';
        $sourceEntity   = ObjectServiceMockBuilder::objectEntity(
            $this,
            [
                'slug'           => 'source-a',
                'configurations' => [$targetConfigId],
                'name'           => 'Source A',
            ],
            'source-uuid-2'
        );

        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [$sourceEntity], 'total' => 1]);

        // Act
        $result = $this->service->getEntitiesByConfiguration($targetConfigId);

        // Assert — entity indexed under its slug
        $this->assertArrayHasKey('source-a', $result['sources']);
        $this->assertSame('Source A', $result['sources']['source-a']['name']);
    }//end testGetEntitiesByConfigurationIndexesBySlug()


}//end class
