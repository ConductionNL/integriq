<?php

/**
 * Seeded registry of named-incumbent column-mapping presets.
 *
 * @category Registry
 * @package  OCA\Integriq\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.integriq.nl
 *
 * @spec openspec/specs/migration-mapping-presets/spec.md#requirement-a-registry-of-named-incumbent-column-mapping-presets-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Migration;

/**
 * A preset is configuration for the existing `file` migration source,
 * not a second reading engine — see
 * `openspec/changes/integriq-adapter-rostering-imports/design.md`
 * "Trade-offs". Presets are loaded once from
 * `lib/migration-mapping-presets.seed.json` and are immutable at
 * runtime; an operator who needs a different mapping authors one
 * through `POST /api/migration-sources/column-mapping/validate`
 * instead of editing a preset.
 *
 * @spec openspec/specs/migration-mapping-presets/spec.md#requirement-a-registry-of-named-incumbent-column-mapping-presets-req-001
 */
final class MigrationMappingPresetRegistry {
	/**
	 * Path to the seed file, relative to this class.
	 */
	private const SEED_PATH = __DIR__ . '/../migration-mapping-presets.seed.json';

	/**
	 * Presets keyed by id, each `{id, sourceSystem, description, mapping: ColumnMapping}`.
	 *
	 * @var array<string,array{id:string,sourceSystem:string,description:string,mapping:ColumnMapping}>
	 */
	private array $presets = [];

	/**
	 * Constructor. Loads the seed file eagerly — it is small, static,
	 * and shipped with the app, so there is no lazy-load benefit.
	 *
	 * @param string|null $seedPath Override for the seed file path (tests only).
	 */
	public function __construct(?string $seedPath = null) {
		$path = ($seedPath ?? self::SEED_PATH);
		$decoded = json_decode((string)file_get_contents($path), true);

		$rows = [];
		if (is_array($decoded) === true && is_array($decoded['presets'] ?? null) === true) {
			$rows = $decoded['presets'];
		}

		foreach ($rows as $row) {
			if (is_array($row) === false || is_array($row['mapping'] ?? null) === false) {
				continue;
			}

			$id = (string)($row['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$this->presets[$id] = [
				'id' => $id,
				'sourceSystem' => (string)($row['sourceSystem'] ?? ''),
				'description' => (string)($row['description'] ?? ''),
				'mapping' => ColumnMapping::fromArray(stored: $row['mapping']),
			];
		}
	}//end __construct()

	/**
	 * Every seeded preset, described for an API listing.
	 *
	 * @return array<int,array{id:string,sourceSystem:string,description:string,mapping:array<string,mixed>}>
	 *
	 * @spec openspec/specs/migration-mapping-presets/spec.md#requirement-a-registry-of-named-incumbent-column-mapping-presets-req-001
	 */
	public function describeAll(): array {
		return array_values(
			array_map(
				static fn (array $preset): array => [
					'id' => $preset['id'],
					'sourceSystem' => $preset['sourceSystem'],
					'description' => $preset['description'],
					'mapping' => $preset['mapping']->toArray(),
				],
				$this->presets
			)
		);
	}//end describeAll()

	/**
	 * The `ColumnMapping` seeded under one preset id.
	 *
	 * @param string $presetId The preset id.
	 *
	 * @return ColumnMapping The mapping.
	 *
	 * @throws UnknownMigrationMappingPresetException When nothing is seeded under the id.
	 *
	 * @spec openspec/specs/migration-mapping-presets/spec.md#requirement-a-registry-of-named-incumbent-column-mapping-presets-req-001
	 */
	public function get(string $presetId): ColumnMapping {
		if (isset($this->presets[$presetId]) === false) {
			throw new UnknownMigrationMappingPresetException(presetId: $presetId, known: array_keys($this->presets));
		}

		return $this->presets[$presetId]['mapping'];
	}//end get()

	/**
	 * Every seeded preset id.
	 *
	 * @return array<int,string> Preset ids.
	 *
	 * @spec openspec/specs/migration-mapping-presets/spec.md#requirement-a-registry-of-named-incumbent-column-mapping-presets-req-001
	 */
	public function ids(): array {
		return array_keys($this->presets);
	}//end ids()
}//end class
