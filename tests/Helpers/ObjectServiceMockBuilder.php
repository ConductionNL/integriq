<?php
/**
 * ObjectServiceMockBuilder — static factory for OR ObjectService test doubles.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Helpers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Helpers;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Static factory that produces pre-configured PHPUnit mock objects for
 * {@see ObjectService} so that individual test classes do not repeat
 * the same boilerplate.
 *
 * Usage in a test's setUp():
 *
 *   $this->objectService = ObjectServiceMockBuilder::make($this);
 *
 * Or when you need a specific find() response:
 *
 *   $this->objectService = ObjectServiceMockBuilder::withFind(
 *       $this, ['name' => 'test-source'], 'some-uuid'
 *   );
 */
class ObjectServiceMockBuilder
{

    /**
     * Build a basic ObjectService mock with sensible defaults:
     *   - find()       returns a mock ObjectEntity (uuid auto-generated)
     *   - findAll()    returns ['results' => [], 'total' => 0]
     *   - saveObject() returns a mock ObjectEntity
     *   - deleteObject() returns true
     *
     * @param  TestCase $test The calling test instance (provides createMock()).
     * @return MockObject A MockObject that stubs ObjectService.
     */
    public static function make(TestCase $test): MockObject
    {
        $mock = $test->getMockBuilder(ObjectService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $defaultEntity = self::objectEntity($test, [], 'default-uuid');

        $mock->method('find')->willReturn($defaultEntity);
        $mock->method('findAll')->willReturn(['results' => [], 'total' => 0]);
        $mock->method('saveObject')->willReturn($defaultEntity);
        $mock->method('deleteObject')->willReturn(true);

        return $mock;
    }//end make()


    /**
     * Build an ObjectService mock whose find() call returns an entity
     * populated with the given body and uuid.
     *
     * @param  TestCase    $test   The calling test instance.
     * @param  array       $object The body array to embed in the returned ObjectEntity.
     * @param  string|null $uuid   UUID to attach to the entity. Defaults to 'test-uuid'.
     * @return MockObject
     */
    public static function withFind(TestCase $test, array $object, ?string $uuid=null): MockObject
    {
        $mock = $test->getMockBuilder(ObjectService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $entity       = self::objectEntity($test, $object, $uuid ?? 'test-uuid');
        $savedEntity  = self::objectEntity($test, $object, $uuid ?? 'test-uuid');

        $mock->method('find')->willReturn($entity);
        $mock->method('findAll')->willReturn(['results' => [$entity], 'total' => 1]);
        $mock->method('saveObject')->willReturn($savedEntity);
        $mock->method('deleteObject')->willReturn(true);

        return $mock;
    }//end withFind()


    /**
     * Build an ObjectService mock whose findAll() returns a shaped result set.
     *
     * @param  TestCase $test The calling test instance.
     * @param  array    $rows Array of body arrays; each becomes an ObjectEntity mock.
     * @return MockObject
     */
    public static function withFindAll(TestCase $test, array $rows): MockObject
    {
        $mock = $test->getMockBuilder(ObjectService::class)
            ->disableOriginalConstructor()
            ->getMock();

        $entities = array_map(
            static fn(array $row, int $idx) => self::objectEntity($test, $row, 'uuid-'.$idx),
            $rows,
            array_keys($rows)
        );

        $defaultEntity = empty($entities) === false ? $entities[0] : self::objectEntity($test, [], 'default-uuid');

        $mock->method('find')->willReturn($defaultEntity);
        $mock->method('findAll')->willReturn(['results' => $entities, 'total' => count($entities)]);
        $mock->method('saveObject')->willReturn($defaultEntity);
        $mock->method('deleteObject')->willReturn(true);

        return $mock;
    }//end withFindAll()


    /**
     * Create a lightweight ObjectEntity mock pre-populated with a body array
     * and optional uuid.
     *
     * @param  TestCase    $test The calling test instance.
     * @param  array       $body The data to return from getObject().
     * @param  string|null $uuid The uuid to return from getUuid(). Defaults to 'test-uuid'.
     * @return MockObject An ObjectEntity MockObject.
     */
    public static function objectEntity(TestCase $test, array $body, ?string $uuid=null): MockObject
    {
        $entity = $test->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->getMock();

        $entity->method('getUuid')->willReturn($uuid ?? 'test-uuid');
        $entity->method('getObject')->willReturn($body);
        $entity->method('jsonSerialize')->willReturn(array_merge(['uuid' => $uuid ?? 'test-uuid'], $body));

        return $entity;
    }//end objectEntity()


}//end class
