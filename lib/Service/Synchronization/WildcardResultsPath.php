<?php

/**
 * Finds a response's objects through a `resultsPosition` that holds wildcards.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Synchronization
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Synchronization;

/**
 * Resolves a dot path with `*` and `**` segments to the objects it reaches.
 *
 * A fixed dot path (`channel.item`) reaches one place. A document that nests
 * its records in repeated containers (articles inside every chapter of a law,
 * chapters inside every part) needs one path for all of them:
 *
 * - `*` stands for each element of the list at that level. A single object
 *   where a list could be counts as a one-element list, because an XML
 *   element that occurs once decodes to an object and one that repeats to a
 *   list.
 * - `**` stands for any depth, zero levels included: `**.artikel` finds every
 *   `artikel` wherever it sits. A match is not searched again for itself, so
 *   nothing is found twice.
 *
 * Every value the path reaches is an object, or a list of objects that is
 * flattened in document order. Scalars reached are not objects and are left
 * out.
 *
 * @spec openspec/changes/native-data-gathering-provider/tasks.md
 */
class WildcardResultsPath {

	/**
	 * Whether a `resultsPosition` holds a wildcard segment.
	 *
	 * @param string $path The position.
	 *
	 * @return bool True when a segment is `*` or `**`.
	 *
	 * @spec openspec/changes/native-data-gathering-provider/tasks.md
	 */
	public function isWildcard(string $path): bool {
		$segments = explode('.', $path);

		return in_array('*', $segments, true) === true || in_array('**', $segments, true) === true;
	}//end isWildcard()

	/**
	 * The objects a wildcard path reaches, in document order.
	 *
	 * @param array  $data The decoded response.
	 * @param string $path The position, segments separated by dots.
	 *
	 * @return list<array> The objects.
	 *
	 * @spec openspec/changes/native-data-gathering-provider/tasks.md
	 */
	public function objects(array $data, string $path): array {
		$objects = [];
		foreach ($this->match(node: $data, segments: explode('.', $path)) as $value) {
			if (is_array($value) === false) {
				continue;
			}

			if (array_is_list($value) === false) {
				$objects[] = $value;
				continue;
			}

			foreach ($value as $item) {
				if (is_array($item) === true) {
					$objects[] = $item;
				}
			}
		}

		return $objects;
	}//end objects()

	/**
	 * Every value the remaining segments reach from a node.
	 *
	 * @param mixed        $node     The node.
	 * @param list<string> $segments The segments still to follow.
	 *
	 * @return list<mixed> The values reached.
	 */
	private function match(mixed $node, array $segments): array {
		if ($segments === []) {
			return [$node];
		}

		if (is_array($node) === false) {
			return [];
		}

		$segment = array_shift($segments);
		if ($segment === '**') {
			return $this->matchAnyDepth(node: $node, rest: $segments);
		}

		if ($segment === '*') {
			$elements = [$node];
			if (array_is_list($node) === true) {
				$elements = $node;
			}

			$found = [];
			foreach ($elements as $element) {
				array_push($found, ...$this->match(node: $element, segments: $segments));
			}

			return $found;
		}

		if (array_key_exists($segment, $node) === false) {
			return [];
		}

		return $this->match(node: $node[$segment], segments: $segments);
	}//end match()

	/**
	 * Every value the segments after a `**` reach, from this node or any below it.
	 *
	 * @param array        $node The node.
	 * @param list<string> $rest The segments after the `**`.
	 *
	 * @return list<mixed> The values reached.
	 */
	private function matchAnyDepth(array $node, array $rest): array {
		$found = $this->match(node: $node, segments: $rest);

		// The key the next segment matched here is a match already; searching
		// inside it again would report its own children a second time.
		$consumed = null;
		if ($rest !== [] && $rest[0] !== '*' && $rest[0] !== '**' && array_is_list($node) === false) {
			$consumed = $rest[0];
		}

		foreach ($node as $key => $child) {
			if (is_array($child) === false || ($consumed !== null && (string)$key === $consumed)) {
				continue;
			}

			array_push($found, ...$this->matchAnyDepth(node: $child, rest: $rest));
		}

		return $found;
	}//end matchAnyDepth()
}//end class
