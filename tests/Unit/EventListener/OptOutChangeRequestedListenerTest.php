<?php

/**
 * OptOutChangeRequestedListener Unit Tests
 *
 * @category Tests
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use InvalidArgumentException;
use OCA\Integriq\Event\OptOutChangeRequestedEvent;
use OCA\Integriq\EventListener\OptOutChangeRequestedListener;
use OCA\Integriq\Outbound\Identity\OptOutRegistryInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An opt-out change is recorded only while integriq is the authority; a bad request is refused, a failure leaves it unhandled.
 *
 * @covers \OCA\Integriq\EventListener\OptOutChangeRequestedListener
 * @uses   \OCA\Integriq\Event\OptOutChangeRequestedEvent
 */
class OptOutChangeRequestedListenerTest extends TestCase {

	private OptOutRegistryInterface&MockObject $registry;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->registry = $this->createMock(OptOutRegistryInterface::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * An opt-out from pipelinq.
	 *
	 * @return OptOutChangeRequestedEvent
	 */
	private function change(): OptOutChangeRequestedEvent {
		return new OptOutChangeRequestedEvent(
			sourceApp: 'pipelinq',
			address: 'jan@example.test',
			state: 'opted-out',
			channel: 'email',
			correlationId: 'corr-1'
		);
	}//end change()

	/**
	 * The listener under test.
	 *
	 * @return OptOutChangeRequestedListener
	 */
	private function listener(): OptOutChangeRequestedListener {
		return new OptOutChangeRequestedListener($this->registry, $this->logger);
	}//end listener()

	public function testWhileNotTheAuthorityTheEventStaysUnanswered(): void {
		$this->registry->method('isAuthorityEnabled')->willReturn(false);
		$this->registry->expects($this->never())->method('record');
		$event = $this->change();

		$this->listener()->handle($event);

		$this->assertFalse($event->isHandled());
		$this->assertNull($event->getRecordId());
	}//end testWhileNotTheAuthorityTheEventStaysUnanswered()

	public function testTheChangeIsRecordedAndItsIdWrittenBack(): void {
		$event = $this->change();
		$this->registry->method('isAuthorityEnabled')->willReturn(true);
		$this->registry->expects($this->once())->method('record')->with($event->toRequest())->willReturn(17);

		$this->listener()->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame(17, $event->getRecordId());
		$this->assertNull($event->getRefusal());
	}//end testTheChangeIsRecordedAndItsIdWrittenBack()

	public function testAnInvalidRequestIsHandledAsARefusal(): void {
		$this->registry->method('isAuthorityEnabled')->willReturn(true);
		$this->registry->method('record')->willThrowException(new InvalidArgumentException('state must be opted-in or opted-out'));
		$this->logger->expects($this->never())->method('error');
		$event = $this->change();

		$this->listener()->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame(['code' => 'invalid-request', 'reason' => 'state must be opted-in or opted-out'], $event->getRefusal());
	}//end testAnInvalidRequestIsHandledAsARefusal()

	public function testAStorageFailureIsLoggedAndLeavesTheEventUnhandled(): void {
		$failure = new RuntimeException('database gone');
		$this->registry->method('isAuthorityEnabled')->willReturn(true);
		$this->registry->method('record')->willThrowException($failure);
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'[OptOutChangeRequestedListener] not recorded, the event stays unhandled: database gone',
				['sourceApp' => 'pipelinq', 'correlationId' => 'corr-1', 'exception' => $failure]
			);
		$event = $this->change();

		$this->listener()->handle($event);

		$this->assertFalse($event->isHandled());
		$this->assertNull($event->getRefusal());
	}//end testAStorageFailureIsLoggedAndLeavesTheEventUnhandled()

	public function testOtherEventsAreIgnored(): void {
		$this->registry->expects($this->never())->method('isAuthorityEnabled');

		$this->listener()->handle(new Event());
	}//end testOtherEventsAreIgnored()
}//end class
