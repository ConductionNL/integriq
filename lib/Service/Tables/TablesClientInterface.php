<?php

/**
 * OpenConnector Tables Client Interface.
 *
 * Narrow domain seam through which every Nextcloud Tables table/column/row
 * read and write occurs. Deliberately API-version-agnostic in its method
 * signatures — the concrete {@see TablesOcsClient} targets the
 * `index.php/apps/tables/api/1/*` REST surface (design.md Decision 2), but a
 * future `TablesOcsV2Client` could be swapped in via DI without touching
 * `TablesSyncAdapter` or `SynchronizationService` once the Tables OCS v2 API
 * gains authenticated single-row CRUD.
 *
 * @category Service
 * @package  OCA\OpenConnector\Service\Tables
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenConnector.nl
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Tables;

use OCA\OpenConnector\Exception\TablesNotFoundException;
use OCA\OpenConnector\Exception\TablesPermissionDeniedException;
use OCA\OpenConnector\Exception\TablesUpstreamException;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * A Tables API binding: list/read tables, columns and rows; write rows.
 *
 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
 */
interface TablesClientInterface
{
    /**
     * List the tables accessible to the Source's configured identity.
     *
     * @param ObjectEntity $source The `Source` (register `openconnector`, schema
     *                             `source`) whose `location`/`authentication`
     *                             reach the target Nextcloud instance.
     *
     * @return array<int, array{id: int, title: string, ownerType: string}>
     *
     * @throws TablesUpstreamException On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
     */
    public function listTables(ObjectEntity $source): array;

    /**
     * List a table's columns (id, title, type, subtype, mandatory, constraints).
     *
     * @param ObjectEntity $source  The `Source` whose credentials are used.
     * @param int          $tableId The Tables table id.
     *
     * @return array<int, array{id: int, title: string, type: string, subtype: ?string,
     *                          mandatory: bool, constraints: array<string, mixed>}>
     *
     * @throws TablesNotFoundException          When the table does not exist upstream.
     * @throws TablesPermissionDeniedException  When the identity cannot read the table's columns.
     * @throws TablesUpstreamException          On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
     */
    public function listColumns(ObjectEntity $source, int $tableId): array;

    /**
     * Read one page of rows from a table (optionally scoped to a view).
     *
     * @param ObjectEntity $source   The `Source` whose credentials are used.
     * @param int          $tableId  The Tables table id.
     * @param int|null     $viewId   Optional Tables view id to scope the read to.
     * @param int          $page     1-based page number.
     * @param int          $pageSize Maximum rows to return for this page.
     *
     * @return array<int, array{id: int, tableId: int, data: array<string, mixed>}>
     *         Each row's `data` is keyed by numeric column id (string keys, per the
     *         Tables `Row` schema).
     *
     * @throws TablesNotFoundException          When the table does not exist upstream.
     * @throws TablesPermissionDeniedException  When the identity cannot read the table's rows.
     * @throws TablesUpstreamException          On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
     */
    public function listRows(ObjectEntity $source, int $tableId, ?int $viewId, int $page, int $pageSize): array;

    /**
     * Read a single row by id.
     *
     * @param ObjectEntity $source The `Source` whose credentials are used.
     * @param int          $rowId  The Tables row id.
     *
     * @return array{id: int, tableId: int, data: array<string, mixed>}
     *
     * @throws TablesNotFoundException          When the row does not exist upstream.
     * @throws TablesPermissionDeniedException  When the identity cannot read the row.
     * @throws TablesUpstreamException          On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-source-req-002
     */
    public function getRow(ObjectEntity $source, int $rowId): array;

    /**
     * Create a new row in a table.
     *
     * @param ObjectEntity $source  The `Source` whose credentials are used.
     * @param int          $tableId The Tables table id.
     * @param array        $data    `columnId => value` payload (Tables write shape).
     *
     * @return array{id: int, tableId: int, data: array<string, mixed>} The created row.
     *
     * @throws TablesNotFoundException          When the table does not exist upstream.
     * @throws TablesPermissionDeniedException  When the identity cannot write to the table.
     * @throws TablesUpstreamException          On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
     */
    public function createRow(ObjectEntity $source, int $tableId, array $data): array;

    /**
     * Update an existing row.
     *
     * @param ObjectEntity $source The `Source` whose credentials are used.
     * @param int          $rowId  The Tables row id.
     * @param array        $data   `columnId => value` payload (Tables write shape).
     *
     * @return array{id: int, tableId: int, data: array<string, mixed>} The updated row.
     *
     * @throws TablesNotFoundException          When the row does not exist upstream.
     * @throws TablesPermissionDeniedException  When the identity cannot write to the row.
     * @throws TablesUpstreamException          On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
     */
    public function updateRow(ObjectEntity $source, int $rowId, array $data): array;

    /**
     * Delete a row.
     *
     * @param ObjectEntity $source The `Source` whose credentials are used.
     * @param int          $rowId  The Tables row id.
     *
     * @return void
     *
     * @throws TablesNotFoundException          When the row does not exist upstream (tolerated by callers).
     * @throws TablesPermissionDeniedException  When the identity cannot delete the row.
     * @throws TablesUpstreamException          On network failure or a non-2xx/non-4xx response.
     *
     * @spec openspec/specs/tables-bridge/spec.md#requirement-source-deleted-rows-are-removed-only-under-the-shared-deletion-safety-guard-req-005
     */
    public function deleteRow(ObjectEntity $source, int $rowId): void;
}//end interface
