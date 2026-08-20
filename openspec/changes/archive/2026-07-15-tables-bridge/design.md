# Design: tables-bridge

## Architecture Overview

Nextcloud Tables is treated as **just another HTTP-authenticated Source**
plus a **new synchronization source/target discriminator**
(`nextcloud-table`) that knows the Tables REST dialect. No new transport, no
new secret storage, no new entity type:

```
Synchronization (sourceType|targetType = nextcloud-table)
        │  sourceId/targetId → Source (OR object, register: openconnector, schema: source)
        │  sourceConfig/targetConfig → { tableId, viewId?, columnMapping? }
        ▼
SynchronizationService
        │  getAllObjectsFromSource() / updateTarget() / deleteInvalidObjects()
        │  gain a `nextcloud-table` branch, delegating to:
        ▼
TablesSyncAdapter  (new; lib/Service/Tables/TablesSyncAdapter.php)
        │  row↔contract mapping, column-type coercion, pagination loop
        ▼
TablesClientInterface  (new; lib/Service/Tables/TablesClientInterface.php)
        │  listTables(), listColumns(), listRows(), getRow(), createRow(),
        │  updateRow(), deleteRow()
        ▼
TablesOcsClient  (new; concrete impl — name retained from the brief;
        │        targets the versioned REST API, see Decision 2)
        ▼
CallService::call(Source $source, $endpoint, $method, $config)
        │  (unchanged — CallLog, rate-limit, brokered-credential dispatch)
        ▼
Nextcloud Tables app  (index.php/apps/tables/api/1/*)
```

Feature detection (`IAppManager::isEnabledForUser('tables')`) gates both
(a) whether `nextcloud-table` appears in the editor's source/target type
pickers, and (b) a server-side guard in `SynchronizationService` that
rejects the type with a clear config error if the app is disabled after a
synchronization was configured.

## Goals / Non-Goals

**Goals:**
- Sync external data into an existing Table's rows and out of a Table's
  rows, using the existing contract/hash change-detection machinery.
- Reuse `CallService` end-to-end (CallLog, rate-limit, redaction, brokered
  credentials) — zero new HTTP/secret-handling code.
- Feature-detect cleanly; the target type simply does not exist when Tables
  is absent.
- Give the editor UI enough backend surface (table list, column list with
  types) to build a picker and a column-mapping helper.

**Non-Goals:**
- Creating/altering tables or columns from a synchronization (write-only
  into pre-existing columns; see proposal Open Questions).
- Tables row-change events as triggers (`nextcloud-event-hub`).
- Re-implementing or duplicating the deletion-ratio / incomplete-run guard
  that `sync-safety-guardrails` specs — `nextcloud-table` deletions run
  through the *same* `deleteInvalidObjects()` guard path as every other
  target type, whatever shape that guard ultimately takes.
- Cross-instance federation UX polish (the `Source.location` mechanism
  already supports a remote instance's base URL; this change does not add
  discovery/pairing flows beyond "enter the base URL and a credential").

## Decisions

### Decision 1: Model Tables access as a normal `Source`, not a new entity kind

**Choice:** A `nextcloud-table` synchronization's `sourceId`/`targetId`
continues to point at an OR `source` object (register `openconnector`,
schema `source`) exactly like the existing `api` type. `sourceConfig` /
`targetConfig` gain `tableId` (integer, required) and `viewId` (integer,
optional — scope reads to a Tables *view* rather than the whole table) and
`columnMapping` (see Decision 4).

**Why:** `Source` already carries `location` (the Nextcloud instance base
URL — same instance or, since Tables' API is plain HTTP+Basic-Auth, a
remote federated one) and `authentication` (Basic Auth embedded credential
or `credentialRef`, per the already-shipped `source-broker-credentials`
`CallService::call()` Phase 7b). Inventing a separate "Tables connection"
entity would duplicate credential storage, CallLog wiring, and rate-limit
tracking that `Source` + `CallService` already provide for free — directly
contradicts the `openconnector-direct-or-usage` constraint against
reimplementing capabilities that already exist.

**Alternative considered:** A dedicated `TablesConnection` OR schema
(location + table id + credential in one object). Rejected — it would need
its own CRUD UI, its own credential-broker wiring duplicate of `Source`'s,
and it fragments "where do I configure auth for an HTTP-ish thing" into two
places for no behavioral gain.

