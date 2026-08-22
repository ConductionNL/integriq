<?php

/**
 * Integriq Flow Template.
 *
 * A thin, node-local renderer for `{{dotted.path}}` placeholders resolved
 * against the CURRENT FLOW ITEM's `json` record. It exists because that
 * substitution has no existing implementation in this app: `CallService`
 * already renders a *Source's own* configuration through Twig
 * (`renderConfiguration()`), but with the *source data* as context and at a
 * different stage. Per ADR-011 nothing here re-implements that; the two
 * renderings compose (item -> request config, then source config -> request).
 *
 * If a shared item-templating helper ever lands in OpenRegister's flow
 * package, this class MUST be deleted in favour of it.
 *
 * DETERMINISTIC MISSING-PATH BEHAVIOUR (normative, spec'd): a placeholder
 * whose dotted path is absent from the item renders as the EMPTY STRING. It
 * never renders as the literal `{{...}}` text — a literal placeholder leaking
 * into a URL, a header or a body is how a templating bug becomes a request to
 * the wrong resource, and it is indistinguishable from an author who meant to
 * write braces.
 *
 * TYPE PRESERVATION: a value whose template is EXACTLY one placeholder and
 * nothing else (`"{{issue.number}}"`) yields the resolved value with its type
 * intact, so a numeric id stays an int and an object stays an array. A
 * placeholder embedded in surrounding text (`"/issues/{{issue.number}}/labels"`)
 * is string interpolation, and arrays interpolate as compact JSON.
 *
 * @category Flow
 * @package  OCA\Integriq\Flow
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
 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Flow;

/**
 * Renders `{{dotted.path}}` placeholders against a flow item's `json`.
 *
 * @spec openspec/changes/openconnector-flow-nodes/tasks.md#task-1-flow-node-scaffolding-guarded-registration-shared-helpers
 */
final class FlowTemplate {

