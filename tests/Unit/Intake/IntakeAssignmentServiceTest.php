<?php
/**
 * Unit tests for assigning an inbound message to a case from the shared inbox.
 *
 * The object service and the event dispatcher are doubles: what the message
 * records, what the app that owns the case is handed, and every refusal is
 * pinned on its own.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/intake-channels-beyond-mail/specs/intake-channels/spec.md#requirement-a-handler-works-a-message-from-the-shared-inbox-req-ic-021
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Intake;

use DateTime;
use OCA\Integriq\Event\IntakeMessageAssignedEvent;
use OCA\Integriq\Exception\IntakeChannelException;
use OCA\Integriq\Exception\IntakeRoutingException;
use OCA\Integriq\Intake\IntakeAssignmentService;
use OCA\Integriq\Tests\Helpers\ObjectServiceMockBuilder;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Assign a held message, hand it to the case's app, and refuse what cannot be assigned.
 *
 * @covers \OCA\Integriq\Intake\IntakeAssignmentService
 * @uses   \OCA\Integriq\Event\IntakeMessageAssignedEvent
 */
class IntakeAssignmentServiceTest extends TestCase {

	private const UUID = 'msg-1';

	/**
	 * A Teams message held because no rule matched, with one attachment and one photo.
	 *
	 * @var array<string,mixed>
	 */
	private const HELD = [
		'channelId' => 'teams',
		'externalId' => 'T-1',
		'text' => 'Mijn container is niet geleegd.',
		'attachments' => [['name' => 'brief.pdf', 'mimeType' => 'application/pdf']],
		'media' => [['name' => 'foto.jpg', 'mimeType' => 'image/jpeg']],
		'status' => 'held',
		'reason' => 'No routing rule matched channel "teams".',
	];

	/**
	 * Every object the service saved, with the uuid it was saved under.
	 *
	 * @var array<int,array{object: array<string,mixed>, uuid: ?string}>
	 */
	private array $saved = [];

	/**
	 * Every event the service dispatched.
	 *
	 * @var array<int,object>
	 */
	private array $events = [];

	/**
	 * Whether the double listener takes the message in.
	 *
	 * @var boolean
	 */
	private bool $listenerLinks = true;

	/**
	 * A held message is linked to the picked case: the message records the
	 * case, the handler and when, and the case's app gets the message with
	 * its attachments.
	 *
	 * @return void
	 */
	public function testAHeldMessageIsAssignedToTheCaseTheHandlerPicks(): void {
		$service = $this->service(objectService: $this->storeHolding(object: self::HELD));

		$result = $service->assign(self::UUID, ['caseRef' => 'case-42', 'assignedBy' => 'behandelaar']);

		$this->assertSame(
			[
				'id' => self::UUID,
				'status' => 'assigned',
				'targetRef' => 'case-42',
				'assignedBy' => 'behandelaar',
				'assignedAt' => '2026-11-14T09:30:00+00:00',
				'attachmentsLinked' => true,
			],
			$result
		);
		$this->assertCount(1, $this->saved);
		$this->assertSame(self::UUID, $this->saved[0]['uuid']);
		$saved = $this->saved[0]['object'];
		$this->assertSame('assigned', $saved['status']);
		$this->assertSame('case-42', $saved['targetRef']);
		$this->assertSame('', $saved['reason']);
		$this->assertSame('behandelaar', $saved['assignedBy']);
		$this->assertSame('2026-11-14T09:30:00+00:00', $saved['assignedAt']);
		$this->assertSame('Mijn container is niet geleegd.', $saved['text']);

		$this->assertCount(1, $this->events);
		$event = $this->events[0];
		$this->assertInstanceOf(IntakeMessageAssignedEvent::class, $event);
		$this->assertSame('case-42', $event->getCaseRef());
		$this->assertSame(self::UUID, $event->getMessageUuid());
		$this->assertSame('behandelaar', $event->getMessage()['assignedBy']);
		$this->assertSame(
			[
				['name' => 'brief.pdf', 'mimeType' => 'application/pdf'],
				['name' => 'foto.jpg', 'mimeType' => 'image/jpeg'],
			],
			$event->getAttachments()
		);
	}//end testAHeldMessageIsAssignedToTheCaseTheHandlerPicks()

	/**
	 * Without an app that takes the message in, the assignment still stands
	 * on the message and the answer says the attachments were not linked.
	 *
	 * @return void
	 */
	public function testAnAssignmentNobodyTakesInSaysTheAttachmentsAreNotLinked(): void {
		$this->listenerLinks = false;
		$service = $this->service(objectService: $this->storeHolding(object: array_merge(self::HELD, ['status' => 'routed', 'targetRef' => 'case-1'])));

		$result = $service->assign(self::UUID, ['caseRef' => ' case-2 ', 'assignedBy' => 'behandelaar']);

		$this->assertFalse($result['attachmentsLinked']);
		$this->assertSame('case-2', $result['targetRef']);
		$this->assertSame('case-2', $this->saved[0]['object']['targetRef']);
	}//end testAnAssignmentNobodyTakesInSaysTheAttachmentsAreNotLinked()

