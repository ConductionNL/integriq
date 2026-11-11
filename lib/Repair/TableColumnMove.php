<?php

/**
 * Integriq TableColumnMove: one shard table the Dutch-column step moves.
 *
 * @category Repair
 * @package  OCA\Integriq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Integriq\Repair;

/**
 * One shard table of {@see RenameDutchColumns}: its name, quoted name, the
 * columns it has and the column names its schema declares. Never leaves
 * that class.
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
 *  migration. Pointing this at an existing spec would report conformance to a
 *  requirement that says nothing about it.
 */
final class TableColumnMove {
	/**
	 * The shard table.
	 *
	 * @var string
	 */
	public string $table = '';

	/**
	 * The shard table, quoted for SQL.
	 *
	 * @var string
	 */
	public string $quotedTable = '';

	/**
	 * Column names present in the table.
	 *
	 * @var array<int, string>
	 */
	public array $columns = [];

	/**
	 * The column names the table's schema declares.
	 *
	 * @var array<int, string>
	 */
	public array $declared = [];

	/**
	 * Whether the table has the column.
	 *
	 * @param string $column The column name.
	 *
	 * @return bool
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function has(string $column): bool {
		return in_array($column, $this->columns, true);

	}//end has()
}//end class
