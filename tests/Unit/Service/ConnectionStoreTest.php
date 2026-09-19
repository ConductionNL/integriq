<?php

/**
 * Unit tests for ConnectionStore (connection-registry, umbrella D3).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/connection-registry/specs/connection-registry/spec.md#requirement-the-sync-is-idempotent-and-keeps-linked-rows-req-conn-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ConnectionStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Row reads filter by app, writes drop nulls and unknown keys.
 */
class ConnectionStoreTest extends TestCase {

	/**
	 * An object entity.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string,mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * findRows filters on the app_connection schema and re-checks the app.
	 *
	 * @return void
	 */
	public function testFindRowsFiltersByApp(): void {
		$objects = $this->getMockBuilder(className: OrObjectService::class)->disableOriginalConstructor()->onlyMethods(['findAll'])->getMock();
		$objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'app_connection', 'app' => 'dossiq'], 'limit' => 1000], false, false)
			->willReturn(['results' => [$this->entity(uuid: 'a', data: ['app' => 'dossiq']), $this->entity(uuid: 'b', data: ['app' => 'shillinq'])]]);

		$rows = (new ConnectionStore(objectService: $objects))->findRows('dossiq');

		$this->assertSame(expected: [['uuid' => 'a', 'data' => ['app' => 'dossiq']]], actual: $rows);
	}//end testFindRowsFiltersByApp()

	/**
	 * save sends only the D3 properties that hold a value.
	 *
	 * @return void
	 */
	public function testSaveDropsNullsAndUnknownKeys(): void {
		$objects = $this->getMockBuilder(className: OrObjectService::class)->disableOriginalConstructor()->onlyMethods(['saveObject'])->getMock();
		$objects->expects($this->once())->method('saveObject')
			->with(['app' => 'dossiq', 'key' => 'zgw', 'status' => 'unconfigured'], 'integriq', 'app_connection', 'u-1')
			->willReturn($this->entity(uuid: 'u-1', data: []));

		$uuid = (new ConnectionStore(objectService: $objects))->save(
			['@self' => ['id' => 1], 'app' => 'dossiq', 'key' => 'zgw', 'status' => 'unconfigured', 'checkedAt' => null, 'source' => null],
			'u-1'
		);

		$this->assertSame(expected: 'u-1', actual: $uuid);
	}//end testSaveDropsNullsAndUnknownKeys()

	/**
	 * payload compares equal regardless of key order.
	 *
	 * @return void
	 */
	public function testPayloadIsKeyOrderInsensitive(): void {
		$store = new ConnectionStore(objectService: $this->createMock(originalClassName: OrObjectService::class));

		$this->assertSame(
			expected: $store->payload(['declaration' => ['title' => 'A', 'key' => 'a'], 'app' => 'x']),
			actual: $store->payload(['app' => 'x', 'declaration' => ['key' => 'a', 'title' => 'A']])
		);
	}//end testPayloadIsKeyOrderInsensitive()

	/**
	 * A missing row or source reads as null.
	 *
	 * @return void
	 */
	public function testMissingObjectIsNull(): void {
		$objects = $this->getMockBuilder(className: OrObjectService::class)->disableOriginalConstructor()->onlyMethods(['find'])->getMock();
		$objects->method('find')->willThrowException(new DoesNotExistException('gone'));
		$store = new ConnectionStore(objectService: $objects);

		$this->assertNull(actual: $store->findRow('x'));
		$this->assertNull(actual: $store->findSource('y'));
	}//end testMissingObjectIsNull()
}//end class
