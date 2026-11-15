<?php

/**
 * Unit tests for WriteBackConflictReader.
 *
 * An object is in write-back conflict only when a register/schema
 * synchronization names a conflict-status property and the object carries
 * "conflict" in it. Every failure to read reports "no conflict".
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Integration;

use OCA\Integriq\Service\Integration\WriteBackConflictReader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Conflict detection over a mocked OpenRegister ObjectService.
 *
 * @covers \OCA\Integriq\Service\Integration\WriteBackConflictReader
 */
class WriteBackConflictReaderTest extends TestCase {

	/**
	 * The ObjectService double.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Build the ObjectService double; register and schema setters chain.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectService::class);
		$this->objectService->method('setRegister')->willReturnSelf();
		$this->objectService->method('setSchema')->willReturnSelf();

	}//end setUp()

	/**
	 * Build an ObjectEntity carrying the given body.
	 *
	 * @param array<string, mixed> $body The object body.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $body): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($body);
		return $entity;

	}//end entity()

	/**
	 * Make the synchronization listing return the given result.
	 *
	 * @param array<mixed> $found The findAll() result.
	 *
	 * @return void
	 */
	private function synchronizations(array $found): void {
		$this->objectService->method('findAll')
			->with(['filters' => ['sourceType' => 'register/schema']])
			->willReturn($found);

	}//end synchronizations()

	/**
	 * The object is in conflict when a configured property holds "conflict";
	 * the lookup is for the asked register, schema and id.
	 *
	 * @return void
	 */
	public function testConflictValueInAConfiguredPropertyIsAConflict(): void {
		$this->synchronizations(
			[
				'results' => [
					$this->entity(['targetConfig' => ['conflictStatusProperty' => 'syncStatus']]),
					['object' => ['targetConfig' => ['conflictStatusProperty' => 'writeBack']]],
				],
			]
		);
		$this->objectService->expects($this->once())->method('find')
			->with('obj-1', 'crm', 'contact')
			->willReturn($this->entity(['writeBack' => 'conflict']));

		$reader = new WriteBackConflictReader(objectService: $this->objectService);

		$this->assertTrue($reader->hasConflict(register: 'crm', schema: 'contact', objectId: 'obj-1'));

	}//end testConflictValueInAConfiguredPropertyIsAConflict()

	/**
	 * Any other value, or "conflict" in a property no synchronization names,
	 * is not a conflict.
	 *
	 * @return void
	 */
	public function testOtherValuesOrUnconfiguredPropertiesAreNoConflict(): void {
		$this->synchronizations(
			[['targetConfig' => ['conflictStatusProperty' => 'syncStatus']]]
		);
		$this->objectService->method('find')
			->willReturn($this->entity(['syncStatus' => 'synced', 'otherStatus' => 'conflict']));

		$reader = new WriteBackConflictReader(objectService: $this->objectService);

		$this->assertFalse($reader->hasConflict(register: 'crm', schema: 'contact', objectId: 'obj-1'));

	}//end testOtherValuesOrUnconfiguredPropertiesAreNoConflict()

	/**
	 * Without any synchronization naming a property (empty, non-string or
	 * missing), the object is never even read.
	 *
	 * @return void
	 */
	public function testNoConfiguredPropertyMeansNoConflictWithoutReadingTheObject(): void {
		$this->synchronizations(
			[
				'results' => [
					['targetConfig' => ['conflictStatusProperty' => '']],
					['targetConfig' => ['conflictStatusProperty' => 42]],
					['targetConfig' => []],
					'not a synchronization',
				],
			]
		);
		$this->objectService->expects($this->never())->method('find');

		$reader = new WriteBackConflictReader(objectService: $this->objectService);

		$this->assertFalse($reader->hasConflict(register: 'crm', schema: 'contact', objectId: 'obj-1'));

	}//end testNoConfiguredPropertyMeansNoConflictWithoutReadingTheObject()

	/**
	 * When the synchronizations cannot be listed, nothing is in conflict.
	 *
	 * @return void
	 */
	public function testUnreadableSynchronizationsMeanNoConflict(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('register missing'));
		$this->objectService->expects($this->never())->method('find');

		$reader = new WriteBackConflictReader(objectService: $this->objectService);

		$this->assertFalse($reader->hasConflict(register: 'crm', schema: 'contact', objectId: 'obj-1'));

	}//end testUnreadableSynchronizationsMeanNoConflict()

	/**
	 * When the object cannot be read or is not found, it is not in conflict.
	 *
	 * @return void
	 */
	public function testUnreadableOrMissingObjectMeansNoConflict(): void {
		$this->synchronizations(
			[['targetConfig' => ['conflictStatusProperty' => 'syncStatus']]]
		);
		$this->objectService->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('not found')),
			null
		);

		$reader = new WriteBackConflictReader(objectService: $this->objectService);

		$this->assertFalse($reader->hasConflict(register: 'crm', schema: 'contact', objectId: 'gone'));
		$this->assertFalse($reader->hasConflict(register: 'crm', schema: 'contact', objectId: 'null'));

	}//end testUnreadableOrMissingObjectMeansNoConflict()
}//end class
