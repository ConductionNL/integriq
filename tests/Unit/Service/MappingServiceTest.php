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

use OCA\OpenConnector\Db\Mapping;
use OCA\OpenConnector\Db\MappingMapper;
use OCA\OpenConnector\Db\SourceMapper;
use OCA\OpenConnector\Service\CallService;
use OCA\OpenConnector\Service\MappingService;
use OCA\OpenConnector\Service\ObjectService;
use OCA\OpenRegister\Service\FileService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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
     * @var MappingMapper&MockObject
     */
    private MappingMapper $mappingMapper;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // The MappingMapper is itself an OpenRegister-backed adapter; here it is
        // mocked so the delegation contract of MappingService can be asserted.
        $this->mappingMapper = $this->createMock(MappingMapper::class);

        $loader        = new ArrayLoader([]);
        $callService   = $this->createMock(CallService::class);
        $sourceMapper  = $this->createMock(SourceMapper::class);
        $fileService   = $this->createMock(FileService::class);
        $objectService = $this->createMock(ObjectService::class);

        $this->service = new MappingService(
            $loader,
            $this->mappingMapper,
            $callService,
            $sourceMapper,
            $fileService,
            $objectService,
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
     * Test that getMappings delegates to the (OR-backed) MappingMapper::findAll.
     *
     * @return void
     */
    public function testGetMappingsReturnsResultsFromFindAll(): void
    {
        // Arrange
        $mapping = new Mapping();
        $this->mappingMapper->expects($this->once())
            ->method('findAll')
            ->willReturn([$mapping]);

        // Act
        $result = $this->service->getMappings();

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame($mapping, $result[0]);
    }//end testGetMappingsReturnsResultsFromFindAll()


    /**
     * Test that getMapping delegates to the (OR-backed) MappingMapper::find.
     *
     * @return void
     */
    public function testGetMappingDelegatesToORFind(): void
    {
        // Arrange
        $mapping = new Mapping();
        $this->mappingMapper->expects($this->once())
            ->method('find')
            ->with('map-uuid-42')
            ->willReturn($mapping);

        // Act
        $result = $this->service->getMapping('map-uuid-42');

        // Assert
        $this->assertSame($mapping, $result);
    }//end testGetMappingDelegatesToORFind()


}//end class
