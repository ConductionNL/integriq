<?php

/**
 * Integriq mapping cast.
 *
 * One configured cast, split into its name and its optional argument.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\Integriq\Service;

/**
 * A cast name (`unsetIfValue`, `countValue`, ...) and its argument, if any.
 *
 * @spec openspec/specs/mapping-and-search/spec.md
 */
final class MappingCast {

	/**
	 * Constructor.
	 *
	 * @param string      $name     The cast name.
	 * @param string|null $argument The cast argument, or null when it has none.
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?string $argument,
	) {

	}//end __construct()
}//end class
