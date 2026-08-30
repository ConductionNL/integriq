# Design: openconnector-register-storage

## Architecture Overview

```
                                 ┌─────────────────────────────────────────┐
                                 │  NEXTCLOUD UPGRADE (occ upgrade)         │
                                 └────────────────────┬────────────────────┘
                                                      │
                                                      ▼
                       lib/Migration/Version2Date20260520xxxxxx.php
                                                      │
                            ┌─────────────────────────┼────────────────────────┐
                            ▼                         ▼                         ▼
              ConfigurationService:: import   LegacyToRegisterMigrator   IAppConfig::setValue(
              FromApp('openconnector', …)       ::migrateAll()             'openconnector',
                            │                         │                    'storage_migrated',
                            ▼                         ▼                    'true')
              + register + 15 schemas        15× batched INSERT…SELECT
              + ~33 seed rows                + 6× FK rewrite UPDATE
                                              + 1× sourceId/targetId branching
                                              + per-batch progress log

                                                      │
                                                      ▼
   Runtime read/write path (per mapper)               │
                                                      ▼
   ┌─ legacy path ──────────────────┐   ┌─ OR path ───────────────────────────┐
   │ if !storage_migrated:           │   │ if storage_migrated:                 │
   │   QBSourceMapper::findAll()    │ → │   ObjectMapperFacade::findAll('source')│
   │   (hits oc_openconnector_*)    │   │   → ObjectService::find('openconnector','source',…)│
   │                                │   │   → hydrate as Source entity         │
   └────────────────────────────────┘   └──────────────────────────────────────┘
```

The single switch is the `openconnector.storage_migrated` app-config flag,
cached in each mapper at construction time. Until the migrator sets it to
`true`, every mapper uses its current legacy SQL implementation. After it
flips, every mapper delegates to `ObjectMapperFacade`.

## Declarative-vs-imperative decision (ADR-031)

This change is **imperative by necessity** — a one-shot bulk data migration
from one storage layout to another cannot be expressed declaratively. ADR-031
permits imperative implementations under the **"scheduled bulk work"
exception**, with the rephrasing: this is one-shot bulk work, executed once
per deployment as part of a Nextcloud upgrade migration step. It is the
canonical Nextcloud-app pattern for storage refactors and is the same pattern
adopted by every other Conduction app that has moved off legacy tables.

What IS declarative (and lives in chain A, not here):
- Schema shape, relations, retention, append-only, immutability.
- Seed-data contents.

What is imperative (this change):
- The migrator's row-by-row INSERT…SELECT and FK rewrite passes.
- The mapper facade's runtime dispatch between legacy and OR paths.
- The OCC command.

The facade-with-flag pattern is the imperative half of a textbook
strangler-fig migration: declarative target (chain A), imperative bridge
(this change), declarative future state once the cleanup change drops the
legacy tables.

## API Design

### `POST /api/admin/openconnector/migrate-storage`
**Auth**: Admin session (Nextcloud `IsAdmin` annotation).

**Request body:**
```json
{
  "dryRun": false,
  "entity": null,
  "batchSize": 10000
}
```

**Response (200):**
```json
{
  "started": "2026-05-20T12:00:00+00:00",
  "completed": "2026-05-20T12:04:37+00:00",
  "perEntity": [
    {"slug": "source", "legacyCount": 12, "migratedCount": 12, "skipped": 0, "fkRewrites": 0},
    {"slug": "call_log", "legacyCount": 4193847, "migratedCount": 4193847, "skipped": 0, "fkRewrites": 8387694}
  ],
  "flagSet": true
}
```

**Errors:**
| Code | Condition                                                        |
|------|------------------------------------------------------------------|
| 400  | Unknown `entity` slug, negative `batchSize`, malformed `dryRun`  |
| 401  | Not authenticated                                                |
| 403  | Authenticated user is not an admin                               |
| 409  | `storage_migrated` already `true` AND `entity` not specified     |
| 500  | Migration failure mid-run; response body includes per-entity tally up to failure point |

The HTTP endpoint is a thin wrapper around `LegacyToRegisterMigrator::migrate()`
that the OCC command also calls. Provided for admins who prefer a UI button
over CLI access.

## Database Changes

No schema changes to `oc_openconnector_*` tables in this change (they remain
read-only via app code, NOT via a DB-level constraint). No new openconnector
tables.

