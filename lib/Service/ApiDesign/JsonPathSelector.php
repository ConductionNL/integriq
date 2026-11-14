<?php

/**
 * Integriq JsonPathSelector.
 *
 * Selects nodes of a decoded OpenAPI document with the part of JSONPath the
 * API design rules use.
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

/**
 * A small JSONPath evaluator (design D1).
 *
 * Supported: `$`, `.name`, `['name']`, `.*` and `[*]`, recursive descent
 * `..name`, and filters `[?(...)]` over the children of a node whose terms,
 * joined by `&&`, are `@property.match(/re/)` (optionally negated with `!`),
 * `@.field=='value'` and `@.field` (truthy). A trailing `~` selects the keys
 * instead of the values. Anything else is refused, so a rule cannot silently
 * select nothing.
 *
 * Each match is `['pointer' => JSON pointer, 'key' => its key, 'value' => the node]`.
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
class JsonPathSelector {

	/**
	 * Select the nodes an expression names.
	 *
	 * @param array<mixed> $document   The decoded document.
	 * @param string       $expression The JSONPath expression.
	 *
	 * @return list<array{pointer: string, key: string|int|null, value: mixed}>
	 *
	 * @throws InvalidArgumentException When the expression is outside the supported part.
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function select(array $document, string $expression): array {
		$expression = trim($expression);
		$keysOnly   = false;
		if (str_ends_with($expression, '~') === true) {
			$keysOnly   = true;
			$expression = substr($expression, 0, -1);
		}

		if (str_starts_with($expression, '$') === false) {
			throw new InvalidArgumentException(sprintf('A selector starts with $: %s', $expression));
		}

		$nodes = [['pointer' => '', 'key' => null, 'value' => $document]];
		foreach (self::tokens(expression: substr($expression, 1)) as $token) {
			$nodes = self::apply(nodes: $nodes, token: $token);
		}

		if ($keysOnly === true) {
			return array_map(
				static fn (array $node): array => ['pointer' => $node['pointer'], 'key' => $node['key'], 'value' => $node['key']],
				$nodes
			);
		}

		return $nodes;
	}//end select()

	/**
	 * Split an expression (without its `$`) into steps.
	 *
	 * @param string $expression The expression after `$`.
	 *
	 * @return list<array{type: string, name?: string, filter?: string}>
	 *
	 * @throws InvalidArgumentException On an unsupported step.
	 */
	private static function tokens(string $expression): array {
		$tokens = [];
		$offset = 0;
		$length = strlen($expression);
		while ($offset < $length) {
			$rest = substr($expression, $offset);
			if (preg_match('/^\.\.([A-Za-z0-9_\-\$@]+)/', $rest, $match) === 1) {
				$tokens[] = ['type' => 'descend', 'name' => $match[1]];
			} else if (preg_match('/^(?:\.\*|\[\*\])/', $rest, $match) === 1) {
				$tokens[] = ['type' => 'wildcard'];
			} else if (preg_match('/^\.([A-Za-z0-9_\-\$@]+)/', $rest, $match) === 1) {
				$tokens[] = ['type' => 'child', 'name' => $match[1]];
			} else if (preg_match('/^\[([\'"])(.*?)\1\]/', $rest, $match) === 1) {
				$tokens[] = ['type' => 'child', 'name' => $match[2]];
			} else if (preg_match('/^\[\?\((.*?)\)\]/', $rest, $match) === 1) {
				$tokens[] = ['type' => 'filter', 'filter' => $match[1]];
			} else {
				throw new InvalidArgumentException(sprintf('Unsupported selector step at "%s"', $rest));
			}

			$offset += strlen($match[0]);
		}//end while

		return $tokens;
	}//end tokens()

	/**
	 * Apply one step to the current nodes.
	 *
	 * @param list<array{pointer: string, key: string|int|null, value: mixed}> $nodes The current nodes.
	 * @param array{type: string, name?: string, filter?: string}                $token The step.
	 *
	 * @return list<array{pointer: string, key: string|int|null, value: mixed}>
	 */
	private static function apply(array $nodes, array $token): array {
		$next = [];
		foreach ($nodes as $node) {
			if ($token['type'] === 'descend') {
				$next = array_merge($next, self::descend(node: $node, name: (string) $token['name']));
				continue;
			}

			if (is_array($node['value']) === false) {
				continue;
			}

			foreach ($node['value'] as $key => $value) {
				$child = ['pointer' => $node['pointer'].'/'.self::escape(key: $key), 'key' => $key, 'value' => $value];
				if ($token['type'] === 'wildcard'
					|| ($token['type'] === 'child' && (string) $key === $token['name'])
					|| ($token['type'] === 'filter' && self::matches(filter: (string) $token['filter'], key: $key, value: $value) === true)
				) {
					$next[] = $child;
				}
			}
		}//end foreach

		return $next;
	}//end apply()

	/**
	 * Every descendant of a node (the node included) held under a key.
	 *
	 * @param array{pointer: string, key: string|int|null, value: mixed} $node The node.
	 * @param string                                                         $name The key.
	 *
	 * @return list<array{pointer: string, key: string|int|null, value: mixed}>
	 */
	private static function descend(array $node, string $name): array {
		if (is_array($node['value']) === false) {
			return [];
		}

		$found = [];
		foreach ($node['value'] as $key => $value) {
			$child = ['pointer' => $node['pointer'].'/'.self::escape(key: $key), 'key' => $key, 'value' => $value];
			if ((string) $key === $name) {
				$found[] = $child;
			}

			$found = array_merge($found, self::descend(node: $child, name: $name));
		}

		return $found;
	}//end descend()

	/**
	 * Whether a child passes a filter: every `&&` term must hold.
	 *
	 * @param string     $filter The filter body.
	 * @param string|int $key    The child's key.
	 * @param mixed      $value  The child.
	 *
	 * @return bool
	 *
	 * @throws InvalidArgumentException On an unsupported term.
	 */
	private static function matches(string $filter, string|int $key, mixed $value): bool {
		foreach (explode('&&', $filter) as $term) {
			$term = trim($term);
			if (preg_match('#^(!?)@property\.match\(/(.*)/([a-z]*)\)$#', $term, $match) === 1) {
				$hit = preg_match('/'.$match[2].'/'.$match[3], (string) $key) === 1;
				if ($hit === ($match[1] === '!')) {
					return false;
				}

				continue;
			}

			if (preg_match('/^@\.([A-Za-z0-9_\-]+)\s*==\s*([\'"])(.*)\2$/', $term, $match) === 1) {
				if (is_array($value) === false || ($value[$match[1]] ?? null) !== $match[3]) {
					return false;
				}

				continue;
			}

			if (preg_match('/^@\.([A-Za-z0-9_\-]+)$/', $term, $match) === 1) {
				if (is_array($value) === false || empty($value[$match[1]]) === true) {
					return false;
				}

				continue;
			}

			throw new InvalidArgumentException(sprintf('Unsupported filter term "%s"', $term));
		}//end foreach

		return true;
	}//end matches()

	/**
	 * Escape a key for a JSON pointer (RFC 6901).
	 *
	 * @param string|int $key The key.
	 *
	 * @return string
	 */
	private static function escape(string|int $key): string {
		return str_replace(['~', '/'], ['~0', '~1'], (string) $key);
	}//end escape()
}//end class
