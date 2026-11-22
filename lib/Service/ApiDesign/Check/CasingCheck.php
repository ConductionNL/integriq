<?php

/**
 * Integriq CasingCheck.
 *
 * Passes when a text value is written in the casing `type` names.
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
 * `casing`: `type` is one of flat, camel, pascal, kebab, cobol, snake or macro.
 * An absent field is not checked.
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
class CasingCheck implements RuleCheckInterface {

	/**
	 * The pattern of each casing.
	 *
	 * @var array<string, string>
	 */
	private const CASINGS = [
		'flat' => '/^[a-z][a-z0-9]*$/',
		'camel' => '/^[a-z][a-z0-9]*(?:[A-Z][a-z0-9]*)*$/',
		'pascal' => '/^[A-Z][a-z0-9]*(?:[A-Z][a-z0-9]*)*$/',
		'kebab' => '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/',
		'cobol' => '/^[A-Z][A-Z0-9]*(?:-[A-Z0-9]+)*$/',
		'snake' => '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/',
		'macro' => '/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/',
	];

	/**
	 * The name a rule uses for this check, as in `then.function`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function name(): string {
		return 'casing';
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

		$type = (string) ($options['type'] ?? '');
		if (is_string($value) === false || preg_match(self::CASINGS[$type], $value) !== 1) {
			$shown = gettype($value);
			if (is_scalar($value) === true) {
				$shown = (string) $value;
			}

			return sprintf('"%s" is not %s case', $shown, $type);
		}

		return null;
	}//end check()

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
		if (isset(self::CASINGS[(string) ($options['type'] ?? '')]) === false) {
			return 'casing type must be one of '.implode(', ', array_keys(self::CASINGS));
		}

		return null;
	}//end invalidOptions()
}//end class
