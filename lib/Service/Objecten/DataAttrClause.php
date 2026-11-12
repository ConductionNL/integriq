<?php

/**
 * One `data_attrs` clause of an Objecten API list: `field__operator__value`.
 *
 * A clause that does not split into exactly three parts is malformed; the
 * list then matches nothing. Built by {@see ObjectEndpointHandler} per clause.
 *
 * @category Service
 * @package  OCA\Integriq\Service\Objecten
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Objecten;

/**
 * A parsed `data_attrs` clause.
 *
 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
 */
final class DataAttrClause {

	/**
	 * Whether the clause did not split into field, operator and value.
	 *
	 * @var bool
	 */
	public readonly bool $malformed;

	/**
	 * The data field the clause compares.
	 *
	 * @var string
	 */
	public readonly string $field;

	/**
	 * The operator: exact, icontains, gt, gte, lt or lte.
	 *
	 * @var string
	 */
	public readonly string $operator;

	/**
	 * The value compared against.
	 *
	 * @var string
	 */
	public readonly string $value;

	/**
	 * Constructor.
	 *
	 * @param string $clause One comma-separated clause, untrimmed.
	 */
	public function __construct(string $clause) {
		$parts = explode('__', trim($clause));
		$this->malformed = (count($parts) !== 3);
		$this->field = ($parts[0] ?? '');
		$this->operator = ($parts[1] ?? '');
		$this->value = ($parts[2] ?? '');
	}//end __construct()

	/**
	 * Whether a field value satisfies the clause.
	 *
	 * @param mixed $actual The object's value for the field, null when absent.
	 *
	 * @return bool True when it holds; an absent value never holds.
	 *
	 * @spec openspec/changes/objecten-api-facade/specs/objecten-api-facade/spec.md
	 */
	public function holds(mixed $actual): bool {
		if ($actual === null) {
			return false;
		}

		$actual = (string)$actual;

		return match ($this->operator) {
			'exact' => ($actual === $this->value),
			'icontains' => (stripos($actual, $this->value) !== false),
			'gt' => ($actual > $this->value),
			'gte' => ($actual >= $this->value),
			'lt' => ($actual < $this->value),
			'lte' => ($actual <= $this->value),
			// An operator nobody declared matches nothing, for the same reason
			// a malformed clause does.
			default => false,
		};
	}//end holds()
}//end class
