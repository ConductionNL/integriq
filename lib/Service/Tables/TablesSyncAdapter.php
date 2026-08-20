<?php

/**
 * OpenConnector Tables Synchronization Adapter.
 *
 * Sits between `SynchronizationService`'s `nextcloud-table` dispatch
 * branches and {@see TablesClientInterface}: resolves title-keyed column
 * mappings to numeric column ids, coerces mapped values per the target
 * column's declared type/subtype, runs the source-side pagination loop, and
 * feature-detects the Tables app via `IAppManager` only (design.md
 * Decision 3 — never a direct `OCA\Tables\*` reference).
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
 * @spec openspec/specs/tables-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenConnector\Service\Tables;

use Adbar\Dot;
use OCA\OpenConnector\Exception\TablesConfigException;
use OCA\OpenConnector\Exception\TablesFeatureDisabledException;
use OCA\OpenConnector\Exception\TablesNotFoundException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Column-cache, mapping-resolution, coercion, pagination, and feature-detection
 * layer between the synchronization engine and the raw Tables API client.
 *
 * @spec openspec/specs/tables-bridge/spec.md
 */
class TablesSyncAdapter {

	/**
	 * Tables app id, as registered in `appinfo/info.xml` of nextcloud/tables.
	 *
	 * @var string
	 */
	private const TABLES_APP_ID = 'tables';

	/**
	 * Safety cap on the number of pages fetched per source run — mirrors the
	 * `DEFAULT_MAX_PAGES`-style guard already used elsewhere in the
	 * synchronization engine, so a misbehaving/non-paginating upstream can
	 * never loop forever.
	 *
	 * @var int
	 */
	private const MAX_PAGES = 500;

	/**
	 * In-memory column cache for the lifetime of this adapter instance (one
	 * synchronization run — design.md Decision 5). Keyed by
	 * `"{sourceIdentifier}:{tableId}"`.
	 *
	 * @var array<string, array<int, array{id: int, title: string, type: string,
	 *            subtype: ?string, mandatory: bool, constraints: array<string, mixed>}>>
	 */
	private array $columnCache = [];

	/**
	 * Constructor.
	 *
	 * @param TablesClientInterface $client The raw Tables API client.
	 * @param IAppManager $appManager Feature-detection (never a direct OCA\Tables\* reference).
	 * @param LoggerInterface $logger Logger for per-row failures and diagnostics.
	 * @param TablesColumnCoercer $coercer Column-type-driven value coercion.
	 */
	public function __construct(
		private readonly TablesClientInterface $client,
		private readonly IAppManager $appManager,
		private readonly LoggerInterface $logger,
		private readonly TablesColumnCoercer $coercer,
	) {
	}//end __construct()

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
	public function isEnabled(?IUser $user = null): bool {
		if ($user !== null) {
			return $this->appManager->isEnabledForUser(self::TABLES_APP_ID, $user);
		}

		return $this->appManager->isEnabledForUser(self::TABLES_APP_ID);
	}//end isEnabled()

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
	public function assertEnabled(?IUser $user = null): void {
		if ($this->isEnabled(user: $user) === false) {
			throw new TablesFeatureDisabledException(
				message: 'The Tables app is not enabled — nextcloud-table synchronizations require it.'
			);
		}

	}//end assertEnabled()

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
	public function fetchAllRows(ObjectEntity $source, int $tableId, ?int $viewId = null, int $pageSize = 100): array {
		$rows = [];
		$page = 1;
		$firstRowOfFirst = null;

		while ($page <= self::MAX_PAGES) {
			$batch = $this->client->listRows(source: $source, tableId: $tableId, viewId: $viewId, page: $page, pageSize: $pageSize);
			if (count($batch) === 0) {
				break;
			}

			$firstRowId = ($batch[0]['id'] ?? null);
			if ($page === 1) {
				$firstRowOfFirst = $firstRowId;
			} elseif ($firstRowId === $firstRowOfFirst) {
				// The upstream returned the same first row again — it does not
				// honour pagination. Stop here rather than looping forever.
				$this->logger->warning(
					'TablesSyncAdapter: rows endpoint did not appear to honour pagination; stopping',
					['tableId' => $tableId, 'page' => $page]
				);
				break;
			}

			foreach ($batch as $row) {
				// `id` and `data` are guaranteed by TablesClientInterface's
				// return shape (the client normalises every row).
				$data = $row['data'];

				// Union (not array_merge()): the row `data` is keyed by
				// numeric-string columnId ('7', '8', ...); PHP casts numeric
				// string keys to int, and array_merge() RE-INDEXES integer
				// keys as a list, which would silently drop the columnId
				// association. The `+` union operator preserves every key.
				$flattened = ['id' => (string)$row['id']] + $data;
				$rows[] = $flattened;
			}

			if (count($batch) < $pageSize) {
				break;
			}

			$page++;
		}//end while

		return $rows;
	}//end fetchAllRows()

