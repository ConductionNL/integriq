<?php

/**
 * Integriq OCS payload.
 *
 * Reading the members of a decoded OCS API payload the way the Forms and
 * Tables clients do: a member that is not an array counts as empty.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Helper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.Integriq.nl
 *
 * @spec openspec/specs/nextcloud-tables-connector/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Helper;

/**
 * Reads decoded OCS payload members.
 *
 * @spec openspec/specs/nextcloud-tables-connector/spec.md
 */
final class OcsPayload {

	/**
	 * Coerce a value to an array, treating any non-array as empty.
	 *
	 * @param mixed $value The value to coerce.
	 *
	 * @return array The value, or an empty array.
	 *
	 * @spec openspec/specs/nextcloud-tables-connector/spec.md
	 */
	public static function asArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];

	}//end asArray()
}//end class