	/**
	 * Matches a single `{{ dotted.path }}` placeholder.
	 *
	 * @var string
	 */
	private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_@.\-]+)\s*\}\}/';

	/**
	 * Matches a value that is EXACTLY one placeholder and nothing else.
	 *
	 * @var string
	 */
	private const WHOLE_PLACEHOLDER = '/^\{\{\s*([A-Za-z0-9_@.\-]+)\s*\}\}$/';

	/**
	 * Whether a string carries at least one placeholder.
	 *
	 * Used by the nodes to decide whether an SSRF containment check on the
	 * LITERAL authored value can be conclusive, or whether it must be repeated
	 * against the RENDERED value at execute time.
	 *
	 * @param string $value The authored value.
	 *
	 * @return boolean Whether a placeholder is present.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function hasPlaceholder(string $value): bool {
		return (preg_match(self::PLACEHOLDER, $value) === 1);
	}//end hasPlaceholder()

	/**
	 * Render a template to a string.
	 *
	 * Always returns a string, so it is the right entry point for values that
	 * are structurally strings — an endpoint path above all.
	 *
	 * @param string $template The authored template.
	 * @param array $json The current item's record.
	 *
	 * @return string The rendered string.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function renderString(string $template, array $json): string {
		return (string)preg_replace_callback(
			self::PLACEHOLDER,
			static function (array $matches) use ($json) {
				$value = self::lookup(path: $matches[1], json: $json);
				if ($value === null) {
					return '';
				}

				if (is_array($value) === true) {
					return (string)json_encode($value);
				}

				if (is_bool($value) === true) {
					if ($value === true) {
						return 'true';
					}

					return 'false';
				}

				return (string)$value;
			},
			$template
		);

	}//end renderString()

	/**
	 * Render a value, preserving the resolved type for a whole-placeholder.
	 *
	 * @param mixed $value The authored value (string, array, or scalar).
	 * @param array $json The current item's record.
	 *
	 * @return mixed The rendered value.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function renderValue(mixed $value, array $json): mixed {
		if (is_array($value) === true) {
			$rendered = [];
			foreach ($value as $key => $member) {
				$renderedKey = $key;
				if (is_string($key) === true) {
					$renderedKey = self::renderString(template: $key, json: $json);
				}

				$rendered[$renderedKey] = self::renderValue(value: $member, json: $json);
			}

			return $rendered;
		}

		if (is_string($value) === false) {
			return $value;
		}

		$matches = [];
		if (preg_match(self::WHOLE_PLACEHOLDER, $value, $matches) === 1) {
			return self::lookup(path: $matches[1], json: $json);
		}

		return self::renderString(template: $value, json: $json);
	}//end renderValue()

	/**
	 * Resolve one dotted path against the record.
	 *
	 * @param string $path The dotted path.
	 * @param array $json The current item's record.
	 *
	 * @return mixed The resolved value, or null when the path is absent.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function lookup(string $path, array $json): mixed {
		$value = $json;
		foreach (explode('.', $path) as $segment) {
			if (is_array($value) === false || array_key_exists($segment, $value) === false) {
				return null;
			}

			$value = $value[$segment];
		}

		return $value;
	}//end lookup()

	/**
	 * Write a value into a record at a dotted target path.
	 *
	 * Missing intermediate levels are created; a non-array value standing where
	 * a level is needed is replaced, because the author named that target and a
	 * silently skipped write is the failure mode this whole change exists to
	 * avoid.
	 *
	 * @param array $json The record to write into.
	 * @param string $path The dotted target path.
	 * @param mixed $value The value to write.
	 *
	 * @return array The record, with the value written.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function write(array $json, string $path, mixed $value): array {
		$segments = explode('.', $path);
		$cursor = &$json;

		foreach ($segments as $index => $segment) {
			if ($index === (count($segments) - 1)) {
				$cursor[$segment] = $value;
				break;
			}

			if (isset($cursor[$segment]) === false || is_array($cursor[$segment]) === false) {
				$cursor[$segment] = [];
			}

			$cursor = &$cursor[$segment];
		}

		unset($cursor);

		return $json;
	}//end write()

	/**
	 * Select a value out of a response payload using the node's selector grammar.
	 *
	 * The grammar is deliberately small and documented rather than "JSONPath,
	 * roughly": a dotted path for the common case, an optional leading `$.`,
	 * numeric segments for list indices, and `[*]` (or a bare `*`) to map the
	 * remainder of the path across every member of a list. Anything else is not
	 * supported and resolves to null rather than to a guess.
	 *
	 * @param mixed $payload The decoded response payload.
	 * @param string $selector The selector.
	 *
	 * @return mixed The selected value, or null when the selector matches nothing.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	public static function select(mixed $payload, string $selector): mixed {
		$normalised = trim($selector);
		if (str_starts_with($normalised, '$.') === true) {
			$normalised = substr($normalised, 2);
		} elseif ($normalised === '$') {
			return $payload;
		}

		// Normalise `a[*].b` / `a[0].b` bracket syntax into dotted segments.
		$normalised = (string)preg_replace('/\[([^\]]*)\]/', '.$1', $normalised);
		$segments = array_values(
			array_filter(
				explode('.', $normalised),
				static function ($segment) {
					return ($segment !== '');
				}
			)
		);

		return self::walk(value: $payload, segments: $segments);
	}//end select()

	/**
	 * Walk the remaining selector segments over a value.
	 *
	 * @param mixed $value The current value.
	 * @param array $segments The remaining segments.
	 *
	 * @return mixed The selected value, or null.
	 *
	 * @spec openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md
	 */
	private static function walk(mixed $value, array $segments): mixed {
		if ($segments === []) {
			return $value;
		}

		$segment = array_shift($segments);

		if ($segment === '*') {
			if (is_array($value) === false) {
				return null;
			}

			$collected = [];
			foreach ($value as $member) {
				$selected = self::walk(value: $member, segments: $segments);
				if ($selected !== null) {
					$collected[] = $selected;
				}
			}

			return $collected;
		}

		if (is_array($value) === false || array_key_exists($segment, $value) === false) {
			return null;
		}

		return self::walk(value: $value[$segment], segments: $segments);
	}//end walk()
}//end class
