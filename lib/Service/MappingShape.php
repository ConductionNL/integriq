<?php

/**
 * The input and output shapes MappingService::executeMapping() reads and writes.
 *
 * @category Service
 * @package  OCA\Integriq\Service
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

namespace OCA\Integriq\Service;

use Adbar\Dot;
use OCA\OpenRegister\Db\Mapping as OrMapping;

/**
 * The steps of a mapping that need no Twig and no lookups: expanding a list
 * input into items, removing a mapping's `unset` keys and unwrapping an
 * object written at root level (`#`).
 *
 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
 */
final class MappingShape {

	/**
	 * The items of a list input, each ready to be mapped on its own.
	 *
	 * A `listInput` key carries the list; the other input keys (but `value`)
	 * are extra values merged into every item. An item that is not an array,
	 * or any item when there are extra values, becomes the item's own keys
	 * plus `value` (the item itself) plus the extra values.
	 *
	 * @param array $input The list, or `listInput` with extra values.
	 *
	 * @return array The items, keyed as in the input list.
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
	 */
	public static function listItems(array $input): array {
		$extraValues = [];
		if (array_key_exists('listInput', $input) === true) {
			$extraValues = $input;
			$input = $input['listInput'];
			unset($extraValues['listInput'], $extraValues['value']);
		}

		$items = [];
		foreach ($input as $key => $value) {
			// The mapping expects an array for its input.
			if (is_array($value) === false || empty($extraValues) === false) {
				// Todo: we want to remove ['value' => $value] from this at some point, for now required for DOWR to work.
				$value = array_merge((array)$value, ['value' => $value], $extraValues);
			}

			$items[$key] = $value;
		}

		return $items;
	}//end listItems()

	/**
	 * Remove a mapping's `unset` keys that exist in the mapped values.
	 *
	 * @param OrMapping $mapping  The mapping recipe.
	 * @param Dot       $dotArray The mapped values; changed in place.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
	 */
	public static function removeUnsets(OrMapping $mapping, Dot $dotArray): void {
		foreach (($mapping->getUnset() ?? []) as $unset) {
			if ($dotArray->has($unset) === true) {
				$dotArray->delete($unset);
			}
		}
	}//end removeUnsets()

	/**
	 * Unwrap an output whose only key is `#`: the mapping wrote the object at root level.
	 *
	 * The root value is returned when it is an array, as an empty array when it
	 * is null, and wrapped in a list otherwise.
	 *
	 * @param array $output The mapped output.
	 *
	 * @return array The output.
	 *
	 * @spec openspec/specs/openconnector-direct-or-usage/spec.md
	 */
	public static function rootLevelOutput(array $output): array {
		if (array_keys($output) !== ['#']) {
			return $output;
		}

		$rootValue = $output['#'];
		if ($rootValue === null) {
			return [];
		}

		if (is_array($rootValue) === true) {
			return $rootValue;
		}

		return [$rootValue];
	}//end rootLevelOutput()
}//end class
