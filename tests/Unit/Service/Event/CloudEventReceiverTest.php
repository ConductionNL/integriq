<?php

/**
 * Unit tests for CloudEventReceiver.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Event;

use OCA\Integriq\Service\Event\CloudEventReceiver;
use OCA\Integriq\Service\Event\EventLoopGuard;
use OCA\Integriq\Service\EventServiceInterface;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Stores a received event once per source and id, then routes it.
 *
 * @covers \OCA\Integriq\Service\Event\CloudEventReceiver
 *
 * @spec openspec/changes/cloudevents-receiver/specs/events-cloudevents/spec.md#requirement-outside-systems-shall-send-cloudevents-to-a-receiver-that-checks-and-routes-them-req-017
 */
class CloudEventReceiverTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * The event service.
	 *
	 * @var EventServiceInterface&MockObject
	 */
	private EventServiceInterface&MockObject $eventService;

	/**
	 * The receiver under test.
	 *
	 * @var CloudEventReceiver
	 */
	private CloudEventReceiver $receiver;

	/**
	 * Set up the receiver with mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = ObjectServiceMockBuilder::make($this);
		$this->eventService = $this->createMock(EventServiceInterface::class);
		$this->receiver = new CloudEventReceiver(objectService: $this->objectService, eventService: $this->eventService);
	}//end setUp()

	/**
	 * One received event.
	 *
	 * @return array<string,mixed>
	 */
	private function event(): array {
		return [
			'specversion' => '1.0',
			'id' => 'a1',
			'source' => '/shop',
			'type' => 'nl.example.order.created',
			'time' => '2026-11-14T08:00:00+01:00',
			'subject' => 'order-42',
			'datacontenttype' => 'application/json',
			'data' => ['order' => 42],
		];
	}//end event()

	/**
	 * A new event is stored under its derived uuid, marked as routed, and routed.
	 *
	 * @return void
	 */
	public function testANewEventIsStoredAndRouted(): void {
		$uuid = $this->receiver->uuidFor(receiver: 'webshop', cloudEvent: $this->event());
		$stored = ObjectServiceMockBuilder::objectEntity($this, [], $uuid);

		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects($this->once())->method('saveObject')
			->with(
				[
					'source' => '/shop',
					'type' => 'nl.example.order.created',
					'specversion' => '1.0',
					'time' => '2026-11-14T08:00:00+01:00',
					'datacontenttype' => 'application/json',
					'dataschema' => null,
					'subject' => 'order-42',
					'data' => ['order' => 42],
					'userId' => null,
					EventLoopGuard::MARKER_KEY => EventLoopGuard::MARKER_VALUE,
				],
				'integriq',
				'event',
				$uuid,
				false,
				false
			)
			->willReturn($stored);
		$this->eventService->expects($this->once())->method('processEvent')->with($stored)->willReturn([]);

		$this->assertSame($stored, $this->receiver->receive(receiver: 'webshop', cloudEvent: $this->event()));
	}//end testANewEventIsStoredAndRouted()

	/**
	 * A repeated id from the same source is neither stored nor routed again.
	 *
	 * @return void
	 */
	public function testARepeatedEventIsNotProcessedTwice(): void {
		$uuid = $this->receiver->uuidFor(receiver: 'webshop', cloudEvent: $this->event());
		$this->objectService->expects($this->once())->method('find')
			->with($uuid, 'integriq', 'event', false, false)
			->willReturn(new ObjectEntity());
		$this->objectService->expects($this->never())->method('saveObject');
		$this->eventService->expects($this->never())->method('processEvent');

		$this->assertNull($this->receiver->receive(receiver: 'webshop', cloudEvent: $this->event()));
	}//end testARepeatedEventIsNotProcessedTwice()

	/**
	 * The same id from another source, or another receiver, is a different event.
	 *
	 * @return void
	 */
	public function testTheUuidDependsOnReceiverSourceAndId(): void {
		$event = $this->event();
		$uuid = $this->receiver->uuidFor(receiver: 'webshop', cloudEvent: $event);

		$this->assertSame($uuid, $this->receiver->uuidFor(receiver: 'webshop', cloudEvent: $event));
		$this->assertNotSame($uuid, $this->receiver->uuidFor(receiver: 'crm', cloudEvent: $event));
		$this->assertNotSame($uuid, $this->receiver->uuidFor(receiver: 'webshop', cloudEvent: ['source' => '/other'] + $event));
		$this->assertNotSame($uuid, $this->receiver->uuidFor(receiver: 'webshop', cloudEvent: ['id' => 'a2'] + $event));
		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
	}//end testTheUuidDependsOnReceiverSourceAndId()

	/**
	 * Data that is not a JSON object is kept under value, base64 data under data_base64,
	 * and a time that is not a date-time is replaced by the time it arrived.
	 *
	 * @return void
	 */
	public function testDataAndTimeAreStoredInTheSchemaShape(): void {
		$saved = [];
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$saved): ObjectEntity {
				$saved[] = $object;
				return new ObjectEntity();
			}
		);

		$this->receiver->receive(receiver: 'webshop', cloudEvent: ['data' => [1, 2], 'time' => 'yesterday-ish'] + $this->event());
		$this->receiver->receive(receiver: 'webshop', cloudEvent: ['id' => 'a2', 'data' => 'plain text'] + $this->event());
		$withBase64 = $this->event();
		unset($withBase64['data']);
		$this->receiver->receive(receiver: 'webshop', cloudEvent: ['id' => 'a3', 'data_base64' => 'AAE='] + $withBase64);

		$this->assertSame(['value' => [1, 2]], $saved[0]['data']);
		$this->assertNotSame('yesterday-ish', $saved[0]['time']);
		$this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $saved[0]['time']));
		$this->assertSame(['value' => 'plain text'], $saved[1]['data']);
		$this->assertSame(['data_base64' => 'AAE='], $saved[2]['data']);
	}//end testDataAndTimeAreStoredInTheSchemaShape()
}//end class
