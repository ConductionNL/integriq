# openconnector-storage-migration Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- openconnector-register-storage (this change)

## Purpose

Migrate openconnector's storage from 15 hand-rolled `oc_openconnector_*` tables
onto OpenRegister objects, with a transitional facade layer that preserves the
existing mapper API for the 20+ controllers and services that consume it.
Reuses the register descriptor declared by chain A
(`openconnector-register-schema-declaration`). Uses a strangler-fig pattern
gated by the `openconnector.storage_migrated` app-config flag for safe
rollback. Implements ADR-001 (all domain data lives in OpenRegister) and
ADR-031's "scheduled bulk work" exception for the one-shot migrator.

Cross-references the `audit-trail-immutable`, `retention-management`,
`archival-destruction-workflow`, and `nextcloud-entity-relations` specs in
openregister.

## ADDED Requirements

### Requirement: Migration class MUST provision the register via importFromApp

The system MUST ship a Nextcloud migration class at
`lib/Migration/Version2Date20260520xxxxxx.php` that on its `postSchemaChange`
hook calls
`OCA\OpenRegister\Service\ConfigurationService::importFromApp('openconnector',
<absolute-path-to-openconnector_register.json>, <openconnector-app-version>,
false)` exactly once per upgrade. The call MUST be idempotent — re-running the
migration is a no-op.

#### Scenario: Fresh install
- GIVEN openconnector is being installed for the first time
- WHEN Nextcloud runs `occ app:enable openconnector`
- THEN the migration class executes
- AND `oc_openregister_registers` gains one row with `slug='openconnector'`
- AND `oc_openregister_schemas` gains 15 rows

#### Scenario: Idempotent re-run
- GIVEN `oc_openregister_registers` already contains a row with
  `slug='openconnector'`
- WHEN Nextcloud re-runs the migration (e.g. on app upgrade)
- THEN the migration class executes without error
- AND no duplicate register or schema rows are created
- AND schema-metadata updates are applied in-place

### Requirement: Migrator MUST copy all legacy rows preserving uuids

The system MUST ship `lib/Service/Migration/LegacyToRegisterMigrator.php`
exposing a `migrateAll(bool $dryRun, ?string $entitySlug, int $batchSize): array`
method. For each of the 15 entities, the migrator MUST:

1. Read row count from the matching `oc_openconnector_<table>`.
2. Stream rows in batches of `$batchSize` (default 10,000).
3. INSERT each row into `oc_openregister_objects` with the existing
   `uuid` preserved verbatim and `register`/`schema` resolved to the
   openconnector register's primary key.
4. Build the `object` JSON column using platform-specific SQL functions:
   - On MySQL/MariaDB: `JSON_OBJECT(key1, val1, key2, val2, …)`
   - On PostgreSQL: `jsonb_build_object(key1, val1, key2, val2, …)`
   Platform detection MUST use `IDBConnection::getDatabasePlatform()`.
5. Log row counts before and after each entity to the migration log.
6. Return a per-entity result array (slug, legacyCount, migratedCount, skipped,
   skippedReason, fkRewrites, elapsedMs).

#### Scenario: Migrate sources entity
- GIVEN `oc_openconnector_sources` contains 12 rows
- WHEN the migrator runs against `entity='source'`
- THEN 12 rows are inserted into `oc_openregister_objects` with `register` =
  the openconnector register PK and `schema` = the source schema PK
- AND each inserted row's `uuid` matches the source row's `uuid` byte-for-byte
- AND the migration log records "source: 12 → 12, 0 skipped"

#### Scenario: Postgres JSON build
- GIVEN the runtime database is PostgreSQL
- WHEN the migrator INSERTs into `oc_openregister_objects`
- THEN the SQL statement uses `jsonb_build_object(...)` to assemble the
  `object` column
- AND the resulting `object` value is JSONB type

#### Scenario: MySQL JSON build
- GIVEN the runtime database is MariaDB or MySQL
- WHEN the migrator INSERTs into `oc_openregister_objects`
- THEN the SQL statement uses `JSON_OBJECT(...)` to assemble the `object`
  column
- AND the resulting `object` value is JSON type

