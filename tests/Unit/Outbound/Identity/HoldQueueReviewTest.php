<?php

/**
 * Unit tests for the review hold of HoldQueue (REQ-OSI-010): a message a send
 * check refused is held with the check and reason, a person releases or
 * withdraws it, and each act is written with its actor.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/outbound-sender-identity-and-deliverability/specs/outbound-sender-identity/spec.md#requirement-req-osi-010-a-message-that-fails-a-send-check-is-held-for-review
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Outbound\Identity\HoldQueue;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for holding a refused message for review, releasing it and withdrawing it.
 *
 * @covers \OCA\Integriq\Outbound\Identity\HoldQueue
 */
class HoldQueueReviewTest extends TestCase {

	/**
	 * Object service mock; captures what is written.
	 *
	 * @var ORObjectService&MockObject
	 */
	private ORObjectService&MockObject $objectService;

	/**
	 * Message recorder mock.
	 *
	 * @var MessageRecorder&MockObject
	 */
	private MessageRecorder&MockObject $recorder;

	/**
	 * The records written, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $written = [];

	/**
	 * The queue under test.
	 *
	 * @var HoldQueue
	 */
	private HoldQueue $queue;

	/**
	 * Build the queue over mocks, with a fixed clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ORObjectService::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema, string $uuid): ObjectEntity {
				$this->assertSame(MessageRecorder::REGISTER, $register);
				$this->assertSame(MessageRecorder::SCHEMA, $schema);
				$this->written[] = ['uuid' => $uuid, 'object' => $object];
				return new ObjectEntity();
			}
		);
		$this->recorder = $this->createMock(MessageRecorder::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-11-01T12:00:00+00:00'));
		$this->queue = new HoldQueue($this->objectService, $this->recorder, $time);

	}//end setUp()

	/**
	 * A refused message is held for review with the check, the reason and when, and a held step.
	 *
	 * @return void
	 */
	public function testARefusedMessageIsHeldWithTheCheckAndReason(): void {
		$this->recorder->method('read')->with('msg-1')->willReturn(['status' => MessageRecorder::STATUS_FAILED, 'steps' => [], 'recipients' => []]);

		$this->queue->holdForReview('msg-1', 'opt-out', 'opted-out: the recipient opted out');

		$this->assertCount(1, $this->written);
		$object = $this->written[0]['object'];
		$this->assertSame('msg-1', $this->written[0]['uuid']);
		$this->assertSame(
			['status' => HoldQueue::STATUS_REVIEW, 'check' => 'opt-out', 'reason' => 'opted-out: the recipient opted out', 'heldAt' => '2026-11-01T12:00:00+00:00'],
			$object['hold']
		);
		$this->assertSame(HoldQueue::STATUS_REVIEW, $object['holdStatus']);
		$this->assertSame(MessageRecorder::STATUS_FAILED, $object['status'], 'Holding sends nothing: the message stays not sent.');
		$this->assertSame('held', $object['steps'][0]['step']);
		$this->assertSame(MessageRecorder::OUTCOME_FAILED, $object['steps'][0]['outcome']);
		$this->assertSame('Held for review: the opt-out check refused it (opted-out: the recipient opted out).', $object['steps'][0]['detail']);

	}//end testARefusedMessageIsHeldWithTheCheckAndReason()

	/**
	 * Releasing a message held for review records who released it and when.
	 *
	 * @return void
	 */
	public function testAReleaseIsRecordedWithItsActor(): void {
		$this->recorder->method('read')->willReturn(['hold' => ['status' => HoldQueue::STATUS_REVIEW, 'check' => 'opt-out', 'reason' => 'r'], 'steps' => [], 'recipients' => []]);

		$this->queue->releaseHeld('msg-2', 'beheerder');

		$object = $this->written[0]['object'];
		$this->assertSame(HoldQueue::STATUS_RELEASED, $object['hold']['status']);
		$this->assertSame(HoldQueue::STATUS_RELEASED, $object['holdStatus']);
		$this->assertSame('beheerder', $object['hold']['releasedBy']);
		$this->assertSame('2026-11-01T12:00:00+00:00', $object['hold']['releasedAt']);
		$this->assertSame('opt-out', $object['hold']['check'], 'The check that stopped it stays on the record.');
		$this->assertSame(
			['step' => 'released', 'outcome' => MessageRecorder::OUTCOME_SUCCEEDED, 'at' => '2026-11-01T12:00:00+00:00', 'detail' => 'Released by beheerder to be sent.'],
			$object['steps'][0]
		);

	}//end testAReleaseIsRecordedWithItsActor()

	/**
	 * Only a message held for review is released; anything else is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testOnlyAMessageHeldForReviewIsReleased(): void {
		foreach ([[], ['hold' => 'review'], ['hold' => ['status' => HoldQueue::STATUS_HELD]], ['hold' => ['status' => HoldQueue::STATUS_RELEASED]]] as $record) {
			$recorder = $this->createMock(MessageRecorder::class);
			$recorder->method('read')->willReturn($record);
			$time = $this->createMock(ITimeFactory::class);
			$time->method('getDateTime')->willReturn(new \DateTime('2026-11-01T12:00:00+00:00'));
			$queue = new HoldQueue($this->objectService, $recorder, $time);

			try {
				$queue->releaseHeld('msg-3', 'beheerder');
				$this->fail('A message not held for review was released.');
			} catch (RuntimeException $exception) {
				$this->assertSame('Only a message held for review can be released.', $exception->getMessage());
			}
		}

		$this->assertSame([], $this->written);

	}//end testOnlyAMessageHeldForReviewIsReleased()

	/**
	 * A message held for review is withdrawn without a window, recorded as not sent with its actor.
	 *
	 * @return void
	 */
	public function testAMessageHeldForReviewIsWithdrawnAsNotSent(): void {
		$this->recorder->method('read')->willReturn(
			[
				'status' => MessageRecorder::STATUS_FAILED,
				'hold' => ['status' => HoldQueue::STATUS_REVIEW, 'check' => 'opt-out', 'reason' => 'r'],
				'steps' => [],
				'recipients' => [['address' => 'a@example.org', 'status' => 'failed', 'failedStep' => 'opt-out']],
			]
		);

		$this->queue->withdraw('msg-4', 'beheerder');

		$object = $this->written[0]['object'];
		$this->assertSame(HoldQueue::STATUS_WITHDRAWN, $object['hold']['status']);
		$this->assertSame(HoldQueue::STATUS_WITHDRAWN, $object['holdStatus']);
		$this->assertSame('beheerder', $object['hold']['withdrawnBy']);
		$this->assertSame(MessageRecorder::STATUS_FAILED, $object['status']);
		$this->assertSame('Withdrawn by beheerder after review; it was not sent.', $object['steps'][0]['detail']);
		$this->assertSame('withdrawn', $object['recipients'][0]['failedStep']);
		$this->assertSame('Withdrawn before it was sent.', $object['recipients'][0]['reason']);

	}//end testAMessageHeldForReviewIsWithdrawnAsNotSent()

	/**
	 * A window hold still mirrors its status to the top level, so the lists can filter on it.
	 *
	 * @return void
	 */
	public function testAWindowHoldMirrorsItsStatus(): void {
		$this->recorder->method('read')->willReturn([]);

		$this->queue->hold('msg-5', ['holdWindowSeconds' => 30]);

		$this->assertSame(HoldQueue::STATUS_HELD, $this->written[0]['object']['holdStatus']);
		$this->assertSame('2026-11-01T12:00:30+00:00', $this->written[0]['object']['hold']['releaseAt']);

	}//end testAWindowHoldMirrorsItsStatus()

}//end class
