<?php

/**
 * Unit tests for ConnectionStore's source and row lookups.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/specs/connection-registry/spec.md#requirement-add-integration-links-a-source-and-probes-it-at-once-req-conn-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service;

use OCA\Integriq\Service\ConnectionStore;
use OCA\Integriq\Service\SystemWrite;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as OrObjectService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Lookups by uuid and slug, raw re-reads, creation and deletion.
 */
#[CoversClass(ConnectionStore::class)]
#[UsesClass(SystemWrite::class)]
class ConnectionStoreSourceTest extends TestCase {

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
	 * An object service mock with the given methods.
	 *
	 * @param array<int,string> $methods The mocked methods.
	 *
	 * @return OrObjectService&MockObject
	 */
	private function objects(array $methods): OrObjectService&MockObject {
		return $this->getMockBuilder(className: OrObjectService::class)->disableOriginalConstructor()->onlyMethods($methods)->getMock();
	}//end objects()

	/**
	 * findRow returns the uuid and data of the row found.
	 *
	 * @return void
	 */
	public function testFindRowReturnsTheRow(): void {
		$objects = $this->objects(methods: ['find']);
		$objects->expects($this->once())->method('find')
			->with('r-1', 'integriq', 'app_connection', false, false)
			->willReturn($this->entity(uuid: 'r-1', data: ['app' => 'dossiq']));

		$this->assertSame(
			expected: ['uuid' => 'r-1', 'data' => ['app' => 'dossiq']],
			actual: (new ConnectionStore(objectService: $objects))->findRow('r-1')
		);
	}//end testFindRowReturnsTheRow()

	/**
	 * findRows without an app filters on the schema only and skips non-entities.
	 *
	 * @return void
	 */
	public function testFindRowsWithoutAppTakesEveryEntity(): void {
		$objects = $this->objects(methods: ['findAll']);
		$objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'app_connection'], 'limit' => 1000], false, false)
			->willReturn([$this->entity(uuid: 'a', data: ['app' => 'dossiq']), ['not' => 'an entity'], $this->entity(uuid: 'b', data: ['app' => 'shillinq'])]);

		$rows = (new ConnectionStore(objectService: $objects))->findRows();

		$this->assertSame(
			expected: [['uuid' => 'a', 'data' => ['app' => 'dossiq']], ['uuid' => 'b', 'data' => ['app' => 'shillinq']]],
			actual: $rows
		);
	}//end testFindRowsWithoutAppTakesEveryEntity()

	/**
	 * findSourceBySlug returns the first entity whose slug matches exactly.
	 *
	 * @return void
	 */
	public function testFindSourceBySlugMatchesTheSlugAgain(): void {
		$wanted = $this->entity(uuid: 's-2', data: ['slug' => 'zgw']);
		$objects = $this->objects(methods: ['findAll']);
		$objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'integriq', 'schema' => 'source', 'slug' => 'zgw']], false, false)
			->willReturn(['results' => [$this->entity(uuid: 's-1', data: ['slug' => 'zgw-old']), $wanted]]);

		$this->assertSame(expected: $wanted, actual: (new ConnectionStore(objectService: $objects))->findSourceBySlug('zgw'));
	}//end testFindSourceBySlugMatchesTheSlugAgain()

	/**
	 * findSourceBySlug answers null when nothing matches.
	 *
	 * @return void
	 */
	public function testFindSourceBySlugAnswersNullWithoutAMatch(): void {
		$objects = $this->objects(methods: ['findAll']);
		$objects->method('findAll')->willReturn(['results' => [$this->entity(uuid: 's-1', data: ['slug' => 'other'])]]);

		$this->assertNull((new ConnectionStore(objectService: $objects))->findSourceBySlug('zgw'));
	}//end testFindSourceBySlugAnswersNullWithoutAMatch()

	/**
	 * readSourceRaw re-reads unrendered by uuid and returns the raw entity.
	 *
	 * @return void
	 */
	public function testReadSourceRawReturnsTheUnrenderedSource(): void {
		$raw = $this->entity(uuid: 's-1', data: ['secret' => 'kept']);
		$objects = $this->objects(methods: ['find']);
		$objects->expects($this->once())->method('find')
			->with('s-1', 'integriq', 'source', false, false, false)
			->willReturn($raw);

		$this->assertSame(
			expected: $raw,
			actual: (new ConnectionStore(objectService: $objects))->readSourceRaw($this->entity(uuid: 's-1', data: []))
		);
	}//end testReadSourceRawReturnsTheUnrenderedSource()

	/**
	 * readSourceRaw keeps the source passed in when the re-read fails or has no uuid.
	 *
	 * @return void
	 */
	public function testReadSourceRawFallsBackToTheSourceGiven(): void {
		$objects = $this->objects(methods: ['find']);
		$objects->expects($this->once())->method('find')->willThrowException(new \RuntimeException('down'));
		$store = new ConnectionStore(objectService: $objects);

		$given = $this->entity(uuid: 's-1', data: ['slug' => 'zgw']);
		$this->assertSame(expected: $given, actual: $store->readSourceRaw($given));

		$noUuid = new ObjectEntity();
		$this->assertSame(expected: $noUuid, actual: $store->readSourceRaw($noUuid));
	}//end testReadSourceRawFallsBackToTheSourceGiven()

	/**
	 * createSource saves the payload in the source schema.
	 *
	 * @return void
	 */
	public function testCreateSourceSavesInTheSourceSchema(): void {
		$created = $this->entity(uuid: 's-9', data: ['slug' => 'new']);
		$objects = $this->objects(methods: ['saveObject']);
		$objects->expects($this->once())->method('saveObject')
			->with(['slug' => 'new'], 'integriq', 'source')
			->willReturn($created);

		$this->assertSame(expected: $created, actual: (new ConnectionStore(objectService: $objects))->createSource(['slug' => 'new']));
	}//end testCreateSourceSavesInTheSourceSchema()

	/**
	 * delete removes the row from the connection schema.
	 *
	 * @return void
	 */
	public function testDeleteRemovesTheRow(): void {
		$objects = $this->objects(methods: ['deleteObject']);
		$objects->expects($this->once())->method('deleteObject')
			->with('r-1', 'integriq', 'app_connection')
			->willReturn(true);

		(new ConnectionStore(objectService: $objects))->delete('r-1');
	}//end testDeleteRemovesTheRow()
}//end class
