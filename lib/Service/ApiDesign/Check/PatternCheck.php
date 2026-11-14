<?php

/**
 * Integriq PatternCheck.
 *
 * Passes when a string matches `match` and does not match `notMatch`.
 *
 * @category Service
 * @package  OCA\Integriq\Service\ApiDesign\Check
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\ApiDesign\Check;

/**
 * `pattern`: `match` must match and `notMatch` must not. Patterns are written
 * without delimiters, as in Spectral; a pattern that is already `/…/flags` is
 * taken as written. An absent field is not checked; a value that is not a
 * string or a number fails.
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
class PatternCheck implements RuleCheckInterface {

	/**
	 * The name a rule uses for this check, as in `then.function`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function name(): string {
		return 'pattern';
	}//end name()

	/**
	 * Check one value.
	 *
	 * @param mixed                $value   The selected value, null when the field is absent.
	 * @param bool                 $present Whether the field is present at all.
	 * @param array<string, mixed> $options The rule's `functionOptions`.
	 *
	 * @return string|null Why the value fails, or null when it passes.
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function check(mixed $value, bool $present, array $options): ?string {
		if ($present === false) {
			return null;
		}

		if (is_string($value) === false && is_int($value) === false && is_float($value) === false) {
			return 'is not a text value';
		}

		return self::patternFailure(text: (string) $value, options: $options);
	}//end check()

	/**
	 * Why a text fails the `match` or `notMatch` pattern, or null.
	 *
	 * @param string               $text    The text.
	 * @param array<string, mixed> $options The rule's `functionOptions`.
	 *
	 * @return string|null
	 */
	private static function patternFailure(string $text, array $options): ?string {
		if (isset($options['match']) === true && preg_match(self::regex(pattern: (string) $options['match']), $text) !== 1) {
			return sprintf('"%s" does not match %s', $text, $options['match']);
		}

		if (isset($options['notMatch']) === true && preg_match(self::regex(pattern: (string) $options['notMatch']), $text) === 1) {
			return sprintf('"%s" matches %s', $text, $options['notMatch']);
		}

		return null;
	}//end patternFailure()

	/**
	 * Why a rule's options are unusable, or null when they are fine.
	 *
	 * @param array<string, mixed> $options The rule's `functionOptions`.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function invalidOptions(array $options): ?string {
		if (isset($options['match']) === false && isset($options['notMatch']) === false) {
			return 'pattern needs match or notMatch';
		}

		foreach (['match', 'notMatch'] as $option) {
			if (isset($options[$option]) === true
				&& (is_string($options[$option]) === false || @preg_match(self::regex(pattern: $options[$option]), '') === false)
			) {
				return sprintf('pattern %s is not a valid regular expression', $option);
			}
		}

		return null;
	}//end invalidOptions()

	/**
	 * A PCRE for a Spectral pattern.
	 *
	 * @param string $pattern The pattern, bare or `/…/flags`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public static function regex(string $pattern): string {
		if (preg_match('#^/.*/[imsux]*$#s', $pattern) === 1) {
			return $pattern;
		}

		return '/'.str_replace('/', '\/', str_replace('\/', '/', $pattern)).'/u';
	}//end regex()
}//end class
