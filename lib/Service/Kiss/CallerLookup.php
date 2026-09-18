<?php

/**
 * Integriq Caller Lookup.
 *
 * Turns a ringing phone number into the person behind it, or into nothing.
 *
 * THE RISK THIS CLASS EXISTS TO CONTAIN.
 *
 * An agent reads the panel while the phone is ringing and then says a name out
 * loud. If the match is wrong they greet the wrong person, and they do it
 * holding that person's open cases. There is nothing on the screen that says
 * how confident the match was, so the whole burden of being right sits here.
 *
 * So every rule below is a refusal:
 *
 * - The number must already be E.164. This never completes, guesses or
 *   re-prefixes one. `CallEventNormaliser` has done that work and refused
 *   anything it could not place.
 * - The comparison is EXACT, over full E.164 strings. Not a suffix match, not
 *   "the last nine digits", not a LIKE. Those are the matches that find the
 *   wrong person.
 * - More than one party on a number is NO match. Two people sharing a landline
 *   is common, and picking the first is picking at random.
 *
 * Returning null is always safe. The panel says "unknown caller", the agent
 * asks who is speaking, and nobody is greeted by somebody else's name.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Kiss
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

/**
 * Matches an E.164 number against the parties a klantinteracties binding
 * returned, and refuses every match it is not sure of.
 */
class CallerLookup {

	/**
	 * The digital-address kinds that are a telephone number.
	 *
	 * A party's `digitaleAdressen` holds e-mail addresses too. Matching a
	 * number against all of them would be harmless today and wrong the moment
	 * somebody stores a number in a field meant for something else.
	 *
	 * @var string[]
	 */
	public const PHONE_ADDRESS_KINDS = ['telefoonnummer', 'telefoon', 'phone', 'mobiel'];

	/**
	 * The party a number belongs to, or null.
	 *
	 * @param string $e164 The caller's number, already normalised to E.164.
	 * @param array $parties The candidate parties from the klantinteracties binding.
	 *
	 * @return array<string, mixed>|null The single matching party, or null.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function match(string $e164, array $parties): ?array {
		if ($this->isUsable(e164: $e164) === false) {
			return null;
		}

		$matches = [];
		foreach ($parties as $party) {
			if (is_array($party) === false) {
				continue;
			}

			if ($this->partyHasNumber(party: $party, e164: $e164) === true) {
				$matches[] = $party;
			}
		}

		if (count($matches) !== 1) {
			// Nobody, or more than one. A shared landline is common, and
			// picking the first is picking at random: the agent would greet
			// one of two people by name with no way to tell which.
			return null;
		}

		return $matches[0];

	}//end match()

	/**
	 * Whether a number is one this will look up at all.
	 *
	 * Deliberately strict. A number that is not already full E.164 is one
	 * something upstream declined to place, and completing it here would undo
	 * that refusal in the one place where being wrong is most expensive.
	 *
	 * @param string $e164 The candidate.
	 *
	 * @return boolean True when it is safe to match on.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function isUsable(string $e164): bool {
		return (preg_match('/^\+[1-9]\d{6,14}$/', $e164) === 1);

	}//end isUsable()

	/**
	 * Whether one party carries this exact number.
	 *
	 * @param array $party The party.
	 * @param string $e164 The number.
	 *
	 * @return boolean True on an exact match.
	 */
	private function partyHasNumber(array $party, string $e164): bool {
		$addresses = ($party['digitaleAdressen'] ?? []);
		if (is_array($addresses) === false) {
			return false;
		}

		foreach ($addresses as $address) {
			if (is_array($address) === false) {
				continue;
			}

			$kind = mb_strtolower(trim((string) ($address['soortDigitaalAdres'] ?? '')));
			if (in_array($kind, self::PHONE_ADDRESS_KINDS, true) === false) {
				continue;
			}

			// EXACT, over the whole string. A suffix comparison would make
			// +31612345678 and +49612345678 the same caller, and the agent
			// would be reading a German number's case history.
			if ($this->normaliseStored(stored: (string) ($address['adres'] ?? '')) === $e164) {
				return true;
			}
		}

		return false;

	}//end partyHasNumber()

	/**
	 * A stored address in the form this compares.
	 *
	 * Formatting is stripped, because a number stored as `+31 6 1234 5678` is
	 * the same number. Nothing else is done: no prefix is added and no digits
	 * are dropped, so a stored number that is not E.164 simply never matches
	 * rather than being guessed into one that does.
	 *
	 * @param string $stored The stored address.
	 *
	 * @return string The comparable form.
	 */
	private function normaliseStored(string $stored): string {
		$stripped = preg_replace('/[^0-9+]/', '', trim($stored));

		if (is_string($stripped) === true) {
			return $stripped;
		}

		return '';

	}//end normaliseStored()

}//end class
