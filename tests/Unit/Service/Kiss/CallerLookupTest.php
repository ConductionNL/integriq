<?php

/**
 * The caller lookup, probed with numbers that differ only by their prefix.
 *
 * This is where the wrong-person risk lives. An agent reads the panel while
 * the phone rings and then says a name out loud; a wrong match means they
 * greet the wrong person, holding that person's open cases, with nothing on
 * screen saying how confident the match was.
 *
 * So most of these tests assert a REFUSAL. A lookup that returns null is
 * always safe: the panel says "unknown caller", the agent asks who is
 * speaking, and nobody is greeted by somebody else's name.
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
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Tests\Unit\Service\Kiss;

use OCA\Integriq\Service\Kiss\CallerLookup;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the caller lookup's refusals.
 */
class CallerLookupTest extends TestCase {

	/**
	 * @var CallerLookup
	 */
	private CallerLookup $lookup;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->lookup = new CallerLookup();

	}//end setUp()

	/**
	 * A party carrying one phone number.
	 *
	 * @param string $name The party's name.
	 * @param string $number The stored number.
	 * @param string $kind The digital address kind.
	 *
	 * @return array<string, mixed> The party.
	 */
	private function party(string $name, string $number, string $kind = 'telefoonnummer'): array {
		return [
			'uuid'             => 'p-'.$name,
			'naam'             => $name,
			'digitaleAdressen' => [
				['soortDigitaalAdres' => $kind, 'adres' => $number],
			],
		];

	}//end party()

	/**
	 * One party on that exact number is the caller.
	 *
	 * @return void
	 */
	public function testOnePartyOnThatExactNumberIsTheCaller(): void {
		$match = $this->lookup->match(
			e164: '+31612345678',
			parties: [$this->party(name: 'Jansen', number: '+31612345678')]
		);

		$this->assertSame('Jansen', $match['naam']);

	}//end testOnePartyOnThatExactNumberIsTheCaller()

	/**
	 * A number differing only by its country prefix is a different person.
	 *
	 * @return void
	 */
	public function testANumberDifferingOnlyByItsCountryPrefixIsNotTheSameCaller(): void {
		// The probe that matters. A suffix match, a "last nine digits" match,
		// or a LIKE would return De Vries here, and an agent would greet a
		// caller in the Netherlands by the name of somebody in Germany while
		// reading their case history.
		$match = $this->lookup->match(
			e164: '+31612345678',
			parties: [$this->party(name: 'De Vries', number: '+49612345678')]
		);

		$this->assertNull($match);

	}//end testANumberDifferingOnlyByItsCountryPrefixIsNotTheSameCaller()

	/**
	 * A number differing only by its trunk prefix is a different person.
	 *
	 * @return void
	 */
	public function testANumberDifferingOnlyByItsTrunkPrefixIsNotTheSameCaller(): void {
		// +31612345678 and +3161234567 differ by one digit at the end, and
		// +316123456789 by one more. Each is somebody else.
		foreach (['+3161234567', '+316123456789', '+3161234568'] as $stored) {
			$this->assertNull(
				$this->lookup->match(e164: '+31612345678', parties: [$this->party(name: 'Somebody', number: $stored)]),
				$stored.' must not match +31612345678'
			);
		}

	}//end testANumberDifferingOnlyByItsTrunkPrefixIsNotTheSameCaller()

	/**
	 * The right party is still found among near misses.
	 *
	 * @return void
	 */
	public function testTheRightPartyIsFoundAmongNearMisses(): void {
		// The control for the refusals above. Without it, a lookup that
		// matched nobody ever would pass every other test in this file.
		$match = $this->lookup->match(
			e164: '+31612345678',
			parties: [
				$this->party(name: 'De Vries', number: '+49612345678'),
				$this->party(name: 'Jansen', number: '+31612345678'),
				$this->party(name: 'Bakker', number: '+3161234567'),
			]
		);

		$this->assertSame('Jansen', $match['naam']);

	}//end testTheRightPartyIsFoundAmongNearMisses()

	/**
	 * Two parties on one number is no match.
	 *
	 * @return void
	 */
	public function testTwoPartiesOnOneNumberIsNoMatch(): void {
		// A shared landline is common. Picking the first is picking at
		// random, and the agent has no way to tell which of the two answered.
		$match = $this->lookup->match(
			e164: '+31201234567',
			parties: [
				$this->party(name: 'Jansen', number: '+31201234567'),
				$this->party(name: 'Jansen-De Vries', number: '+31201234567'),
			]
		);

		$this->assertNull($match);

	}//end testTwoPartiesOnOneNumberIsNoMatch()

	/**
	 * Nobody on that number is no match.
	 *
	 * @return void
	 */
	public function testNobodyOnThatNumberIsNoMatch(): void {
		$this->assertNull($this->lookup->match(e164: '+31612345678', parties: []));
		$this->assertNull(
			$this->lookup->match(e164: '+31612345678', parties: [$this->party(name: 'Jansen', number: '+31209999999')])
		);

	}//end testNobodyOnThatNumberIsNoMatch()

	/**
	 * Stored formatting does not stop a match.
	 *
	 * @return void
	 */
	public function testStoredFormattingDoesNotStopAMatch(): void {
		// The same number written by a human. Stripping formatting is safe
		// because it removes characters rather than adding digits.
		foreach (['+31 6 1234 5678', '+31-6-12345678', ' +31612345678 '] as $stored) {
			$this->assertSame(
				'Jansen',
				$this->lookup->match(e164: '+31612345678', parties: [$this->party(name: 'Jansen', number: $stored)])['naam'],
				$stored.' is the same number'
			);
		}

	}//end testStoredFormattingDoesNotStopAMatch()

	/**
	 * A stored national number is never completed into a match.
	 *
	 * @return void
	 */
	public function testAStoredNationalNumberIsNeverCompletedIntoAMatch(): void {
		// '0612345678' is almost certainly the same person. Completing it
		// here would be a guess, and the whole point of this class is that it
		// does not guess. It stays unmatched, and the agent asks.
		$this->assertNull(
			$this->lookup->match(e164: '+31612345678', parties: [$this->party(name: 'Jansen', number: '0612345678')])
		);

	}//end testAStoredNationalNumberIsNeverCompletedIntoAMatch()

	/**
	 * A number stored under an address kind that is not a phone is ignored.
	 *
	 * @return void
	 */
	public function testANumberStoredUnderANonPhoneKindIsIgnored(): void {
		$this->assertNull(
			$this->lookup->match(
				e164: '+31612345678',
				parties: [$this->party(name: 'Jansen', number: '+31612345678', kind: 'email')]
			)
		);

	}//end testANumberStoredUnderANonPhoneKindIsIgnored()

	/**
	 * The phone address kinds are matched case-insensitively.
	 *
	 * @return void
	 */
	public function testThePhoneAddressKindsAreMatchedCaseInsensitively(): void {
		$this->assertSame(
			'Jansen',
			$this->lookup->match(
				e164: '+31612345678',
				parties: [$this->party(name: 'Jansen', number: '+31612345678', kind: 'Telefoonnummer')]
			)['naam']
		);

	}//end testThePhoneAddressKindsAreMatchedCaseInsensitively()

	/**
	 * A number that is not E.164 is never looked up.
	 *
	 * @return void
	 */
	public function testANumberThatIsNotE164IsNeverLookedUp(): void {
		// Something upstream declined to place these. Completing one here
		// would undo that refusal in the most expensive place to be wrong.
		foreach (['', '0612345678', '612345678', 'anonymous', '+', '+0612345678'] as $candidate) {
			$this->assertFalse($this->lookup->isUsable(e164: $candidate), $candidate.' must not be looked up');
			$this->assertNull(
				$this->lookup->match(e164: $candidate, parties: [$this->party(name: 'Jansen', number: $candidate)])
			);
		}

	}//end testANumberThatIsNotE164IsNeverLookedUp()

	/**
	 * A party with no digital addresses is skipped rather than throwing.
	 *
	 * @return void
	 */
	public function testAPartyWithNoDigitalAddressesIsSkipped(): void {
		$match = $this->lookup->match(
			e164: '+31612345678',
			parties: [
				['uuid' => 'p-1', 'naam' => 'Shapeless'],
				['uuid' => 'p-2', 'naam' => 'Also shapeless', 'digitaleAdressen' => 'not a list'],
				$this->party(name: 'Jansen', number: '+31612345678'),
			]
		);

		$this->assertSame('Jansen', $match['naam']);

	}//end testAPartyWithNoDigitalAddressesIsSkipped()

}//end class