	/**
	 * Create or update a row from a mapped object, resolving the title-keyed
	 * column mapping to numeric column ids and coercing each value per the
	 * target column's declared type (design.md Decisions 4 and 6).
	 *
	 * A per-row failure (unresolved/ambiguous title, coercion mismatch) is
	 * logged and signalled by a `null` return — the caller MUST treat this as
	 * "skip this row, keep the run going" (REQ-001/REQ-003). A 401/403 from
	 * the Tables API is NOT caught here — it propagates as
	 * {@see \OCA\OpenConnector\Exception\TablesPermissionDeniedException} so
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
	): ?array {
		$columns = $this->getColumns(source: $target, tableId: $tableId);

		$payload = [];
		foreach ($columnMapping as $mapEntry) {
			$columnTitle = ($mapEntry['column'] ?? null);
			if (is_string($columnTitle) === false || $columnTitle === '') {
				continue;
			}

			$resolved = $this->resolveMappingEntry(
				mapEntry: $mapEntry,
				columnTitle: $columnTitle,
				columns: $columns,
				mappedObject: $mappedObject,
				tableId: $tableId
			);

			if ($resolved === null) {
				// A per-row skip (ambiguous/unresolved title, coercion
				// failure) was already logged by resolveMappingEntry().
				return null;
			}

			$payload[$resolved['columnId']] = $resolved['value'];
		}//end foreach

		if ($existingRowId === null || $existingRowId === '') {
			$row = $this->client->createRow(source: $target, tableId: $tableId, data: $payload);
			return ['id' => (string)$row['id']];
		}

		$row = $this->client->updateRow(source: $target, rowId: (int)$existingRowId, data: $payload);

		return ['id' => (string)$row['id']];
	}//end writeRow()

	/**
	 * Resolve one `columnMapping` entry to a `{columnId: value}` payload pair:
	 * looks up the mapped column title (failing on zero or >1 matches — never
	 * a first-match guess), extracts the mapped-object value via its dot-path,
	 * and coerces it per the column's declared type.
	 *
	 * @param array $mapEntry The `{"column": "<title>", "value": "<dot-path>"}` entry.
	 * @param string $columnTitle The entry's `column` value (pre-validated non-empty string).
	 * @param array $columns The cached column list for this table.
	 * @param array $mappedObject The already-mapped object to read the value from.
	 * @param int $tableId The Tables table id (logging context only).
	 *
	 * @return array{columnId: string, value: mixed}|null The resolved pair, or null on any
	 *                                                    per-row-skip condition (already logged).
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-column-type-coercion-req-003
	 */
	private function resolveMappingEntry(array $mapEntry, string $columnTitle, array $columns, array $mappedObject, int $tableId): ?array {
		$matches = array_values(
			array_filter($columns, static fn (array $column) => $column['title'] === $columnTitle)
		);

		if (count($matches) === 0) {
			$this->logger->warning(
				'TablesSyncAdapter: column mapping title does not resolve to any column — skipping row write',
				['column' => $columnTitle, 'tableId' => $tableId]
			);
			return null;
		}

		if (count($matches) > 1) {
			$this->logger->error(
				'TablesSyncAdapter: ambiguous column title in mapping — refusing to guess, skipping row write',
				['column' => $columnTitle, 'matchCount' => count($matches), 'tableId' => $tableId]
			);
			return null;
		}

		$column = $matches[0];
		$valuePath = ($mapEntry['value'] ?? null);
		$rawValue = null;
		if (is_string($valuePath) === true && $valuePath !== '') {
			$rawValue = (new Dot($mappedObject))->get($valuePath);
		}

		try {
			$coerced = $this->coercer->coerce(column: $column, value: $rawValue);
		} catch (TablesConfigException $exception) {
			$this->logger->warning(
				'TablesSyncAdapter: value coercion failed for column — skipping row write',
				[
					'column' => $columnTitle,
					'tableId' => $tableId,
					'reason' => $exception->getMessage(),
				]
			);
			return null;
		}

		return ['columnId' => (string)$column['id'], 'value' => $coerced];
	}//end resolveMappingEntry()

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
	public function deleteRow(ObjectEntity $target, string $rowId): bool {
		try {
			$this->client->deleteRow(source: $target, rowId: (int)$rowId);
			return true;
		} catch (TablesNotFoundException $exception) {
			$this->logger->info(
				'TablesSyncAdapter: row already absent upstream during delete — tolerating',
				['rowId' => $rowId]
			);
			return false;
		}

	}//end deleteRow()

	/**
	 * List the tables accessible to a Source's identity, for the editor's table picker.
	 *
	 * @param ObjectEntity $source The `Source` whose credentials are used.
	 *
	 * @return array<int, array{id: int, title: string, ownerType: string}>
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007
	 */
	public function listTablesForEditor(ObjectEntity $source): array {
		return $this->client->listTables(source: $source);
	}//end listTablesForEditor()

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
	public function listColumnsForEditor(ObjectEntity $source, int $tableId): array {
		return $this->getColumns(source: $source, tableId: $tableId);
	}//end listColumnsForEditor()

	/**
	 * Fetch (and cache for this adapter instance's lifetime) a table's columns.
	 *
	 * @param ObjectEntity $source The `Source` whose credentials are used.
	 * @param int $tableId The Tables table id.
	 *
	 * @return array<int, array{id: int, title: string, type: string, subtype: ?string,
	 *               mandatory: bool, constraints: array<string, mixed>}>
	 *
	 * @spec openspec/specs/tables-bridge/spec.md#requirement-column-type-coercion-req-003
	 */
	private function getColumns(ObjectEntity $source, int $tableId): array {
		$identifier = $source->getUuid();
		if ($identifier === null || $identifier === '') {
			$identifier = (string)$source->getId();
		}

		$cacheKey = $identifier . ':' . $tableId;
		if (isset($this->columnCache[$cacheKey]) === false) {
			$this->columnCache[$cacheKey] = $this->client->listColumns(source: $source, tableId: $tableId);
		}

		return $this->columnCache[$cacheKey];
	}//end getColumns()
}//end class
