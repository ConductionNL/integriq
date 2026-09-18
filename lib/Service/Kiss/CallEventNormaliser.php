<?php

/**
 * CallEventNormaliser — the one place a vendor's call payload becomes the
 * shape this app knows.
 *
 * Shared by every CTI provider, so the kinds, the phone-number format and the
 * required fields are decided once. A provider that normalised on its own
 * would be a second vocabulary, and the panel would show a call kind nobody
 * else recognises.
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
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Kiss;

use OCA\Integriq\Service\Sms\PhoneNumberValidator;

/**
 * Normalises one vendor call payload into the shared call-event shape.
 *
 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
 */
class CallEventNormaliser {

	/**
	 * The call kinds this integration understands.
	 *
	 * A closed set on purpose. A PBX that invents a fifth kind gets it
	 * dropped rather than passed through: the consuming panel switches on
	 * this value, and an unknown one renders as nothing at all.
	 *
	 * @var string[]
	 */
	public const KINDS = ['ringing', 'answered', 'ended', 'transferred'];

	/**
	 * The default dutch country code used to complete a national number.
	 *
	 * @var string
	 */
	public const DEFAULT_COUNTRY_CODE = '+31';

	/**
	 * Normalise one payload through a field mapping.
	 *
	 * @param array $payload The decoded vendor payload.
	 * @param array $mapping Field mapping: our key to the vendor's dotted path.
	 * @param array $kindMap Vendor kind to ours, e.g. `['alerting' => 'ringing']`.
	 *
	 * @return array<string, mixed>|null The normalised event, or null when it is not one we carry.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-telephony-provider-seam-with-a-verified-webhook-req-005
	 */
	public function normalise(array $payload, array $mapping = [], array $kindMap = []): ?array {
		$rawKind = (string) $this->at(payload: $payload, path: (string) ($mapping['kind'] ?? 'kind'));
		$kind    = (string) ($kindMap[$rawKind] ?? $rawKind);

		if (in_array($kind, self::KINDS, true) === false) {
			return null;
		}

		$callId = trim((string) $this->at(payload: $payload, path: (string) ($mapping['callId'] ?? 'callId')));
		if ($callId === '') {
			// Without a call id nothing can be deduplicated, correlated across
			// kinds, or pushed back as a contact moment. It is not an event,
			// it is a notification with no subject.
			return null;
		}

		$event = [
			'kind'         => $kind,
			'callId'       => $callId,
			'callerNumber' => $this->toE164(
				number: (string) $this->at(payload: $payload, path: (string) ($mapping['callerNumber'] ?? 'callerNumber'))
			),
			'agentId'      => trim((string) $this->at(payload: $payload, path: (string) ($mapping['agentId'] ?? 'agentId'))),
			'at'           => $this->toIso(
				value: (string) $this->at(payload: $payload, path: (string) ($mapping['at'] ?? 'at'))
			),
		];

		if ($kind === 'ended') {
			$duration                  = $this->at(payload: $payload, path: (string) ($mapping['durationSeconds'] ?? 'durationSeconds'));
			if (is_numeric($duration) === true) {
				$event['durationSeconds'] = (int) $duration;
			} else {
				// A duration nobody can read is zero, not a refusal: the call
				// still ended, and the panel still has to clear the caller.
				$event['durationSeconds'] = 0;
			}
		}

		return $event;

	}//end normalise()

	/**
	 * A phone number in E.164, as far as it can be read.
	 *
	 * Delegates the stripping and the E.164 validation to
	 * {@see PhoneNumberValidator}, which the SMS path already uses, with ONE
	 * deliberate difference: bare digits carrying neither a `+`, a `00` nor a
	 * trunk `0` are refused here.
	 *
	 * PhoneNumberValidator prefixes those with a `+` and accepts the result.
	 * For an outbound SMS that is reasonable: you send to what you were given
	 * and a wrong number fails to deliver. For a CALLER LOOKUP it is not.
	 * `612345678` becomes `+612345678`, a valid number in another country,
	 * and the lookup matches somebody else's partij: an agent picks up the
	 * phone looking at the wrong person's case history, with nothing on
	 * screen saying the match was a guess.
	 *
	 * So the shared rules are reused and the consequence-bearing one is not.
	 *
	 * @param string $number The number as the PBX sent it.
	 *
	 * @return string The E.164 number, or '' when there is nothing safe to read.
	 *
	 * @spec openspec/changes/kcc-cti-adapter/specs/kiss-kcc-bridge/spec.md#requirement-a-call-carries-its-caller-context-as-a-typed-event-req-006
	 */
	public function toE164(string $number): string {
		$trimmed = trim($number);
		if ($trimmed === '') {
			return '';
		}

		// A withheld number arrives as a word. Reading it as a number would
		// make every anonymous caller the same "person" in the panel.
		$digits = preg_replace('/[^0-9+]/', '', $trimmed);
		if (is_string($digits) === false || $digits === '' || $digits === '+') {
			return '';
		}

		// The one rule this does not share with the SMS path. See above.
		if (str_starts_with($digits, '+') === false
			&& str_starts_with($digits, '0') === false
		) {
			return '';
		}

		$normalised = PhoneNumberValidator::toE164(rawNumber: $digits);

		return ($normalised ?? '');

	}//end toE164()

	/**
	 * Read a dotted path out of a payload.
	 *
	 * @param array $payload The payload.
	 * @param string $path A dotted path.
	 *
	 * @return mixed The value, or null.
	 */
	private function at(array $payload, string $path): mixed {
		if ($path === '') {
			return null;
		}

		$value = $payload;
		foreach (explode('.', $path) as $segment) {
			if (is_array($value) === false || array_key_exists($segment, $value) === false) {
				return null;
			}

			$value = $value[$segment];
		}

		if (is_array($value) === true) {
			return null;
		}

		return $value;

	}//end at()

	/**
	 * A timestamp as ISO 8601.
	 *
	 * @param string $value The value the PBX sent.
	 *
	 * @return string The timestamp, or '' when it cannot be read.
	 */
	private function toIso(string $value): string {
		$trimmed = trim($value);
		if ($trimmed === '') {
			return '';
		}

		$parsed = date_create_immutable($trimmed);
		if ($parsed === false) {
			return '';
		}

		return $parsed->format(DATE_ATOM);

	}//end toIso()

}//end class
