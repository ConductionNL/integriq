<?php

/**
 * DigitalPostSendRequestedListener Unit Tests
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

use OCA\Integriq\Event\DigitalPostSendRequestedEvent;
use OCA\Integriq\EventListener\DigitalPostSendRequestedListener;
use OCA\Integriq\Service\DigitalPost\DigitalPostServiceInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A send request reaches the digital-post service; a failure is logged and written back as a handled refusal.
 *
 * @covers \OCA\Integriq\EventListener\DigitalPostSendRequestedListener
 * @uses   \OCA\Integriq\Event\DigitalPostSendRequestedEvent
 */
class DigitalPostSendRequestedListenerTest extends TestCase {

	private DigitalPostServiceInterface&MockObject $service;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->service = $this->createMock(DigitalPostServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A send request from zaakiq.
	 *
	 * @return DigitalPostSendRequestedEvent
	 */
	private function request(): DigitalPostSendRequestedEvent {
		return new DigitalPostSendRequestedEvent(
			sourceApp: 'zaakiq',
			sourceId: 'case-1',
			recipient: '999993653',
			subject: 'Besluit',
			body: 'Uw aanvraag is toegekend.'
		);
	}//end request()

	public function testTheRequestIsHandedToTheServiceUntouched(): void {
		$event = $this->request();
		$this->service->expects($this->once())->method('handleSendRequest')->with($this->identicalTo($event));
		$this->logger->expects($this->never())->method('error');

		(new DigitalPostSendRequestedListener($this->service, $this->logger))->handle($event);

		$this->assertFalse($event->isHandled());
		$this->assertNull($event->getRefusal());
	}//end testTheRequestIsHandedToTheServiceUntouched()

	public function testAServiceFailureIsLoggedAndRefusedAsHandled(): void {
		$event = $this->request();
		$this->service->method('handleSendRequest')->willThrowException(new RuntimeException('no provider configured'));
		$this->logger->expects($this->once())
			->method('error')
			->with('digital-post.request.failed', ['sourceApp' => 'zaakiq', 'error' => 'no provider configured']);

		(new DigitalPostSendRequestedListener($this->service, $this->logger))->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame(['code' => 'exception', 'reason' => 'no provider configured'], $event->getRefusal());
	}//end testAServiceFailureIsLoggedAndRefusedAsHandled()

	public function testOtherEventsAreIgnored(): void {
		$this->service->expects($this->never())->method('handleSendRequest');

		(new DigitalPostSendRequestedListener($this->service, $this->logger))->handle(new Event());
	}//end testOtherEventsAreIgnored()
}//end class
