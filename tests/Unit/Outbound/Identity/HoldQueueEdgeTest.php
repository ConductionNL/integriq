<?php

/**
 * Unit tests for the edges of HoldQueue: a held message without a release
 * time cannot be withdrawn, and a message that is not held is not released.
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Outbound\Identity;

use OCA\Integriq\Outbound\Identity\HoldQueue;
use OCA\Integriq\Outbound\MessageRecorder;
use OCA\OpenRegister\Service\ObjectService as ORObjectService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for withdrawing and releasing messages outside the normal window.
 *
 * @covers \OCA\Integriq\Outbound\Identity\HoldQueue
 */
class HoldQueueEdgeTest extends TestCase {

	/**
	 * Object service mock; nothing may be written.
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
	 * The queue under test.
	 *
	 * @var HoldQueue
	 */
	private HoldQueue $queue;

	/**
	 * Build the queue over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ORObjectService::class);
		$this->objectService->expects($this->never())->method('saveObject');
		$this->recorder = $this->createMock(MessageRecorder::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-11-01T12:00:00+00:00'));
		$this->queue = new HoldQueue($this->objectService, $this->recorder, $time);

	}//end setUp()

	/**
	 * A held message without a release time cannot be withdrawn, and withdraw refuses it.
	 *
	 * @return void
	 */
	public function testAHeldMessageWithoutAReleaseTimeIsNotWithdrawable(): void {
		$this->recorder->method('read')->with('msg-1')->willReturn(['hold' => ['status' => HoldQueue::STATUS_HELD, 'releaseAt' => '']]);

		$this->assertFalse($this->queue->isWithdrawable('msg-1'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('This message has left; nothing can be recalled after the hold window.');
		$this->queue->withdraw('msg-1', 'admin');

	}//end testAHeldMessageWithoutAReleaseTimeIsNotWithdrawable()

	/**
	 * A held message whose release time is still ahead is withdrawable.
	 *
	 * @return void
	 */
	public function testAHeldMessageInsideItsWindowIsWithdrawable(): void {
		$this->recorder->method('read')->willReturn(['hold' => ['status' => HoldQueue::STATUS_HELD, 'releaseAt' => '2026-11-01T12:05:00+00:00']]);

		$this->assertTrue($this->queue->isWithdrawable('msg-2'));

	}//end testAHeldMessageInsideItsWindowIsWithdrawable()

	/**
	 * A message that is not held, or whose hold is not an array, is never released.
	 *
	 * @return void
	 */
	public function testAMessageThatIsNotHeldIsNotReleased(): void {
		$this->recorder->method('read')->willReturnMap(
			[
				['withdrawn', ['hold' => ['status' => HoldQueue::STATUS_WITHDRAWN, 'releaseAt' => '2026-01-01T00:00:00+00:00']]],
				['scalar', ['hold' => 'held']],
				['none', []],
			]
		);

		$this->assertFalse($this->queue->release('withdrawn'));
		$this->assertFalse($this->queue->release('scalar'));
		$this->assertFalse($this->queue->release('none'));
		$this->assertFalse($this->queue->isWithdrawable('scalar'));

	}//end testAMessageThatIsNotHeldIsNotReleased()

	/**
	 * A held message is not released before its release time.
	 *
	 * @return void
	 */
	public function testAHeldMessageIsNotReleasedEarly(): void {
		$this->recorder->method('read')->willReturn(['hold' => ['status' => HoldQueue::STATUS_HELD, 'releaseAt' => '2026-11-01T13:00:00+00:00']]);

		$this->assertFalse($this->queue->release('msg-3'));

	}//end testAHeldMessageIsNotReleasedEarly()

}//end class
