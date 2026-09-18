<?php

/**
 * A vendor's call payload becomes one shape, or it becomes nothing.
 *
 * The rule worth guarding here is the negative one: a phone number this
 * cannot place is returned EMPTY, never guessed into a valid one. A guessed
 * number looks a caller up and shows the agent somebody else's case history
 * while they pick up the phone.
 *
 * @category Test
 * @package  OCA\Integriq\Tests\Unit\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use OCA\Integriq\Service\Kiss\CallEventNormaliser;
use OCA\Integriq\Service\Sms\PhoneNumberValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the shared call-event normaliser.
 */
class CallEventNormaliserTest extends TestCase {

	/**
	 * @var CallEventNormaliser
	 */
	private CallEventNormaliser $normaliser;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->normaliser = new CallEventNormaliser();

	}//end setUp()

	/**
	 * A plain payload normalises.
	 *
	 * @return void
	 */
	public function testAPlainPayloadNormalises(): void {
		$event = $this->normaliser->normalise(
			payload: [
				'kind'         => 'ringing',
				'callId'       => '42',
				'callerNumber' => '+31612345678',
				'agentId'      => 'agent-7',
				'at'           => '2026-09-18T12:00:00+00:00',
			]
		);

		$this->assertSame('ringing', $event['kind']);
		$this->assertSame('42', $event['callId']);
		$this->assertSame('+31612345678', $event['callerNumber']);
		$this->assertSame('agent-7', $event['agentId']);
		$this->assertSame('2026-09-18T12:00:00+00:00', $event['at']);

	}//end testAPlainPayloadNormalises()

	/**
	 * A vendor's own field names are read through the mapping.
	 *
	 * @return void
	 */
	public function testAVendorsOwnFieldNamesAreReadThroughTheMapping(): void {
		$event = $this->normaliser->normalise(
			payload: [
				'event'  => ['type' => 'alerting'],
				'call'   => ['id' => 'abc', 'from' => '0612345678'],
				'target' => 'agent-9',
			],
			mapping: [
				'kind'         => 'event.type',
				'callId'       => 'call.id',
				'callerNumber' => 'call.from',
				'agentId'      => 'target',
			],
			kindMap: ['alerting' => 'ringing']
		);

		$this->assertSame('ringing', $event['kind']);
		$this->assertSame('abc', $event['callId']);
		$this->assertSame('+31612345678', $event['callerNumber']);

	}//end testAVendorsOwnFieldNamesAreReadThroughTheMapping()

	/**
	 * A kind this integration does not carry is dropped, not passed through.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsDropped(): void {
		// The panel switches on this value, so an unknown one renders as
		// nothing at all. Dropping it here is visible; passing it through is
		// a blank row somebody has to debug.
		$this->assertNull(
			$this->normaliser->normalise(payload: ['kind' => 'keepalive', 'callId' => '42'])
		);

	}//end testAnUnknownKindIsDropped()

	/**
	 * An event with no call id is not an event.
	 *
	 * @return void
	 */
	public function testAnEventWithNoCallIdIsDropped(): void {
		// Without one it cannot be deduplicated, correlated across kinds, or
		// pushed back as a contact moment.
		$this->assertNull($this->normaliser->normalise(payload: ['kind' => 'ringing', 'callId' => '']));
		$this->assertNull($this->normaliser->normalise(payload: ['kind' => 'ringing']));

	}//end testAnEventWithNoCallIdIsDropped()

	/**
	 * A national number gains the country code.
	 *
	 * @return void
	 */
	public function testANationalNumberGainsTheCountryCode(): void {
		$this->assertSame('+31612345678', $this->normaliser->toE164(number: '0612345678'));
		$this->assertSame('+31612345678', $this->normaliser->toE164(number: '06 12 34 56 78'));
		$this->assertSame('+31612345678', $this->normaliser->toE164(number: '06-12345678'));

	}//end testANationalNumberGainsTheCountryCode()

	/**
	 * An international prefix becomes a plus.
	 *
	 * @return void
	 */
	public function testAnInternationalPrefixBecomesAPlus(): void {
		$this->assertSame('+4930123456', $this->normaliser->toE164(number: '004930123456'));
		$this->assertSame('+4930123456', $this->normaliser->toE164(number: '+4930123456'));

	}//end testAnInternationalPrefixBecomesAPlus()

	/**
	 * A number this cannot place is empty, never guessed.
	 *
	 * @return void
	 */
	public function testANumberThatCannotBePlacedIsEmptyNeverGuessed(): void {
		// Bare digits could be an internal extension or a foreign number that
		// lost its plus. Either guess looks up the wrong person and shows an
		// agent their case history while the phone is ringing.
		$this->assertSame('', $this->normaliser->toE164(number: '1234'));
		$this->assertSame('', $this->normaliser->toE164(number: '612345678'));

	}//end testANumberThatCannotBePlacedIsEmptyNeverGuessed()

	/**
	 * This path refuses a bare number that the SMS path accepts.
	 *
	 * @return void
	 */
	public function testThisPathRefusesABareNumberTheSmsPathAccepts(): void {
		// PhoneNumberValidator, which this otherwise reuses, prefixes a bare
		// number with '+' and accepts it: '612345678' becomes '+612345678',
		// a valid number in another country. For an outbound SMS that is
		// reasonable, because a wrong number simply fails to deliver. For a
		// caller lookup it matches somebody else's partij, and an agent picks
		// up looking at the wrong person's case history.
		//
		// This asserts the difference in both directions, so neither side can
		// be "tidied" into the other without a test saying what it costs.
		$this->assertSame('+612345678', PhoneNumberValidator::toE164(rawNumber: '612345678'));
		$this->assertSame('', $this->normaliser->toE164(number: '612345678'));

		// Everything the two DO agree on stays shared.
		$this->assertSame(
			PhoneNumberValidator::toE164(rawNumber: '0612345678'),
			$this->normaliser->toE164(number: '0612345678')
		);
		$this->assertSame(
			PhoneNumberValidator::toE164(rawNumber: '004930123456'),
			$this->normaliser->toE164(number: '004930123456')
		);

	}//end testThisPathRefusesABareNumberTheSmsPathAccepts()

	/**
	 * A withheld number is empty, not a person.
	 *
	 * @return void
	 */
	public function testAWithheldNumberIsEmptyNotAPerson(): void {
		// It arrives as a word. Reading it as a number would make every
		// anonymous caller the same "person" in the panel.
		$this->assertSame('', $this->normaliser->toE164(number: 'anonymous'));
		$this->assertSame('', $this->normaliser->toE164(number: 'withheld'));
		$this->assertSame('', $this->normaliser->toE164(number: ''));
		$this->assertSame('', $this->normaliser->toE164(number: '   '));

	}//end testAWithheldNumberIsEmptyNotAPerson()

	/**
	 * A withheld caller still produces an event.
	 *
	 * @return void
	 */
	public function testAWithheldCallerStillProducesAnEvent(): void {
		// The phone still rings. An agent who sees "unknown caller" can pick
		// up; an agent who sees nothing thinks the panel is broken.
		$event = $this->normaliser->normalise(
			payload: ['kind' => 'ringing', 'callId' => '42', 'callerNumber' => 'anonymous']
		);

		$this->assertSame('ringing', $event['kind']);
		$this->assertSame('', $event['callerNumber']);

	}//end testAWithheldCallerStillProducesAnEvent()

	/**
	 * An ended call carries its duration.
	 *
	 * @return void
	 */
	public function testAnEndedCallCarriesItsDuration(): void {
		$event = $this->normaliser->normalise(
			payload: ['kind' => 'ended', 'callId' => '42', 'durationSeconds' => '187']
		);

		$this->assertSame(187, $event['durationSeconds']);

	}//end testAnEndedCallCarriesItsDuration()

	/**
	 * Only an ended call carries a duration.
	 *
	 * @return void
	 */
	public function testOnlyAnEndedCallCarriesADuration(): void {
		$event = $this->normaliser->normalise(payload: ['kind' => 'ringing', 'callId' => '42']);

		$this->assertArrayNotHasKey('durationSeconds', $event);

	}//end testOnlyAnEndedCallCarriesADuration()

	/**
	 * A duration that is not a number reads as zero, not as an error.
	 *
	 * @return void
	 */
	public function testAnUnreadableDurationReadsAsZero(): void {
		$event = $this->normaliser->normalise(
			payload: ['kind' => 'ended', 'callId' => '42', 'durationSeconds' => 'a while']
		);

		$this->assertSame(0, $event['durationSeconds']);

	}//end testAnUnreadableDurationReadsAsZero()

	/**
	 * An unreadable timestamp is empty rather than now.
	 *
	 * @return void
	 */
	public function testAnUnreadableTimestampIsEmptyRatherThanNow(): void {
		// Stamping it with the moment it was parsed would make a replayed
		// event look like it just happened.
		$event = $this->normaliser->normalise(payload: ['kind' => 'ringing', 'callId' => '42', 'at' => 'whenever']);

		$this->assertSame('', $event['at']);

	}//end testAnUnreadableTimestampIsEmptyRatherThanNow()

	/**
	 * A dotted path that reaches an object is not a value.
	 *
	 * @return void
	 */
	public function testAPathThatReachesAnObjectIsNotAValue(): void {
		$event = $this->normaliser->normalise(
			payload: ['kind' => 'ringing', 'callId' => '42', 'callerNumber' => ['unexpected' => 'shape']]
		);

		$this->assertSame('', $event['callerNumber']);

	}//end testAPathThatReachesAnObjectIsNotAValue()

	/**
	 * The kinds are a closed set.
	 *
	 * @return void
	 */
	public function testTheKindsAreAClosedSet(): void {
		$this->assertSame(['ringing', 'answered', 'ended', 'transferred'], CallEventNormaliser::KINDS);

	}//end testTheKindsAreAClosedSet()

}//end class
