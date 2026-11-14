<?php

/**
 * Integriq SourceCredentialRefs.
 *
 * Walks one exported Source's `configuration.authentication` subtree for
 * `{"credentialRef": {...}}` placeholders, for {@see PromotionService}: it
 * lists their field paths (the scan) and rewrites the ones an operator
 * supplied a binding for (the rebinding). It only ever reads and writes
 * reference strings (`credentialId`/`credentialName`); it never resolves a
 * credential. Built by PromotionService per Source.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-credentialref-placeholders-are-re-bound-per-target-environment-never-resolved-to-a-secret-req-004
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * The credentialRef placeholders of one Source.
 *
 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-credentialref-placeholders-are-re-bound-per-target-environment-never-resolved-to-a-secret-req-004
 */
final class SourceCredentialRefs {

	/**
	 * The placeholders {@see collect()} found, in walk order.
	 *
	 * @var array<int,array{type:string,slug:string,field:string}>
	 */
	public array $flagged = [];

	/**
	 * Constructor.
	 *
	 * @param string $slug The Source's slug.
	 * @param array<string,array<string,array<string,string>>> $index The bindings, `bySlugField` and `bySlug` (empty for a scan).
	 */
	public function __construct(
		private readonly string $slug,
		private readonly array $index = ['bySlugField' => [], 'bySlug' => []],
	) {
	}//end __construct()

	/**
	 * Recursively collect `credentialRef` placeholder field paths under the
	 * subtree into {@see $flagged}.
	 *
	 * @param array<string,mixed> $node The current subtree.
	 * @param string $parentPath Dotted path to `$node` (not yet including the leaf key).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#requirement-credentialref-placeholders-are-re-bound-per-target-environment-never-resolved-to-a-secret-req-004
	 */
	public function collect(array $node, string $parentPath): void {
		if ($this->isPlaceholder(value: $node) === true) {
			$this->flagged[] = ['type' => 'source', 'slug' => $this->slug, 'field' => $parentPath . '.credentialRef'];
			return;
		}

		foreach ($node as $key => $value) {
			if (is_array($value) === true) {
				$this->collect(node: $value, parentPath: $parentPath . '.' . $key);
			}
		}

	}//end collect()

	/**
	 * Recursively rewrite matched `credentialRef` placeholders; unmatched
	 * placeholders and every other value are returned byte-for-byte unchanged (REQ-004).
	 *
	 * @param array<string,mixed> $node The current subtree.
	 * @param string $parentPath Dotted path to `$node`.
	 *
	 * @return array<string,mixed> The rewritten subtree.
	 *
	 * @spec openspec/specs/environments-and-promotion/spec.md#scenario-an-operator-supplied-rebinding-replaces-the-reference-before-the-target-ever-sees-the-original
	 */
	public function rewrite(array $node, string $parentPath): array {
		if ($this->isPlaceholder(value: $node) === true) {
			return $this->replacement(node: $node, field: $parentPath . '.credentialRef');
		}

		$result = [];
		foreach ($node as $key => $value) {
			$result[$key] = $value;
			if (is_array($value) === true) {
				$result[$key] = $this->rewrite(node: $value, parentPath: $parentPath . '.' . $key);
			}
		}

		return $result;
	}//end rewrite()

	/**
	 * Resolve one placeholder's replacement reference, or return it verbatim
	 * when no binding matches. A `slug|field` binding wins over a slug-wide one.
	 *
	 * @param array<string,mixed> $node The placeholder node (`{"credentialRef": {...}}`).
	 * @param string $field The placeholder's dotted field path.
	 *
	 * @return array<string,mixed> `{"credentialRef": <replacement>}` when matched, else `$node` unchanged.
	 */
	private function replacement(array $node, string $field): array {
		$key = $this->slug . '|' . $field;
		if (isset($this->index['bySlugField'][$key]) === true) {
			return ['credentialRef' => $this->index['bySlugField'][$key]];
		}

		if (isset($this->index['bySlug'][$this->slug]) === true) {
			return ['credentialRef' => $this->index['bySlug'][$this->slug]];
		}

		return $node;
	}//end replacement()

	/**
	 * Whether a value is a credential placeholder — the same shape
	 * {@see BrokeredCallService::isPlaceholder()} detects (single
	 * `credentialRef` key, array value). Deliberately duplicated rather than
	 * calling the private method on a service this class does not otherwise
	 * depend on — see design.md Decision 3 ("new, in-process" scan step).
	 *
	 * @param mixed $value The candidate value.
	 *
	 * @return boolean Whether the value is a credential placeholder.
	 */
	private function isPlaceholder(mixed $value): bool {
		return (is_array($value) === true
			&& array_keys($value) === ['credentialRef']
			&& is_array($value['credentialRef']) === true);

	}//end isPlaceholder()
}//end class