#### Scenario: UUID preservation
- GIVEN a `Source` row with `uuid='00000000-0000-0000-0000-000000000123'`
- WHEN the migrator processes it
- THEN the resulting `oc_openregister_objects` row has the same uuid
- AND any existing FK that referenced this uuid as a string-typed column
  still resolves after migration

### Requirement: FK rewrite pass MUST translate 6 integer FK columns to uuids

The migrator MUST translate all 6 legacy integer foreign-key columns into target-uuid relation fields during a dedicated second SQL pass.

After the bulk INSERT pass completes for the two entities with integer FKs
(`call_log` and `event_message`), the migrator MUST run a second pass that
rewrites each integer FK column into the target schema's uuid. The 6 FKs
covered:

| Source schema   | Legacy column          | Target table           | Resulting field   | Failure handling          |
|-----------------|------------------------|------------------------|-------------------|----------------------------|
| `call_log`      | `source_id` (int)      | `oc_openconnector_sources` | `source` (uuid)    | Skip + log on missing target |
| `call_log`      | `synchronization_id` (int) | `oc_openconnector_synchronizations` | `synchronization` (uuid) | Skip + log     |
| `call_log`      | `action_id` (int)      | (ambiguous — see notes) | `action` (string) | Migrate as opaque, no FK rewrite |
| `event_message` | `event_id` (int)       | `oc_openconnector_events` | `event` (uuid)     | Skip + log                 |
| `event_message` | `consumer_id` (int)    | `oc_openconnector_consumers` | `consumer` (uuid) | Skip + log                 |
| `event_message` | `subscription_id` (int) | `oc_openconnector_event_subscriptions` | `subscription` (uuid) | Skip + log |

After the rewrite, BOTH the legacy `*Id` field (with the integer value) AND
the relation-named field (with the resolved uuid) MUST be present in the OR
object's payload, per chain A REQ-A-008.

#### Scenario: Rewrite CallLog.sourceId to source uuid
- GIVEN a `oc_openconnector_call_logs` row with `source_id=42` migrated as
  `{"sourceId": 42}` in the OR object
- AND `oc_openconnector_sources` row with `id=42` has
  `uuid='00000000-0000-0000-0000-000000000042'`
- WHEN the FK rewrite pass runs
- THEN the OR object's `object` JSON is updated to contain
  `"source": "00000000-0000-0000-0000-000000000042"`
- AND the legacy `"sourceId": 42` field is preserved (chain A REQ-A-008)

#### Scenario: Missing target row triggers skip + log
- GIVEN a `oc_openconnector_call_logs` row with `source_id=99` where no
  `oc_openconnector_sources.id=99` exists (orphaned FK in legacy data)
- WHEN the FK rewrite pass runs
- THEN the OR object's `source` field is NOT set
- AND the migration log records "call_log row {uuid}: sourceId=99 has no
  target — skipped FK rewrite, legacy sourceId preserved"

### Requirement: Synchronization.sourceId/targetId branching MUST handle 3 formats

The migrator MUST detect the format of every
`oc_openconnector_synchronizations.source_id` and `target_id` value and
rewrite according to the same logic as
`OCA\OpenConnector\Service\SynchronizationService` lines 141–545:

| Pattern                              | Action                                                   |
|--------------------------------------|----------------------------------------------------------|
| `^\d+$` (integer PK as string)       | JOIN to `oc_openconnector_sources` and substitute uuid   |
| `^[\w-]+\/[\w-]+$` (register/schema) | Leave as-is — already an OR slug pair                    |
| RFC 4122 uuid                        | Leave as-is — already an OR uuid                          |
| anything else                        | Skip + log; legacy value preserved; migrator counts the skip |