	/**
	 * No case named: refused before the message is read or written.
	 *
	 * @return void
	 */
	public function testAnAssignmentWithoutACaseIsRefused(): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->expects($this->never())->method('find');
		$objectService->expects($this->never())->method('saveObject');

		$this->expectException(IntakeRoutingException::class);
		$this->expectExceptionMessage('Pick the case to assign the message to.');

		$this->service(objectService: $objectService)->assign(self::UUID, ['caseRef' => '  ', 'assignedBy' => 'behandelaar']);
	}//end testAnAssignmentWithoutACaseIsRefused()

	/**
	 * A message refused at the door, or one that failed, is not one to work:
	 * nothing is written and nothing is handed over.
	 *
	 * @return void
	 */
	public function testAMessageInAnotherStateCannotBeAssigned(): void {
		foreach (['rejected', 'failed', 'received', ''] as $status) {
			$this->saved = [];
			$this->events = [];
			$service = $this->service(objectService: $this->storeHolding(object: array_merge(self::HELD, ['status' => $status])));
			try {
				$service->assign(self::UUID, ['caseRef' => 'case-42', 'assignedBy' => 'behandelaar']);
				$this->fail('A "' . $status . '" message was assigned.');
			} catch (IntakeRoutingException $exception) {
				$this->assertSame('A message that is "' . $status . '" cannot be assigned to a case.', $exception->getMessage());
			}

			$this->assertSame([], $this->saved, $status);
			$this->assertSame([], $this->events, $status);
		}
	}//end testAMessageInAnotherStateCannotBeAssigned()

	/**
	 * A message the handler cannot find is reported as unknown.
	 *
	 * @return void
	 */
	public function testAnUnknownMessageIsReportedAsUnknown(): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('find')->willThrowException(new DoesNotExistException('gone'));
		$objectService->expects($this->never())->method('saveObject');

		$this->expectException(IntakeChannelException::class);
		$this->expectExceptionMessage('No inbound message "missing".');

		$this->service(objectService: $objectService)->assign('missing', ['caseRef' => 'case-42', 'assignedBy' => 'behandelaar']);
	}//end testAnUnknownMessageIsReportedAsUnknown()

	/**
	 * A find that answers with no object is unknown too, not an empty message.
	 *
	 * @return void
	 */
	public function testAFindWithoutAnObjectIsReportedAsUnknown(): void {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('find')->willReturn(null);

		$this->expectException(IntakeChannelException::class);
		$this->expectExceptionMessage('No inbound message "msg-1".');

		$this->service(objectService: $objectService)->assign(self::UUID, ['caseRef' => 'case-42', 'assignedBy' => 'behandelaar']);
	}//end testAFindWithoutAnObjectIsReportedAsUnknown()

	/**
	 * An object service that holds one message and records every save.
	 *
	 * @param array<string,mixed> $object The stored message.
	 *
	 * @return ObjectService&MockObject
	 */
	private function storeHolding(array $object): MockObject {
		$objectService = ObjectServiceMockBuilder::make($this);
		$objectService->method('find')
			->with(self::UUID, 'integriq', 'intake_message')
			->willReturn(ObjectServiceMockBuilder::objectEntity($this, $object, self::UUID));
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?string $register=null, ?string $schema=null, ?string $uuid=null) {
				$this->assertSame(['integriq', 'intake_message'], [$register, $schema]);
				$this->saved[] = ['object' => $object, 'uuid' => $uuid];
				return ObjectServiceMockBuilder::objectEntity($this, $object, $uuid);
			}
		);

		return $objectService;
	}//end storeHolding()

	/**
	 * The service over an object service, with a fixed clock and a recording dispatcher.
	 *
	 * @param MockObject $objectService The object service double.
	 *
	 * @return IntakeAssignmentService
	 */
	private function service(MockObject $objectService): IntakeAssignmentService {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				if ($this->listenerLinks === true && $event instanceof IntakeMessageAssignedEvent) {
					$event->markLinked();
				}

				$this->events[] = $event;
			}
		);
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('getDateTime')->willReturn(new DateTime('2026-11-14T09:30:00+00:00'));

		return new IntakeAssignmentService(objectService: $objectService, eventDispatcher: $dispatcher, timeFactory: $clock);
	}//end service()
}//end class
