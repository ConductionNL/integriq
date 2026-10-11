<?php

/**
 * Unit tests for StufZdsConfirmationReceivedListener.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\EventListener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\EventListener;

use OCA\Integriq\Event\StufZdsConfirmationReceivedEvent;
use OCA\Integriq\EventListener\StufZdsConfirmationReceivedListener;
use OCA\Integriq\Service\StufZkn\StufZdsConfirmationReceiver;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\EventListener\StufZdsConfirmationReceivedListener
 * @covers \OCA\Integriq\Event\StufZdsConfirmationReceivedEvent
 */
class StufZdsConfirmationReceivedListenerTest extends TestCase {

	/**
	 * The forwarded envelope reaches the receiver unchanged and its answer lands on the event.
	 *
	 * @return void
	 */
	public function testTheReceiversAnswerLandsOnTheEvent(): void {
		$receiver = $this->createMock(StufZdsConfirmationReceiver::class);
		$receiver->expects($this->once())->method('receive')->with('<Envelope/>')->willReturn(['statusCode' => 422, 'body' => '<Fo03/>']);

		$event = new StufZdsConfirmationReceivedEvent('dossiq', '<Envelope/>');
		$this->assertFalse($event->isHandled());
		(new StufZdsConfirmationReceivedListener($receiver))->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame(422, $event->getStatusCode());
		$this->assertSame('<Fo03/>', $event->getBody());
		$this->assertSame('dossiq', $event->getSourceApp());
	}//end testTheReceiversAnswerLandsOnTheEvent()

	/**
	 * Another event type is ignored.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$receiver = $this->createMock(StufZdsConfirmationReceiver::class);
		$receiver->expects($this->never())->method('receive');

		(new StufZdsConfirmationReceivedListener($receiver))->handle(new Event());
	}//end testOtherEventsAreIgnored()
}//end class
