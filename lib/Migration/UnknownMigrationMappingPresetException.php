<?php

/**
 * Raised when a caller names a migration mapping preset id nothing seeds.
 *
 * @category Exception
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

use RuntimeException;

/**
 * It fails naming the preset id, and resolves no mapping.
 *
 * @spec openspec/specs/migration-mapping-presets/spec.md#requirement-a-registry-of-named-incumbent-column-mapping-presets-req-001
 */
class UnknownMigrationMappingPresetException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $presetId The preset id nothing seeds.
	 * @param array<int,string> $known Preset ids that do exist.
	 */
	public function __construct(private readonly string $presetId, array $known = []) {
		$knownText = '(none)';
		if ($known !== []) {
			$knownText = implode(', ', $known);
		}

		parent::__construct(
			message: sprintf(
				'No migration mapping preset is seeded under the id "%s". Seeded ids: %s.',
				$presetId,
				$knownText
			)
		);
	}//end __construct()

	/**
	 * The preset id nothing seeds.
	 *
	 * @return string Preset id.
	 *
	 * @spec openspec/specs/migration-mapping-presets/spec.md#requirement-a-registry-of-named-incumbent-column-mapping-presets-req-001
	 */
	public function getPresetId(): string {
		return $this->presetId;
	}//end getPresetId()
}//end class