`oc_openregister_*` tables gain rows but their schema is unchanged. After the
migrator runs in production:

```sql
-- New rows in OR's tables:
SELECT count(*) FROM oc_openregister_registers WHERE slug='openconnector';
-- = 1

SELECT count(*) FROM oc_openregister_schemas
 WHERE register = (SELECT id FROM oc_openregister_registers WHERE slug='openconnector');
-- = 15

SELECT count(*) FROM oc_openregister_objects
 WHERE register = (SELECT id FROM oc_openregister_registers WHERE slug='openconnector');
-- = (legacy row counts summed across 15 entities)
```

The migration class is a versioned Nextcloud migration:

```
Version: 2Date20260520xxxxxx   (placeholder — final timestamp chosen at apply time)
File:    lib/Migration/Version2Date20260520xxxxxx.php
Schema:  no schema changes
Pre:     ConfigurationService::importFromApp(...)   // idempotent
Post:    LegacyToRegisterMigrator::migrateAll(force=false, dryRun=false)
         IAppConfig::setValue('openconnector','storage_migrated','true')
```

## Nextcloud Integration

- **Controllers:** new `MigrateStorageController` (1 endpoint, admin-only) at
  `lib/Controller/MigrateStorageController.php`.
- **Services:**
  - new `LegacyToRegisterMigrator` at `lib/Service/Migration/LegacyToRegisterMigrator.php`
  - new `ObjectMapperFacade` at `lib/Service/Storage/ObjectMapperFacade.php`
- **Mappers/Entities:** 15 existing mappers in `lib/Db/*Mapper.php` rewritten
  as facades. Entity classes unchanged (used as DTOs).
- **Events/Hooks:** None. The migrator does not emit events to avoid triggering
  webhook delivery against partial-migration state.
- **OCC command:** new at `lib/Command/MigrateToOpenRegister.php`.
- **OCP services used:**
  - `IDBConnection` (for raw INSERT…SELECT batches)
  - `IAppConfig` (storage_migrated flag)
  - `IConfig` (admin user lookup for owner fallback)
  - `LoggerInterface` (progress + skip logging)
  - `OCA\OpenRegister\Service\ConfigurationService` (importer)
  - `OCA\OpenRegister\Service\ObjectService` (read/write through facade)

## Security Considerations

- **Admin-only migration trigger.** The HTTP endpoint and OCC command both
  require admin/CLI access; no anonymous re-run.
- **Encrypted column handling** (Risk 1) — DEFERRED Q3. If column-level
  encryption: secrets remain encrypted at rest before AND after migration;
  Entity `setApikey()` etc. hooks the encryption invisibly. If storage-level:
  the migrator must decrypt during read and re-encrypt during write via OR's
  storage layer. Provisional choice: column-level. Verified by reading
  `lib/Db/Source.php` for setter overrides during apply.
- **Owner field is null** (Risk 4 resolved). Openconnector is a system-level
  integration platform; rows are not user-owned data. The migrator writes
  `owner = NULL` on every object (OR treats null owner as system-level).
  Legacy `userId` values on log entities (CallLog/JobLog/EventMessage etc.)
  are preserved in the object's JSON body for provenance. Permission checks
  for openconnector objects fall back to OR's system-level rules; if any
  consumer relies on `owner`-based ACLs, that breaks visibly and is handled
  in a follow-up.
- **No new auth surface end-user side.** Mapper API unchanged; same auth
  guards apply.
- **Audit-trail enforcement.** Logs migrated under `appendOnly + immutable`
  flags begin enforcing OR's `AppendOnlyException` immediately after the
  storage_migrated flag flips; any code path that tries to UPDATE a log row
  will raise.

## NL Design System

No UI changes in this scope. The `POST /api/admin/openconnector/migrate-storage`
endpoint is administrator-facing and may be surfaced via a Vue
admin-settings panel in a follow-up change.

## File Structure

