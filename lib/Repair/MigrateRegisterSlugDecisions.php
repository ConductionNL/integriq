<?php

/**
 * The pure decisions behind MigrateRegisterSlug.
 *
 * A collaborator rather than static helpers, because the ruleset forbids static
 * access — and an object keeps these testable on their own. They take plain
 * arrays and scalars and touch neither database nor logger, which is what makes
 * the DECISION unit-testable while the UPDATE that follows it is not.
 *
 * @category  Repair
 * @package   OCA\Integriq\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

/**
 * Pure predicates for the register-slug migration.
 *
 * @spec exclude No canonical spec covers the `openconnector` -> `integriq`
 *  register-slug migration. Pointing this at an existing spec would report
 *  conformance to a requirement that says nothing about it.
 */
class MigrateRegisterSlugDecisions {

	/**
	 * Decide which register slugs may be renamed, given what the install holds.
	 *
	 * Three outcomes, and the difference between them matters:
	 *
	 * - the OLD slug is absent — nothing to do. Either this install never had
	 *   the register, or a previous run already renamed it. Not a refusal, and
	 *   this is what makes the step idempotent.
	 * - the NEW slug is ALREADY present alongside the old one — refuse, and
	 *   rename neither. OpenRegister resolves a register by slug and caps the
	 *   lookup at one row ordered by id, so two rows sharing a slug means the
	 *   lower id silently wins every lookup. Merging two registers is a decision
	 *   about data, not a rename, and a repair step must not take it.
	 * - otherwise — rename.
	 *
	 * The `$existing` set is updated as it goes, so a rename earlier in the map
	 * is visible to the collision check of a later one; otherwise two entries
	 * targeting the same name would both look safe.
	 *
	 * @param array<string, string> $map Old slug => new slug.
	 * @param array<int, string> $existing Register slugs currently present.
	 *
	 * @return array{renames: array<string, string>, refused: array<string, string>}
	 *
	 * @spec exclude No canonical spec covers the `openconnector` -> `integriq`
	 *  register-slug migration. Pointing this at an existing spec would report
	 *  conformance to a requirement that says nothing about it.
	 */
	public function plan(array $map, array $existing): array {
		$renames = [];
		$refused = [];

		foreach ($map as $old => $new) {
			if (in_array($old, $existing, true) === false) {
				continue;
			}

			if (in_array($new, $existing, true) === true) {
				$refused[$old] = sprintf("target slug '%s' already exists", $new);
				continue;
			}

			$renames[$old] = $new;
			$existing[] = $new;
		}

		return [
			'renames' => $renames,
			'refused' => $refused,
		];
	}//end plan()

	/**
	 * Pull the slugs out of register rows.
	 *
	 * Defensive on purpose: a row with a null slug must yield an empty string
	 * rather than a TypeError inside a repair step, where an escaping exception
	 * aborts the install and the app never enables.
	 *
	 * @param array<int, array<string, mixed>> $rows Register rows.
	 *
	 * @return array<int, string> The slugs.
	 *
	 * @spec exclude No canonical spec covers the `openconnector` -> `integriq`
	 *  register-slug migration. Pointing this at an existing spec would report
	 *  conformance to a requirement that says nothing about it.
	 */
	public function slugsFrom(array $rows): array {
		return array_map(static fn (array $row): string => (string)($row['slug'] ?? ''), $rows);
	}//end slugsFrom()

	/**
	 * The slugs this step needs to read: every old one and every new one.
	 *
	 * Reading BOTH sides is what makes the collision check possible. Reading
	 * only the old ones would find the register to rename and stay blind to the
	 * row already holding its target name.
	 *
	 * @param array<string, string> $map Old slug => new slug.
	 *
	 * @return array<int, string> Distinct slugs to query for.
	 *
	 * @spec exclude No canonical spec covers the `openconnector` -> `integriq`
	 *  register-slug migration. Pointing this at an existing spec would report
	 *  conformance to a requirement that says nothing about it.
	 */
	public function slugsToRead(array $map): array {
		return array_values(array_unique(array_merge(array_keys($map), array_values($map))));
	}//end slugsToRead()

	/**
	 * Build the `?,?,?` placeholder list for an IN clause.
	 *
	 * Here rather than inline because a mismatch between the placeholder count
	 * and the bound parameters is the kind of error that only shows up at
	 * runtime, inside a repair step, on somebody else's install.
	 *
	 * @param int $count Number of bound parameters.
	 *
	 * @return string The placeholder list.
	 *
	 * @spec exclude No canonical spec covers the `openconnector` -> `integriq`
	 *  register-slug migration. Pointing this at an existing spec would report
	 *  conformance to a requirement that says nothing about it.
	 */
	public function placeholders(int $count): string {
		return implode(',', array_fill(0, max(0, $count), '?'));
	}//end placeholders()
}//end class
