<?php

/**
 * The four education acknowledgement events carry what their translator read.
 *
 * Pins the constructor and every getter of the ROD, Verzuimloket, OSO and
 * UWLR/Edu-V acknowledgement events, built with named arguments, so their shared
 * body can move without changing what a listener reads.
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
 * @spec openspec/specs/uwlr-eduv-adapter/spec.md#requirement-req-006-shared-acknowledgement-translation-and-event-dispatch
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Event;

use OCA\Integriq\Event\OsoAcknowledgementReceivedEvent;
use OCA\Integriq\Event\RodAcknowledgementReceivedEvent;
use OCA\Integriq\Event\UwlrEduVAcknowledgementReceivedEvent;
use OCA\Integriq\Event\VerzuimloketAcknowledgementReceivedEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the acknowledgement events.
 */
class AcknowledgementReceivedEventsTest extends TestCase {

	/**
	 * The four event classes.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function eventClasses(): array {
		return [
			'rod' => [RodAcknowledgementReceivedEvent::class],
			'verzuimloket' => [VerzuimloketAcknowledgementReceivedEvent::class],
			'oso' => [OsoAcknowledgementReceivedEvent::class],
			'uwlr-eduv' => [UwlrEduVAcknowledgementReceivedEvent::class],
		];

	}//end eventClasses()

	/**
	 * A rejection carries its kenmerk, code and description.
	 *
	 * @param class-string $class The event class.
	 *
	 * @return void
	 */
	#[DataProvider('eventClasses')]
	public function testARejectionCarriesItsSignal(string $class): void {
		$event = new $class(kenmerk: 'job-1:lp-1', signaalcode: 'BRON-102', signaalOmschrijving: 'Onbekend BSN', accepted: false);

		self::assertInstanceOf(Event::class, $event);
		self::assertSame('job-1:lp-1', $event->getKenmerk());
		self::assertSame('BRON-102', $event->getSignaalcode());
		self::assertSame('Onbekend BSN', $event->getSignaalOmschrijving());
		self::assertFalse($event->isAccepted());
		self::assertFalse($event->isPropagationStopped());

	}//end testARejectionCarriesItsSignal()

	/**
	 * An acceptance carries no description.
	 *
	 * @param class-string $class The event class.
	 *
	 * @return void
	 */
	#[DataProvider('eventClasses')]
	public function testAnAcceptanceByNameCarriesNoDescription(string $class): void {
		$event = new $class(kenmerk: 'k', signaalcode: '0000', signaalOmschrijving: null, accepted: true);

		self::assertSame('k', $event->getKenmerk());
		self::assertSame('0000', $event->getSignaalcode());
		self::assertNull($event->getSignaalOmschrijving());
		self::assertTrue($event->isAccepted());

	}//end testAnAcceptanceByNameCarriesNoDescription()

	/**
	 * ROD carries the berichtsoort, empty when not given.
	 *
	 * @return void
	 */
	public function testRodCarriesItsBerichtsoort(): void {
		$named = new RodAcknowledgementReceivedEvent(kenmerk: 'k', signaalcode: 'c', signaalOmschrijving: 'o', accepted: true, berichtsoort: 'inschrijving');
		$bare = new RodAcknowledgementReceivedEvent(kenmerk: 'k', signaalcode: 'c', signaalOmschrijving: null, accepted: false);

		self::assertSame('inschrijving', $named->getBerichtsoort());
		self::assertSame('k', $named->getKenmerk());
		self::assertSame('', $bare->getBerichtsoort());

	}//end testRodCarriesItsBerichtsoort()

	/**
	 * Verzuimloket carries the melding type, empty when not given.
	 *
	 * @return void
	 */
	public function testVerzuimloketCarriesItsMeldingType(): void {
		$named = new VerzuimloketAcknowledgementReceivedEvent(kenmerk: 'k', signaalcode: 'c', signaalOmschrijving: 'o', accepted: true, meldingType: 'eerste-melding');
		$bare = new VerzuimloketAcknowledgementReceivedEvent(kenmerk: 'k', signaalcode: 'c', signaalOmschrijving: null, accepted: false);

		self::assertSame('eerste-melding', $named->getMeldingType());
		self::assertSame('o', $named->getSignaalOmschrijving());
		self::assertSame('', $bare->getMeldingType());

	}//end testVerzuimloketCarriesItsMeldingType()
}//end class
