<?php

/**
 * A call event says who is calling, or says plainly that it does not know.
 *
 * The distinction this file guards: "the number was withheld" and "we do not
 * know this number" are different facts and the panel says different things
 * for them. Collapsing both into `caller = null` would put "unknown caller"
 * on an anonymous call, and invite an agent to ask for a number that was
 * deliberately not given.
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
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Event;

use OCA\Integriq\Event\CallEvent;
use OCA\Integriq\Service\Kiss\CallEventNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the typed call event.
 */
class CallEventTest extends TestCase {

	/**
	 * Build an event with sensible defaults.
	 *
	 * @param array $overrides Field overrides.
	 *
	 * @return CallEvent The event.
	 */
	private function event(array $overrides = []): CallEvent {
		$fields = array_merge(
			[
				'kind'         => CallEvent::KIND_RINGING,
				'callId'       => '42',
				'callerNumber' => '+31612345678',
				'caller'       => ['uuid' => 'p-1', 'naam' => 'Jansen'],
				'openCases'    => ['ZAAK-2026-0001'],
				'agentId'      => 'agent-7',
				'sourceId'     => 'pbx-1',
				'at'           => '2026-09-18T12:00:00+00:00',
			],
			$overrides
		);

		return new CallEvent(
			kind: $fields['kind'],
			callId: $fields['callId'],
			callerNumber: $fields['callerNumber'],
			caller: $fields['caller'],
			openCases: $fields['openCases'],
			agentId: $fields['agentId'],
			sourceId: $fields['sourceId'],
			at: $fields['at'],
			durationSeconds: ($fields['durationSeconds'] ?? 0)
		);

	}//end event()

	/**
	 * A known citizen ringing carries their partij and their open cases.
	 *
	 * @return void
	 */
	public function testAKnownCitizenCarriesTheirPartijAndCases(): void {
		$event = $this->event();

		$this->assertTrue($event->isIdentified());
		$this->assertSame('Jansen', $event->caller['naam']);
		$this->assertSame(['ZAAK-2026-0001'], $event->openCases);

	}//end testAKnownCitizenCarriesTheirPartijAndCases()

	/**
	 * An unknown number still rings, and says it is unknown.
	 *
	 * @return void
	 */
	public function testAnUnknownNumberStillRings(): void {
		$event = $this->event(['caller' => null, 'openCases' => []]);

		$this->assertFalse($event->isIdentified());
		$this->assertFalse($event->isAnonymous(), 'the number arrived; it just matched nobody');
		$this->assertSame('+31612345678', $event->callerNumber);

	}//end testAnUnknownNumberStillRings()

	/**
	 * A withheld number is told apart from an unknown one.
	 *
	 * @return void
	 */
	public function testAWithheldNumberIsToldApartFromAnUnknownOne(): void {
		// Both have caller = null. Only one of them is worth asking the
		// caller for their number, and the panel says different things.
		$withheld = $this->event(['caller' => null, 'callerNumber' => '', 'openCases' => []]);
		$unknown  = $this->event(['caller' => null, 'openCases' => []]);

		$this->assertTrue($withheld->isAnonymous());
		$this->assertFalse($unknown->isAnonymous());
		$this->assertFalse($withheld->isIdentified());
		$this->assertFalse($unknown->isIdentified());

	}//end testAWithheldNumberIsToldApartFromAnUnknownOne()

	/**
	 * The CloudEvents type is built from the kind.
	 *
	 * @return void
	 */
	public function testTheCloudEventTypeIsBuiltFromTheKind(): void {
		$this->assertSame(
			'nl.conduction.integriq.call.ringing',
			$this->event(['kind' => CallEvent::KIND_RINGING])->cloudEventType()
		);
		$this->assertSame(
			'nl.conduction.integriq.call.ended',
			$this->event(['kind' => CallEvent::KIND_ENDED])->cloudEventType()
		);

	}//end testTheCloudEventTypeIsBuiltFromTheKind()

	/**
	 * Only an ended call carries a duration.
	 *
	 * @return void
	 */
	public function testAnEndedCallCarriesItsDuration(): void {
		$this->assertSame(
			187,
			$this->event(['kind' => CallEvent::KIND_ENDED, 'durationSeconds' => 187])->durationSeconds
		);
		$this->assertSame(0, $this->event()->durationSeconds);

	}//end testAnEndedCallCarriesItsDuration()

	/**
	 * The four kinds are the four the normaliser accepts.
	 *
	 * @return void
	 */
	public function testTheFourKindsMatchTheNormalisers(): void {
		// One vocabulary. Two lists that drift are a panel that renders
		// nothing for a kind the normaliser happily passes through.
		$this->assertSame(
			[
				CallEvent::KIND_RINGING,
				CallEvent::KIND_ANSWERED,
				CallEvent::KIND_ENDED,
				CallEvent::KIND_TRANSFERRED,
			],
			CallEventNormaliser::KINDS
		);

	}//end testTheFourKindsMatchTheNormalisers()

}//end class
