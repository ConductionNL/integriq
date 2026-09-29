<?php

/**
 * Integriq Roster Mapping Preset Registry.
 *
 * Loads `lib/roster-mapping-presets.seed.json`: one preset per rostering
 * Source row, each mapping a planninq timetable session field (planninq
 * contract v1) to the vendor field that feeds it. Same shape as
 * {@see \OCA\Integriq\Migration\MigrationMappingPresetRegistry}: small,
 * static, shipped with the app and immutable at runtime.
 *
 * @category Source
 * @package  OCA\Integriq\Sources\Roster
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
 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-one-mapping-preset-per-rostering-source-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Sources\Roster;

use InvalidArgumentException;

/**
 * Registry of rostering mapping presets, keyed by Source row id.
 *
 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-one-mapping-preset-per-rostering-source-req-001
 */
class RosterMappingPresetRegistry {
	/**
	 * Path to the seed file, relative to this class.
	 */
	private const SEED_PATH = __DIR__ . '/../../roster-mapping-presets.seed.json';

	/**
	 * Presets keyed by Source row id.
	 *
	 * @var array<string,array{id:string,sourceSystem:string,target:string,contractVersion:int,fields:array<string,array<string,mixed>>}>
	 */
	private array $presets = [];

	/**
	 * Constructor. Loads the seed file eagerly; it is small and static.
	 *
	 * @param string|null $seedPath Override for the seed file path (tests only).
	 */
	public function __construct(?string $seedPath = null) {
		$decoded = json_decode((string)file_get_contents(($seedPath ?? self::SEED_PATH)), true);

		$rows = [];
		if (is_array($decoded) === true && is_array($decoded['presets'] ?? null) === true) {
			$rows = $decoded['presets'];
		}

		foreach ($rows as $row) {
			$id = (string)($row['id'] ?? '');
			if ($id === '' || is_array($row['fields'] ?? null) === false) {
				continue;
			}

			$this->presets[$id] = [
				'id' => $id,
				'sourceSystem' => (string)($row['sourceSystem'] ?? ''),
				'target' => (string)($row['target'] ?? ''),
				'contractVersion' => (int)($row['contractVersion'] ?? 0),
				'fields' => $row['fields'],
			];
		}
	}//end __construct()

	/**
	 * Every preset, keyed by Source row id.
	 *
	 * @return array<string,array{id:string,sourceSystem:string,target:string,contractVersion:int,fields:array<string,array<string,mixed>>}>
	 *
	 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-one-mapping-preset-per-rostering-source-req-001
	 */
	public function all(): array {
		return $this->presets;
	}//end all()

	/**
	 * Whether a preset exists for a Source row id.
	 *
	 * @param string $systemId The rostering Source row id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-one-mapping-preset-per-rostering-source-req-001
	 */
	public function has(string $systemId): bool {
		return isset($this->presets[$systemId]);
	}//end has()

	/**
	 * The preset for one Source row id.
	 *
	 * @param string $systemId The rostering Source row id.
	 *
	 * @return array{id:string,sourceSystem:string,target:string,contractVersion:int,fields:array<string,array<string,mixed>>}
	 *
	 * @throws InvalidArgumentException When no preset exists for the id.
	 *
	 * @spec openspec/specs/rostering-planninq-target/spec.md#requirement-one-mapping-preset-per-rostering-source-req-001
	 */
	public function get(string $systemId): array {
		if ($this->has(systemId: $systemId) === false) {
			throw new InvalidArgumentException("No rostering mapping preset for source '{$systemId}'.");
		}

		return $this->presets[$systemId];
	}//end get()
}//end class