Per-format counts MUST be logged at the end of the synchronization migration
(e.g. "synchronization: integer-PK→uuid: 4, register/schema: 2, uuid: 1,
unrecognised: 1 (skipped)").

#### Scenario: Integer PK gets resolved
- GIVEN a synchronization row with `source_id='42'`
- AND `oc_openconnector_sources.id=42` has
  `uuid='00000000-0000-0000-0000-000000000042'`
- WHEN the migrator processes this row
- THEN the resulting OR object's `sourceId` field is
  `"00000000-0000-0000-0000-000000000042"`

#### Scenario: Register/schema slug pair preserved
- GIVEN a synchronization row with `source_id='zaken/zaak'`
- WHEN the migrator processes this row
- THEN the resulting OR object's `sourceId` field is `"zaken/zaak"`
  unchanged

#### Scenario: Unrecognised format skipped
- GIVEN a synchronization row with `source_id='not-a-recognised-format'`
- WHEN the migrator processes this row
- THEN the migration log records the skip with row uuid + raw value
- AND the migrator's return tally increments `unrecognised` by 1
- AND the OR object's `sourceId` field is set to the raw legacy value (not
  rewritten)

### Requirement: Migrator MUST set the storage_migrated app-config flag on success

The migrator MUST set `openconnector.storage_migrated = "true"` via IAppConfig only after every per-entity copy, the FK rewrite pass, and the sourceId branching pass have all completed without error.

After all 15 entities migrate successfully AND the FK-rewrite + branching
passes complete, the migrator MUST set
`IAppConfig::setValue('openconnector', 'storage_migrated', 'true')`. If any
entity fails (exception or post-migration row-count mismatch), the flag MUST
remain unset (or set to `"false"` if previously set in a partial earlier
run).

#### Scenario: Success path flips flag
- GIVEN all 15 entities migrate without skips or errors
- WHEN the migrator's `migrateAll()` returns
- THEN `IAppConfig::getValue('openconnector', 'storage_migrated', null)` is
  `'true'`

#### Scenario: Failure path leaves flag false
- GIVEN entity 7 (job) raises an exception during migration
- WHEN the migrator's `migrateAll()` returns (or throws)
- THEN `IAppConfig::getValue('openconnector', 'storage_migrated', null)` is
  NOT `'true'` (either unset or `'false'`)
- AND mappers continue to use the legacy table path

### Requirement: ObjectMapperFacade MUST translate the mapper API to ObjectService

The system MUST ship `lib/Service/Storage/ObjectMapperFacade.php` exposing
methods that match the existing mapper API:

- `find(string $registerSlug, string $schemaSlug, int $id): Entity`
- `findByUuid(string $registerSlug, string $schemaSlug, string $uuid): Entity`
- `findBySlug(string $registerSlug, string $schemaSlug, string $slug): Entity`
- `findAll(string $registerSlug, string $schemaSlug, ?int $limit, ?int $offset, array $filters, ?string $search, ?array $sort): Entity[]`
- `createFromArray(string $registerSlug, string $schemaSlug, array $data, string $entityClass): Entity`
- `updateFromArray(string $registerSlug, string $schemaSlug, string $uuid, array $data, string $entityClass): Entity`
- `delete(string $registerSlug, string $schemaSlug, string $uuid): void`

Each method MUST internally invoke OR's `ObjectService` with the appropriate
filter, then hydrate the returned `ObjectEntity` into the openconnector
typed entity class (`Source`, `Job`, …) via the entity's `hydrate(array)`
method (already exists on all 15 entities).

The facade MUST maintain a per-mapper int-id→uuid cache. The cache MUST be
populated lazily on first `find(int)` call and invalidated on any
`createFromArray` or `delete` call for the same register/schema.

#### Scenario: find(int) resolves via the cache
- GIVEN a freshly-booted facade with an empty cache
- AND an OR object exists for `(openconnector, source, uuid=U)` whose legacy
  payload includes `"id": 42`
- WHEN the facade is asked `find(registerSlug='openconnector', schemaSlug='source', id=42)`
- THEN it queries OR with filter `["id" => 42]`, retrieves the object, caches
  `42 → U`, and returns a hydrated `Source` entity
- AND a second `find(..., id=42)` call resolves via the cache without a new
  OR query

#### Scenario: createFromArray hydrates returned typed entity
- GIVEN the facade is asked `createFromArray('openconnector', 'source',
  ['name' => 'New Source', 'type' => 'api'], Source::class)`
- WHEN OR's `ObjectService::saveObject` returns an `ObjectEntity`
- THEN the facade returns a `Source` instance whose properties were populated
  via `(new Source())->hydrate($objectEntity->getObject())`

