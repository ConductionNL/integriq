<?php

/**
 * Unit tests for HeldMessageRelease: the release is recorded first, then the
 * message is sent through the retry pipeline in the releaser's name.
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

use OCA\Integriq\Outbound\Identity\HeldMessageRelease;
use OCA\Integriq\Outbound\Identity\HoldQueue;
use OCA\Integriq\Outbound\Identity\HoldQueueInterface;
use OCA\Integriq\Outbound\OutboundRetryServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for releasing a held message.
 *
 * @covers \OCA\Integriq\Outbound\Identity\HeldMessageRelease
 */
class HeldMessageReleaseTest extends TestCase {

	/**
	 * A release is written before the send, and the answer carries the send's outcome.
	 *
	 * @return void
	 */
	public function testTheReleaseIsRecordedThenTheMessageIsSent(): void {
		$calls = [];
		$holdQueue = $this->createMock(HoldQueueInterface::class);
		$holdQueue->expects($this->once())->method('releaseHeld')->with('msg-1', 'beheerder')
			->willReturnCallback(
				static function () use (&$calls): ObjectEntity {
					$calls[] = 'released';
					return new ObjectEntity();
				}
			);
		$outcome = ['message' => 'msg-1', 'retried' => ['a@example.org'], 'skipped' => [], 'succeeded' => true, 'detail' => 'ok'];
		$retry = $this->createMock(OutboundRetryServiceInterface::class);
		$retry->expects($this->once())->method('retry')->with('msg-1', 'beheerder')
			->willReturnCallback(
				static function () use (&$calls, $outcome): array {
					$calls[] = 'sent';
					return $outcome;
				}
			);

		$result = (new HeldMessageRelease($holdQueue, $retry))->release('msg-1', 'beheerder');

		$this->assertSame(['released', 'sent'], $calls);
		$this->assertSame(['message' => 'msg-1', 'status' => HoldQueue::STATUS_RELEASED, 'retry' => $outcome], $result);

	}//end testTheReleaseIsRecordedThenTheMessageIsSent()

	/**
	 * A message not held for review is refused and nothing is sent.
	 *
	 * @return void
	 */
	public function testAMessageNotHeldIsNotSent(): void {
		$holdQueue = $this->createMock(HoldQueueInterface::class);
		$holdQueue->method('releaseHeld')->willThrowException(new RuntimeException('Only a message held for review can be released.'));
		$retry = $this->createMock(OutboundRetryServiceInterface::class);
		$retry->expects($this->never())->method('retry');

		$this->expectException(RuntimeException::class);
		(new HeldMessageRelease($holdQueue, $retry))->release('msg-2', 'beheerder');

	}//end testAMessageNotHeldIsNotSent()

}//end class
