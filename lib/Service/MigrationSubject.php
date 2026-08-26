<?php

/**
 * How a migration generator names the entity it was pointed at.
 *
 * Shared by the task-3.3 generators because both need exactly the same two
 * answers about a serialised OpenRegister record — "what do I record this as?"
 * and "what do I call it in a sentence?" — and a second copy of that logic is a
 * second place for the fallback order to drift.
 *
 * The order matters: `uuid` first, because that is what survives a rename, then
 * `slug` and `reference` (which a cross-environment export carries), then `id`.
 * An empty or non-scalar value is skipped rather than returned, so a record with
 * a null uuid falls through to the next name instead of being labelled "".
 *
 * @category Service
 * @package  OCA\Integriq\Service
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
 * @spec openspec/changes/flow-native-synchronization/design.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * Reads the identity of an entity a migration generator was handed.
 *
 * @spec openspec/changes/flow-native-synchronization/design.md
 */
final class MigrationSubject {

	/**
	 * The record keys that can name an entity, most durable first.
	 *
	 * @var array<int, string>
	 */
	private const NAME_KEYS = ['uuid', 'slug', 'reference', 'id'];

	/**
	 * Every identifier this record carries, in fallback order.
	 *
	 * @param array $entity The entity's serialised record.
	 *
	 * @return array<int, string> The non-empty identifiers.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function identifiersOf(array $entity): array {
		$identifiers = [];
		foreach (self::NAME_KEYS as $key) {
			$value = ($entity[$key] ?? null);
			if (is_scalar($value) === false) {
				continue;
			}

			$candidate = trim((string)$value);
			if ($candidate !== '') {
				$identifiers[] = $candidate;
			}
		}

		return $identifiers;
	}//end identifiersOf()

	/**
	 * The reference a generated flow records this entity by.
	 *
	 * @param array $entity The entity's serialised record.
	 *
	 * @return string The uuid, slug or reference; empty when there is none.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function referenceOf(array $entity): string {
		return ($this->identifiersOf(entity: $entity)[0] ?? '');
	}//end referenceOf()

	/**
	 * The entity's human label, for flow names and refusal messages.
	 *
	 * @param array $entity The entity's serialised record.
	 *
	 * @return string The name, falling back to the reference.
	 *
	 * @spec openspec/changes/flow-native-synchronization/design.md
	 */
	public function labelOf(array $entity): string {
		$name = trim((string)($entity['name'] ?? ''));
		if ($name !== '') {
			return $name;
		}

		return $this->referenceOf(entity: $entity);
	}//end labelOf()
}//end class
