# tables-bridge Specification

## Purpose
TBD - created by archiving change tables-bridge. Update Purpose after archive.
## Requirements
### Requirement: Nextcloud Table as a synchronization target (REQ-001)

The system SHALL support `targetType: nextcloud-table`. `targetId` SHALL
reference a `Source` object (register `openconnector`, schema `source`)
whose `location`/`authentication` are used to reach the target Nextcloud
instance's Tables API; `targetConfig` SHALL carry `tableId` (required,
integer) and MAY carry `viewId` and `columnMapping` (array of
`{"column": "<title>", "value": "<mapping output field path>"}`). For each
transformed object, the system SHALL create a row (`POST
.../tables/{tableId}/rows`) when no `SynchronizationContract` exists for the
object's origin id, or update the existing row
(`PUT .../rows/{rowId}`) when one does, recording the Tables row id as the
contract's `targetId` exactly like every other target type.

#### Scenario: first sync creates rows with contract originId to rowId mapping

- **GIVEN** a synchronization with `sourceType: api` and `targetType:
  nextcloud-table` pointed at an empty table, and no existing
  `SynchronizationContract`s for this synchronization
- **WHEN** the synchronization runs against 3 source objects
- **THEN** 3 rows are created in the table via the Tables API
- **AND** 3 `SynchronizationContract`s are persisted, each with `originId`
  set to the source object's id and `targetId` set to the Tables row id
  returned by the create call

#### Scenario: re-sync updates only changed rows

- **GIVEN** a synchronization that previously created rows with contracts
  recording a `targetHash` for each
- **WHEN** the synchronization runs again and only one of the source
  objects' mapped output differs from its last-synced hash
- **THEN** only that one row is written (`PUT .../rows/{rowId}`) via the
  Tables API
- **AND** the other rows receive no write call, and their contracts'
  `targetHash` is unchanged

#### Scenario: title-keyed column mapping resolves to the current column id

- **GIVEN** `targetConfig.columnMapping` contains `{"column": "Amount",
  "value": "invoice.total"}` and the table currently has a column titled
  "Amount" with id `7`
- **WHEN** a row is created or updated
- **THEN** the write payload's `data` object uses `{"7": <mapped value>}`
  (numeric column id key), resolved from the cached column list for this
  run

#### Scenario: ambiguous column title is a hard config error, never a guess

- **GIVEN** `targetConfig.columnMapping` references the title "Status" and
  the table has two columns both titled "Status"
- **WHEN** a row write is attempted
- **THEN** the system SHALL fail that row's write with a config-error log
  entry naming the ambiguous title and the match count
- **AND** SHALL NOT guess by picking the first match

### Requirement: Nextcloud Table as a synchronization source (REQ-002)

The system SHALL support `sourceType: nextcloud-table`. `sourceId` SHALL
reference a `Source` object; `sourceConfig` SHALL carry `tableId` (required)
and MAY carry `viewId`. The system SHALL read rows page-by-page
(`GET .../tables/{tableId}/rows`, paginated) and feed each row's `data`
(columnId-keyed) into the existing mapping/transformation pipeline exactly
as any other source's fetched objects. The Tables row id (`Row.id`) SHALL be
used as the origin id via the existing `idPosition` default (`id`), with no
adapter-specific override required. Change detection SHALL use the existing
order-independent `hashObject()` primitive against each row's `data`.

#### Scenario: rows are read and mapped as sync input

- **GIVEN** a synchronization with `sourceType: nextcloud-table` pointed at
  a table with 50 rows and `sourceConfig.tableId` set
- **WHEN** the synchronization runs
- **THEN** all 50 rows are fetched (paginated as needed) and each row's
  `data` is passed through `MappingService`, exactly as an `api`-sourced
  object would be

#### Scenario: unchanged row content produces no downstream write

- **GIVEN** a previously-synced row whose content is unchanged since the
  last run
- **WHEN** the synchronization runs again
- **THEN** `hashObject()` on the row's `data` matches the contract's
  `sourceHash`, and no downstream target write occurs for that row

### Requirement: Column-type coercion (REQ-003)

