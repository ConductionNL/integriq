<?php

/**
 * Contract of the DsoActivityTable.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.Integriq.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Service\Portal;

/**
 * Public operations of {@see \OCA\Integriq\Service\Dso\DsoActivityTable} that other components call.
 */
interface ActivityMappingTableInterface {
	/**
	 * The register that holds the table.
	 *
	 * @var string
	 */
	public const REGISTER = 'integriq';

	/**
	 * The schema of one row.
	 *
	 * @var string
	 */
	public const SCHEMA = 'dso_activity_mapping';

	/**
	 * Every active row, in store order, each with its uuid under `id`.
	 *
	 * A row without `isActive` is active, as the schema's default says.
	 * A failing read is not caught: a verzoek mapped against a table that
	 * could not be read would look unmapped, and nobody would know why.
	 *
	 * @return array<int, array<string, mixed>> The active rows.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-3.1
	 */
	public function activeRows(): array;

	/**
	 * Every row, active or not, each with its uuid under `id`.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/dso-activity-mapping-table/tasks.md#task-2.2
	 */
	public function allRows(): array;
}
