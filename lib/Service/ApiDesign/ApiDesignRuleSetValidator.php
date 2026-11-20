<?php

/**
 * Integriq ApiDesignRuleSetValidator.
 *
 * Says why a rule set cannot be saved: a header, a rule, a selector or a check
 * option that the engine cannot run.
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

use InvalidArgumentException;
use OCA\Integriq\Service\ApiDesign\Check\RuleCheckInterface;

/**
 * Validates API design rule sets.
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
class ApiDesignRuleSetValidator {

	/**
	 * Constructor.
	 *
	 * @param JsonPathSelector                  $selector Parses the selectors (given).
	 * @param array<string, RuleCheckInterface> $checks   The checks, by name.
	 */
	public function __construct(
		private readonly JsonPathSelector $selector,
		private readonly array $checks,
	) {
	}//end __construct()

	/**
	 * What makes a rule set unusable, one line per problem; empty when it can be saved.
	 *
	 * @param array<string, mixed> $ruleSet The rule set.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function problemsOf(array $ruleSet): array {
		$problems = self::headerProblems(ruleSet: $ruleSet);

		$rules = $ruleSet['rules'] ?? null;
		if (is_array($rules) === false || $rules === [] || array_is_list($rules) === false) {
			$problems[] = 'The rule set has no rules.';
			return $problems;
		}

		return array_merge($problems, $this->ruleListProblems(rules: $rules));
	}//end problemsOf()

	/**
	 * The problems of a rule set's rules: a label used twice, and each rule's own.
	 *
	 * @param list<mixed> $rules The rules.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	private function ruleListProblems(array $rules): array {
		$problems = [];
		$seen     = [];
		foreach ($rules as $index => $rule) {
			if (is_array($rule) === false) {
				$rule = [];
			}

			$label = self::ruleLabel(index: $index, rule: $rule);
			if (isset($seen[$label]) === true) {
				$problems[] = sprintf('Rule %s appears twice.', $label);
			}

			$seen[$label] = true;
			foreach ($this->ruleProblems(rule: $rule) as $problem) {
				$problems[] = sprintf('Rule %s: %s', $label, $problem);
			}
		}

		return $problems;
	}//end ruleListProblems()

	/**
	 * What is missing from a rule set's name and version.
	 *
	 * @param array<string, mixed> $ruleSet The rule set.
	 *
	 * @return list<string>
	 */
	private static function headerProblems(array $ruleSet): array {
		$problems = [];
		foreach (['name', 'version'] as $member) {
			if (is_string($ruleSet[$member] ?? null) === false || trim($ruleSet[$member]) === '') {
				$problems[] = sprintf('The rule set has no %s.', $member);
			}
		}

		return $problems;
	}//end headerProblems()

	/**
	 * How a rule is named in a problem: its id, else its position.
	 *
	 * @param int                  $index The rule's position.
	 * @param array<string, mixed> $rule  The rule.
	 *
	 * @return string
	 */
	private static function ruleLabel(int $index, array $rule): string {
		if (is_string($rule['id'] ?? null) === true) {
			return $rule['id'];
		}

		return '#'.$index;
	}//end ruleLabel()

	/**
	 * What is wrong with one rule.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return list<string>
	 */
	private function ruleProblems(array $rule): array {
		$problems = self::metadataProblems(rule: $rule);
		if (($rule['manual'] ?? false) === true) {
			return $problems;
		}

		return array_merge($problems, $this->givenProblems(rule: $rule), $this->thenProblems(rule: $rule));
	}//end ruleProblems()

	/**
	 * What is wrong with a rule's id, severity and link.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return list<string>
	 */
	private static function metadataProblems(array $rule): array {
		$problems = [];
		if (is_string($rule['id'] ?? null) === false || $rule['id'] === '') {
			$problems[] = 'it has no id';
		}

		if (in_array($rule['severity'] ?? null, ApiDesignRuleService::SEVERITIES, true) === false) {
			$problems[] = 'its severity is not error, warning or info';
		}

		if (is_string($rule['url'] ?? null) === false || str_starts_with($rule['url'], 'https://') === false) {
			$problems[] = 'it has no https link to the rule text';
		}

		return $problems;
	}//end metadataProblems()

	/**
	 * What is wrong with a rule's `given` selectors.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return list<string>
	 */
	private function givenProblems(array $rule): array {
		$problems = [];
		$givens = (array) ($rule['given'] ?? []);
		if ($givens === []) {
			$problems[] = 'it selects nothing (given)';
		}

		foreach ($givens as $given) {
			try {
				$this->selector->select(document: [], expression: (string) $given);
			} catch (InvalidArgumentException $exception) {
				$problems[] = $exception->getMessage();
			}
		}

		return $problems;
	}//end givenProblems()

	/**
	 * What is wrong with a rule's `then` checks.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return list<string>
	 */
	private function thenProblems(array $rule): array {
		$problems = [];
		$thens = $this->thensOf(rule: $rule);
		if ($thens === []) {
			$problems[] = 'it checks nothing (then)';
		}

		foreach ($thens as $then) {
			$problem = $this->thenProblem(then: $then);
			if ($problem !== null) {
				$problems[] = $problem;
			}
		}

		return $problems;
	}//end thenProblems()

	/**
	 * The problem of one `then`: an unknown check or options it refuses, or null.
	 *
	 * @param array<string, mixed> $then The `then`.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	private function thenProblem(array $then): ?string {
		$check = $this->checks[(string) ($then['function'] ?? '')] ?? null;
		if ($check === null) {
			return sprintf('check "%s" is not one of %s', (string) ($then['function'] ?? ''), implode(', ', array_keys($this->checks)));
		}

		return $check->invalidOptions(options: (array) ($then['functionOptions'] ?? []));
	}//end thenProblem()

	/**
	 * A rule's `then` as a list.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function thensOf(array $rule): array {
		$then = $rule['then'] ?? [];
		if (is_array($then) === false || $then === []) {
			return [];
		}

		if (array_is_list($then) === false) {
			return [$then];
		}

		return array_values(array_filter($then, 'is_array'));
	}//end thensOf()
}//end class
