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
	 * The checks by name.
	 *
	 * @var array<string, RuleCheckInterface>
	 */
	private array $checks = [];

	/**
	 * Checks rule sets before they are saved.
	 *
	 * @var ApiDesignRuleSetValidator
	 */
	private readonly ApiDesignRuleSetValidator $ruleSets;

	/**
	 * Inlines the local `$ref`s of a document.
	 *
	 * @var JsonRefInliner
	 */
	private readonly JsonRefInliner $refs;

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

		$this->ruleSets = new ApiDesignRuleSetValidator(selector: $selector, checks: $this->checks);
		$this->refs     = new JsonRefInliner();

	}//end __construct()

	/**
	 * The problems that keep a rule set from being saved; empty when it is sound.
	 *
	 * @param array<string,mixed> $ruleSet The rule set.
	 *
	 * @return list<string> The problems, each naming the rule it is about.
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function problemsOf(array $ruleSet): array {
		return $this->ruleSets->problemsOf(ruleSet: $ruleSet);

	}//end problemsOf()

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
		$document = $this->refs->inlined(document: $document);
		$findings = [];
		$manual   = [];
		foreach ($ruleSets as $ruleSet) {
			if (($ruleSet['enabled'] ?? true) === false) {
				continue;
			}

			$checked = $this->checkRuleSet(document: $document, ruleSet: $ruleSet);
			array_push($findings, ...$checked['findings']);
			array_push($manual, ...$checked['manual']);
		}

		return ['findings' => $findings, 'manual' => $manual];
	}//end check()

	/**
	 * The findings and the manual entries of one enabled rule set.
	 *
	 * @param array<string, mixed> $document The inlined API description.
	 * @param array<string, mixed> $ruleSet  The rule set.
	 *
	 * @return array{findings: list<array<string, mixed>>, manual: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	private function checkRuleSet(array $document, array $ruleSet): array {
		$name     = (string) ($ruleSet['name'] ?? '');
		$findings = [];
		$manual   = [];
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
		}

		return ['findings' => $findings, 'manual' => $manual];
	}//end checkRuleSet()

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
				array_push($failures, ...$this->nodeFailures(node: $node, rule: $rule));
			}
		}

		return $failures;
	}//end evaluate()

	/**
	 * The failures of every `then` of a rule on one selected node.
	 *
	 * @param array<string, mixed> $node The selected node.
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return list<array{path: string, message: string}>
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	private function nodeFailures(array $node, array $rule): array {
		$failures = [];
		foreach ($this->ruleSets->thensOf(rule: $rule) as $then) {
			$failure = $this->thenFailure(node: $node, ruleThen: ['rule' => $rule, 'then' => $then]);
			if ($failure !== null) {
				$failures[] = $failure;
			}
		}

		return $failures;
	}//end nodeFailures()

	/**
	 * The failure of one `then` on one node, or null when it holds.
	 *
	 * @param array<string, mixed>                                           $node     The selected node.
	 * @param array{rule: array<string, mixed>, then: array<string, mixed>} $ruleThen The rule and the `then` to apply.
	 *
	 * @return array{path: string, message: string}|null
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	private function thenFailure(array $node, array $ruleThen): ?array {
		['rule' => $rule, 'then' => $then] = $ruleThen;
		$check = $this->checks[(string) ($then['function'] ?? '')] ?? null;
		if ($check === null) {
			return [
				'path' => $node['pointer'],
				'message' => sprintf('The rule cannot be evaluated: unknown check "%s"', (string) ($then['function'] ?? '')),
			];
		}

		[$present, $value, $pointer] = self::field(node: $node, field: $then['field'] ?? null);
		$error = $check->check(value: $value, present: $present, options: (array) ($then['functionOptions'] ?? []));
		if ($error === null) {
			return null;
		}

		return ['path' => $pointer, 'message' => self::message(rule: $rule, error: $error, value: $value, key: $node['key'])];
	}//end thenFailure()

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

		return self::memberPath(value: $node['value'], pointer: $node['pointer'], path: (string) $field);
	}//end field()

	/**
	 * The value a dotted member path names under a value.
	 *
	 * @param mixed  $value   The value.
	 * @param string $pointer The value's pointer.
	 * @param string $path    The dotted member path.
	 *
	 * @return array{0: bool, 1: mixed, 2: string} Present, value and its pointer.
	 */
	private static function memberPath(mixed $value, string $pointer, string $path): array {
		foreach (explode('.', $path) as $member) {
			if (is_array($value) === false || array_key_exists($member, $value) === false) {
				return [false, null, $pointer];
			}

			$value    = $value[$member];
			$pointer .= '/'.str_replace(['~', '/'], ['~0', '~1'], $member);
		}

		return [true, $value, $pointer];
	}//end memberPath()

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

}//end class
