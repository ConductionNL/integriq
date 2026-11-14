<?php

/**
 * Integriq FalsyCheck.
 *
 * Passes when the value is absent or empty.
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
 * `falsy`: the value is absent, or false, null, zero, an empty string or an empty list.
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
class FalsyCheck implements RuleCheckInterface {

	/**
	 * The name a rule uses for this check, as in `then.function`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function name(): string {
		return 'falsy';
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
		if ($present === true && empty($value) === false) {
			return 'is present';
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
		return null;
	}//end invalidOptions()
}//end class
