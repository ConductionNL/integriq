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
		$expr = trim($expr);
		if ($expr === '' || $expr === '$') {
			return $data;
		}

		if (str_starts_with($expr, '$') === true) {
			$expr = substr($expr, 1);
		}

		// Strip a single leading '.' left after removing the '$' (the
		// canonical `$.a.b` form); a bracket-first expression like `$[0]`
		// has no leading dot to strip.
		if (str_starts_with($expr, '.') === true) {
			$expr = substr($expr, 1);
		}

		$tokens = $this->tokenize(path: $expr);

		$current = $data;
		foreach ($tokens as $token) {
			if (is_array($current) === false) {
				return null;
			}

			if (array_key_exists($token, $current) === false) {
				return null;
			}

			$current = $current[$token];
		}

		return $current;
	}//end evaluate()

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
