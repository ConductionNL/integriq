<?php

/**
 * Integriq Tables row write run.
 *
 * The state one TablesSyncAdapter::writeRow() call shares while it resolves
 * a column mapping: the target table's columns, the mapped object the values
 * are read from, and the table id the skip logs name. Never leaves
 * TablesSyncAdapter.
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
 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Tables;

/**
 * One row write being resolved.
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
 */
final class RowWriteRun {

	/**
	 * Constructor.
	 *
	 * @param array $columns      The cached column list of the table.
	 * @param array $mappedObject The already-mapped object to read the values from.
	 * @param int   $tableId      The Tables table id (logging context).
	 */
	public function __construct(
		public readonly array $columns,
		public readonly array $mappedObject,
		public readonly int $tableId,
	) {

	}//end __construct()
}//end class
