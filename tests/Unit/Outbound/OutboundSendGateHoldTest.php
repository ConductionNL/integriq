<?php

/**
 * Unit tests for the send gate holding a refused message for review (REQ-OSI-010).
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Outbound
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

namespace OCA\Integriq\Tests\Unit\Outbound;

use OCA\Integriq\Outbound\Identity\HoldQueueInterface;
use OCA\Integriq\Outbound\Identity\MessageComposer;
use OCA\Integriq\Outbound\Identity\OptOutRegistry;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\Integriq\Outbound\OutboundSendGate;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for recordRefusal() putting the refused message on hold.
 *
 * @covers \OCA\Integriq\Outbound\OutboundSendGate
 */
class OutboundSendGateHoldTest extends TestCase {

	/**
	 * Message recorder mock.
	 *
	 * @var MessageRecorder&MockObject
	 */
	private MessageRecorder&MockObject $recorder;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the recorder so a row opens as msg-1.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->recorder = $this->createMock(MessageRecorder::class);
		$row = new ObjectEntity();
		$row->setUuid('msg-1');
		$this->recorder->method('start')->willReturn($row);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * The gate under test.
	 *
	 * @param HoldQueueInterface|null $holdQueue The hold queue, or none.
	 *
	 * @return OutboundSendGate The gate.
	 */
	private function gate(?HoldQueueInterface $holdQueue): OutboundSendGate {
		return new OutboundSendGate(
			$this->createMock(OptOutRegistry::class),
			$this->createMock(MessageComposer::class),
			$this->recorder,
			$this->logger,
			$holdQueue
		);

	}//end gate()

	/**
	 * A refusal marks the recipient failed in the opt-out step, then holds the message with the same reason.
	 *
	 * @return void
	 */
	public function testARefusedMessageIsHeldForReviewWithTheCheckAndReason(): void {
		$calls = [];
		$this->recorder->expects($this->once())->method('recipientFailed')
			->with('msg-1', 'a@example.org', OutboundSendGate::STEP_OPT_OUT, 'opted-out: the recipient opted out')
			->willReturnCallback(
				static function () use (&$calls): ObjectEntity {
					$calls[] = 'failed';
					return new ObjectEntity();
				}
			);
		$holdQueue = $this->createMock(HoldQueueInterface::class);
		$holdQueue->expects($this->once())->method('holdForReview')
			->with('msg-1', OutboundSendGate::STEP_OPT_OUT, 'opted-out: the recipient opted out')
			->willReturnCallback(
				static function () use (&$calls): ObjectEntity {
					$calls[] = 'held';
					return new ObjectEntity();
				}
			);

		$uuid = $this->gate($holdQueue)->recordRefusal(
			'email',
			'case-1',
			['address' => 'a@example.org', 'code' => 'opted-out', 'reason' => 'the recipient opted out', 'category' => 'case-update']
		);

		$this->assertSame('msg-1', $uuid);
		$this->assertSame(['failed', 'held'], $calls);

	}//end testARefusedMessageIsHeldForReviewWithTheCheckAndReason()

	/**
	 * A hold that cannot be written is logged and does not change the refusal's answer.
	 *
	 * @return void
	 */
	public function testAHoldThatCannotBeWrittenLeavesTheRefusalStanding(): void {
		$holdQueue = $this->createMock(HoldQueueInterface::class);
		$holdQueue->method('holdForReview')->willThrowException(new RuntimeException('store down'));
		$this->logger->expects($this->once())->method('warning')
			->with('[OutboundSendGate] could not hold the refused message for review', ['uuid' => 'msg-1', 'exception' => 'store down']);

		$this->assertSame('msg-1', $this->gate($holdQueue)->recordRefusal('sms', 'case-2', ['address' => '+31600000000', 'code' => 'opted-out', 'reason' => 'r']));

	}//end testAHoldThatCannotBeWrittenLeavesTheRefusalStanding()

	/**
	 * Without a hold queue, or without a row, nothing is held.
	 *
	 * @return void
	 */
	public function testNothingIsHeldWithoutAQueueOrARow(): void {
		$this->assertSame('msg-1', $this->gate(null)->recordRefusal('sms', 'case-3', ['address' => 'x', 'code' => 'c', 'reason' => 'r']));

		$recorder = $this->createMock(MessageRecorder::class);
		$recorder->method('start')->willThrowException(new RuntimeException('no row'));
		$holdQueue = $this->createMock(HoldQueueInterface::class);
		$holdQueue->expects($this->never())->method('holdForReview');
		$gate = new OutboundSendGate($this->createMock(OptOutRegistry::class), $this->createMock(MessageComposer::class), $recorder, $this->logger, $holdQueue);

		$this->assertNull($gate->recordRefusal('sms', 'case-4', ['address' => 'x', 'code' => 'c', 'reason' => 'r']));

	}//end testNothingIsHeldWithoutAQueueOrARow()

}//end class