#### Scenario: delete invalidates the cache
- GIVEN the facade's int-id→uuid cache contains `42 → U` for
  `(openconnector, source)`
- WHEN the facade is asked `delete('openconnector', 'source', uuid=U)`
- THEN the cache entry for that register/schema is dropped
- AND a subsequent `find(..., id=42)` re-queries OR

### Requirement: 15 mappers MUST delegate to the facade behind a flag

Every `*Mapper.php` in `lib/Db/` MUST be rewritten as a thin wrapper. Each
mapper:

- Reads `openconnector.storage_migrated` once at construction time and caches
  the result.
- If `false`: continues to use its existing `QBMapper`-based SQL path
  (unchanged).
- If `true`: delegates every public method to `ObjectMapperFacade` with the
  appropriate register slug (`'openconnector'`), schema slug (per the
  mapper), and entity class.

Public method signatures, exception types, and return types MUST be
preserved byte-for-byte from before this change.

#### Scenario: Flag false routes to legacy path
- GIVEN `openconnector.storage_migrated = 'false'`
- WHEN any controller calls `SourceMapper::find(42)`
- THEN execution runs the legacy SQL query against `oc_openconnector_sources`
- AND the returned `Source` entity is identical to the pre-migration behaviour

#### Scenario: Flag true routes to facade path
- GIVEN `openconnector.storage_migrated = 'true'`
- WHEN any controller calls `SourceMapper::find(42)`
- THEN execution routes to `ObjectMapperFacade::find('openconnector', 'source', 42)`
- AND the returned `Source` entity has the same shape as the legacy-path
  return

#### Scenario: Append-only enforcement on log mappers
- GIVEN `openconnector.storage_migrated = 'true'`
- WHEN any controller calls `JobLogMapper::updateFromArray(uuid=U, data=[...])`
- THEN execution propagates OR's `AppendOnlyException` (or its facade
  translation to `LogicException`)
- AND no UPDATE is issued

### Requirement: OCC command MUST allow manual re-runnability

The system MUST ship an OCC command at
`lib/Command/MigrateToOpenRegister.php` invokable as
`occ openconnector:migrate-storage [--dry-run] [--entity=<slug>] [--batch-size=<n>]`.
The command MUST:

- Validate args (entity in the 15 slugs, batch-size in [100, 100000]).
- Call the same `LegacyToRegisterMigrator::migrateAll(...)` that the
  migration class uses.
- Emit verbose progress (one line per batch).
- Exit 0 on success, non-zero on partial/total failure.

#### Scenario: Dry-run reports counts without writing
- GIVEN `oc_openconnector_sources` has 12 rows
- WHEN an admin runs `occ openconnector:migrate-storage --entity=source --dry-run`
- THEN the command exits 0
- AND output reports "source: 12 rows would migrate"
- AND `oc_openregister_objects` count for the source schema is unchanged
- AND `openconnector.storage_migrated` is unchanged

#### Scenario: Per-entity retry after partial failure
- GIVEN a previous migration failed during the `job` entity, leaving
  `storage_migrated` unset
- WHEN an admin runs `occ openconnector:migrate-storage --entity=job`
- THEN only the `job` entity migrates
- AND on success, the flag is NOT flipped (because other entities are still
  legacy); a follow-up full run is required

### Requirement: Legacy tables MUST stay readable for one release as rollback buffer

After `storage_migrated = 'true'`, the system MUST NOT drop, truncate, or
schema-alter `oc_openconnector_*` tables. Application code MUST NOT
write to them either (write attempts via mapper code paths route to OR via
the facade; direct SQL writes are out-of-band and not addressed here).

