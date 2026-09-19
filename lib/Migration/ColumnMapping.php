<?php

/**
 * A stored, versioned mapping of a delivered file's columns onto target fields.
 *
 * @category ValueObject
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

use InvalidArgumentException;

/**
 * Authored once and reused across deliveries, so a second file of the same
 * shape is a selection rather than a morning of mapping columns again.
 *
 * @spec openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002
 */
final class ColumnMapping {
	/**
	 * Constructor.
	 *
	 * @param string $name The mapping's name, as an administrator selects it.
	 * @param string $kind The record kind it produces.
	 * @param array<string,string> $columns Column name to target field name.
	 * @param string $identifierColumn The column carrying the record's foreign identifier.
	 * @param int $version The mapping's version.
	 */
	public function __construct(
		private readonly string $name,
		private readonly string $kind,
		private readonly array $columns,
		private readonly string $identifierColumn = '',
		private readonly int $version = 1,
	) {
	}//end __construct()

	/**
	 * Read a mapping from its stored shape.
	 *
	 * @param array<string,mixed> $stored The stored mapping object.
	 *
	 * @return self The mapping.
	 *
	 * @throws InvalidArgumentException When the stored shape carries no columns.
	 */
	public static function fromArray(array $stored): self {
		$columns = ($stored['columns'] ?? []);
		if (is_array($columns) === false || $columns === []) {
			throw new InvalidArgumentException('A column mapping with no columns cannot read a file.');
		}

		return new self(
			(string)($stored['name'] ?? ''),
			(string)($stored['kind'] ?? ''),
			array_map(static fn ($target): string => (string)$target, $columns),
			(string)($stored['identifierColumn'] ?? ''),
			(int)($stored['version'] ?? 1)
		);
	}//end fromArray()

	/**
	 * The mapping's name.
	 *
	 * @return string Name.
	 */
	public function getName(): string {
		return $this->name;
	}//end getName()

	/**
	 * The record kind this mapping produces.
	 *
	 * @return string Record kind.
	 */
	public function getKind(): string {
		return $this->kind;
	}//end getKind()

	/**
	 * The mapping's version.
	 *
	 * @return int Version.
	 */
	public function getVersion(): int {
		return $this->version;
	}//end getVersion()

	/**
	 * Column name to target field name.
	 *
	 * @return array<string,string> The mapping.
	 */
	public function getColumns(): array {
		return $this->columns;
	}//end getColumns()

	/**
	 * The target fields this mapping fills.
	 *
	 * @return array<int,string> Target field names.
	 */
	public function targetFields(): array {
		return array_values(array_unique(array_values($this->columns)));
	}//end targetFields()

	/**
	 * The column carrying the foreign identifier.
	 *
	 * @return string Column name, empty when the file carries no stable key.
	 */
	public function getIdentifierColumn(): string {
		return $this->identifierColumn;
	}//end getIdentifierColumn()

	/**
	 * Turn one row of a delivered file into target fields.
	 *
	 * @param array<string,mixed> $row The row, keyed by column name.
	 *
	 * @return array<string,mixed> The target fields.
	 */
	public function apply(array $row): array {
		$mapped = [];
		foreach ($this->columns as $column => $target) {
			if (array_key_exists($column, $row) === false) {
				continue;
			}

			$mapped[$target] = $row[$column];
		}

		return $mapped;
	}//end apply()

	/**
	 * The mapping as it is stored.
	 *
	 * @return array<string,mixed> Serialisable mapping.
	 */
	public function toArray(): array {
		return [
			'name' => $this->name,
			'kind' => $this->kind,
			'columns' => $this->columns,
			'identifierColumn' => $this->identifierColumn,
			'version' => $this->version,
		];
	}//end toArray()
}//end class
