<?php

/**
 * Golden tests for CloudEventListener::handle(): the self-reference skip on
 * every event kind, and the exact warning and error lines.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\EventListener\CloudEventListener;
use OCA\Integriq\Service\Event\EventLoopGuard;
use OCA\Integriq\Service\EventService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins what each object event forwards, skips and logs.
 */
class CloudEventListenerGoldenTest extends TestCase {

	/**
	 * Reset the process-wide self schema id cache.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$prop = (new \ReflectionClass(CloudEventListener::class))->getProperty('selfSchemaIds');
		$prop->setAccessible(true);
		$prop->setValue(null, null);

	}//end setUp()

	/**
	 * An object of a register and schema.
	 *
	 * @param string $schema The schema id.
	 * @param string $uuid   The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setRegister('65');
		$entity->setSchema($schema);
		$entity->setObject(['type' => $schema]);

		return $entity;

	}//end entity()

	/**
	 * An event service with subscriptions whose own schemas are 25 and 26.
	 *
	 * @return EventService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function eventService() {
		$eventService = $this->createMock(EventService::class);
		$eventService->method('hasActiveSubscriptions')->willReturn(true);
		$eventService->method('getSelfSchemaIds')->willReturn(['25', '26']);

		return $eventService;

	}//end eventService()

	/**
	 * Self-referencing objects are skipped on update and delete too; others are forwarded.
	 *
	 * @return void
	 */
	public function testSelfReferencesAreSkippedOnEveryKind(): void {
		$own = $this->entity('25', 'own');
		$other = $this->entity('7', 'other');
		$eventService = $this->eventService();
		$eventService->expects($this->once())->method('handleObjectCreated')->with($other);
		$eventService->expects($this->once())->method('handleObjectUpdated')->with($other, $other);
		$eventService->expects($this->once())->method('handleObjectDeleted')->with($other);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$logger->expects($this->never())->method('warning');
		$logger->expects($this->exactly(3))->method('debug')->with($this->stringStartsWith('[CloudEventListener] suppressed: '), ['uuid' => 'own']);

		$listener = new CloudEventListener($eventService, $logger, new EventLoopGuard());
		foreach ([$own, $other] as $object) {
			$listener->handle(new ObjectCreatedEvent($object));
			$listener->handle(new ObjectUpdatedEvent(newObject: $object, oldObject: $object));
			$listener->handle(new ObjectDeletedEvent($object));
		}

	}//end testSelfReferencesAreSkippedOnEveryKind()

	/**
	 * A self-referencing update without an old state is skipped silently; another one warns.
	 *
	 * @return void
	 */
	public function testMissingOldStateWarnsOnlyForForwardableObjects(): void {
		$eventService = $this->eventService();
		$eventService->expects($this->never())->method('handleObjectUpdated');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Skipped CloudEvent forwarding: ObjectUpdatedEvent carried no previous object state.',
			['uuid' => 'other']
		);

		$listener = new CloudEventListener($eventService, $logger, new EventLoopGuard());
		$listener->handle(new ObjectUpdatedEvent(newObject: $this->entity('26', 'own'), oldObject: null));
		$listener->handle(new ObjectUpdatedEvent(newObject: $this->entity('7', 'other'), oldObject: null));

	}//end testMissingOldStateWarnsOnlyForForwardableObjects()

	/**
	 * A failure while forwarding is logged once with the exception and the event class.
	 *
	 * @return void
	 */
	public function testFailureIsLoggedWithTheEventClass(): void {
		$failure = new RuntimeException('down');
		$eventService = $this->eventService();
		$eventService->method('handleObjectDeleted')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Failed to process object event: down',
			['exception' => $failure, 'event' => ObjectDeletedEvent::class]
		);

		(new CloudEventListener($eventService, $logger, new EventLoopGuard()))->handle(new ObjectDeletedEvent($this->entity('7', 'x')));

	}//end testFailureIsLoggedWithTheEventClass()
}//end class
