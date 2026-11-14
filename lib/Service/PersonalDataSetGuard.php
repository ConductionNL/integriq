<?php

/**
 * Integriq PersonalDataSetGuard.
 *
 * Refuses to import a configuration set marked as carrying personal data when
 * any of its mappings passes unmapped fields through.
 *
 * @category Service
 * @package  OCA\Integriq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/connectors-hr-staff-sync/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Service;

/**
 * A set that moves personal data must name every field it sends.
 *
 * A pass-through mapping copies the record and forwards whatever the source
 * adds next (a BSN, a salary, an IBAN), so in a set marked `personalData:
 * true` (top level, or `info.x-personalData`) every mapping must be an
 * allow-list: `passThrough` absent or false. A set without the mark is not
 * checked, so every existing set imports as before.
 *
 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-allow-listed-fields-leave-the-hr-register-req-hrs-003
 */
final class PersonalDataSetGuard {

	/**
	 * Whether a configuration document is marked as carrying personal data.
	 *
	 * @param array<string,mixed> $document The configuration document.
	 *
	 * @return bool True when `personalData` or `info.x-personalData` is true.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/tasks.md#task-3
	 */
	public static function carriesPersonalData(array $document): bool {
		return ($document['personalData'] ?? false) === true || ($document['info']['x-personalData'] ?? false) === true;
	}//end carriesPersonalData()

	/**
	 * Why the document may not be imported; empty when it may.
	 *
	 * @param array<string,mixed> $document The configuration document.
	 *
	 * @return array<int,string> One sentence per pass-through mapping, naming it.
	 *
	 * @spec openspec/changes/connectors-hr-staff-sync/specs/staff-timetable-sync/spec.md#requirement-only-allow-listed-fields-leave-the-hr-register-req-hrs-003
	 */
	public static function refusals(array $document): array {
		if (self::carriesPersonalData(document: $document) === false) {
			return [];
		}

		$refusals = [];
		$mappings = ($document['components']['mappings'] ?? []);
		if (is_array($mappings) === false) {
			return [];
		}

		foreach ($mappings as $slug => $mapping) {
			if (is_array($mapping) === true && ($mapping['passThrough'] ?? false) !== false) {
				$refusals[] = sprintf(
					'The mapping "%s" passes unmapped fields through, and this set carries personal data. '
					.'Name every field the target needs and set passThrough to false.',
					(string)$slug
				);
			}
		}

		return $refusals;
	}//end refusals()
}//end class
