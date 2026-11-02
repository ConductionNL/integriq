<?php

/**
 * Contract of the TablesSyncAdapter.
 *
 * @category Contract
 * @package  OCA\Integriq\Service\Tables
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

namespace OCA\Integriq\Service\Tables;

use OCA\Integriq\Exception\TablesFeatureDisabledException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IUser;

/**
 * Public operations of {@see TablesSyncAdapter} that other components call.
 */
interface TablesSyncAdapterInterface {
	/**
	 * Whether the Tables app is enabled for the given user (or instance-wide
	 * when no user is given — used by cron/background dispatch).
	 *
	 * @param IUser|null $user The acting user, or null for a non-interactive context.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-feature-detection--tables-app-absence-hides-the-type-entirely-req-004
	 */
	public function isEnabled(?IUser $user = null): bool;

	/**
	 * Guard every `nextcloud-table` entry point on feature detection.
	 *
	 * @param IUser|null $user The acting user, or null for a non-interactive context.
	 *
	 * @return void
	 *
	 * @throws TablesFeatureDisabledException When the Tables app is absent/disabled.
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-feature-detection--tables-app-absence-hides-the-type-entirely-req-004
	 */
	public function assertEnabled(?IUser $user = null): void;

	/**
	 * Fetch every row of a table (or a view), paginating until exhausted.
	 *
	 * Each returned row is flattened to `['id' => <row id string>, ...data]`
	 * so the existing `getOriginId()` default `idPosition` ('id') resolves
	 * the Tables row id with no adapter-specific override (REQ-002).
	 *
	 * @param ObjectEntity $source The `Source` whose credentials are used.
	 * @param int $tableId The Tables table id.
	 * @param int|null $viewId Optional Tables view id to scope the read to.
	 * @param int $pageSize Rows requested per page (default 100).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
	 */
	public function fetchAllRows(ObjectEntity $source, int $tableId, ?int $viewId = null, int $pageSize = 100): array;

	/**
	 * Create or update a row from a mapped object, resolving the title-keyed
	 * column mapping to numeric column ids and coercing each value per the
	 * target column's declared type (design.md Decisions 4 and 6).
	 *
	 * A per-row failure (unresolved/ambiguous title, coercion mismatch) is
	 * logged and signalled by a `null` return — the caller MUST treat this as
	 * "skip this row, keep the run going" (REQ-001/REQ-003). A 401/403 from
	 * the Tables API is NOT caught here — it propagates as
	 * {@see \OCA\Integriq\Exception\TablesPermissionDeniedException} so
	 * the run aborts (REQ-006).
	 *
	 * @param ObjectEntity $target The `Source` whose credentials are used.
	 * @param int $tableId The Tables table id.
	 * @param string|null $existingRowId The contract's existing `targetId`, or null to create.
	 * @param array $mappedObject The already-mapped object to write.
	 * @param array $columnMapping `[{"column": "<title>", "value": "<dot-path>"}, ...]`.
	 *
	 * @return array{id: string}|null The written row's id, or null on a per-row skip.
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
	 */
	public function writeRow(
		ObjectEntity $target,
		int $tableId,
		?string $existingRowId,
		array $mappedObject,
		array $columnMapping,
	): ?array;

	/**
	 * Delete a row, tolerating an upstream 404 (already gone) as a no-op.
	 *
	 * A 401/403 is NOT caught — it propagates so the deletion pass aborts,
	 * consistent with REQ-006's run-level failure posture for permission
	 * denials.
	 *
	 * @param ObjectEntity $target The `Source` whose credentials are used.
	 * @param string $rowId The Tables row id to delete.
	 *
	 * @return bool True when the row was deleted; false when it was already absent.
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-source-deleted-rows-are-removed-only-under-the-shared-deletion-safety-guard-req-005
	 */
	public function deleteRow(ObjectEntity $target, string $rowId): bool;

	/**
	 * List the tables accessible to a Source's identity, for the editor's table picker.
	 *
	 * @param ObjectEntity $source The `Source` whose credentials are used.
	 *
	 * @return array<int, array{id: int, title: string, ownerType: string}>
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
	 */
	public function listTablesForEditor(ObjectEntity $source): array;

	/**
	 * List a table's columns with type metadata, for the editor's mapping helper.
	 *
	 * @param ObjectEntity $source The `Source` whose credentials are used.
	 * @param int $tableId The Tables table id.
	 *
	 * @return array<int, array{id: int, title: string, type: string, subtype: ?string,
	 *               mandatory: bool, constraints: array<string, mixed>}>
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
	 */
	public function listColumnsForEditor(ObjectEntity $source, int $tableId): array;
}
