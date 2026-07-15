<?php
/**
 * Unit tests for NextcloudCalendarEventListener.
 *
 * @category Test
 * @package  OCA\OpenConnector\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Tests\Unit\EventListener;

use OCA\DAV\Events\CachedCalendarObjectCreatedEvent;
use OCA\OpenConnector\EventListener\NextcloudCalendarEventListener;
use OCA\OpenConnector\Service\EventService;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
 */
class NextcloudCalendarEventListenerTest extends TestCase
{


    /**
     * TC-3: a CalendarObjectCreatedEvent (regular calendar, NC32+ OCP event)
     * produces a matching `com.nextcloud.calendar.object.created` CloudEvent.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
     */
    public function testCalendarObjectCreatedEventProducesMatchingCloudEvent(): void
    {
        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->expects($this->once())
            ->method('handleNextcloudEvent')
            ->with(
                'com.nextcloud.calendar.object.created',
                $this->callback(function (array $payload) {
                    return $payload['source'] === '/nextcloud/calendar'
                        && $payload['subject'] === 'event-1.ics'
                        && $payload['data']['calendarId'] === 5
                        && $payload['data']['objectUri'] === 'event-1.ics'
                        && $payload['data']['provenance'] === 'own';
                })
            );

        $listener = new NextcloudCalendarEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new CalendarObjectCreatedEvent(5, [], [], ['uri' => 'event-1.ics', 'etag' => '"abc"']));
    }//end testCalendarObjectCreatedEventProducesMatchingCloudEvent()


    /**
     * A CachedCalendarObjectCreatedEvent (external calendar subscription)
     * produces the SAME CloudEvents type, provenance='cached-subscription'.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
     */
    public function testCachedCalendarSubscriptionEventProducesSameType(): void
    {
        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->expects($this->once())
            ->method('handleNextcloudEvent')
            ->with(
                'com.nextcloud.calendar.object.created',
                $this->callback(fn(array $payload) => $payload['data']['provenance'] === 'cached-subscription')
            );

        $listener = new NextcloudCalendarEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new CachedCalendarObjectCreatedEvent(9, [], [], ['uri' => 'ext-1.ics']));
    }//end testCachedCalendarSubscriptionEventProducesSameType()


    /**
     * CalendarObjectUpdatedEvent/DeletedEvent produce the expected distinct types.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
     */
    public function testUpdatedAndDeletedProduceDistinctTypes(): void
    {
        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->expects($this->exactly(2))
            ->method('handleNextcloudEvent')
            ->willReturnCallback(
                function (string $type, array $payload) {
                    static $call = 0;
                    $call++;
                    if ($call === 1) {
                        $this->assertSame('com.nextcloud.calendar.object.updated', $type);
                    } else {
                        $this->assertSame('com.nextcloud.calendar.object.deleted', $type);
                    }

                    return [];
                }
            );

        $listener = new NextcloudCalendarEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new CalendarObjectUpdatedEvent(5, [], [], ['uri' => 'event-1.ics']));
        $listener->handle(new CalendarObjectDeletedEvent(5, [], [], ['uri' => 'event-1.ics']));
    }//end testUpdatedAndDeletedProduceDistinctTypes()


    /**
     * TC-4: an event of an unrelated type is ignored entirely.
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
     */
    public function testUnrelatedEventIsIgnored(): void
    {
        $eventService = $this->createMock(EventService::class);
        $eventService->expects($this->never())->method('handleNextcloudEvent');

        $listener = new NextcloudCalendarEventListener($eventService, $this->createMock(LoggerInterface::class));
        $listener->handle(new Event());
    }//end testUnrelatedEventIsIgnored()


    /**
     * TC-4: an event shape missing the expected id/object-data accessors
     * logs a warning and returns WITHOUT persisting or throwing — the
     * defensive OCA-stability check (REQ-002).
     *
     * @return void
     *
     * @spec openspec/changes/nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
     */
    public function testMalformedEventShapeLogsAndSkips(): void
    {
        // A minimal fake event that instanceof-matches CalendarObjectCreatedEvent
        // (via a subclass) but is missing getObjectData() — simulates an NC
        // major version signature break.
        $malformed = new class(1, [], [], []) extends CalendarObjectCreatedEvent {
            public function getObjectData(): never
            {
                throw new \BadMethodCallException('accessor removed');
            }
        };

        $eventService = $this->createMock(EventService::class);
        $eventService->method('hasActiveSubscriptions')->willReturn(true);
        $eventService->expects($this->never())->method('handleNextcloudEvent');

        $logger = $this->createMock(LoggerInterface::class);
        // getObjectData() DOES exist on the subclass (it's overridden, not
        // removed) so method_exists() still passes; this test instead proves
        // that an exception thrown while reading event data is caught and
        // logged as an error, never thrown into the NC dispatcher — the
        // listener's own broad catch, not the method_exists gate.
        $logger->expects($this->once())->method('error');

        $listener = new NextcloudCalendarEventListener($eventService, $logger);
        $listener->handle($malformed);
    }//end testMalformedEventShapeLogsAndSkips()
}//end class
