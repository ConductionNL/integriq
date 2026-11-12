<?php

/**
 * Integriq — JSONPath-lite expression evaluator.
 *
 * Evaluates the small `$.a.b.c` / `$.a[0].b` value-expression dialect used by
 * the `dashboard-http-datasource` resolve façade against a decoded JSON
 * response body. Deliberately NOT a full JSONPath implementation (no
 * wildcards, filters, or recursive descent) — the façade resolves exactly
 * one scalar value per call, so a minimal dotted-path + integer-index
 * grammar is all the contract requires.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Datasource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-resolve-endpoint-returns-a-single-value-from-a-named-source
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Datasource;

/**
 * Stateless evaluator for the `$.a.b.c` / `$.a[0].b` value-expression dialect.
 *
 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-resolve-endpoint-returns-a-single-value-from-a-named-source
 */
class JsonPathLiteEvaluator {
	/**
	 * Evaluate a JSONPath-lite expression against decoded JSON data.
	 *
	 * Supports a leading `$` (whole document), dotted-key traversal
	 * (`$.a.b.c`), and zero-based integer array indices (`$.a[0].b`,
	 * `$.a[0][1]`). Any segment that does not resolve (missing key,
	 * out-of-range index, or traversal into a scalar) returns `null` rather
	 * than throwing — per the "Value expression finds nothing" scenario, a
	 * missing path is a normal, non-error outcome.
	 *
	 * @param mixed $data The decoded JSON document (array/scalar/null) to traverse.
	 * @param string $expr The JSONPath-lite expression, e.g. `$.data.open_count` or `$.items[0].id`.
	 *
	 * @return mixed The resolved value, or null when the path does not resolve.
	 *
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-resolve-endpoint-returns-a-single-value-from-a-named-source
	 */
	public function evaluate(mixed $data, string $expr): mixed {
		$path = self::relativePath(expr: $expr);
		if ($path === null) {
			return $data;
		}

		return self::walk(data: $data, tokens: $this->tokenize(path: $path));
	}//end evaluate()

	/**
	 * The path below the root, or null when the expression names the root itself.
	 *
	 * @param string $expr The expression (`$`, `$.a.b`, `$[0]`, `a.b`).
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-resolve-endpoint-returns-a-single-value-from-a-named-source
	 */
	private static function relativePath(string $expr): ?string {
		$expr = trim($expr);
		if ($expr === '' || $expr === '$') {
			return null;
		}

		// Strip one leading '$', then a single leading '.' (the canonical
		// `$.a.b` form); a bracket-first expression like `$[0]` has no
		// leading dot to strip.
		return (string)preg_replace('/^\$?\.?/', '', $expr, 1);
	}//end relativePath()

	/**
	 * Follow the tokens down the data; null as soon as one is missing.
	 *
	 * @param mixed                  $data   The data.
	 * @param array<int, int|string> $tokens The path tokens.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/dashboard-http-datasource/specs/dashboard-http-datasource/spec.md#requirement-resolve-endpoint-returns-a-single-value-from-a-named-source
	 */
	private static function walk(mixed $data, array $tokens): mixed {
		$current = $data;
		foreach ($tokens as $token) {
			if (is_array($current) === false || array_key_exists($token, $current) === false) {
				return null;
			}

			$current = $current[$token];
		}

		return $current;
	}//end walk()

	/**
	 * Tokenize a dotted-key / bracket-index path into an ordered list of
	 * string keys and integer indices.
	 *
	 * @param string $path The path with the leading `$`/`.` already stripped.
	 *
	 * @return array<int, string|int> Ordered traversal tokens.
	 */
	private function tokenize(string $path): array {
		if ($path === '') {
			return [];
		}

		preg_match_all('/[^.\[\]]+|\[\d+\]/', $path, $matches);

		$tokens = [];
		foreach ($matches[0] as $rawToken) {
			if (str_starts_with($rawToken, '[') === true) {
				$tokens[] = (int)trim($rawToken, '[]');
			} else {
				$tokens[] = $rawToken;
			}
		}

		return $tokens;
	}//end tokenize()
}//end class