Before writing a mapped value to a table column, the system MUST coerce it
according to the target column's declared `type`/`subtype`, fetched from
the Tables API's column metadata for the run (cached once per run, not
per-row): `text` (string cast; a value exceeding `textMaxLength` fails that
row's write rather than being silently truncated), `number` (numeric cast
per `numberDecimals`; a non-numeric mapped value fails that row's write),
`datetime` (ISO-8601 normalisation respecting the `date`/`time`/`datetime`
subtype), and `selection` (matched against the column's `selectionOptions`
by label; no match fails that row's write). A single row's coercion failure
MUST be logged and skipped without aborting the rest of the run.

#### Scenario: number column coercion

- **GIVEN** a target column of `type: number, numberDecimals: 2` and a
  mapped value of the string `"19.999"`
- **WHEN** the row is written
- **THEN** the value is coerced to the float `19.999` rounded/represented
  per `numberDecimals` before being sent to the Tables API

#### Scenario: non-numeric value fails only that row

- **GIVEN** a target column of `type: number` and a mapped value of
  `"not-a-number"`
- **WHEN** that row is written during a run that also writes other,
  well-formed rows
- **THEN** that row's write is skipped with a logged coercion-failure entry
  naming the column and the offending value
- **AND** the run continues and writes the other rows

#### Scenario: selection value with no matching option fails that row

- **GIVEN** a target column of `type: selection` with `selectionOptions:
  ["open", "paid", "overdue"]` and a mapped value of `"cancelled"`
- **WHEN** that row is written
- **THEN** the write is skipped with a logged entry naming the column, the
  offending value, and the allowed options

### Requirement: Feature detection — Tables app absence hides the type entirely (REQ-004)

The system MUST feature-detect the Tables app via
`IAppManager::isEnabledForUser('tables', ...)` only — never via a direct
reference to any `OCA\Tables\*` class and never via an OCS
capabilities round-trip. When disabled or absent: `nextcloud-table` MUST be
omitted from any editor-facing list of available source/target types, and a
synchronization already configured with `nextcloud-table` MUST fail its run
with a 409-class config error naming the missing dependency rather than
attempting any HTTP call to a Tables endpoint.

#### Scenario: Tables app absent hides the type in the editor

- **GIVEN** the Tables app is not installed on this Nextcloud instance
- **WHEN** the synchronization editor requests the list of available
  source/target types
- **THEN** `nextcloud-table` is not present in the returned list

#### Scenario: run against nextcloud-table fails cleanly when Tables is disabled

- **GIVEN** a synchronization configured with `targetType: nextcloud-table`
  AND the Tables app has since been disabled
- **WHEN** the synchronization runs
- **THEN** it fails with a config-error log entry stating the Tables app is
  not enabled
- **AND** no HTTP call is attempted against any Tables endpoint

### Requirement: Source-deleted rows are removed only under the shared deletion-safety guard (REQ-005)

The system MUST route `nextcloud-table` deletions (a contract whose origin
object is no longer present in the source) through the same
`deleteInvalidObjects()` guard path used by every other target type —
including any run-completeness check and deletion-ratio threshold specced
for that method — rather than implementing a separate or bypassing deletion
path. A `nextcloud-table` target MUST NOT delete rows whose contracts were
not confidently determined absent from the source (e.g. because the source
fetch itself failed or returned a partial page set).

#### Scenario: absent-from-source rows are deleted after a complete, successful fetch

- **GIVEN** a `nextcloud-table` target with 10 existing contracts and a
  source fetch that completes successfully and returns 9 of the 10
  previously-known origin ids
- **WHEN** `deleteInvalidObjects()` runs
- **THEN** the row corresponding to the one missing origin id is deleted via
  the Tables API
- **AND** the corresponding `SynchronizationContract` is removed

#### Scenario: a failed or partial source fetch does not trigger row deletion

- **GIVEN** a `nextcloud-table` target and a source fetch for this run that
  errors or returns a partial page set (run marked incomplete by the
  synchronization engine's fetch-failure detection)
- **WHEN** the run would otherwise call `deleteInvalidObjects()`
- **THEN** no row deletion is attempted for this target
- **AND** the run's log records that deletion was skipped due to an
  incomplete fetch

### Requirement: Permission-denied writes fail the run, not a partial subset of rows (REQ-006)

The system MUST treat a 401/403 response from the Tables API on the first
row write attempted in a run as a run-level failure (abort remaining writes
for that run) rather than a per-row skip, and MUST log a clear, actionable
message identifying the table and the identity used. Integriq MUST NOT
pre-check or re-implement Tables' authorization — the Tables API's own
response is the sole authority.

#### Scenario: permission denied on first write aborts the run with no partial writes

- **GIVEN** a `nextcloud-table` target whose configured `Source` credential
  lacks write access to the table, and a run with 5 objects to write
- **WHEN** the first row write returns 403 from the Tables API
- **THEN** the run is marked failed with a log entry naming the table and
  the identity used
- **AND** none of the remaining 4 rows are attempted
- **AND** no partial set of rows is left in an inconsistent contract state
  (no contract is created/updated for a write that was never attempted)

### Requirement: Table and column discovery for the synchronization editor (REQ-007)

The system SHALL expose read-only endpoints (see contract.md) that, given a
`Source` id, list the tables and — given a table id — the columns
(id, title, type, subtype, constraints) accessible to that Source's
configured identity, gated by the same feature-detection guard as REQ-004,
so the synchronization editor can build a table picker and a
column-mapping helper without the frontend talking to Tables directly.

#### Scenario: table list reflects the configured identity's access

- **GIVEN** a `Source` whose credential can see 2 of the 5 tables that
  otherwise exist on the target instance
- **WHEN** the editor calls the table-list endpoint with that Source's id
- **THEN** exactly the 2 accessible tables are returned

#### Scenario: column list includes type metadata for the mapping helper

- **GIVEN** a table with a `number` column and a `selection` column
- **WHEN** the editor calls the column-list endpoint for that table
- **THEN** each column's `type`/`subtype`/constraints (e.g.
  `selectionOptions`) are returned, sufficient for the mapping helper to
  render an appropriate input control

