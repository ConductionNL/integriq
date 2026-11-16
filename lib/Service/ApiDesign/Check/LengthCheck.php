<?php

/**
 * Integriq LengthCheck.
 *
 * Passes when the length of a text, list or object is within `min` and `max`.
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
 * `length`: characters of a text, items of a list or members of an object,
 * between `min` and `max` inclusive. A number is measured by its value. An
 * absent field is not checked.
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
class LengthCheck implements RuleCheckInterface {

	/**
	 * The name a rule uses for this check, as in `then.function`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function name(): string {
		return 'length';
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

		$length = self::lengthOf(value: $value);
		if ($length === null) {
			return 'has no length';
		}

		return self::boundFailure(length: $length, options: $options);
	}//end check()

	/**
	 * The length of a text, the size of a list, or a number itself; null for anything else.
	 *
	 * @param mixed $value The selected value.
	 *
	 * @return int|float|null
	 */
	private static function lengthOf(mixed $value): int|float|null {
		return match (true) {
			is_string($value) => mb_strlen($value),
			is_array($value) => count($value),
			is_int($value), is_float($value) => $value,
			default => null,
		};
	}//end lengthOf()

	/**
	 * Why a length falls outside the rule's `min` and `max`, or null.
	 *
	 * @param int|float            $length  The length.
	 * @param array<string, mixed> $options The rule's `functionOptions`.
	 *
	 * @return string|null
	 */
	private static function boundFailure(int|float $length, array $options): ?string {
		if (isset($options['min']) === true && $length < $options['min']) {
			return sprintf('is shorter than %s', $options['min']);
		}

		if (isset($options['max']) === true && $length > $options['max']) {
			return sprintf('is longer than %s', $options['max']);
		}

		return null;
	}//end boundFailure()

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
		$min = $options['min'] ?? null;
		$max = $options['max'] ?? null;
		if ($min === null && $max === null) {
			return 'length needs min or max';
		}

		if (self::isNumberOrAbsent(value: $min) === false || self::isNumberOrAbsent(value: $max) === false) {
			return 'length min and max are numbers';
		}

		return null;
	}//end invalidOptions()

	/**
	 * Whether an option is absent or numeric.
	 *
	 * @param mixed $value The option.
	 *
	 * @return bool
	 */
	private static function isNumberOrAbsent(mixed $value): bool {
		return $value === null || is_numeric($value) === true;
	}//end isNumberOrAbsent()
}//end class
