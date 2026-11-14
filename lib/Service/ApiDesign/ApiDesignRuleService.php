<?php

/**
 * Integriq ApiDesignRuleService.
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

use InvalidArgumentException;
use OCA\Integriq\Service\ApiDesign\Check\CasingCheck;
use OCA\Integriq\Service\ApiDesign\Check\EnumCheck;
use OCA\Integriq\Service\ApiDesign\Check\FalsyCheck;
use OCA\Integriq\Service\ApiDesign\Check\LengthCheck;
use OCA\Integriq\Service\ApiDesign\Check\PatternCheck;
use OCA\Integriq\Service\ApiDesign\Check\RuleCheckInterface;
use OCA\Integriq\Service\ApiDesign\Check\SchemaCheck;
use OCA\Integriq\Service\ApiDesign\Check\TruthyCheck;

/**
 * Rules are data, the engine is small (design D1).
 *
 * Local `$ref`s are inlined before a rule selects anything, so a schema
 * reached through `components` is checked as what it is. A remote `$ref`
 * stays as written. A rule whose selector cannot be read is reported as a
 * finding of that rule rather than skipped, so a broken rule cannot pass.
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
class ApiDesignRuleService implements ApiDesignRuleServiceInterface {

	/**
	 * The severities a rule can carry.
	 *
	 * @var list<string>
	 */
	public const SEVERITIES = ['error', 'warning', 'info'];

	/**
	 * How deep local references are followed.
	 *
	 * @var int
	 */
	private const MAX_REF_DEPTH = 32;

	/**
	 * The checks by name.
	 *
	 * @var array<string, RuleCheckInterface>
	 */
	private array $checks = [];

	/**
	 * Constructor.
	 *
	 * @param JsonPathSelector $selector Evaluates a rule's `given`.
	 */
	public function __construct(
		private readonly JsonPathSelector $selector,
	) {
		$checks = [new TruthyCheck(), new FalsyCheck(), new PatternCheck(), new EnumCheck(), new CasingCheck(), new SchemaCheck(), new LengthCheck()];
		foreach ($checks as $check) {
			$this->checks[$check->name()] = $check;
		}

	}//end __construct()

	/**
	 * The rule sets integriq ships, the Dutch API design rules first.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-the-dutch-api-design-rules-are-built-in-req-adrc-002
	 */
	public function builtInRuleSets(): array {
		$files = glob(dirname(__DIR__, 2).'/Settings/api-design-rules/*.json');
		if ($files === false) {
			return [];
		}

		sort($files);
		$ruleSets = [];
		foreach ($files as $file) {
			$ruleSet = json_decode((string) file_get_contents($file), true);
			if (is_array($ruleSet) === true) {
				$ruleSets[] = $ruleSet;
			}
		}

		usort(
			$ruleSets,
			static fn (array $one, array $two): int => (($two['name'] ?? '') === 'nl-api-design-rules') <=> (($one['name'] ?? '') === 'nl-api-design-rules')
		);

		return $ruleSets;
	}//end builtInRuleSets()

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

		$seen = [];
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
	}//end problemsOf()

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
	 * Check a document against every enabled rule set.
	 *
	 * @param array<string, mixed>       $document The decoded OpenAPI description.
	 * @param list<array<string, mixed>> $ruleSets The rule sets.
	 *
	 * @return array{findings: list<array<string, mixed>>, manual: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function check(array $document, array $ruleSets): array {
		$document = self::inline(node: $document, root: $document, depth: 0, chain: []);
		$findings = [];
		$manual   = [];
		foreach ($ruleSets as $ruleSet) {
			if (($ruleSet['enabled'] ?? true) === false) {
				continue;
			}

			$name = (string) ($ruleSet['name'] ?? '');
			foreach ((array) ($ruleSet['rules'] ?? []) as $rule) {
				if (is_array($rule) === false) {
					continue;
				}

				if (($rule['manual'] ?? false) === true) {
					$manual[] = self::manualEntry(ruleSetName: $name, rule: $rule);
					continue;
				}

				foreach ($this->evaluate(document: $document, rule: $rule) as $failure) {
					$findings[] = self::finding(ruleSetName: $name, rule: $rule, failure: $failure);
				}
			}//end foreach
		}//end foreach

		return ['findings' => $findings, 'manual' => $manual];
	}//end check()

	/**
	 * A manual rule as an entry of the manual checklist.
	 *
	 * @param string               $ruleSetName The rule set's name.
	 * @param array<string, mixed> $rule        The rule.
	 *
	 * @return array<string, mixed>
	 */
	private static function manualEntry(string $ruleSetName, array $rule): array {
		return [
			'ruleset' => $ruleSetName,
			'ruleId' => (string) ($rule['id'] ?? ''),
			'title' => (string) ($rule['title'] ?? ''),
			'url' => (string) ($rule['url'] ?? ''),
		];
	}//end manualEntry()

	/**
	 * One failure of a rule as an open finding.
	 *
	 * @param string                               $ruleSetName The rule set's name.
	 * @param array<string, mixed>                 $rule        The rule.
	 * @param array{path: string, message: string} $failure     The failure.
	 *
	 * @return array<string, mixed>
	 */
	private static function finding(string $ruleSetName, array $rule, array $failure): array {
		return [
			'ruleset' => $ruleSetName,
			'ruleId' => (string) ($rule['id'] ?? ''),
			'severity' => (string) ($rule['severity'] ?? 'error'),
			'path' => $failure['path'],
			'message' => $failure['message'],
			'url' => (string) ($rule['url'] ?? ''),
			'status' => 'open',
		];
	}//end finding()

	/**
	 * The findings that hold back publication: open and error-level.
	 *
	 * @param list<array<string, mixed>> $findings The findings.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003
	 */
	public function blockingFindings(array $findings): array {
		return array_values(
			array_filter(
				$findings,
				static fn (array $finding): bool => ($finding['severity'] ?? null) === 'error' && ($finding['status'] ?? 'open') === 'open'
			)
		);
	}//end blockingFindings()

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

		if (in_array($rule['severity'] ?? null, self::SEVERITIES, true) === false) {
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
		$thens = self::thens(rule: $rule);
		if ($thens === []) {
			$problems[] = 'it checks nothing (then)';
		}

		foreach ($thens as $then) {
			$check = $this->checks[(string) ($then['function'] ?? '')] ?? null;
			if ($check === null) {
				$problems[] = sprintf('check "%s" is not one of %s', (string) ($then['function'] ?? ''), implode(', ', array_keys($this->checks)));
				continue;
			}

			$invalid = $check->invalidOptions(options: (array) ($then['functionOptions'] ?? []));
			if ($invalid !== null) {
				$problems[] = $invalid;
			}
		}

		return $problems;
	}//end thenProblems()

	/**
	 * Run one rule over a document.
	 *
	 * @param array<string, mixed> $document The document, references inlined.
	 * @param array<string, mixed> $rule     The rule.
	 *
	 * @return list<array{path: string, message: string}>
	 */
	private function evaluate(array $document, array $rule): array {
		$failures = [];
		foreach ((array) ($rule['given'] ?? []) as $given) {
			try {
				$nodes = $this->selector->select(document: $document, expression: (string) $given);
			} catch (InvalidArgumentException $exception) {
				$failures[] = ['path' => '', 'message' => 'The rule cannot be evaluated: '.$exception->getMessage()];
				continue;
			}

			foreach ($nodes as $node) {
				foreach (self::thens(rule: $rule) as $then) {
					$check = $this->checks[(string) ($then['function'] ?? '')] ?? null;
					if ($check === null) {
						$failures[] = [
							'path' => $node['pointer'],
							'message' => sprintf('The rule cannot be evaluated: unknown check "%s"', (string) ($then['function'] ?? '')),
						];
						continue;
					}

					[$present, $value, $pointer] = self::field(node: $node, field: $then['field'] ?? null);
					$error = $check->check(value: $value, present: $present, options: (array) ($then['functionOptions'] ?? []));
					if ($error !== null) {
						$failures[] = ['path' => $pointer, 'message' => self::message(rule: $rule, error: $error, value: $value, key: $node['key'])];
					}
				}
			}
		}//end foreach

		return $failures;
	}//end evaluate()

	/**
	 * A rule's `then` as a list.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function thens(array $rule): array {
		$then = $rule['then'] ?? [];
		if (is_array($then) === false || $then === []) {
			return [];
		}

		if (array_is_list($then) === false) {
			return [$then];
		}

		return array_values(array_filter($then, 'is_array'));
	}//end thens()

	/**
	 * The value a `then.field` names under a selected node.
	 *
	 * @param array{pointer: string, key: string|int|null, value: mixed} $node  The node.
	 * @param mixed                                                       $field `@key`, a dotted member path, or null for the node itself.
	 *
	 * @return array{0: bool, 1: mixed, 2: string} Present, value and its pointer.
	 */
	private static function field(array $node, mixed $field): array {
		if ($field === null || $field === '') {
			return [true, $node['value'], $node['pointer']];
		}

		if ($field === '@key') {
			return [true, $node['key'], $node['pointer']];
		}

		$value   = $node['value'];
		$pointer = $node['pointer'];
		foreach (explode('.', (string) $field) as $member) {
			if (is_array($value) === false || array_key_exists($member, $value) === false) {
				return [false, null, $pointer];
			}

			$value    = $value[$member];
			$pointer .= '/'.str_replace(['~', '/'], ['~0', '~1'], $member);
		}

		return [true, $value, $pointer];
	}//end field()

	/**
	 * A finding's message: the rule's own, with `{{error}}`, `{{value}}` and
	 * `{{property}}` filled in, followed by the error when the rule's message
	 * does not place it.
	 *
	 * @param array<string, mixed> $rule  The rule.
	 * @param string               $error What failed.
	 * @param mixed                $value The checked value.
	 * @param string|int|null      $key   The selected node's key.
	 *
	 * @return string
	 */
	private static function message(array $rule, string $error, mixed $value, string|int|null $key): string {
		$template = (string) ($rule['message'] ?? ($rule['title'] ?? ''));
		if ($template === '') {
			return $error;
		}

		$text = strtr(
			$template,
			[
				'{{error}}' => $error,
				'{{value}}' => self::scalarText(value: $value),
				'{{property}}' => (string) $key,
			]
		);

		if (str_contains($template, '{{error}}') === true) {
			return $text;
		}

		return $text.' ('.$error.')';
	}//end message()

	/**
	 * A value as text, empty when it is not a scalar.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function scalarText(mixed $value): string {
		if (is_scalar($value) === true) {
			return (string) $value;
		}

		return '';
	}//end scalarText()

	/**
	 * Inline local `$ref`s. A reference that loops or runs deeper than the
	 * limit is left as written.
	 *
	 * @param mixed                $node  The node.
	 * @param array<string, mixed> $root  The whole document.
	 * @param int                  $depth How many references deep.
	 * @param list<string>         $chain The references followed to get here.
	 *
	 * @return mixed
	 */
	private static function inline(mixed $node, array $root, int $depth, array $chain): mixed {
		if (is_array($node) === false) {
			return $node;
		}

		$ref = $node['$ref'] ?? null;
		if (is_string($ref) === true && str_starts_with($ref, '#/') === true) {
			if ($depth >= self::MAX_REF_DEPTH || in_array($ref, $chain, true) === true) {
				return $node;
			}

			[$found, $target] = self::resolvePointer(root: $root, ref: $ref);
			if ($found === false) {
				return $node;
			}

			return self::inline(node: $target, root: $root, depth: $depth + 1, chain: [...$chain, $ref]);
		}

		foreach ($node as $key => $child) {
			$node[$key] = self::inline(node: $child, root: $root, depth: $depth, chain: $chain);
		}

		return $node;
	}//end inline()

	/**
	 * The value a local `#/...` reference points at.
	 *
	 * @param array<string, mixed> $root The whole document.
	 * @param string               $ref  The reference, starting with `#/`.
	 *
	 * @return array{0: bool, 1: mixed} Whether it resolves, and the value it points at.
	 */
	private static function resolvePointer(array $root, string $ref): array {
		$target = $root;
		foreach (explode('/', substr($ref, 2)) as $part) {
			$part = str_replace(['~1', '~0'], ['/', '~'], $part);
			if (is_array($target) === false || array_key_exists($part, $target) === false) {
				return [false, null];
			}

			$target = $target[$part];
		}

		return [true, $target];
	}//end resolvePointer()
}//end class
