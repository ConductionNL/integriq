<?php
/**
 * Unit tests for SynchronizationService.
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
use OCA\OpenConnector\Service\StorageService;
use OCA\OpenConnector\Service\SynchronizationService;
use OCA\OpenConnector\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the synchronization orchestration service (OR cutover — no deleted Db types).
 */
class SynchronizationServiceTest extends TestCase
{

    /**
     * @var SynchronizationService
     */
    private SynchronizationService $service;

    /**
     * @var ORObjectService|\PHPUnit\Framework\MockObject\MockObject
     */
    private $orObjectService;

    /**
     * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $logger;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = ObjectServiceMockBuilder::make($this);
        $this->logger          = $this->createMock(LoggerInterface::class);

        $callService    = $this->createMock(CallService::class);
        $mappingService = $this->createMock(MappingService::class);
        $container      = $this->createMock(ContainerInterface::class);
        $objectService  = $this->createMock(ObjectService::class);
        $storageService = $this->createMock(StorageService::class);
        $appConfig      = $this->createMock(IAppConfig::class);
        $appConfig->method('hasKey')->willReturn(false);

        $this->service = new SynchronizationService(
            $callService,
            $mappingService,
            $container,
            $this->orObjectService,
            $objectService,
            $storageService,
            $this->logger,
            $appConfig,
        );
    }//end setUp()


    /**
     * Test that the constructor instantiates SynchronizationService without errors.
     *
     * @return void
     */
    public function testConstructorWiresDependencies(): void
    {
        $this->assertInstanceOf(SynchronizationService::class, $this->service);
    }//end testConstructorWiresDependencies()


    /**
     * Test that getSynchronization by id returns the entity from OR find.
     *
     * @return void
     */
    public function testGetSynchronizationByIdReturnsFindResult(): void
    {
        // Arrange
        $syncEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['name' => 'my-sync'],
            'sync-uuid-1'
        );

        $this->orObjectService->method('find')->willReturn($syncEntity);

        // Act
        $result = $this->service->getSynchronization('sync-uuid-1');

        // Assert
        $this->assertSame($syncEntity, $result);
    }//end testGetSynchronizationByIdReturnsFindResult()


    /**
     * Test that getSynchronization throws DoesNotExistException when OR find returns null.
     *
     * @return void
     */
    public function testGetSynchronizationThrowsWhenNotFound(): void
    {
        // Arrange
        $this->orObjectService->method('find')->willReturn(null);

        // Assert
        $this->expectException(DoesNotExistException::class);

        // Act
        $this->service->getSynchronization('non-existent-uuid');
    }//end testGetSynchronizationThrowsWhenNotFound()


    /**
     * Test findAllBySourceId returns results array from OR findAll.
     *
     * @return void
     */
    public function testFindAllBySourceIdReturnsSynchronizations(): void
    {
        // Arrange
        $syncEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['name' => 'sync-a'],
            'sync-uuid-2'
        );

        $this->orObjectService->method('findAll')
            ->willReturn(['results' => [$syncEntity], 'total' => 1]);

        // Act
        $result = $this->service->findAllBySourceId('openconnector', 'source');

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertSame($syncEntity, $result[0]);
    }//end testFindAllBySourceIdReturnsSynchronizations()


    /**
     * Test handleObjectEventSynchronization silently ignores invalid mutation types.
     *
     * @return void
     */
    public function testHandleObjectEventIgnoresInvalidMutationType(): void
    {
        // Arrange
        $objectEntity = ObjectServiceMockBuilder::objectEntity(
            $this,
            ['register' => 'openconnector', 'schema' => 'source'],
            'obj-uuid-1'
        );

        $objectEntity->method('getRegister')->willReturn('openconnector');
        $objectEntity->method('getSchema')->willReturn('source');

        // OR findAll must not be called for invalid mutation type
        $this->orObjectService->expects($this->never())->method('findAll');

        // Act — 'invalid_type' is not in VALID_MUTATION_TYPES
        $this->service->handleObjectEventSynchronization($objectEntity, 'invalid_type');

        // Assert — reached here means no exception was thrown
        $this->assertTrue(true);
    }//end testHandleObjectEventIgnoresInvalidMutationType()


    /**
     * Test sortNestedArray returns true for a non-empty array.
     *
     * @return void
     */
    public function testSortNestedArrayReturnsTrueForNonEmptyArray(): void
    {
        // Arrange
        $array = ['b' => 2, 'a' => 1, 'c' => 3];

        // Act
        $result = $this->service->sortNestedArray($array);

        // Assert
        $this->assertTrue($result);
        // Keys should now be in alphabetical order
        $this->assertSame(['a', 'b', 'c'], array_keys($array));
    }//end testSortNestedArrayReturnsTrueForNonEmptyArray()


}//end class
