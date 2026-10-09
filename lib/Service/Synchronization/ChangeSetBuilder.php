<?php

/**
 * Builds the change set a gated synchronization shows before it writes.
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
 * Compares each mapped source object with the target it would overwrite.
 *
 * Pure: it reads nothing and writes nothing. The engine hands it what the
 * run fetched and mapped, and the targets those objects point at, at the
 * approval gate, where nothing has been written yet (design D3).
 *
 * The fingerprint covers every object, also those past the cut, so an
 * accept can tell whether the source changed after the preview (design D4).
 *
 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
 */
class ChangeSetBuilder {

	/**
	 * Objects listed in full per kind; the rest are counted.
	 */
	public const LIMIT = 500;

	/**
	 * Keys OpenRegister adds to a stored object that a mapping never writes.
	 */
	private const METADATA_KEYS = ['id', 'uuid', '@self'];

	/**
	 * Build the change set.
	 *
	 * Each entry is {originId, targetId, mapped, existing}: the mapped form of
	 * one fetched object and the stored target, or null when there is none.
	 * Each removal is {originId, targetId}.
	 *
	 * @param array $entries         One entry per fetched object.
	 * @param array $removed         Targets the source no longer carries.
	 * @param bool  $removalsAllowed Whether this run may delete at all (REQ-010).
	 *
	 * @return array The change set: created, changed, removed, unchanged, counts, truncated, limit, fingerprint.
	 *
	 * @spec openspec/changes/connectors-inavigator-case-types/specs/synchronization-engine/spec.md#requirement-a-gated-run-stores-its-change-set-on-the-approval-request-req-inav-003
	 */
	public function build(array $entries, array $removed, bool $removalsAllowed): array {
		$created = [];
		$changed = [];
		$unchanged = 0;

		foreach ($entries as $entry) {
			$mapped = $entry['mapped'];
			if ($entry['existing'] === null) {
				$created[] = ['originId' => (string)$entry['originId'], 'fields' => $mapped];
				continue;
			}

			$fields = $this->diff(mapped: $mapped, existing: $entry['existing']);
			if ($fields === []) {
				$unchanged++;
				continue;
			}

			$changed[] = [
				'originId' => (string)$entry['originId'],
				'targetId' => $entry['targetId'],
				'fields' => $fields,
			];
		}//end foreach

		$removedList = [];
		if ($removalsAllowed === true) {
			foreach ($removed as $target) {
				$removedList[] = ['originId' => (string)$target['originId'], 'targetId' => (string)$target['targetId']];
			}
		}

		$byOrigin = static fn (array $one, array $two): int => strcmp($one['originId'], $two['originId']);
		usort($created, $byOrigin);
		usort($changed, $byOrigin);
		usort($removedList, $byOrigin);

		$counts = [
			'created' => count($created),
			'changed' => count($changed),
			'removed' => count($removedList),
			'unchanged' => $unchanged,
		];

		$fingerprint = hash(
			'sha256',
			(string)json_encode(
				$this->canonical(value: ['created' => $created, 'changed' => $changed, 'removed' => $removedList, 'unchanged' => $unchanged])
			)
		);

		return [
			'created' => array_slice($created, 0, self::LIMIT),
			'changed' => array_slice($changed, 0, self::LIMIT),
			'removed' => array_slice($removedList, 0, self::LIMIT),
			'unchanged' => $unchanged,
			'counts' => $counts,
			'truncated' => max($counts['created'], $counts['changed'], $counts['removed']) > self::LIMIT,
			'limit' => self::LIMIT,
			'fingerprint' => $fingerprint,
		];
	}//end build()

	/**
	 * The fields a mapped object would change on its stored target.
	 *
	 * Only the keys the mapping writes are compared: a field the target has
	 * and the mapping does not name is left alone by the write, so it is no
	 * change.
	 *
	 * @param array $mapped   The mapped source object.
	 * @param array $existing The stored target.
	 *
	 * @return array<int, array{field: string, before: mixed, after: mixed}>
	 */
	private function diff(array $mapped, array $existing): array {
		$fields = [];
		foreach ($mapped as $field => $after) {
			if (in_array($field, self::METADATA_KEYS, true) === true) {
				continue;
			}

			$before = ($existing[$field] ?? null);
			if ($this->canonical(value: $before) === $this->canonical(value: $after)) {
				continue;
			}

			$fields[] = ['field' => (string)$field, 'before' => $before, 'after' => $after];
		}

		return $fields;
	}//end diff()

	/**
	 * A value with its object keys sorted, so key order is never a change.
	 *
	 * @param mixed $value Any JSON value.
	 *
	 * @return mixed
	 */
	private function canonical(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === false) {
			ksort($value);
		}

		foreach ($value as $key => $item) {
			$value[$key] = $this->canonical(value: $item);
		}

		return $value;
	}//end canonical()
}//end class
