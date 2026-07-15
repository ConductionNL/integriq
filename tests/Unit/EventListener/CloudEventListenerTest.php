<?php
/**
 * Unit tests for CloudEventListener.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\EventListener;

use OCA\OpenConnector\EventListener\CloudEventListener;
use OCA\OpenConnector\Service\EventService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the listener that forwards OR object lifecycle events into
 * EventService as CloudEvents.
 *
 * @spec openspec/changes/outbound-webhooks-activation/tasks.md#task-6
 * @spec openspec/changes/outbound-webhooks-activation/tasks.md#task-7
 * @spec openspec/changes/outbound-webhooks-activation/tasks.md#task-8
 * @spec openspec/changes/outbound-webhooks-activation/tasks.md#task-9
 */
class CloudEventListenerTest extends TestCase
{


    /**
     * Build an ObjectEntity with the given register/schema/uuid.
     *
     * @param string $register The register slug.
     * @param string $schema   The schema slug.
     * @param string $uuid     The object uuid.
     *
     * @return ObjectEntity
     */
    private function entity(string $register, string $schema, string $uuid='obj-uuid'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setRegister($register);
        $entity->setSchema($schema);
        $entity->setObject(['type' => $schema]);

        return $entity;

    }//end entity()


    /**
     * A created event with a matching subscription (hasActiveSubscriptions
     * true) on an ordinary (non-openconnector) object is forwarded.
     *
     * @return void
     */
    public function testForwardsCreatedEventWhenSubscriptionsExist(): void
    {
        $object = $this->entity('someapp', 'person');

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->expects($this->once())
            ->method('handleObjectCreated')
            ->with($object);

        $listener = new CloudEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new ObjectCreatedEvent($object));
    }//end testForwardsCreatedEventWhenSubscriptionsExist()


    /**
     * REQ: the firehose gate — zero active subscriptions means zero work,
     * for every event type.
     *
     * @return void
     */
    public function testSkipsAllForwardingWhenNoActiveSubscriptions(): void
    {
        $object = $this->entity('someapp', 'person');

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(false);
        $eventService->expects($this->never())->method('handleObjectCreated');
        $eventService->expects($this->never())->method('handleObjectUpdated');
        $eventService->expects($this->never())->method('handleObjectDeleted');

        $listener = new CloudEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new ObjectCreatedEvent($object));
        $listener->handle(new ObjectUpdatedEvent(newObject: $object, oldObject: $object));
        $listener->handle(new ObjectDeletedEvent($object));
    }//end testSkipsAllForwardingWhenNoActiveSubscriptions()


    /**
     * REQ: self-reference guard — an `event` object (openconnector register)
     * must never be re-forwarded, or persisting it would recurse forever.
     *
     * @return void
     */
    public function testSkipsSelfReferenceEventSchema(): void
    {
        $object = $this->entity('openconnector', 'event');

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->expects($this->never())->method('handleObjectCreated');

        $listener = new CloudEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new ObjectCreatedEvent($object));
    }//end testSkipsSelfReferenceEventSchema()


    /**
     * REQ: self-reference guard also covers `event_message` (status updates
     * during delivery would otherwise recurse via ObjectUpdatedEvent too).
     *
     * @return void
     */
    public function testSkipsSelfReferenceEventMessageSchema(): void
    {
        $object = $this->entity('openconnector', 'event_message');

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->expects($this->never())->method('handleObjectUpdated');

        $listener = new CloudEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new ObjectUpdatedEvent(newObject: $object, oldObject: $object));
    }//end testSkipsSelfReferenceEventMessageSchema()


    /**
     * An ordinary schema in the openconnector register (e.g. a `source` or
     * `consumer` object) is NOT a self-reference and forwards normally.
     *
     * @return void
     */
    public function testForwardsOpenconnectorObjectsThatAreNotSelfReferences(): void
    {
        $object = $this->entity('openconnector', 'source');

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->expects($this->once())->method('handleObjectCreated')->with($object);

        $listener = new CloudEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new ObjectCreatedEvent($object));
    }//end testForwardsOpenconnectorObjectsThatAreNotSelfReferences()


    /**
     * REQ: a null previous-state (OR's ObjectUpdatedEvent declares
     * getOldObject() nullable) must be skipped, not crash the listener.
     *
     * @return void
     */
    public function testSkipsUpdatedEventWithNullOldObject(): void
    {
        $object = $this->entity('someapp', 'person');

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->expects($this->never())->method('handleObjectUpdated');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $listener = new CloudEventListener($eventService, $logger);
        $listener->handle(new ObjectUpdatedEvent(newObject: $object, oldObject: null));
    }//end testSkipsUpdatedEventWithNullOldObject()


    /**
     * A well-formed updated event forwards both old and new state.
     *
     * @return void
     */
    public function testForwardsUpdatedEventWithBothStates(): void
    {
        $oldObject = $this->entity('someapp', 'person', 'uuid-old');
        $newObject = $this->entity('someapp', 'person', 'uuid-old');

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->expects($this->once())
            ->method('handleObjectUpdated')
            ->with($oldObject, $newObject);

        $listener = new CloudEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new ObjectUpdatedEvent(newObject: $newObject, oldObject: $oldObject));
    }//end testForwardsUpdatedEventWithBothStates()


    /**
     * A well-formed deleted event forwards the deleted object.
     *
     * @return void
     */
    public function testForwardsDeletedEvent(): void
    {
        $object = $this->entity('someapp', 'person');

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->expects($this->once())->method('handleObjectDeleted')->with($object);

        $listener = new CloudEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new ObjectDeletedEvent($object));
    }//end testForwardsDeletedEvent()


    /**
     * REQ: the catch must be Throwable, not just Exception — this listener
     * runs synchronously inside an unrelated host save and a TypeError/Error
     * must never unwind into it.
     *
     * @return void
     */
    public function testContainsThrowableFromEventService(): void
    {
        $object = $this->entity('someapp', 'person');

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->method('handleObjectCreated')->willThrowException(new \TypeError('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $listener = new CloudEventListener($eventService, $logger);

        // Must not throw.
        $listener->handle(new ObjectCreatedEvent($object));
        $this->assertTrue(true);
    }//end testContainsThrowableFromEventService()


    /**
     * Unrelated NC events are ignored before the (relatively expensive)
     * hasActiveSubscriptions() check even runs.
     *
     * @return void
     */
    public function testIgnoresUnrelatedEvents(): void
    {
        $eventService = $this->createMock(EventService::class);
        $eventService->expects($this->never())->method('hasActiveSubscriptions');

        $listener = new CloudEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle($this->createMock(Event::class));
    }//end testIgnoresUnrelatedEvents()
}//end class
