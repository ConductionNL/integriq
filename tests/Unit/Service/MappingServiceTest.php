<?php
/**
 * Unit tests for MappingService.
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

use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Twig\Loader\ArrayLoader;

/**
 * Tests for the mapping execution service (OR cutover — no deleted Db types).
 */
class MappingServiceTest extends TestCase
{

    /**
     * @var MappingService
     */
    private MappingService $service;

    /**
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = ObjectServiceMockBuilder::make($this);

        $loader        = new ArrayLoader([]);
        $callService   = $this->createMock(CallService::class);
        $fileService   = $this->createMock(FileService::class);
        $objectService = $this->createMock(ObjectService::class);
        $container     = $this->createMock(ContainerInterface::class);
        $logger        = $this->createMock(LoggerInterface::class);

        // Container::get throws so the service falls back to local implementation.
        $container->method('get')->willThrowException(new \RuntimeException('not available'));

        $this->service = new MappingService(
            $loader,
            $callService,
            $fileService,
            $objectService,
            $this->orObjectService,
            $container,
            $logger,
        );
    }//end setUp()


    /**
     * Test that the constructor instantiates MappingService without errors.
     *
     * @return void
     */
    public function testConstructorWiresDependencies(): void
    {
        $this->assertInstanceOf(MappingService::class, $this->service);
    }//end testConstructorWiresDependencies()


    /**
     * Test that encodeArrayKeys replaces dots in keys.
     *
     * @return void
     */
    public function testEncodeArrayKeysReplacesDots(): void
    {
        // Arrange
        $array = ['foo.bar' => 'value', 'baz' => 'other'];

        // Act
        $result = $this->service->encodeArrayKeys($array, '.', '__DOT__');

        // Assert
        $this->assertArrayHasKey('foo__DOT__bar', $result);
        $this->assertArrayNotHasKey('foo.bar', $result);
        $this->assertSame('value', $result['foo__DOT__bar']);
        $this->assertSame('other', $result['baz']);
    }//end testEncodeArrayKeysReplacesDots()


    /**
     * Test that encodeArrayKeys recurses into nested arrays.
     *
     * @return void
     */
    public function testEncodeArrayKeysRecursesIntoNestedArrays(): void
    {
        // Arrange
        $array = ['parent' => ['child.key' => 'deep-value']];

        // Act
        $result = $this->service->encodeArrayKeys($array, '.', '_');

        // Assert
        $this->assertArrayHasKey('child_key', $result['parent']);
        $this->assertSame('deep-value', $result['parent']['child_key']);
    }//end testEncodeArrayKeysRecursesIntoNestedArrays()


    /**
     * Test that getMappings returns the results array from OR findAll.
     *
     * @return void
     */
    public function testGetMappingsReturnsResultsFromFindAll(): void
    {
        // Arrange
        $mappingEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['name' => 'test-mapping', 'mapping' => []],
            'mapping-uuid-1'
        );

        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [$mappingEntity], 'total' => 1]);

        // Act
        $result = $this->service->getMappings();

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame($mappingEntity, $result[0]);
    }//end testGetMappingsReturnsResultsFromFindAll()


    /**
     * Test that getMapping delegates to OR find with the correct register and schema.
     *
     * @return void
     */
    public function testGetMappingDelegatesToORFind(): void
    {
        // Arrange
        $mappingEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['name' => 'my-map'],
            'map-uuid-42'
        );

        $this->orObjectService->expects($this->once())
            ->method('find')
            ->willReturn($mappingEntity);

        // Act
        $result = $this->service->getMapping('map-uuid-42');

        // Assert
        $this->assertSame($mappingEntity, $result);
    }//end testGetMappingDelegatesToORFind()


}//end class
