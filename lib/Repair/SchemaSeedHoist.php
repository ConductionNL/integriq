<?php

/**
 * Integriq Schema Seed Hoist.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lists the register descriptor's per-schema `x-openregister-seed` blocks as
 * `components.objects`, the only place OpenRegister seeds from.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

/**
 * Moves schema seed blocks into the descriptor's listed objects.
 *
 * @spec openspec/specs/repair-and-app-boot/spec.md#requirement-register-descriptor-import-via-or-configurationservice-on-install-upgrade-req-001
 */
class SchemaSeedHoist {

	/**
	 * Move every schema's `x-openregister-seed` list into `components.objects`.
	 *
	 * OpenRegister seeds only `components.objects` (and the top-level `objects`);
	 * it drops a schema's `x-openregister-seed` key as unknown, so those seeds
	 * never reached an install. A seed without `@self.slug` is left out: the
	 * import finds a seed again by register, schema and slug, so one without a
	 * slug would be created again on every upgrade.
	 *
	 * @param array<mixed> $descriptor The merged register descriptor.
	 *
	 * @return array<mixed> The descriptor with the seeds listed as objects and the key removed.
	 *
	 * @spec openspec/specs/repair-and-app-boot/spec.md#requirement-register-descriptor-import-via-or-configurationservice-on-install-upgrade-req-001
	 */
	public function hoist(array $descriptor): array {
		$schemas = ($descriptor['components']['schemas'] ?? null);
		if (is_array($schemas) === false) {
			return $descriptor;
		}

		$objects = ($descriptor['components']['objects'] ?? []);
		$listed  = array_fill_keys(array_map(static fn (mixed $object): string => self::seedKey(seed: $object), $objects), true);
		foreach ($schemas as $schemaKey => $schema) {
			if (is_array($schema) === false || array_key_exists('x-openregister-seed', $schema) === false) {
				continue;
			}

			foreach (self::slugSeeds(seeds: $schema['x-openregister-seed']) as $seed) {
				// An object a fragment already lists wins; two seeds of one identity would overwrite each other on every import.
				if (isset($listed[self::seedKey(seed: $seed)]) === false) {
					$listed[self::seedKey(seed: $seed)] = true;
					$objects[] = $seed;
				}
			}

			unset($descriptor['components']['schemas'][$schemaKey]['x-openregister-seed']);
		}

		if ($objects !== []) {
			$descriptor['components']['objects'] = $objects;
		}

		return $descriptor;
	}//end hoist()

	/**
	 * A seed's identity as the import finds it again: register, schema and slug.
	 *
	 * @param mixed $seed The seed object.
	 *
	 * @return string
	 */
	private static function seedKey(mixed $seed): string {
		if (is_array($seed) === false) {
			return '';
		}

		$self = ($seed['@self'] ?? []);
		return (string)($self['register'] ?? '') . '/' . (string)($self['schema'] ?? '') . '/' . (string)($self['slug'] ?? '');
	}//end seedKey()

	/**
	 * The seeds of one block that carry a `@self.slug`.
	 *
	 * @param mixed $seeds The block's value.
	 *
	 * @return list<array<mixed>>
	 */
	private static function slugSeeds(mixed $seeds): array {
		if (is_array($seeds) === false) {
			return [];
		}

		$kept = [];
		foreach ($seeds as $seed) {
			if (is_array($seed) === true && (string)($seed['@self']['slug'] ?? '') !== '') {
				$kept[] = $seed;
			}
		}

		return $kept;
	}//end slugSeeds()
}//end class
