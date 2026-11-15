<?php

/**
 * Golden tests for NextcloudCalendarEventListener::handle(): the exact
 * CloudEvent per event class, the subscription gate and the failure path.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\DAV\Events\CachedCalendarObjectCreatedEvent;
use OCA\DAV\Events\CachedCalendarObjectDeletedEvent;
use OCA\DAV\Events\CachedCalendarObjectUpdatedEvent;
use OCA\Integriq\EventListener\NextcloudCalendarEventListener;
use OCA\Integriq\Service\EventServiceInterface;
use OCP\Calendar\Events\CalendarObjectCreatedEvent;
use OCP\Calendar\Events\CalendarObjectDeletedEvent;
use OCP\Calendar\Events\CalendarObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every forwarded CloudEvent and every skip.
 *
 * @spec openspec/changes/archive/2026-07-15-nextcloud-event-hub/specs/nextcloud-event-triggers/spec.md#requirement-calendar-events-must-be-normalized-to-cloudevents-with-an-oca-stability-caveat-req-002
 */
class NextcloudCalendarEventGoldenTest extends TestCase {

	/**
	 * Each recognised event with its CloudEvents type and provenance.
	 *
	 * @return array<string,array{0:Event,1:string,2:string}>
	 */
	public static function events(): array {
		$object = ['uri' => 'o.ics', 'etag' => '"e"'];
		return [
			'created' => [new CalendarObjectCreatedEvent(3, [], [], $object), 'com.nextcloud.calendar.object.created', 'own'],
			'updated' => [new CalendarObjectUpdatedEvent(3, [], [], $object), 'com.nextcloud.calendar.object.updated', 'own'],
			'deleted' => [new CalendarObjectDeletedEvent(3, [], [], $object), 'com.nextcloud.calendar.object.deleted', 'own'],
			'cached created' => [new CachedCalendarObjectCreatedEvent(3, [], [], $object), 'com.nextcloud.calendar.object.created', 'cached-subscription'],
			'cached updated' => [new CachedCalendarObjectUpdatedEvent(3, [], [], $object), 'com.nextcloud.calendar.object.updated', 'cached-subscription'],
			'cached deleted' => [new CachedCalendarObjectDeletedEvent(3, [], [], $object), 'com.nextcloud.calendar.object.deleted', 'cached-subscription'],
		];

	}//end events()

	/**
	 * Each event class forwards exactly this CloudEvent.
	 *
	 * @param Event  $event      The event.
	 * @param string $type       The CloudEvents type.
	 * @param string $provenance own or cached-subscription.
	 *
	 * @return void
	 */
	#[DataProvider('events')]
	public function testEventIsForwarded(Event $event, string $type, string $provenance): void {
		$eventService = $this->createMock(EventServiceInterface::class);
		$eventService->method('hasActiveSubscriptions')->willReturn(true);
		$eventService->expects($this->once())->method('handleNextcloudEvent')->with(
			$type,
			[
				'source' => '/nextcloud/calendar',
				'subject' => 'o.ics',
				'data' => ['calendarId' => 3, 'objectUri' => 'o.ics', 'provenance' => $provenance, 'etag' => '"e"'],
			]
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());

		(new NextcloudCalendarEventListener($eventService, $logger))->handle($event);

	}//end testEventIsForwarded()

	/**
	 * An object without uri or etag forwards nulls.
	 *
	 * @return void
	 */
	public function testMissingUriAndEtagAreNull(): void {
		$eventService = $this->createMock(EventServiceInterface::class);
		$eventService->method('hasActiveSubscriptions')->willReturn(true);
		$eventService->expects($this->once())->method('handleNextcloudEvent')->with(
			'com.nextcloud.calendar.object.created',
			[
				'source' => '/nextcloud/calendar',
				'subject' => null,
				'data' => ['calendarId' => 1, 'objectUri' => null, 'provenance' => 'own', 'etag' => null],
			]
		);

		$listener = new NextcloudCalendarEventListener($eventService, $this->createMock(LoggerInterface::class));
		$listener->handle(new CalendarObjectCreatedEvent(1, [], [], []));

	}//end testMissingUriAndEtagAreNull()

	/**
	 * Without active subscriptions nothing is forwarded or logged.
	 *
	 * @return void
	 */
	public function testNoSubscriptionsForwardsNothing(): void {
		$eventService = $this->createMock(EventServiceInterface::class);
		$eventService->method('hasActiveSubscriptions')->willReturn(false);
		$eventService->expects($this->never())->method('handleNextcloudEvent');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method($this->anything());

		(new NextcloudCalendarEventListener($eventService, $logger))->handle(new CalendarObjectDeletedEvent(2, [], [], []));

	}//end testNoSubscriptionsForwardsNothing()

	/**
	 * A failure while forwarding is logged as an error and not rethrown.
	 *
	 * @return void
	 */
	public function testForwardingFailureIsLogged(): void {
		$failure = new RuntimeException('down');
		$eventService = $this->createMock(EventServiceInterface::class);
		$eventService->method('hasActiveSubscriptions')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Failed to process Nextcloud calendar event: down',
			['exception' => $failure, 'event' => CalendarObjectUpdatedEvent::class]
		);

		(new NextcloudCalendarEventListener($eventService, $logger))->handle(new CalendarObjectUpdatedEvent(2, [], [], []));

	}//end testForwardingFailureIsLogged()

	/**
	 * An unrelated event is ignored before the subscription gate.
	 *
	 * @return void
	 */
	public function testUnrelatedEventTouchesNothing(): void {
		$eventService = $this->createMock(EventServiceInterface::class);
		$eventService->expects($this->never())->method($this->anything());

		(new NextcloudCalendarEventListener($eventService, $this->createMock(LoggerInterface::class)))->handle(new Event());

	}//end testUnrelatedEventTouchesNothing()
}//end class
