---
kind: code
depends_on:
  - openconnector-register-schema-declaration
  - openconnector-adopt-or-abstractions
---

# Proposal: openconnector-register-storage

## Summary

Migrate openconnector's storage layer from 15 hand-rolled `oc_openconnector_*`
tables onto OpenRegister-backed objects. Reuses the register descriptor declared
by `openconnector-register-schema-declaration` (chain A) and provisions live
storage at app upgrade time via `ConfigurationService::importFromApp(...)`. A
one-shot data migrator copies every row into `oc_openregister_objects`,
preserves existing UUIDs, rewrites integer foreign keys into UUID relations, and
sets an app-config flag that switches all 15 mappers to read/write through OR.
Mappers stay as thin facades over `ObjectService` so the 20+ services and
controllers consuming them keep their existing call shape (`find()`, `findAll()`,
`createFromArray()`, etc.). Old tables remain read-only for one release as a
rollback buffer, then are dropped by a follow-up change.

## Motivation

Once the chain-A descriptor lands, openconnector has two storage paths in
parallel: the legacy hand-rolled tables (still authoritative) and the new OR
register (provisioned but empty). This proposal closes that gap and makes OR the
authoritative storage:

- **Logs become immutable in the storage layer**, not just by convention.
  `appendOnly: true` + `immutable: true` are enforced by OR's
  `AppendOnlyException` and immutability guards. Audit-trail compliance is
  satisfied without bespoke openconnector code.
- **Relation queries become first-class.** Today, "all CallLogs for source X" is
  a manual `WHERE source_id = ?` query; after migration it is OR's
  `extend=source` traversal, with `inversedBy` automatically wiring the reverse
  side.
- **The OR retention/archival workflow drives log expiry**, replacing the
  hardcoded retention constants (which `openconnector-adopt-or-abstractions`
  removes).
- **Cross-app traversal** — apps that already read OR objects (decidesk,
  pipelinq, procest) can now reach openconnector entities through the same
  `register/schema/uuid` surface, with no per-app adapter.

The two-change split is mandated by ADR-032: the configuration declaration is
~300 lines of JSON, the code migration is hundreds of LOC of PHP. They cannot
share a proposal under the ADR-032 mixed-proposal rule, so chain A landed first
and chain B (this change) builds on it.

## Affected Projects

- [x] Project: `openconnector` — major: new migration class, new `Migrator`
  service, new `ObjectMapperFacade`, rewrite of 15 mapper bodies, new OCC
  command, new tests. No controller/service consumer is changed (mapper API
  preserved).
- [x] Project: `openregister` — passive consumer of the importer call from
  openconnector's migration; no openregister code is added or changed.
- [x] Project: `decidesk`, `pipelinq`, `procest`, `docudesk` — passive
  beneficiaries: once the migration runs, these apps can target
  `register/schema/uuid` for openconnector objects with no per-app change.

## Scope

### In Scope

- **Schema provisioning** via a new Nextcloud migration class
  (`lib/Migration/Version2Date20260520xxxxxx.php`) that calls
  `ConfigurationService::importFromApp('openconnector', <register-json>,
  <version>, false)` on app upgrade.
- **One-shot data migration** via a new `LegacyToRegisterMigrator` service that
  copies every row from each `oc_openconnector_*` table into
  `oc_openregister_objects`, preserving uuids and batching at 10,000 rows. JSON
  payload assembly is dual-platform (MySQL `JSON_OBJECT`, Postgres
  `jsonb_build_object`) selected via `IDBConnection::getDatabasePlatform()`.
- **FK rewrite pass** that translates 6 integer FK columns into uuid relations
  by JOINing the legacy table back to itself; populates both the legacy `*Id`
  and the new relation-named field per chain-A REQ-008.
- **`Synchronization.sourceId/targetId` branching** that recognises three input
  formats (integer PK, register/schema slug-pair, uuid) and rewrites
  appropriately, with per-variant counts logged.
- **`ObjectMapperFacade`** under `lib/Service/Storage/` that translates the
  existing mapper API (`find`, `findAll`, `createFromArray`, `updateFromArray`,
  `delete`, plus per-mapper `findBy*` helpers) into OR `ObjectService` calls.
  Result hydration maps OR `ObjectEntity` back into existing typed entities
  (`Source`, `Job`, …) so callers receive the original types.
- **Rewrite of 15 mapper bodies** to delegate to `ObjectMapperFacade`; each
  mapper binds at construction time to its register slug + schema slug.
- **App-config flag** `openconnector.storage_migrated = "true"` set by the
  migrator on success. Mappers read once at boot and cache; if `false` →
  legacy table path, if `true` → OR path. Enables incremental rollout and
  rollback.
- **OCC command** `occ openconnector:migrate-storage [--dry-run]
  [--entity=<slug>] [--batch-size=10000]` for manual re-runnability + verbose
  progress.
- **Tests** — unit tests on the migrator (mocks `IDBConnection`, covers
  batching/FK-rewrite/dry-run/sourceId-branching) and integration tests on the
  facade (write through facade, read back as typed entity; both legacy and OR
  paths via the flag).

### Out of Scope