A follow-up cleanup change (one release later, tracked as a separate GH issue
that MUST be filed at proposal time per user's deferred-work rule) removes
the legacy tables and the storage_migrated flag.

#### Scenario: Legacy tables present after migration
- GIVEN the migration succeeded and `storage_migrated = 'true'`
- WHEN an admin queries `SELECT count(*) FROM oc_openconnector_sources`
- THEN the table still exists and contains the pre-migration rows

#### Scenario: Rollback to legacy path
- GIVEN the migration succeeded and `storage_migrated = 'true'`
- WHEN an admin runs `occ config:app:set openconnector storage_migrated --value=false`
- THEN subsequent reads through `SourceMapper::find(int)` route to the
  legacy table
- AND the returned data matches the pre-migration data (legacy table not
  modified)

### Requirement: Credential columns on Source MUST be copied verbatim during migration (currently plaintext)

The migrator MUST copy every credential column on `Source` verbatim into the OR object's JSON body — no decrypt, re-encrypt, or transformation steps.

The 6 string columns on `Source` that store credentials (`apikey`,
`password`, `secret`, `jwt`, `username`, plus any string-typed entries inside
`authenticationConfig`) are currently stored as **plaintext** in
`oc_openconnector_sources` — per ADR-007, no `EncryptionService` class exists
in openconnector, and `SourceMapper::insert()` does NOT apply encryption.
The migrator MUST copy these columns into OR storage exactly as they appear
in the legacy tables, preserving the plaintext content as-is.

The migrator MUST assert (at startup) that the codebase is in the expected
state (no `OCA\OpenConnector\Service\EncryptionService` class present, no
encryption hook in `lib/Db/Source.php` setters). If the assertion fails —
indicating encryption has been introduced since this spec was written — the
migrator MUST abort BEFORE any write to OR storage and emit a message
pointing the operator at this spec and ADR-007.

Once a real `EncryptionService` is implemented (see "MISSING-ADR-5" in
[audit-2026-05-20]), a follow-up change MUST decide whether to decrypt
during migration and re-encrypt via OR, or keep verbatim copy. That decision
belongs in the follow-up change, not here.

#### Scenario: Plaintext-credentials state confirmed at startup (current codebase)
- GIVEN no `OCA\OpenConnector\Service\EncryptionService` class exists in the codebase
- AND `lib/Db/Source.php` setters apply no encryption (verified by grep for `encrypt(` returning zero matches in `lib/Db/Source.php`)
- WHEN the migrator's startup assertion runs
- THEN the assertion passes
- AND the migrator copies the credential columns verbatim from `oc_openconnector_sources` to `oc_openregister_objects` with no transformation

#### Scenario: Encryption-was-introduced-since-this-spec aborts (defensive)
- GIVEN an `OCA\OpenConnector\Service\EncryptionService` class HAS BEEN ADDED to the codebase since this spec was written (hypothetical future state)
- WHEN the migrator's startup assertion runs and detects the class
- THEN it raises `\LogicException` "encryption layer introduced since chain B spec was written; the verbatim-copy strategy no longer applies — see ADR-007 follow-up and revise this requirement"
- AND no rows are written to OR

### Requirement: Owner field MUST be left null on every migrated object

Every OR object the migrator writes MUST have its `owner` column set to NULL, regardless of whether the source row carries a `userId` value.

Openconnector is a system-level integration platform; rows are not user-owned
data. The migrator MUST leave OR's `owner` column null (treated by OR as
system-owned) for every migrated object, regardless of whether the source row
has a `userId` value. The legacy `userId` value (where present on log entities
like CallLog/JobLog/EventMessage) MUST still be copied into the object's JSON
body as `userId` for provenance — only the OR-managed `owner` column is left
null.

#### Scenario: Job row migrates with null owner
- GIVEN a `oc_openconnector_jobs` row with `user_id='ruben'`
- WHEN the migrator processes it
- THEN the resulting OR object has `owner IS NULL`
- AND the object JSON body retains `userId: 'ruben'` as a regular property

#### Scenario: Source row migrates with null owner
- GIVEN a `oc_openconnector_sources` row (Source has no `userId` column)
- WHEN the migrator processes it
- THEN the resulting OR object has `owner IS NULL`

### Requirement: Migrator MUST NOT emit per-object audit-trail entries

The migrator MUST bypass OR's per-object audit-trail emission during the bulk migration and MUST emit exactly one summary audit-trail entry at the end of the run.

OR's `audit-trail-immutable` capability normally emits one audit-trail entry
per object write. For bulk migration this would multiply storage by ~2× and
take prohibitively long. The migrator MUST:

- Bypass per-object audit emission during the bulk INSERT.
- Emit exactly ONE summary audit-trail entry at the end of the migration with
  per-entity counts and the storage_migrated flag flip event.

