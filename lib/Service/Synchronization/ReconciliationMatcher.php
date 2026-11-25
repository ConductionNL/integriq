<?php

/**
 * Reads a target list and matches its items to register objects.
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

use Adbar\Dot;
use JWadhams\JsonLogic;

/**
 * Pure helpers of a reconciliation run: index, match and conditions.
 *
 * @spec openspec/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
 *
 * @SuppressWarnings(PHPMD.StaticAccess) JsonLogic::apply() is the library's only entry point,
 *   the same one the engine evaluates a synchronization's conditions with.
 */
class ReconciliationMatcher {

	/**
	 * The listed items keyed by the id at `$idPosition`; items without an id are left out.
	 *
	 * @param iterable $items      The listed items.
	 * @param string   $idPosition The dot path of an item's id.
	 *
	 * @return array<string, array> The items by id.
	 *
	 * @spec openspec/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	public function indexById(iterable $items, string $idPosition): array {
		$byId = [];
		foreach ($items as $item) {
			$id = (new Dot((array)$item))->get($idPosition);
			if (is_scalar($id) === true && (string)$id !== '') {
				$byId[(string)$id] = (array)$item;
			}
		}

		return $byId;
	}//end indexById()

	/**
	 * The first unclaimed item that matches the object on one of the pairs, tried in order.
	 *
	 * @param array                $pairs   The `{target, source}` dot-path pairs.
	 * @param array                $object  The serialized object.
	 * @param array<string, array> $items   The target items by id.
	 * @param array<string, bool>  $claimed The ids already claimed.
	 *
	 * @return string|null The matching id, or null.
	 *
	 * @spec openspec/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	public function findMatch(array $pairs, array $object, array $items, array $claimed): ?string {
		$source = new Dot($object);
		foreach ($pairs as $pair) {
			$value = $source->get((string)($pair['source'] ?? ''));
			if (is_scalar($value) === false || (string)$value === '') {
				continue;
			}

			$found = $this->findByValue(items: array_diff_key($items, $claimed), path: (string)($pair['target'] ?? ''), value: (string)$value);
			if ($found !== null) {
				return $found;
			}
		}

		return null;
	}//end findMatch()

	/**
	 * Whether an object meets a synchronization's conditions, as the event push reads them.
	 *
	 * A list holds when every JsonLogic group in it holds; only a literal false fails a group.
	 *
	 * @param mixed $conditions The conditions: [] (none), a JsonLogic object, or a list of them.
	 * @param array $data       The serialized object.
	 *
	 * @return bool True when there are no conditions or every one holds.
	 *
	 * @spec openspec/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	public function conditionsHold(mixed $conditions, array $data): bool {
		if ($conditions === [] || $conditions === null) {
			return true;
		}

		if (is_array($conditions) === true && array_is_list($conditions) === true) {
			foreach ($conditions as $group) {
				if ($this->conditionsHold(conditions: $group, data: $data) === false) {
					return false;
				}
			}

			return true;
		}

		return JsonLogic::apply($conditions, $data) !== false;
	}//end conditionsHold()

	/**
	 * The id of the first item whose value at `$path` equals `$value`.
	 *
	 * @param array<string, array> $items The items by id.
	 * @param string               $path  The dot path.
	 * @param string               $value The value.
	 *
	 * @return string|null The id, or null.
	 *
	 * @spec openspec/specs/staff-timetable-sync/spec.md#requirement-a-nightly-run-repairs-drift-without-making-teachers-inactive-on-a-partial-read-req-hrs-004
	 */
	private function findByValue(array $items, string $path, string $value): ?string {
		foreach ($items as $id => $item) {
			$candidate = (new Dot($item))->get($path);
			if (is_scalar($candidate) === true && (string)$candidate === $value) {
				return (string)$id;
			}
		}

		return null;
	}//end findByValue()
}//end class
