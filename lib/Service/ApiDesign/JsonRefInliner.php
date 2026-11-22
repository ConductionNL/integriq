<?php

/**
 * Integriq JsonRefInliner.
 *
 * Inlines the local `$ref`s (`#/...`) of a JSON document, so a selector sees
 * the schema a reference points at. A cyclic or too deep chain is left as the
 * reference it is.
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
 * Inlines local JSON references.
 *
 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
 */
class JsonRefInliner {

	/**
	 * How deep local references are followed.
	 *
	 * @var int
	 */
	private const MAX_REF_DEPTH = 32;

	/**
	 * The document with every resolvable local `$ref` replaced by what it points at.
	 *
	 * @param array<string,mixed> $document The document.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/gateway-api-design-rules-check/specs/api-design-rules/spec.md#requirement-an-api-description-is-checked-against-rule-sets-req-adrc-001
	 */
	public function inlined(array $document): array {
		return (array) self::inline(node: $document, root: $document, chain: []);

	}//end inlined()

	/**
	 * Inline local `$ref`s. A reference that loops or runs deeper than the
	 * limit is left as written.
	 *
	 * @param mixed                $node  The node.
	 * @param array<string, mixed> $root  The whole document.
	 * @param list<string>         $chain The references followed to get here.
	 *
	 * @return mixed
	 */
	private static function inline(mixed $node, array $root, array $chain): mixed {
		if (is_array($node) === false) {
			return $node;
		}

		$ref = $node['$ref'] ?? null;
		if (is_string($ref) === true && str_starts_with($ref, '#/') === true) {
			return self::inlineRef(node: $node, ref: $ref, root: $root, chain: $chain);
		}

		foreach ($node as $key => $child) {
			$node[$key] = self::inline(node: $child, root: $root, chain: $chain);
		}

		return $node;
	}//end inline()

	/**
	 * A local reference node replaced by its inlined target, or left as written when
	 * it loops, runs deeper than the limit or points nowhere.
	 *
	 * @param array<mixed, mixed>  $node  The reference node.
	 * @param string               $ref   Its `#/...` reference.
	 * @param array<string, mixed> $root  The whole document.
	 * @param list<string>         $chain The references followed to get here.
	 *
	 * @return mixed
	 */
	private static function inlineRef(array $node, string $ref, array $root, array $chain): mixed {
		if (count($chain) >= self::MAX_REF_DEPTH || in_array($ref, $chain, true) === true) {
			return $node;
		}

		[$found, $target] = self::resolvePointer(root: $root, ref: $ref);
		if ($found === false) {
			return $node;
		}

		return self::inline(node: $target, root: $root, chain: [...$chain, $ref]);
	}//end inlineRef()

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
