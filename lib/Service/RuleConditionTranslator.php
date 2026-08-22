<?php

/**
 * Rewrites a Rule's JsonLogic from the request envelope onto a flow item.
 *
 * Split out of {@see \OCA\Integriq\Service\RuleToFlowGenerator} because it
 * is the part of that translation with a right answer independent of flows: a
 * dialect question, testable on its own.
 *
 * Both sides evaluate JsonLogic through the same `jwadhams/json-logic-php`, and
 * OpenRegister's `FlowExpression` REGISTERS EXTRA OPERATORS on top, so the
 * operator vocabulary is a superset and no operator needs translating. What
 * differs is the DATA the expression is applied to. A rule is evaluated against
 * the endpoint's request envelope (`body`, `parameters`, `headers`, `path`,
 * `logicResult`, …); a flow condition is evaluated against
 * `{json, binary, itemIndex, itemCount, context, subject}` where `json` is the
 * object. So `body.x` becomes `json.x` and NOTHING ELSE translates — a
 * condition on a header or a query parameter has no object-event equivalent at
 * all, and this class REPORTS it rather than dropping it, because a dropped
 * condition turns "fire on some objects" into "fire on every object" while the
 * flow still reports success.
 *
 * Sub-scope operators (`map`, `filter`, `reduce`, `all`, `some`, `none`) REBIND
 * what `var` means inside them, so a blind `body.` → `json.` rewrite would be
 * wrong in exactly the places it looked right. They are reported too.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Translates rule conditions onto flow items, and names what it cannot.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
final class RuleConditionTranslator {

	/**
	 * The request-envelope root a flow item can stand in for.
	 *
	 * @var string
	 */
	private const TRANSLATABLE_ROOT = 'body';

	/**
	 * The item key that root becomes.
	 *
	 * @var string
	 */
	private const TRANSLATED_ROOT = 'json';

	/**
	 * JsonLogic operators that rebind `var` inside their own scope.
	 *
	 * @var array<int, string>
	 */
	private const SCOPE_OPERATORS = ['map', 'filter', 'reduce', 'all', 'some', 'none'];

	/**
	 * Everything in this expression that cannot be translated.
	 *
	 * An empty `scopes` and an empty `paths` mean the whole tree translates.
	 *
	 * @param array $conditions The rule's JsonLogic expression tree.
	 *
	 * @return array{scopes: array<int, string>, paths: array<int, string>} What blocks translation.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function problemsIn(array $conditions): array {
		$found = ['scopes' => [], 'paths' => []];
		$this->collect(logic: $conditions, found: $found);

		return [
			'scopes' => array_values(array_unique($found['scopes'])),
			'paths' => array_values(array_unique($found['paths'])),
		];

	}//end problemsIn()

	/**
	 * Walk a JsonLogic tree, collecting what cannot be translated.
	 *
	 * @param mixed $logic The expression (or sub-expression).
	 * @param array $found Accumulator with `scopes` and `paths` keys.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	private function collect(mixed $logic, array &$found): void {
		if (is_array($logic) === false) {
			return;
		}

		foreach ($logic as $operator => $operand) {
			if (is_string($operator) === true && in_array($operator, self::SCOPE_OPERATORS, true) === true) {
				$found['scopes'][] = $operator;
			}

			if ($operator === 'var') {
				$path = $operand;
				if (is_array($path) === true) {
					$path = ($path[0] ?? '');
				}

				if ($this->pathFor(path: (string)$path) === null) {
					$found['paths'][] = '"' . (string)$path . '"';
				}

				continue;
			}

			$this->collect(logic: $operand, found: $found);
		}//end foreach

	}//end collect()

	/**
	 * Rewrite one request-envelope path onto the flow item, or refuse it.
	 *
	 * @param string $path The `var` path as the rule wrote it.
	 *
	 * @return string|null The item path, or null when it has no equivalent.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function pathFor(string $path): ?string {
		$trimmed = trim($path);

		if ($trimmed === self::TRANSLATABLE_ROOT) {
			return self::TRANSLATED_ROOT;
		}

		if (str_starts_with($trimmed, (self::TRANSLATABLE_ROOT . '.')) === true) {
			return self::TRANSLATED_ROOT . substr($trimmed, strlen(self::TRANSLATABLE_ROOT));
		}

		return null;

	}//end pathFor()

	/**
	 * The expression, rewritten onto the flow item.
	 *
	 * Only ever called once {@see self::problemsIn()} has come back empty, so
	 * every `var` it meets is translatable by construction.
	 *
	 * @param mixed $logic The expression (or sub-expression).
	 *
	 * @return mixed The rewritten expression.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function translate(mixed $logic): mixed {
		if (is_array($logic) === false) {
			return $logic;
		}

		$translated = [];
		foreach ($logic as $operator => $operand) {
			if ($operator !== 'var') {
				$translated[$operator] = $this->translate(logic: $operand);
				continue;
			}

			if (is_array($operand) === false) {
				$translated[$operator] = $this->pathFor(path: (string)$operand);
				continue;
			}

			$rewritten = $operand;
			$rewritten[0] = $this->pathFor(path: (string)($operand[0] ?? ''));
			$translated[$operator] = $rewritten;
		}//end foreach

		return $translated;

	}//end translate()
}//end class