```
openconnector/
├── lib/
│   ├── Command/
│   │   └── MigrateToOpenRegister.php                    [NEW]
│   ├── Controller/
│   │   └── MigrateStorageController.php                 [NEW]
│   ├── Db/
│   │   ├── SourceMapper.php                             [REWRITTEN — facade]
│   │   ├── ConsumerMapper.php                           [REWRITTEN]
│   │   ├── EndpointMapper.php                           [REWRITTEN]
│   │   ├── EventMapper.php                              [REWRITTEN]
│   │   ├── EventMessageMapper.php                       [REWRITTEN]
│   │   ├── EventSubscriptionMapper.php                  [REWRITTEN]
│   │   ├── JobMapper.php                                [REWRITTEN]
│   │   ├── JobLogMapper.php                             [REWRITTEN]
│   │   ├── MappingMapper.php                            [REWRITTEN]
│   │   ├── RuleMapper.php                               [REWRITTEN]
│   │   ├── SynchronizationMapper.php                    [REWRITTEN]
│   │   ├── SynchronizationContractMapper.php            [REWRITTEN]
│   │   ├── SynchronizationContractLogMapper.php         [REWRITTEN]
│   │   ├── SynchronizationLogMapper.php                 [REWRITTEN]
│   │   └── CallLogMapper.php                            [REWRITTEN]
│   ├── Migration/
│   │   └── Version2Date20260520xxxxxx.php               [NEW]
│   └── Service/
│       ├── Migration/
│       │   └── LegacyToRegisterMigrator.php             [NEW]
│       └── Storage/
│           └── ObjectMapperFacade.php                   [NEW]
└── tests/
    ├── Unit/
    │   └── Service/
    │       └── Migration/
    │           └── LegacyToRegisterMigratorTest.php     [NEW]
    └── Integration/
        └── Service/
            └── Storage/
                └── ObjectMapperFacadeTest.php           [NEW]
```

## Seed Data

Seed data for this change is the **migrated live data** — by definition. This
deviates from ADR-001's "every change ships seed data" rule because the
authoritative seed set is whatever lives in the legacy tables at migration
time, not a hand-crafted set. **Deviation rationale documented per ADR-001 line
46.**

For dev-env testing the migrator runs against the chain-A seed objects (33
records). For production it runs against live data (potentially millions of
log rows).

If chain A's seed data is insufficient for dev testing of the facade (e.g. no
seeded CallLog because chain A explicitly excludes log seeds), the facade tests
write throwaway CallLog rows directly through the post-migration OR path —
exercising both the migrator's idempotency and the facade's append-only
enforcement.

## Trade-offs

| Alternative considered                                                | Chosen?   | Reasoning |
|-----------------------------------------------------------------------|-----------|-----------|
| Strangler-fig migration (this design)                                 | Yes       | Industry-standard for storage refactors; reversible during transition. |
| Big-bang cutover (no flag, no legacy fallback)                        | No        | No rollback path; production CallLog volumes (millions) make multi-hour migration windows risky. |
| Keep legacy tables forever (no migration, OR objects synced to them)  | No        | Doubles storage; sync drift is a constant maintenance burden. |
| Rewrite all 20+ services + controllers to call OR directly             | No        | 10× the code change; gives up backwards compatibility for no architectural win — the mapper API IS the abstraction. |
| Per-entity migration in separate releases (e.g. ship source first)    | No        | FK rewrites require both endpoints present — must move them together. |
| `appendOnly` enforcement at PHP level instead of OR level             | No        | Defeats the purpose; OR's `AppendOnlyException` is the canonical guarantee. |
| Decrypt+re-encrypt during migration unconditionally                   | No        | Risks data corruption if column-level encryption is in use. Confirm Risk 1 first; switch implementation only if storage-level is confirmed. |
| Set owner = null on every migrated object                             | Yes       | Openconnector data is system-level, not user-owned. Null owner is OR's idiomatic representation of system-level objects. Legacy `userId` is preserved in object JSON for provenance. |
| Use row's `userId` with admin fallback                                | No        | Considered; rejected because the choice conflates row authorship (often the user who triggered a sync) with ownership (administrative responsibility). Openconnector administrators, not row-triggers, own this data. |
| Set owner = system user unconditionally                               | No        | OR-idiomatic alternative for system data is null, not a synthetic user UID. |
| In-memory FK rewrite (load all rows into PHP, rewrite, INSERT)        | No        | OOMs on CallLog volumes. SQL JOIN rewrite stays in DB. |
| Use OR's `ObjectService::createFromArray` for bulk insert             | No        | Per-object cost (validation, audit trail, hooks) is prohibitive at million-row scale. Bulk SQL INSERT goes around these. The migrator is a one-shot exception. |
