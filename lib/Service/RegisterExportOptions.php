<?php

/**
 * Integriq RegisterExportOptions.
 *
 * What a register export of ConfigurationService takes along: the register's
 * endpoints and synchronizations, and on which side of a synchronization
 * (source, target or both) the register is looked for. Every option is on by
 * default.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/configuration-export-import/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * The options of one register export.
 *
 * @spec openspec/specs/configuration-export-import/spec.md
 */
final class RegisterExportOptions {

	/**
	 * Whether a synchronization whose source is the register counts.
	 *
	 * @var bool
	 */
	public bool $searchSource = true;

	/**
	 * Whether a synchronization whose target is the register counts.
	 *
	 * @var bool
	 */
	public bool $searchTarget = true;

	/**
	 * Constructor.
	 *
	 * @param bool $includeEndpoints Whether to include the register's endpoints.
	 * @param bool $includeSynchronizations Whether to include the register's synchronizations.
	 */
	public function __construct(
		public readonly bool $includeEndpoints = true,
		public readonly bool $includeSynchronizations = true,
	) {
	}//end __construct()

	/**
	 * The synchronization sides searched for the register, source before target.
	 *
	 * @return list<string> `source` and/or `target`.
	 */
	private function searchedSides(): array {
		$sides = [];
		if ($this->searchSource === true) {
			$sides[] = 'source';
		}

		if ($this->searchTarget === true) {
			$sides[] = 'target';
		}

		return $sides;
	}//end searchedSides()

	/**
	 * Whether a synchronization links to the register on a searched side: a
	 * `register/schema` source or target whose id is the register or one of
	 * its schemas.
	 *
	 * @param array<string,mixed> $data The synchronization's data.
	 * @param string $registerId The register ID.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/configuration-export-import/spec.md
	 */
	public function linksRegister(array $data, string $registerId): bool {
		foreach ($this->searchedSides() as $side) {
			if (($data[$side . 'Type'] ?? '') === 'register/schema'
				&& $this->pointsAtRegister(id: ($data[$side . 'Id'] ?? ''), registerId: $registerId) === true
			) {
				return true;
			}
		}

		return false;
	}//end linksRegister()

	/**
	 * Whether an id names the register itself, or one of its schemas.
	 *
	 * @param string $id The source or target id.
	 * @param string $registerId The register ID.
	 *
	 * @return bool
	 */
	private function pointsAtRegister(string $id, string $registerId): bool {
		return (str_starts_with($id, $registerId . '/') === true || $id === $registerId);
	}//end pointsAtRegister()
}//end class