- **Dropping `oc_openconnector_*` tables.** Kept read-only for one release as a
  rollback buffer. Follow-up cleanup change drops them and removes the
  `storage_migrated` flag — tracked at [#820](https://github.com/ConductionNL/openconnector/issues/820).
- **Renaming FK fields `*Id` → target-schema name** in the descriptor or in
  call sites. Chain-A REQ-008 preserves both forms; a follow-up change does the
  rename once frontend Vue stores are updated — tracked at [#821](https://github.com/ConductionNL/openconnector/issues/821).
- **The hardcoded retention constants** in `JobService`, `CallService`,
  `SynchronizationService`. Handled by `openconnector-adopt-or-abstractions`,
  declared in `depends_on`.
  > **Status of dependency (2026-05-20):** `openconnector-adopt-or-abstractions`
  > exists at `openspec/changes/openconnector-adopt-or-abstractions/` but is
  > NOT yet validator-clean (4 MUST/SHALL placement issues — same pattern as
  > chain A/B before they were fixed; trivial to repair). Chain B can proceed
  > if (a) `adopt-or-abstractions` is fixed in parallel OR (b) the specific
  > retention-constant removal it covers is implemented as a one-off pre-step
  > in chain B's Task 0. Chain B does NOT depend on the rest of
  > `adopt-or-abstractions`'s scope.
- **Frontend Vue store changes.** Stores keep reading the mapper API (returns
  the same typed entities). No store edits in this change.
- **Performance optimisation of the OR path.** Initial implementation accepts
  some latency overhead relative to raw SQL; targeted profiling is a follow-up
  if benchmarks show regression.

## Approach

1. **Phase 1 — schema provisioning.** Migration class invokes
   `ConfigurationService::importFromApp(...)` against the chain-A descriptor.
   Idempotent — re-runs are no-ops at the schema level.
2. **Phase 2 — data migration (per entity, sequential):**
   a. Read row count from `oc_openconnector_<table>`.
   b. Batch SELECT 10,000 rows at a time.
   c. INSERT into `oc_openregister_objects` with `uuid` copied verbatim,
      `register` = openconnector PK, `schema` = matching schema PK, `object` =
      JSON-built from row columns via platform-specific JSON builder, `owner` =
      see DEFERRED Q3, `created`/`updated` copied from source row.
   d. Log row counts before/after; abort the entity if counts diverge.
3. **Phase 3 — FK rewrite pass (per relation):** UPDATE
   `oc_openregister_objects` set `object = jsonb_set(object, '{<relation>}',
   to_jsonb((SELECT uuid FROM oc_openconnector_<target_table> WHERE id = <fk_int>)))`
   (postgres syntax; equivalent on MySQL via `JSON_SET`). Skip + log rows where
   the FK target row is missing.
4. **Phase 4 — sourceId/targetId branching** (`Synchronization` only): per-row
   regex match on the legacy column value, rewrite or leave-as-is per the three
   formats. Per-variant counts go to the migration log.
5. **Phase 5 — facade switch.** Set `openconnector.storage_migrated = "true"`
   via `IAppConfig`. Mappers, which read the flag once at boot, will switch on
   the next request cycle.
6. **Phase 6 — read-only lock on legacy tables.** Pure-PHP guard inside legacy
   mapper paths (raises `\LogicException` on write after flag is true). The
   actual DB-level lock is a follow-up: in cleanup change we drop the tables.

## New Dependencies

None. OpenConnector already lists `openregister` as a peer. No new composer or
npm package is added.

## Impact

### New code
- `lib/Migration/Version2Date20260520xxxxxx.php` (~150 LOC)
- `lib/Service/Migration/LegacyToRegisterMigrator.php` (~600 LOC — bulk of the work)
- `lib/Service/Storage/ObjectMapperFacade.php` (~400 LOC)
- `lib/Command/MigrateToOpenRegister.php` (~120 LOC)
- `tests/Unit/Service/Migration/LegacyToRegisterMigratorTest.php` (~500 LOC)
- `tests/Integration/Service/Storage/ObjectMapperFacadeTest.php` (~400 LOC)

### Modified code
- `lib/Db/SourceMapper.php`, `ConsumerMapper.php`, `EndpointMapper.php`,
  `EventMapper.php`, `EventMessageMapper.php`, `EventSubscriptionMapper.php`,
  `JobMapper.php`, `JobLogMapper.php`, `MappingMapper.php`, `RuleMapper.php`,
  `SynchronizationMapper.php`, `SynchronizationContractMapper.php`,
  `SynchronizationContractLogMapper.php`, `SynchronizationLogMapper.php`,
  `CallLogMapper.php` — 15 files rewritten as thin facades over
  `ObjectMapperFacade`.

### Unchanged
- All controllers in `lib/Controller/`.
- All non-mapper services in `lib/Service/`.
- All Vue stores in `src/store/`.
- All `lib/Db/<EntityName>.php` typed-entity classes — used as DTOs by the
  facade's hydration.

## Cross-Project Dependencies

- **Depends on**: `openconnector-register-schema-declaration` (chain A) for the
  register descriptor file on disk.
- **Depends on**: `openconnector-adopt-or-abstractions` for removal of the
  triplicated retention constants. After this change ships, the only retention
  source-of-truth is the chain-A `x-openregister-archival` annotation.
- **Affects**: any downstream app that reads openconnector entities through
  OR's `/api/objects/...` surface gains access once this change ships. Today
  that's a forward-looking integration — no app does this yet — so no breaking
  change.

## Risks

### Risk 1: Encrypted column handling could lose data
**Severity:** High — **Mitigation:** DEFERRED investigation (Q3 in
DEFERRED_QUESTIONS) — inspect `lib/Db/Source.php` setter overrides and any
`EncryptionService` to determine whether encryption happens at the Entity level
(`setApikey()` hooks it in) or at the storage layer. Provisional plan: if
column-level, copy encrypted bytes verbatim; if storage-level, the migrator
must decrypt-then-re-encrypt via OR's storage layer. Block the migration with a
guarded assertion until this is confirmed.

### Risk 2: CallLog volume in production (potentially millions of rows)
**Severity:** High — **Mitigation:** Batched migration at 10,000 rows; OCC
command with `--batch-size` override; per-entity verbose logging; PHP CLI
runtime (not web request) avoids 30s timeout. The migration class delegates the
heavy lift to a dedicated migrator service that can also be invoked outside
the upgrade window via the OCC command if it exceeds the upgrade window.

### Risk 3: Synchronization.sourceId/targetId overload
**Severity:** Medium — **Mitigation:** Per-row regex match; rows with
unrecognised formats are SKIPPED + logged to the migration log (not silently
corrupted). The OCC command `--entity=synchronization` allows re-running with
finer-grained handling for outliers.

### Risk 4: Owner-null breaks owner-based ACL checks if any consumer relies on them
**Severity:** Medium — **Mitigation:** Q4 resolved: owner is left null on
every migrated object (openconnector is system-level, not per-user data).
Permission for openconnector objects defers to OR's system-level rules.
If any downstream consumer (admin UI, audit pipeline) reads `owner` to gate
visibility, it will observe null and must handle that — covered by the
post-migration smoke test. Legacy `userId` is preserved inside the object
JSON body for provenance.

### Risk 5: Performance: 15 mappers through ObjectService adds latency
**Severity:** Medium — **Mitigation:** The facade caches int-id→uuid resolution
in a per-mapper lookup table; `findAll()` consolidates into single
ObjectService calls; opaque PHPUnit performance test (PR-time) measures p95
latency on `findById(int)` and `findBySlug(string)` against legacy baseline.
Regression > 50% blocks merge.

### Risk 6: FK integer overlap across tables (e.g. CallLog.sourceId=123 vs
Job.id=123 — different things)
**Severity:** Low — **Mitigation:** Each FK rewrite query JOINs to the specific
target table (`source` for `CallLog.sourceId`, `event` for `EventMessage.eventId`,
etc.). No ambiguity because each FK column has exactly one target.

### Risk 7: Migration partial-failure leaves split data
**Severity:** Low — **Mitigation:** Per-entity transaction (where DB allows);
storage_migrated flag flipped only when ALL entities complete; OCC command
`--entity=X` allows re-running individual entities if one fails. Legacy tables
remain authoritative until the flag flips.

## Rollback Strategy

The migration is designed to be reversible during the one-release transition:

1. **Pre-flag (during data migration):** Migrator failure leaves
   `storage_migrated=false`. Mappers continue using legacy tables; OR objects
   created in the partial migration become orphaned but harmless. The OCC
   command's `--clean` flag (added in this change) deletes the orphaned OR
   objects to allow a clean retry.
2. **Post-flag (after migration completes):** Setting
   `storage_migrated=false` via `occ config:app:set openconnector
   storage_migrated --value=false` flips all mappers back to the legacy tables,
   which still contain authoritative data (one-release read-only buffer).
   Writes that occurred against OR after the flag flipped are NOT
   automatically copied back — admins must manually export them with the OCC
   command and bulk-insert them into the legacy tables (rare path; documented
   in the runbook).
3. **One release later (cleanup change ships):** Legacy tables are dropped; the
   `storage_migrated` flag is removed; this rollback path is no longer
   available. By that point the OR path is the only path.

## Open Questions

See DEFERRED_QUESTIONS for the canonical list. Key items affecting
implementation:

1. **CallLog.actionId target** — ambiguous schema; storage chain must inspect
   `lib/Service/CallService.php` and `EndpointService.php` to resolve. Until
   then: `actionId` migrated as opaque integer + an additional `action`
   string-uuid column with NO `$ref`.
2. **Encrypted columns** — column-level vs storage-level encryption. Provisional
   choice: column-level (Entity setters hook it). If wrong, decrypt+re-encrypt
   step is added in the migrator.
3. **Owner field** — RESOLVED: owner is left null on every migrated object.
   Openconnector is system-level data, not per-user. Legacy `userId` columns
   on log entities are preserved in the object JSON body for provenance.
4. **FK rename timing** — keep `*Id` and target-named fields both during this
   change; rename in a follow-up change once frontend stores are updated.
