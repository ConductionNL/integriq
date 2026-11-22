<?php

/**
 * Object event synchronisation listeners Unit Tests
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\ObjectCreatedEventListener;
use OCA\Integriq\EventListener\ObjectDeletedEventListener;
use OCA\Integriq\EventListener\ObjectUpdatedEventListener;
use OCA\Integriq\Service\SynchronizationServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Each object listener synchronises its own event's object with its own mutation type and ignores every other event.
 *
 * @covers \OCA\Integriq\EventListener\ObjectCreatedEventListener
 * @covers \OCA\Integriq\EventListener\ObjectUpdatedEventListener
 * @covers \OCA\Integriq\EventListener\ObjectDeletedEventListener
 */
class ObjectEventSynchronizationListenersTest extends TestCase {

	private SynchronizationServiceInterface&MockObject $sync;

	protected function setUp(): void {
		$this->sync = $this->createMock(SynchronizationServiceInterface::class);
	}//end setUp()

	/**
	 * An object with a uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		return $object;
	}//end object()

	public function testACreatedObjectIsSynchronisedAsACreate(): void {
		$object = $this->object('o-1');
		$this->sync->expects($this->once())->method('handleObjectEventSynchronization')->with($object, 'create');

		(new ObjectCreatedEventListener($this->sync))->handle(new ObjectCreatedEvent($object));
	}//end testACreatedObjectIsSynchronisedAsACreate()

	public function testAnUpdatedObjectIsSynchronisedAsAnUpdateOfItsNewState(): void {
		$new = $this->object('o-new');
		$old = $this->object('o-old');
		$this->sync->expects($this->once())->method('handleObjectEventSynchronization')->with($new, 'update');

		(new ObjectUpdatedEventListener($this->sync))->handle(new ObjectUpdatedEvent($new, $old));
	}//end testAnUpdatedObjectIsSynchronisedAsAnUpdateOfItsNewState()

	public function testADeletedObjectIsSynchronisedAsADelete(): void {
		$object = $this->object('o-2');
		$this->sync->expects($this->once())->method('handleObjectEventSynchronization')->with($object, 'delete');

		(new ObjectDeletedEventListener($this->sync))->handle(new ObjectDeletedEvent($object));
	}//end testADeletedObjectIsSynchronisedAsADelete()

	public function testEachListenerIgnoresEveryOtherEvent(): void {
		$this->sync->expects($this->never())->method('handleObjectEventSynchronization');
		$object = $this->object('o-3');

		(new ObjectCreatedEventListener($this->sync))->handle(new ObjectUpdatedEvent($object));
		(new ObjectCreatedEventListener($this->sync))->handle(new Event());
		(new ObjectUpdatedEventListener($this->sync))->handle(new ObjectCreatedEvent($object));
		(new ObjectUpdatedEventListener($this->sync))->handle(new ObjectDeletedEvent($object));
		(new ObjectDeletedEventListener($this->sync))->handle(new ObjectCreatedEvent($object));
		(new ObjectDeletedEventListener($this->sync))->handle(new Event());
	}//end testEachListenerIgnoresEveryOtherEvent()
}//end class
