<?php

/**
 * Refuses a column mapping that cannot work, at save and before a run.
 *
 * @category Service
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
 */

declare(strict_types=1);

namespace OCA\Integriq\Migration;

/**
 * A mapping onto a field the schema does not have is refused when it is
 * saved, naming the field. A required field left unmapped stops the run before
 * a row is read, which is the difference between a refusal and a half-finished
 * import.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */
class ColumnMappingValidator {
	/**
	 * Check a mapping's target fields against the schema it writes into.
	 *
	 * @param ColumnMapping $mapping The mapping.
	 * @param array<int,string> $schemaFields Every field the target schema has.
	 *
	 * @return array<int,string> The refusals, empty when the mapping is valid.
	 */
	public function validateTargets(ColumnMapping $mapping, array $schemaFields): array {
		$refusals = [];
		foreach ($mapping->targetFields() as $target) {
			if (in_array($target, $schemaFields, true) === false) {
				$refusals[] = sprintf('The target schema has no field named "%s".', $target);
			}
		}

		return $refusals;
	}//end validateTargets()

	/**
	 * Check that every required target field is mapped.
	 *
	 * @param ColumnMapping $mapping The mapping.
	 * @param array<int,string> $requiredFields The schema's required fields.
	 *
	 * @return array<int,string> The refusals, empty when nothing required is missing.
	 */
	public function validateRequired(ColumnMapping $mapping, array $requiredFields): array {
		$mapped = $mapping->targetFields();
		$refusals = [];
		foreach ($requiredFields as $required) {
			if (in_array($required, $mapped, true) === false) {
				$refusals[] = sprintf('The required field "%s" is not mapped to any column.', $required);
			}
		}

		return $refusals;
	}//end validateRequired()

	/**
	 * Check that every mapped column is actually in the delivered file.
	 *
	 * @param ColumnMapping $mapping The mapping.
	 * @param array<int,string> $headers The delivered file's column headers.
	 *
	 * @return array<int,string> The refusals.
	 */
	public function validateColumns(ColumnMapping $mapping, array $headers): array {
		$refusals = [];
		foreach (array_keys($mapping->getColumns()) as $column) {
			if (in_array((string)$column, $headers, true) === false) {
				$refusals[] = sprintf('The delivered file has no column named "%s".', $column);
			}
		}

		return $refusals;
	}//end validateColumns()
}//end class