### Decision 2: `TablesClientInterface` targets the v1 REST API, not OCS v2, for row/table/column CRUD

**Choice:** `TablesOcsClient` (name kept for continuity with the brief; the
class targets `index.php/apps/tables/api/1/*`) implements
`TablesClientInterface`:

```php
interface TablesClientInterface
{
    public function listTables(ObjectEntity $source): array;
    public function listColumns(ObjectEntity $source, int $tableId): array;
    public function listRows(ObjectEntity $source, int $tableId, ?int $viewId, int $page, int $pageSize): array;
    public function getRow(ObjectEntity $source, int $rowId): array;
    public function createRow(ObjectEntity $source, int $tableId, array $data): array;
    public function updateRow(ObjectEntity $source, int $rowId, array $data): array;
    public function deleteRow(ObjectEntity $source, int $rowId): void;
}
```

**Why:** verified against the Tables app's published OpenAPI schema
(discovery.md) that `ocs/v2.php/apps/tables/api/2/{nodeCollection}/{nodeId}/rows`
only exposes `POST` for authenticated callers — no `GET`/`PUT`/`DELETE` on a
single row outside the share-token (`public/{token}/...`) path, which
requires a share link rather than a normal session/app-password identity.
`index.php/apps/tables/api/1/*` has full CRUD for tables, columns, and rows
and is still live in the current schema. Building on v1 is the only way to
get `updateRow()`/`deleteRow()` without depending on a share-token flow that
does not fit a background sync identity.

**Alternative considered:** Build on v2 and simulate `PUT`/`DELETE` via
`POST` semantics (e.g. re-create). Rejected — Tables assigns row ids
server-side; simulating update/delete via delete+recreate would break
`SynchronizationContract`'s `targetId` (Tables row id) stability across
re-syncs and multiply write amplification. Building on v2's public/token
path was rejected because it requires a share, not an authenticated user
identity, which conflicts with the brief's "syncs run as configured user
context" requirement.

**Consequence:** `TablesClientInterface` is deliberately API-version-agnostic
in its signatures so a `TablesOcsV2Client` can be added later (once v2 gains
authenticated single-row CRUD) and swapped in via DI without touching
`SynchronizationService` or `TablesSyncAdapter`.

### Decision 3: Feature detection via `IAppManager` only, never via an OCS/capabilities round-trip or a direct `OCA\Tables\*` reference