This is permitted by the `audit-trail-immutable` spec's bulk-migration
exception.

#### Scenario: One summary audit entry per migration run
- GIVEN the migrator processes 4,193,847 CallLog rows + 15 other entities
- WHEN the migrator completes
- THEN exactly ONE audit-trail entry exists for the migration run
- AND that entry contains a `perEntity` summary with all 15 entities' counts

## Non-Functional Requirements

- **Performance:** Migration MUST process ≥ 4,000 rows/sec (sustained) for
  `call_log` on a postgres+SSD dev environment with 8GB RAM. Falls below
  threshold → mark as Risk-2 incident, do not block merge but flag for OCC
  command as preferred path over migration-time execution.
- **Read latency post-migration:** `find(int)` p95 latency through the
  facade MUST be no more than 1.5× the legacy SQL p95 baseline (measured by
  a PR-time PHPUnit perf test).
- **Internationalization:** This change touches no user-facing strings. The
  OCC command outputs in English only (operator audience). i18n N/A.
- **Idempotency:** The migration class MUST be safe to re-run after success;
  the migrator MUST detect already-migrated state and exit fast.

## Acceptance Criteria

- [ ] Migration class invokes `ConfigurationService::importFromApp(...)`
  exactly once per upgrade (REQ-001)
- [ ] All 15 entities migrate; row counts pre/post match (REQ-002)
- [ ] All 6 integer FKs rewrite to uuids; legacy `*Id` retained (REQ-003)
- [ ] All 3 Synchronization formats handled (REQ-004); unrecognised → skipped
  + logged
- [ ] `storage_migrated` flag flips only on full success (REQ-005)
- [ ] `ObjectMapperFacade` exposes the contract API (REQ-006)
- [ ] All 15 mappers preserve their public signature (REQ-007)
- [ ] OCC command runs dry-run + targeted retry (REQ-008)
- [ ] Legacy tables present and readable post-migration (REQ-009)
- [ ] Encrypted columns preserved bytes-for-bytes (REQ-010)
- [ ] Owner field null on every migrated object per REQ-011
- [ ] Single summary audit entry per migration run (REQ-012)
- [ ] PHPUnit tests cover migrator + facade (see test-plan.md)
- [ ] Manual dev-env smoke test passes

## Notes

- `CallLog.actionId` target schema is **NOT resolved** in this change — the
  field migrates as opaque integer; a parallel `action` string field is added
  with no `$ref`. Resolved by a follow-up change after call-site inspection
  in `lib/Service/CallService.php` and `EndpointService.php`. See DEFERRED Q2.
- FK rename `*Id` → target-schema-name is deferred to a follow-up change once
  frontend Vue stores are updated. Both names co-exist during this change
  per chain-A REQ-008. See DEFERRED Q1.
- Encryption layering for `Source` secrets is verified by static inspection
  at migrator startup (REQ-010); the provisional implementation is
  column-level (verbatim copy). See DEFERRED Q3.
- Owner field is left null on every migrated object — openconnector data is
  system-level, not per-user. Legacy `userId` is preserved in the object JSON
  body for provenance. DEFERRED Q4 resolved by user decision: null owner.
- Seed data for this change deviates from ADR-001 — the seed set IS the
  migrated live data. Rationale per ADR-001 line 46 documented in design.md.
- **Per-source retention unit conversion (P2-2 from 2026-05-20 audit):** The
  `Source` entity has `logRetention` (default 3600) and `errorRetention`
  (default 86400) columns expressed in **seconds**, while service-level
  retention constants in `JobService::DEFAULT_SUCCESS_LOG_RETENTION` and
  `DEFAULT_ERROR_LOG_RETENTION` are in **milliseconds** (3600000, 2592000000).
  When migrating `Source` rows, the migrator MUST convert the per-source
  override fields from seconds to the unit used by OR's archival annotations.
  Chain A's log schemas use ISO-8601 durations (e.g. `PT1H`, `P30D`); the
  per-source numeric overrides MUST be expressed in seconds in the migrated
  JSON body (the canonical unit), with a `unit: "seconds"` sibling field if
  needed for clarity. Cross-ref ADR-004 + `lib/Db/Source.php:50-51`.
