<?php

/**
 * The StUF envelope events carry the envelope and the answer integriq gives.
 *
 * Pins the constructor, every getter and the answer slot of the ZDS
 * confirmation and provider request events, so their shared body can move
 * without changing what a sibling app or the listeners read.
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
 * @spec openspec/specs/stuf-zds-case-client/spec.md#requirement-asynchronous-confirmations-conclude-the-message
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Event;

use OCA\Integriq\Event\StufProviderRequestReceivedEvent;
use OCA\Integriq\Event\StufZdsConfirmationReceivedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the StUF envelope events.
 */
class StufEnvelopeEventsTest extends TestCase {

	/**
	 * A confirmation is unanswered until integriq answers it.
	 *
	 * @return void
	 */
	public function testAConfirmationCarriesItsEnvelopeAndAnswer(): void {
		$event = new StufZdsConfirmationReceivedEvent(sourceApp: 'dossiq', rawXml: '<Envelope/>');

		self::assertInstanceOf(Event::class, $event);
		self::assertSame('dossiq', $event->getSourceApp());
		self::assertSame('<Envelope/>', $event->getRawXml());
		self::assertFalse($event->isHandled());
		self::assertNull($event->getStatusCode());
		self::assertSame('', $event->getBody());

		$event->answer(statusCode: 200, body: '<Bv03/>');
		self::assertTrue($event->isHandled());
		self::assertSame(200, $event->getStatusCode());
		self::assertSame('<Bv03/>', $event->getBody());

	}//end testAConfirmationCarriesItsEnvelopeAndAnswer()

	/**
	 * A provider request also carries its source and service.
	 *
	 * @return void
	 */
	public function testAProviderRequestCarriesItsEnvelopeSourceAndAnswer(): void {
		$event = new StufProviderRequestReceivedEvent(sourceApp: 'dossiq', rawXml: '<Envelope/>', sourceId: 'zsh', service: 'persons');

		self::assertSame('dossiq', $event->getSourceApp());
		self::assertSame('<Envelope/>', $event->getRawXml());
		self::assertSame('zsh', $event->getSourceId());
		self::assertSame('persons', $event->getService());
		self::assertFalse($event->isHandled());
		self::assertNull($event->getStatusCode());
		self::assertSame('', $event->getBody());

		$event->answer(statusCode: 500, body: '');
		self::assertTrue($event->isHandled());
		self::assertSame(500, $event->getStatusCode());
		self::assertSame('', $event->getBody());

	}//end testAProviderRequestCarriesItsEnvelopeSourceAndAnswer()
}//end class
