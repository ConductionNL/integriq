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
 *
 * Note (#1015): `ObjectEntity::getUuid()` is a magic method via `Entity::__call`,
 * which PHPUnit cannot stub through `MockObject::method()` — the call raises
 * `MethodCannotBeConfiguredException`. Earlier versions of this helper
 * `createMock(ObjectEntity::class)` + `->method('getUuid')`, which crashed the
 * shared `setUp()` of every suite that used it (CallServiceTest, JobServiceTest,
 * SynchronizationServiceTest). The fix here builds a real `ObjectEntity` and
 * hydrates the magic `object` payload + uuid via Nextcloud's standard `Entity`
 * setters, so the magic getters (`getUuid`, `getObject`, etc.) work via the
 * real `__call` path instead of being mocked.
 */
class ObjectServiceMockBuilder {

	/**
	 * Build a basic ObjectService mock with sensible defaults:
	 *   - find()       returns a real ObjectEntity (uuid auto-generated)
	 *   - findAll()    returns ['results' => [], 'total' => 0]
	 *   - saveObject() returns a real ObjectEntity
	 *   - deleteObject() returns true
	 *
	 * @param TestCase $test The calling test instance (provides createMock()).
	 * @return MockObject A MockObject that stubs ObjectService.
	 */
	public static function make(TestCase $test): MockObject {
		$mock = $test->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->getMock();

		// PHPUnit treats every ->method('x')->willReturn() as adding a NEW
		// matcher; the first one matches the first invocation, the second
		// matches the second. Pre-configuring find/findAll/saveObject here
		// meant tests that added their own willReturn/willReturnCallback
		// never overrode the defaults — the engine kept seeing the
		// make()-supplied value instead of the test-supplied one (this
		// surfaced once #1015 unblocked the suites from running at all).
		// Configure ONLY the methods most callers don't override
		// (deleteObject). Tests that need find / findAll / saveObject must
		// configure them explicitly.
		$mock->method('deleteObject')->willReturn(true);

		return $mock;
	}//end make()

	/**
	 * Build an ObjectService mock whose find() call returns an entity
	 * populated with the given body and uuid.
	 *
	 * @param TestCase $test The calling test instance.
	 * @param array $object The body array to embed in the returned ObjectEntity.
	 * @param string|null $uuid UUID to attach to the entity. Defaults to 'test-uuid'.
	 * @return MockObject
	 */
	public static function withFind(TestCase $test, array $object, ?string $uuid = null): MockObject {
		$mock = $test->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->getMock();

		$entity = self::objectEntity($test, $object, $uuid ?? 'test-uuid');
		$savedEntity = self::objectEntity($test, $object, $uuid ?? 'test-uuid');

		$mock->method('find')->willReturn($entity);
		$mock->method('findAll')->willReturn(['results' => [$entity], 'total' => 1]);
		$mock->method('saveObject')->willReturn($savedEntity);
		$mock->method('deleteObject')->willReturn(true);

		return $mock;
	}//end withFind()

	/**
	 * Build an ObjectService mock whose findAll() returns a shaped result set.
	 *
	 * @param TestCase $test The calling test instance.
	 * @param array $rows Array of body arrays; each becomes an ObjectEntity instance.
	 * @return MockObject
	 */
	public static function withFindAll(TestCase $test, array $rows): MockObject {
		$mock = $test->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->getMock();

		$entities = array_map(
			static fn (array $row, int $idx) => self::objectEntity($test, $row, 'uuid-' . $idx),
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
	 * Create a real `ObjectEntity` pre-populated with a body array and uuid.
	 *
	 * Unlike a PHPUnit mock, this returns the genuine ObjectEntity so its
	 * magic getters (`getUuid`, `getObject`, `setObject`, …) work through
	 * the real `Entity::__call` path — PHPUnit cannot stub those because
	 * they are declared via `@method` on a magic `__call`, not as physical
	 * methods. Stubbing them via `->method('getUuid')` raises
	 * `MethodCannotBeConfiguredException` ("method does not exist, has not
	 * been specified, is final, or is static") and prevented several unit
	 * suites from running at all (#1015).
	 *
	 * The `$test` parameter is retained for backward compatibility with
	 * existing call sites — it is no longer used here.
	 *
	 * @param TestCase $test The calling test instance (unused, kept for back-compat).
	 * @param array $body The data to return from getObject().
	 * @param string|null $uuid The uuid to return from getUuid(). Defaults to 'test-uuid'.
	 * @return ObjectEntity A real ObjectEntity instance hydrated with the body + uuid.
	 */
	public static function objectEntity(TestCase $test, array $body, ?string $uuid = null): ObjectEntity {
		$entity = new ObjectEntity();
		// Positional args only — Entity::__call's setter() uses $args[0].
		// Named args on magic setters silently miscompose.
		$entity->setUuid($uuid ?? 'test-uuid');
		$entity->setObject($body);

		return $entity;
	}//end objectEntity()

}//end class
