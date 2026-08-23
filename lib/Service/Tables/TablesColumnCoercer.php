<?php

/**
 * Integriq Tables Column-Type Coercer.
 *
 * Coerces a mapped value against a target Tables column's declared
 * `type`/`subtype` (design.md Decision 6). Extracted from
 * {@see TablesSyncAdapter} so the coercion rules live in one focused,
 * independently-testable collaborator rather than swelling the adapter.
 * Every mismatch throws {@see TablesConfigException} — the adapter treats
 * that as a per-row skip, never a silent truncation/guess.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Tables
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
 * @spec openspec/specs/tables-bridge/spec.md#requirement-column-type-coercion-req-003
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Tables;

use DateTime;
use OCA\Integriq\Exception\TablesConfigException;

/**
 * Column-type-driven value coercion for Tables row writes.
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-column-type-coercion-req-003
 */
class TablesColumnCoercer {
	/**
	 * Coerce a mapped value against a target column's declared type/subtype.
	 *
	 * @param array $column The normalised column (id, title, type, subtype, constraints).
	 * @param mixed $value The raw mapped value.
	 *
	 * @return mixed The coerced value, ready to place under the column's numeric id.
	 *
	 * @throws TablesConfigException When the value cannot be safely coerced.
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-column-type-coercion-req-003
	 */
	public function coerce(array $column, mixed $value): mixed {
		return match ($column['type'] ?? '') {
			'text' => $this->coerceText(column: $column, value: $value),
			'number' => $this->coerceNumber(column: $column, value: $value),
			'datetime' => $this->coerceDatetime(column: $column, value: $value),
			'selection' => $this->coerceSelection(column: $column, value: $value),
			'usergroup' => $this->rejectUsergroup(column: $column),
			default => $this->rejectUnsupportedType(column: $column),
		};

	}//end coerce()

	/**
	 * Coerce a value for a `text` column: cast to string; a value exceeding
	 * `textMaxLength` fails rather than being silently truncated.
	 *
	 * @param array $column The normalised column.
	 * @param mixed $value The raw mapped value.
	 *
	 * @return string
	 *
	 * @throws TablesConfigException When the value exceeds `textMaxLength`.
	 */
	private function coerceText(array $column, mixed $value): string {
		$stringValue = (string)$value;
		$maxLength = ($column['constraints']['textMaxLength'] ?? null);
		if ($maxLength !== null && strlen($stringValue) > (int)$maxLength) {
			throw new TablesConfigException(
				message: "Value exceeds textMaxLength ({$maxLength}) for column '{$column['title']}'; refusing to truncate"
			);
		}

		return $stringValue;
	}//end coerceText()

	/**
	 * Coerce a value for a `number` column: numeric cast, rounded per
	 * `numberDecimals`; a non-numeric mapped value fails.
	 *
	 * @param array $column The normalised column.
	 * @param mixed $value The raw mapped value.
	 *
	 * @return float
	 *
	 * @throws TablesConfigException When the value is not numeric.
	 */
	private function coerceNumber(array $column, mixed $value): float {
		if (is_numeric($value) === false) {
			$describedValue = gettype($value);
			if (is_scalar($value) === true) {
				$describedValue = (string)$value;
			}

			throw new TablesConfigException(
				message: "Value '{$describedValue}' is not numeric for column '{$column['title']}'"
			);
		}

		$decimals = ($column['constraints']['numberDecimals'] ?? null);
		$number = (float)$value;
		if ($decimals !== null) {
			$number = round($number, (int)$decimals);
		}

		return $number;
	}//end coerceNumber()

	/**
	 * Coerce a value for a `datetime` column: ISO-8601 normalisation
	 * respecting the `date`/`time`/`datetime` subtype.
	 *
	 * @param array $column The normalised column.
	 * @param mixed $value The raw mapped value (string or unix timestamp int).
	 *
	 * @return string
	 *
	 * @throws TablesConfigException When the value is not a recognisable date.
	 */
	private function coerceDatetime(array $column, mixed $value): string {
		if (is_string($value) === false && is_int($value) === false) {
			throw new TablesConfigException(
				message: "Value for datetime column '{$column['title']}' is not a recognisable date"
			);
		}

		if (is_int($value) === true) {
			$date = (new DateTime())->setTimestamp($value);
			return $this->formatDatetime(date: $date, subtype: ($column['subtype'] ?? 'datetime'));
		}

		try {
			$date = new DateTime((string)$value);
		} catch (\Exception $exception) {
			throw new TablesConfigException(
				message: "Value '{$value}' could not be parsed as a date for column '{$column['title']}': " . $exception->getMessage()
			);
		}

		return $this->formatDatetime(date: $date, subtype: ($column['subtype'] ?? 'datetime'));
	}//end coerceDatetime()

	/**
	 * Format a DateTime per the Tables datetime subtype
	 * (`date`/`time`/`datetime`).
	 *
	 * @param DateTime $date The date to format.
	 * @param string $subtype The Tables column subtype.
	 *
	 * @return string
	 */
	private function formatDatetime(DateTime $date, string $subtype): string {
		return match ($subtype) {
			'date' => $date->format('Y-m-d'),
			'time' => $date->format('H:i:s'),
			default => $date->format(DateTime::ATOM),
		};

	}//end formatDatetime()

	/**
	 * Coerce a value for a `selection` column: matched against
	 * `selectionOptions` by label; no match fails.
	 *
	 * @param array $column The normalised column.
	 * @param mixed $value The raw mapped value.
	 *
	 * @return string
	 *
	 * @throws TablesConfigException When the value matches no option.
	 */
	private function coerceSelection(array $column, mixed $value): string {
		$options = ($column['constraints']['selectionOptions'] ?? []);
		if (is_array($options) === false) {
			$options = [];
		}

		$stringValue = (string)$value;
		foreach ($options as $option) {
			$optionLabel = $option;
			if (is_array($option) === true) {
				$optionLabel = ($option['label'] ?? null);
			}

			if ($optionLabel === $stringValue) {
				return $stringValue;
			}
		}

		$allowedOptions = implode(', ', array_map('strval', $options));
		throw new TablesConfigException(
			message: "Value '{$stringValue}' does not match any option of column "
				. "'{$column['title']}' (allowed: {$allowedOptions})"
		);

	}//end coerceSelection()

	/**
	 * `usergroup` columns are out of scope for v1 write coercion (design.md
	 * Decision 6) — no safe generic mapping from arbitrary source data to a
	 * Nextcloud user/group/team reference.
	 *
	 * @param array $column The normalised column.
	 *
	 * @return never
	 *
	 * @throws TablesConfigException Always.
	 */
	private function rejectUsergroup(array $column): never {
		throw new TablesConfigException(
			message: "Column '{$column['title']}' is of type 'usergroup', which is not supported for write coercion in this version"
		);

	}//end rejectUsergroup()

	/**
	 * Any column type this coercer does not recognise is a hard config error,
	 * never a silent pass-through.
	 *
	 * @param array $column The normalised column.
	 *
	 * @return never
	 *
	 * @throws TablesConfigException Always.
	 */
	private function rejectUnsupportedType(array $column): never {
		throw new TablesConfigException(
			message: "Column '{$column['title']}' has an unsupported type '" . ($column['type'] ?? '') . "' for write coercion"
		);

	}//end rejectUnsupportedType()
}//end class
