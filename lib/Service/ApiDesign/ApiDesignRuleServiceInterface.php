<?php

/**
 * Integriq ApiDesignRuleServiceInterface.
 *
 * Checks an OpenAPI description against API design rule sets.
 *
 * @category Service
 * @package  OCA\Integriq\Service\ApiDesign
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

namespace OCA\Integriq\Service\ApiDesign;

/**
 * The API design rule engine (design D1 to D4).
 *
 * A rule set is `name`, `version`, `source` (`built-in` or `custom`),
 * `enabled` and `rules`. A rule is `id`, `title`, `severity` (`error`,
 * `warning` or `info`), `url`, and either `manual: true` or `given` (one
 * JSONPath selector or a list) and `then` (`field`, `function`,
 * `functionOptions`, or a list of those), with an optional `message`.
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
interface ApiDesignRuleServiceInterface {

	/**
	 * The rule sets integriq ships, the Dutch API design rules first.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-the-dutch-api-design-rules-are-built-in-req-adrc-002
	 */
	public function builtInRuleSets(): array;

	/**
	 * What makes a rule set unusable, one line per problem; empty when it can be saved.
	 *
	 * @param array<string, mixed> $ruleSet The rule set.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function problemsOf(array $ruleSet): array;

	/**
	 * Check a document against every enabled rule set.
	 *
	 * Each failed rule is a finding: `ruleset`, `ruleId`, `severity`, `path`
	 * (a JSON pointer into the document), `message`, `url` and `status`
	 * `open`. Manual rules are listed apart, so the result says what still
	 * needs a person.
	 *
	 * @param array<string, mixed>       $document The decoded OpenAPI description.
	 * @param list<array<string, mixed>> $ruleSets The rule sets.
	 *
	 * @return array{findings: list<array<string, mixed>>, manual: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function check(array $document, array $ruleSets): array;

	/**
	 * The findings that hold back publication: open and error-level. Warnings never block.
	 *
	 * @param list<array<string, mixed>> $findings The findings.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
	 */
	public function blockingFindings(array $findings): array;
}//end interface
