<?php
/**
 * Unit tests for MappingService (chain-C, OR-direct).
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
use OCA\OpenRegister\Db\Mapping as OrMapping;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Tests for the mapping execution service after chain-C cutover.
 *
 * MappingService now consumes OpenRegister's ObjectService directly; the legacy
 * `OCA\OpenConnector\Db\Mapping*` types are no longer referenced.
 */
class MappingServiceTest extends TestCase
{

    /**
     * @var MappingService
     */
    private MappingService $service;

    /**
     * @var OrObjectService&MockObject
     */
    private OrObjectService $orObjectService;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->orObjectService = $this->createMock(OrObjectService::class);

        $loader        = new ArrayLoader([]);
        $callService   = $this->createMock(CallService::class);
        $fileService   = $this->createMock(FileService::class);
        $objectService = $this->createMock(ObjectService::class);

        $this->service = new MappingService(
            $loader,
            $callService,
            $fileService,
            $objectService,
            $this->orObjectService,
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
        $array  = ['foo.bar' => 'value', 'baz' => 'other'];
        $result = $this->service->encodeArrayKeys($array, '.', '__DOT__');

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
        $array  = ['parent' => ['child.key' => 'deep-value']];
        $result = $this->service->encodeArrayKeys($array, '.', '_');

        $this->assertArrayHasKey('child_key', $result['parent']);
        $this->assertSame('deep-value', $result['parent']['child_key']);
    }//end testEncodeArrayKeysRecursesIntoNestedArrays()


    /**
     * Test that getMapping resolves an OpenRegister ObjectEntity into an OrMapping.
     *
     * @return void
     */
    public function testGetMappingHydratesOrMappingFromObjectEntity(): void
    {
        $object = new ObjectEntity();
        $object->setObject(
            [
                'name'        => 'demo',
                'mapping'     => ['greet' => 'hi'],
                'passThrough' => true,
            ]
        );

        $this->orObjectService->expects($this->once())
            ->method('find')
            ->with(id: 'map-uuid-42', register: 'openconnector', schema: 'mapping')
            ->willReturn($object);

        $result = $this->service->getMapping('map-uuid-42');

        $this->assertInstanceOf(OrMapping::class, $result);
        $this->assertSame('demo', $result->getName());
        $this->assertSame(['greet' => 'hi'], $result->getMapping());
    }//end testGetMappingHydratesOrMappingFromObjectEntity()


    /**
     * Test that getMapping raises DoesNotExistException when OR returns null.
     *
     * @return void
     */
    public function testGetMappingThrowsWhenObjectMissing(): void
    {
        $this->orObjectService->expects($this->once())
            ->method('find')
            ->willReturn(null);

        $this->expectException(DoesNotExistException::class);
        $this->service->getMapping('missing-uuid');
    }//end testGetMappingThrowsWhenObjectMissing()


    /**
     * Test that getMappings delegates to OR findAll and hydrates the results.
     *
     * @return void
     */
    public function testGetMappingsHydratesAllResults(): void
    {
        $first  = new ObjectEntity();
        $first->setObject(['name' => 'first', 'mapping' => ['a' => 'b']]);

        $second = new ObjectEntity();
        $second->setObject(['name' => 'second', 'mapping' => ['c' => 'd']]);

        $this->orObjectService->expects($this->once())
            ->method('findAll')
            ->willReturn(['results' => [$first, $second], 'total' => 2]);

        $result = $this->service->getMappings();

        $this->assertCount(2, $result);
        $this->assertContainsOnlyInstancesOf(OrMapping::class, $result);
        $this->assertSame('first', $result[0]->getName());
        $this->assertSame('second', $result[1]->getName());
    }//end testGetMappingsHydratesAllResults()


    /**
     * Test that executeMapping accepts an array payload directly.
     *
     * @return void
     */
    public function testExecuteMappingAcceptsArrayPayload(): void
    {
        $payload = [
            'name'        => 'inline',
            'mapping'     => ['out' => 'in.value'],
            'passThrough' => false,
        ];

        $result = $this->service->executeMapping(
            $payload,
            ['in' => ['value' => 'hello']],
            false
        );

        $this->assertSame(['out' => 'hello'], $result);
    }//end testExecuteMappingAcceptsArrayPayload()


    /**
     * Test that executeMapping accepts a hydrated OrMapping value object.
     *
     * @return void
     */
    public function testExecuteMappingAcceptsHydratedOrMapping(): void
    {
        $mapping = (new OrMapping())->hydrate(
            [
                'name'        => 'hydrated',
                'mapping'     => ['copy' => 'src'],
                'passThrough' => false,
            ]
        );

        $result = $this->service->executeMapping($mapping, ['src' => 'value']);

        $this->assertSame(['copy' => 'value'], $result);
    }//end testExecuteMappingAcceptsHydratedOrMapping()


    /**
     * Test that executeMapping accepts an ObjectEntity directly.
     *
     * @return void
     */
    public function testExecuteMappingAcceptsObjectEntity(): void
    {
        $object = new ObjectEntity();
        $object->setObject(
            [
                'name'        => 'entity-form',
                'mapping'     => ['x' => 'a.b'],
                'passThrough' => false,
            ]
        );

        $result = $this->service->executeMapping($object, ['a' => ['b' => 'deep']]);

        $this->assertSame(['x' => 'deep'], $result);
    }//end testExecuteMappingAcceptsObjectEntity()


    /**
     * Test that executeMapping resolves a string id through OR.
     *
     * @return void
     */
    public function testExecuteMappingResolvesStringIdThroughOr(): void
    {
        $object = new ObjectEntity();
        $object->setObject(
            [
                'name'        => 'resolved',
                'mapping'     => ['out' => 'in'],
                'passThrough' => false,
            ]
        );

        $this->orObjectService->expects($this->once())
            ->method('find')
            ->with(id: 'lookup-uuid', register: 'openconnector', schema: 'mapping')
            ->willReturn($object);

        $result = $this->service->executeMapping('lookup-uuid', ['in' => 'value']);

        $this->assertSame(['out' => 'value'], $result);
    }//end testExecuteMappingResolvesStringIdThroughOr()


    /**
     * Test that executeMapping renders Twig template strings.
     *
     * @return void
     */
    public function testExecuteMappingRendersTwigTemplate(): void
    {
        $payload = [
            'name'        => 'twig',
            'mapping'     => ['rendered' => '{{ name | upper }}'],
            'passThrough' => false,
        ];

        $result = $this->service->executeMapping($payload, ['name' => 'world']);

        $this->assertSame(['rendered' => 'WORLD'], $result);
    }//end testExecuteMappingRendersTwigTemplate()


    /**
     * Test that coordinateStringToArray splits a coordinate pair list.
     *
     * @return void
     */
    public function testCoordinateStringToArraySplitsPairs(): void
    {
        $result = $this->service->coordinateStringToArray('4.88525 52.37025');

        $this->assertSame(['4.88525', '52.37025'], $result);
    }//end testCoordinateStringToArraySplitsPairs()


    /**
     * Test that coordinateStringToArray collects multiple pairs.
     *
     * @return void
     */
    public function testCoordinateStringToArrayCollectsMultiplePairs(): void
    {
        $result = $this->service->coordinateStringToArray('1.0 2.0 3.0 4.0');

        $this->assertSame([['1.0', '2.0'], ['3.0', '4.0']], $result);
    }//end testCoordinateStringToArrayCollectsMultiplePairs()

}//end class
