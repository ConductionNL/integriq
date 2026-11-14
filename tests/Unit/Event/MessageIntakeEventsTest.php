<?php
/**
 * The message intake events: a received message is claimed or declined, and a routed message records what it created.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/message-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Event;

use InvalidArgumentException;
use OCA\Integriq\Event\IntakeMessageRoutedEvent;
use OCA\Integriq\Event\MessageReceivedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Outcome validation on the received-message event and the created reference
 * on the routed-message event.
 *
 * @covers \OCA\Integriq\Event\MessageReceivedEvent
 * @covers \OCA\Integriq\Event\IntakeMessageRoutedEvent
 */
class MessageIntakeEventsTest extends TestCase {

	/**
	 * A received message is unclaimed until a listener links or creates an object.
	 *
	 * @return void
	 */
	public function testAReceivedMessageIsClaimedByLinkingOrCreating(): void {
		$event = new MessageReceivedEvent(message: ['subject' => 'Bezwaar'], detectedReference: 'Z-2026-1', messageUuid: 'm-1', sourceId: 'imap');

		self::assertSame(expected: ['subject' => 'Bezwaar'], actual: $event->getMessage());
		self::assertSame(expected: 'Z-2026-1', actual: $event->getDetectedReference());
		self::assertSame(expected: 'm-1', actual: $event->getMessageUuid());
		self::assertSame(expected: 'imap', actual: $event->getSourceId());
		self::assertNull(actual: $event->getOutcome());
		self::assertFalse(condition: $event->isClaimed());

		$event->setOutcome(outcome: MessageReceivedEvent::OUTCOME_LINKED, objectRef: '  case/42  ');
		self::assertSame(expected: 'linked', actual: $event->getOutcome());
		self::assertSame(expected: 'case/42', actual: $event->getObjectRef());
		self::assertTrue(condition: $event->isClaimed());

		$event->setOutcome(outcome: MessageReceivedEvent::OUTCOME_CREATED, objectRef: 'case/43');
		self::assertTrue(condition: $event->isClaimed());
	}//end testAReceivedMessageIsClaimedByLinkingOrCreating()

	/**
	 * A declined message needs no object and does not count as claimed.
	 *
	 * @return void
	 */
	public function testADeclinedMessageIsNotClaimed(): void {
		$event = new MessageReceivedEvent(message: [], detectedReference: null);
		$event->setOutcome(outcome: MessageReceivedEvent::OUTCOME_DECLINED);

		self::assertSame(expected: 'declined', actual: $event->getOutcome());
		self::assertNull(actual: $event->getObjectRef());
		self::assertFalse(condition: $event->isClaimed());
		self::assertSame(expected: '', actual: $event->getMessageUuid());
		self::assertSame(expected: '', actual: $event->getSourceId());
	}//end testADeclinedMessageIsNotClaimed()

	/**
	 * Unknown outcomes, and claims without an object reference, are refused.
	 *
	 * @return void
	 */
	public function testInvalidOutcomesAreRefused(): void {
		$event = new MessageReceivedEvent(message: [], detectedReference: null);
		$messages = [];
		foreach ([['ignored', 'x'], ['linked', null], ['created', '   ']] as [$outcome, $ref]) {
			try {
				$event->setOutcome(outcome: $outcome, objectRef: $ref);
			} catch (InvalidArgumentException $e) {
				$messages[] = $e->getMessage();
			}
		}

		self::assertSame(
			expected: [
				'Unknown message intake outcome: ignored',
				'Outcome linked requires an object reference.',
				'Outcome created requires an object reference.',
			],
			actual: $messages
		);
		self::assertNull(actual: $event->getOutcome());
	}//end testInvalidOutcomesAreRefused()

	/**
	 * A routed message carries its target and records the trimmed reference of what was created.
	 *
	 * @return void
	 */
	public function testARoutedMessageRecordsWhatItCreated(): void {
		$event = new IntakeMessageRoutedEvent(
			message: ['from' => 'a@b.nl'],
			targetSchema: 'request',
			targetPayload: ['title' => 'Vraag'],
			files: ['f1'],
			messageUuid: 'm-2',
			ruleName: 'vragen'
		);

		self::assertSame(expected: ['from' => 'a@b.nl'], actual: $event->getMessage());
		self::assertSame(expected: 'request', actual: $event->getTargetSchema());
		self::assertSame(expected: ['title' => 'Vraag'], actual: $event->getTargetPayload());
		self::assertSame(expected: ['f1'], actual: $event->getFiles());
		self::assertSame(expected: 'm-2', actual: $event->getMessageUuid());
		self::assertSame(expected: 'vragen', actual: $event->getRuleName());
		self::assertNull(actual: $event->getCreatedRef());

		$event->setCreatedRef(objectRef: ' request/7 ');
		self::assertSame(expected: 'request/7', actual: $event->getCreatedRef());
	}//end testARoutedMessageRecordsWhatItCreated()

	/**
	 * An empty created reference is refused and leaves the event unanswered.
	 *
	 * @return void
	 */
	public function testAnEmptyCreatedReferenceIsRefused(): void {
		$event = new IntakeMessageRoutedEvent(message: [], targetSchema: 'request', targetPayload: []);

		try {
			$event->setCreatedRef(objectRef: '  ');
			self::fail(message: 'An empty reference was accepted.');
		} catch (InvalidArgumentException $e) {
			self::assertSame(expected: 'A created object reference cannot be empty.', actual: $e->getMessage());
		}

		self::assertNull(actual: $event->getCreatedRef());
		self::assertSame(expected: [], actual: $event->getFiles());
		self::assertSame(expected: '', actual: $event->getRuleName());
	}//end testAnEmptyCreatedReferenceIsRefused()
}//end class