**Choice:** `TablesSyncAdapter` (and the editor-facing controller) guard
every entry point with
`$this->appManager->isEnabledForUser('tables', $user)` (mirroring
`HealthController`'s existing pattern for OpenRegister). When false: the
type is omitted from `GET .../synchronizations/source-target-types` (or
equivalent option-collection endpoint the editor calls), and any
synchronization already configured with `nextcloud-table` fails its run
with a 409-class config error ("Tables app is not enabled") rather than
attempting the HTTP call.

**Why:** `IAppManager` works even when Tables is completely absent (no
class to resolve), matches the "soft dependency" requirement in the brief
exactly, and mirrors the one and only precedent already in this codebase
(`lib/Controller/HealthController.php`'s doc comment: "detects the missing
dependency using `IAppManager` ONLY — it never references [the other app's]
classes"). A capabilities OCS round-trip would work too but is strictly
slower (extra HTTP hop) and adds a call that itself needs error handling
when Tables is absent — no benefit over the in-process check.

### Decision 4: Contract identity — `originId` ↔ `targetId` (Tables row id), column mapping keyed by column title (alias), resolved to numeric `columnId` at write time

**Choice:**
- **Tables as target:** `SynchronizationContract.targetId` = the Tables row
  id (`(string) $row['id']`), exactly like every other target type's
  `targetId`. `SynchronizationContract.targetHash` = a hash of the mapped
  `data` payload actually sent, using the existing `hashObject()` primitive
  (order-independent) — so a re-sync with unchanged mapped values is a
  no-op write, matching REQ-004's existing dedup behavior for other targets.
  `targetConfig.columnMapping` is an array of
  `{"column": "<title>", "value": "<mapping-output-field-path>"}` — column
  **titles**, not raw `columnId`s, so the editor and the stored config stay
  human-readable and stable across a column being deleted/recreated with a
  new id but the same title. `TablesSyncAdapter` resolves title → `columnId`
  against the column list fetched at the start of each run (Decision 5) and
  fails that row's write (not the whole run) with a clear per-row log entry
  if a mapped title no longer resolves.
- **Tables as source:** `SynchronizationContract.originId` = the Tables row
  id (`getOriginId()`'s configured `idPosition` defaults to `id`, which is
  exactly the `Row.id` field — no adapter-specific override needed). The
  row's `data` (columnId → value) is exposed to the mapping/mapping-preview
  pipeline as-is; `MappingService` already handles field renaming, so
  presenting raw `columnId` keys on the source side (and letting the
  synchronization's own mapping translate them) avoids a second alias layer
  on the read path where `dataByAlias` already exists for the editor
  preview to consume for readability, without adapter code needing to
  choose one shape.

**Why:** This is the smallest change that satisfies "reuse Mapping" and
"honoring SynchronizationContracts (originId ↔ rowId)" from the brief while
staying inside the existing contract shape (`originId`/`targetId` are
already generic strings — REQ-003/REQ-004 of `synchronization-engine`) with
zero schema changes to `SynchronizationContract`.

**Alternative considered:** Store raw numeric `columnId` in
`targetConfig.columnMapping`. Rejected — the editor's column-mapping helper
(REQ-SYNCUI in the sync-editor-ui delta) is far more usable against titles,
and a title-keyed mapping degrades gracefully (clear per-row failure) rather
than silently mis-targeting a column if ids are renumbered by a table
export/import.

### Decision 5: Column metadata is fetched once per run, cached in-memory, not persisted

**Choice:** `TablesSyncAdapter` calls `listColumns()` once at the start of
`getAllObjectsFromSource()` / `updateTarget()`'s first invocation for a
given synchronization run, holds the `columnId ↔ {title, type, subtype,
constraints}` map for the lifetime of that run (same object, not a service
singleton — no cross-run staleness), and uses it both for `dataByAlias`-style
title resolution (Decision 4) and for coercion (Decision 6).

**Why:** Column schema changes are rare relative to sync frequency; fetching
once per run bounds the extra HTTP calls to a constant (not per-row) and
guarantees a run sees a single consistent schema snapshot rather than
racing a concurrent column edit mid-run.

### Decision 6: Column-type coercion is table-schema-driven, not mapping-driven

**Choice:** Before `createRow()`/`updateRow()`, `TablesSyncAdapter` coerces
each mapped value against the *target column's* declared `type`/`subtype`
from the cached column list (Decision 5):
- `text`: cast to string; truncate/reject per `textMaxLength` (log, don't
  silently truncate data — a truncated write is itself a data-loss bug
  class per this repo's `feedback_no-mock-fixes-real-functionality`
  standard, so this change fails the row instead of silently truncating).
- `number`: cast to float/int per `numberDecimals`; a non-numeric input
  value fails that row's write with a clear log entry.
- `datetime`: normalise to ISO-8601, respecting the column's
  `date`/`time`/`datetime` subtype (date-only strips a time component).
- `selection`: match the mapped value against `selectionOptions` by label;
  no match fails that row's write (this change does not auto-create
  selection options — see proposal Out of Scope).
- `usergroup`: out of scope for v1 coercion (no safe generic mapping from
  arbitrary source data to a Nextcloud user/group/team reference); a column
  of this type in the mapping is a config-time validation error in the
  editor, not a runtime failure.

**Why:** matches the brief's explicit "column type coercion for
text/number/datetime/select" requirement precisely, and failing a single
row (not the whole run) on a coercion mismatch matches this repo's
established "no silent-fail, no partial-truncate" data-integrity standard
and the "no partial writes on failure" scenario for the permission-denied
case (Decision 8) — the same posture applies here: a single row's coercion
failure is logged and skipped, the run otherwise continues, and the run
summary surfaces a non-zero failed-row count.

### Decision 7: Deletion is delegated entirely to the shared `deleteInvalidObjects()` guard path

**Choice:** `nextcloud-table` adds a `case 'nextcloud-table':` branch to
`deleteInvalidObjects()`'s target-type `switch` that resolves the
synchronization's target `Source`/`tableId`, diffs `synchronizedTargetIds`
against known contract `targetId`s exactly like the `register/schema`
branch already does, and calls `TablesClientInterface::deleteRow()` for
each id to delete — **after** whatever pre-deletion guard
`sync-safety-guardrails` lands (incomplete-run abort, deletion-ratio
threshold). This change does not add a second, `nextcloud-table`-specific
threshold/guard.

**Why:** the brief explicitly requires aligning with, not duplicating,
`sync-safety-guardrails`'s deletion guard. Since that change is still at
context-brief stage (no spec/REQ ids to cite yet), this design intentionally
describes the *composition point* (same `deleteInvalidObjects()` method,
same guard call site) rather than inventing REQ-id cross-references that
would go stale. The synchronization-engine spec delta below states this as
a MUST that both changes are expected to satisfy jointly.

### Decision 8: Permission-denied on a Table write fails the run, no partial writes, via the Table's own ACL — not a re-implemented one

**Choice:** OpenConnector does **not** pre-check whether the configured
identity can write to the target table. It attempts the write; Tables'
existing OCS/REST-layer ACL check does the enforcement server-side (Tables
already 403s a caller lacking table access — verified: this is core Tables
behavior, not something this change adds). `TablesSyncAdapter` maps a 401/403
from `CallService`'s resulting `CallLog`/response to a synchronization-run
failure with a clear log message ("permission denied writing to table
{tableId} as {identity}") and — critically — treats it as a fetch/write
**failure for the run**, not a per-row skip: a 401/403 on the *first*
write in a run aborts the rest of that run's writes rather than continuing
to attempt (and partially succeed at) further rows, satisfying "no partial
writes" for the permission-denied case specifically (this is distinct from
Decision 6's per-row coercion failures, which are expected/routine and do
not indicate every subsequent row will also fail).

**Why:** "Do NOT reimplement OR capabilities" generalizes here to "do not
reimplement Tables' own authorization" — Tables is the authority on who can
write to a table it owns. Treating 401/403 as a whole-run abort (rather than
per-row skip) is the right granularity because a permission failure is
almost certainly identity-wide (the sync-owner's credential lacks access to
the *table*), not row-specific, unlike a coercion failure which is
data-specific.

## Risks / Trade-offs

- [Risk] v1 API deprecation before v2 gains authenticated row CRUD →
  Mitigation: `TablesClientInterface` boundary isolates the swap to one
  class; flagged in proposal Risk 1.
- [Risk] Column-title mapping is ambiguous if two columns share a title
  (Tables does not enforce title uniqueness, only `technicalName`/id
  uniqueness) → Mitigation: `TablesSyncAdapter` resolves by title but MUST
  treat >1 match as a hard config error (same "never a guess" posture as
  `source-broker-credentials`' `credentialName` resolution), not a
  first-match guess.
- [Trade-off] Fetching columns once per run (Decision 5) means a column
  renamed mid-run is invisible until the next run — acceptable; Table
  schema edits mid-sync are rare and the alternative (per-row column fetch)
  is a large, unjustified performance cost.

## Migration Plan

No database schema migration — `Synchronization`, `Source`, and
`SynchronizationContract` are all OpenRegister objects (per
`openconnector-direct-or-usage`); `nextcloud-table` is simply a new
recognised string value for the existing `sourceType`/`targetType` string
fields, and `tableId`/`viewId`/`columnMapping` are new keys inside the
existing free-form `sourceConfig`/`targetConfig` JSON objects. Deploying
this change is: ship the new PHP classes, the new controller endpoints, and
the Vue editor additions; existing synchronizations of every other type are
untouched (the new `switch` branches are additive). Rollback is a plain code
revert (see proposal.md Rollback Strategy).

## Open Questions

- Exact editor endpoint path/shape for table + column discovery — captured
  as a working contract in `contract.md`; open to bikeshedding on the URL
  segment (`.../synchronizations/tables-bridge/tables` vs a more generic
  `.../tables-bridge/...` namespace) during implementation.
- Whether `usergroup` columns should get *read*-side support (Tables →
  OpenConnector) even though write-side is out of scope — leaning yes since
  reading a usergroup value as a plain string/array is safe and requires no
  coercion-authorization concerns; deferred to tasks.md scoping.
