<?php

/**
 * Unit tests for StufProviderRequestReceivedListener.
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

use OCA\Integriq\Event\StufProviderRequestReceivedEvent;
use OCA\Integriq\EventListener\StufProviderRequestReceivedListener;
use OCA\Integriq\Service\StufZkn\StufProviderReceiver;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Integriq\EventListener\StufProviderRequestReceivedListener
 * @covers \OCA\Integriq\Event\StufProviderRequestReceivedEvent
 */
class StufProviderRequestReceivedListenerTest extends TestCase {

	/**
	 * The forwarded request reaches the receiver unchanged, with its source and service, and its answer lands on the event.
	 *
	 * @return void
	 */
	public function testTheReceiversAnswerLandsOnTheEvent(): void {
		$receiver = $this->createMock(StufProviderReceiver::class);
		$receiver->expects($this->once())->method('receiveForwarded')->with('<Envelope/>', 'zsh', 'persons')->willReturn(['statusCode' => 422, 'body' => '<Fo03/>']);

		$event = new StufProviderRequestReceivedEvent('dossiq', '<Envelope/>', 'zsh', 'persons');
		$this->assertFalse($event->isHandled());
		(new StufProviderRequestReceivedListener($receiver))->handle($event);

		$this->assertTrue($event->isHandled());
		$this->assertSame(422, $event->getStatusCode());
		$this->assertSame('<Fo03/>', $event->getBody());
		$this->assertSame('dossiq', $event->getSourceApp());
		$this->assertSame('zsh', $event->getSourceId());
		$this->assertSame('persons', $event->getService());
	}//end testTheReceiversAnswerLandsOnTheEvent()

	/**
	 * Another event type is ignored.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$receiver = $this->createMock(StufProviderReceiver::class);
		$receiver->expects($this->never())->method('receive');
		$receiver->expects($this->never())->method('receiveForwarded');

		(new StufProviderRequestReceivedListener($receiver))->handle(new Event());
	}//end testOtherEventsAreIgnored()
}//end class
