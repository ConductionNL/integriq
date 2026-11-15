<?php

/**
 * The agent action store saves and finds agent actions in the integriq register without RBAC, and finds nothing rather than failing.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\AgentTools
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\AgentTools;

use OCA\Integriq\Service\AgentTools\AgentActionStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Integriq\Service\AgentTools\AgentActionStore
 */
class AgentActionStoreTest extends TestCase {

	/**
	 * A record is saved to the agent_action schema of the integriq register, without RBAC or multitenancy.
	 *
	 * @return void
	 */
	public function testARecordIsSavedAndItsUuidReturned(): void {
		$saved = $this->createMock(originalClassName: ObjectEntity::class);
		$saved->method('getUuid')->willReturn('uuid-1');
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->expects($this->once())
			->method('saveObject')
			->with(
				['tool' => 'send', 'status' => 'proposed'],
				'integriq',
				'agent_action'
			)
			->willReturnCallback(
				function (array $object, string $register, string $schema, ?string $uuid, bool $_rbac, bool $_multitenancy) use ($saved) {
					$this->assertNull(actual: $uuid);
					$this->assertFalse(condition: $_rbac);
					$this->assertFalse(condition: $_multitenancy);
					return $saved;
				}
			);

		$uuid = (new AgentActionStore(objectService: $objects))->record(record: ['tool' => 'send', 'status' => 'proposed']);

		$this->assertSame(expected: 'uuid-1', actual: $uuid);
	}//end testARecordIsSavedAndItsUuidReturned()

	/**
	 * A stored action is found by uuid, without RBAC or multitenancy, as its object data.
	 *
	 * @return void
	 */
	public function testAnActionIsFoundAsItsObject(): void {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('getObject')->willReturn(['tool' => 'send']);
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->expects($this->once())
			->method('find')
			->willReturnCallback(
				function ($id, ?string $register, ?string $schema, bool $_rbac, bool $_multitenancy) use ($entity) {
					$this->assertSame(
						expected: ['uuid-1', 'integriq', 'agent_action', false, false],
						actual: [$id, $register, $schema, $_rbac, $_multitenancy]
					);
					return $entity;
				}
			);

		$this->assertSame(expected: ['tool' => 'send'], actual: (new AgentActionStore(objectService: $objects))->find(uuid: 'uuid-1'));
	}//end testAnActionIsFoundAsItsObject()

	/**
	 * A missing action, a lookup failure and an empty answer all find nothing.
	 *
	 * @return void
	 */
	public function testAMissingOrFailingLookupFindsNothing(): void {
		$objects = $this->createMock(originalClassName: ObjectService::class);
		$objects->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(exception: new DoesNotExistException('gone')),
			$this->throwException(exception: new RuntimeException('database down')),
			null
		);
		$store = new AgentActionStore(objectService: $objects);

		$this->assertNull(actual: $store->find(uuid: 'a'));
		$this->assertNull(actual: $store->find(uuid: 'b'));
		$this->assertNull(actual: $store->find(uuid: 'c'));
	}//end testAMissingOrFailingLookupFindsNothing()
}//end class
