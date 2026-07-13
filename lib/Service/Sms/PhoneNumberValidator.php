<?php

/**
 * OpenConnector Phone Number Validator.
 *
 * Pure, dependency-free E.164 normalisation + validation with a configurable
 * default calling code (NL, `31`, unless overridden). Deliberately does NOT
 * depend on `giggsey/libphonenumber-for-php` (not shipped by this app —
 * `composer.json` was checked before writing this class) — a full metadata
 * library is unnecessary for the narrow "is this a plausible E.164 SMS
 * recipient" check this app needs, so the rules below are intentionally
 * conservative rather than exhaustively region-aware.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/changes/notifynl-sms-channel/specs/notifynl-sms-channel/spec.md#requirement-e164-phone-validation-with-nl-default-region
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Sms;

/**
 * Normalises and validates SMS recipient numbers to E.164.
 *
 * @spec openspec/changes/notifynl-sms-channel/specs/notifynl-sms-channel/spec.md#requirement-e164-phone-validation-with-nl-default-region
 */
final class PhoneNumberValidator
{

    /**
     * Default calling code applied to a national-format number (NL).
     *
     * @var string
     */
    public const DEFAULT_CALLING_CODE = '31';

    /**
     * E.164: a leading `+`, a non-zero first digit, then 1-14 further digits (max 15 digits total).
     *
     * @var string
     */
    private const E164_PATTERN = '/^\+[1-9]\d{6,14}$/';

    /**
     * Private constructor — this class exposes only static, pure helpers.
     */
    private function __construct()
    {

    }//end __construct()

    /**
     * Normalise a raw phone number to E.164, defaulting a bare national number to the given calling code.
     *
     * Accepted input shapes, in order:
     *   - Already E.164 (`+31612345678`) — validated and returned unchanged.
     *   - International prefix `00` (`0031612345678`) — `00` is replaced with `+`.
     *   - National trunk prefix `0` (`0612345678`) — the `0` is stripped and `+<callingCode>` is prepended.
     *   - Bare digits with no leading `0`/`+`/`00` (`31612345678`) — assumed to already carry the calling
     *     code, only the `+` is missing, so it is prepended as-is.
     *
     * Every candidate is formatting-stripped (spaces, hyphens, dots, parentheses) before the shape rules
     * above are applied, then validated against {@see self::E164_PATTERN} before being returned.
     *
     * @param string $rawNumber   The raw, possibly human-formatted phone number.
     * @param string $callingCode The calling code (digits only, no `+`) to default a national number to.
     *
     * @return string|null The normalised E.164 number, or null when no valid E.164 number can be derived.
     *
     * @spec openspec/changes/notifynl-sms-channel/specs/notifynl-sms-channel/spec.md#scenario-a-national-format-nl-number-is-normalised-to-e164
     */
    public static function toE164(string $rawNumber, string $callingCode=self::DEFAULT_CALLING_CODE): ?string
    {
        $stripped = self::stripFormatting(rawNumber: $rawNumber);
        if ($stripped === '') {
            return null;
        }

        $candidate = self::applyShapeRules(stripped: $stripped, callingCode: $callingCode);

        if (self::isValidE164(candidate: $candidate) === false) {
            return null;
        }

        return $candidate;

    }//end toE164()

    /**
     * Whether a string is already a syntactically valid E.164 number.
     *
     * @param string $candidate The candidate string.
     *
     * @return boolean Whether the candidate matches E.164 shape.
     *
     * @spec openspec/changes/notifynl-sms-channel/specs/notifynl-sms-channel/spec.md#requirement-e164-phone-validation-with-nl-default-region-req-005
     */
    public static function isValidE164(string $candidate): bool
    {
        return (preg_match(self::E164_PATTERN, $candidate) === 1);

    }//end isValidE164()

    /**
     * Strip common human formatting (spaces, hyphens, dots, parentheses) while preserving a leading `+`.
     *
     * A leading `(0)` (e.g. `+31 (0)6 1234 5678`) is dropped as a UNIT before the generic digit
     * strip below — it is the common European "trunk prefix, omit when dialling internationally"
     * display convention, not a digit to keep; without this the `0` inside the parentheses would
     * survive digit-stripping and corrupt the result (`+310612345678` instead of `+31612345678`).
     *
     * @param string $rawNumber The raw input.
     *
     * @return string The formatting-stripped candidate (digits, and an optional leading `+`, only).
     */
    private static function stripFormatting(string $rawNumber): string
    {
        $trimmed = trim($rawNumber);
        $hasPlus = str_starts_with($trimmed, '+');

        $withoutTrunkHint = preg_replace('/\(0\)/', '', $trimmed);
        if ($withoutTrunkHint === null) {
            $withoutTrunkHint = $trimmed;
        }

        $digitsOnly = preg_replace('/\D+/', '', $withoutTrunkHint);
        if ($digitsOnly === null) {
            $digitsOnly = '';
        }

        if ($hasPlus === true) {
            return '+'.$digitsOnly;
        }

        return $digitsOnly;

    }//end stripFormatting()

    /**
     * Apply the shape rules (E.164 / `00` prefix / national `0` prefix / bare digits) to a stripped candidate.
     *
     * @param string $stripped    The formatting-stripped candidate (see {@see self::stripFormatting()}).
     * @param string $callingCode The calling code to default a national number to.
     *
     * @return string The resulting `+`-prefixed candidate (not yet validated).
     */
    private static function applyShapeRules(string $stripped, string $callingCode): string
    {
        if (str_starts_with($stripped, '+') === true) {
            return $stripped;
        }

        if (str_starts_with($stripped, '00') === true) {
            return '+'.substr($stripped, 2);
        }

        if (str_starts_with($stripped, '0') === true) {
            return '+'.$callingCode.substr($stripped, 1);
        }

        return '+'.$stripped;

    }//end applyShapeRules()
}//end class
