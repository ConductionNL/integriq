# Migration: openconnector-register-storage

## Current State

Before this change ships:

- `lib/Settings/openconnector_register.json` exists on disk (provided by
  chain A) but is not consumed by any migration class — it sits dormant.
- `oc_openconnector_*` tables (15 tables) hold all authoritative
  openconnector data. Each is managed by a hand-rolled mapper in `lib/Db/`.
- `oc_openregister_*` tables exist but contain no row with
  `register.slug='openconnector'`.
- 20+ controllers and services in `lib/Controller/` and `lib/Service/`
  consume the 15 mappers via the existing PHP API.

## Target State

After this change ships and the migration class runs in a deployment:

- `oc_openregister_registers` contains a row with `slug='openconnector'`.
- `oc_openregister_schemas` contains 15 rows for the 15 openconnector
  schemas.
- `oc_openregister_objects` contains every row from every
  `oc_openconnector_*` table, with UUIDs preserved and FK columns rewritten
  to relations.
- `openconnector.storage_migrated` app-config flag is `"true"`.
- All 15 mappers route their reads/writes through `ObjectMapperFacade` → OR
  `ObjectService` (because the flag is `true`).
- `oc_openconnector_*` tables remain on disk, readable but not written to by
  application code. Held as a rollback buffer for one release.

## Migration Class

```
Version: 2Date20260520xxxxxx          (final timestamp chosen at apply commit time)
File:    lib/Migration/Version2Date20260520xxxxxx.php

Implements OCP\Migration\IMigrationStep.

changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    No schema changes to oc_openconnector_* tables in this change.
    Return null (no schema diff).

postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    1. Resolve absolute path to lib/Settings/openconnector_register.json.
    2. Call ConfigurationService::importFromApp('openconnector', $path, $appVersion, false).
    3. Instantiate LegacyToRegisterMigrator via DI container.
    4. Call $migrator->migrateAll(dryRun=false, entitySlug=null, batchSize=10000).
    5. If migrator returns success (no entity failed):
         $appConfig->setValue('openconnector', 'storage_migrated', 'true');
       Else:
         $output->warning('Storage migration partial — flag NOT set; re-run via OCC');
    6. Emit final IOutput summary line with per-entity counts.

name(): string
    'Migrate openconnector storage to OpenRegister'

description(): string
    'Provisions openconnector_register, then copies oc_openconnector_* into oc_openregister_objects with FK rewrites.'

Key dependencies (constructor):
- IDBConnection
- IAppConfig
- IConfig
- LoggerInterface
- ConfigurationService (from OCA\OpenRegister\Service)
- LegacyToRegisterMigrator (this change)
```

## Migration Steps

Ordered, each atomic. The migration class executes 1–8 sequentially; the OCC
command can re-run 4–7 against a single entity.

1. **Validate** chain-A descriptor exists at
   `<appPath>/lib/Settings/openconnector_register.json`. Abort if missing.

2. **Provision register/schemas** via
   `ConfigurationService::importFromApp(...)`. Idempotent — re-runs are no-ops.

3. **Verify register PK** — `SELECT id FROM oc_openregister_registers WHERE
   slug='openconnector'`. Cache for steps 5–6.

4. **Verify schema PKs** — one query per schema; build a map
   `slug → schema_id`. Cache for steps 5–6.

5. **Bulk INSERT pass** (per entity, sequential — strict order to satisfy
   FK rewrite ordering in step 6):
   - source, consumer, endpoint, event, event_subscription, job, mapping, rule
     (no integer FKs; safe to run first; provides the lookup targets for step 6)
   - synchronization (with sourceId/targetId branching per REQ-004)
   - synchronization_contract (string FK to synchronization, already-resolved)
   - event_message (integer FKs to event, consumer, event_subscription)
   - call_log (integer FKs to source, synchronization; actionId opaque)
   - job_log (string FK to job, already-resolved)
   - synchronization_log (string FK to synchronization, already-resolved)
   - synchronization_contract_log (string FK to synchronization, contract, log
     — already-resolved)

   For each: batch SELECT 10,000 rows; INSERT into oc_openregister_objects
   with platform-specific JSON_OBJECT / jsonb_build_object; log per-batch
   progress; per-entity row-count assertion at end.

6. **FK rewrite pass** (the 6 integer FK columns per REQ-003): per relation,
   one UPDATE that JOINs the OR object back to the legacy target table by
   the integer id, sets the relation-named field to the target uuid.

7. **Owner column** (REQ-011): no backfill step required — the INSERT in step 5
   writes `owner = NULL` for every row. Openconnector data is system-level;
   legacy `userId` values remain inside the object JSON body as ordinary
   properties for provenance.

8. **Set flag** (REQ-005): `IAppConfig::setValue('openconnector',
   'storage_migrated', 'true')`. ONLY if steps 5–7 completed without per-entity
   failures.

9. **Audit summary** (REQ-012): single audit-trail entry with the
   per-entity counts JSON.

## Data Impact

| Entity                          | Pre rows (legacy) | Post rows (OR)     | Operation              | Live-data safety |
|---------------------------------|-------------------|---------------------|------------------------|------------------|
| source                          | ~10–100           | +same               | Bulk INSERT            | Safe             |
| consumer                        | ~5–20             | +same               | Bulk INSERT            | Safe             |
| endpoint                        | ~10–100           | +same               | Bulk INSERT            | Safe             |
| event                           | ~10K–100K         | +same               | Bulk INSERT            | Safe             |
| event_subscription              | ~5–50             | +same               | Bulk INSERT            | Safe             |
| job                             | ~10–50            | +same               | Bulk INSERT            | Safe             |
| mapping                         | ~10–100           | +same               | Bulk INSERT            | Safe             |
| rule                            | ~10–100           | +same               | Bulk INSERT            | Safe             |
| synchronization                 | ~5–50             | +same (some skipped)| Bulk INSERT + branch   | Some skipped     |
| synchronization_contract        | ~100–10K          | +same               | Bulk INSERT            | Safe             |
| event_message                   | ~10K–100K         | +same               | Bulk INSERT + FK rewrite | Safe           |
| call_log                        | ~100K–10M         | +same               | Bulk INSERT + FK rewrite | Safe (batched) |
| job_log                         | ~10K–1M           | +same               | Bulk INSERT            | Safe             |
| synchronization_log             | ~10K–1M           | +same               | Bulk INSERT            | Safe             |
| synchronization_contract_log    | ~10K–1M           | +same               | Bulk INSERT            | Safe             |

**Data loss risk: zero** — every legacy row is copied; uuid preserved; legacy
tables remain on disk.

**Data transformation:** integer FKs rewritten to uuids (REQ-003); overloaded
sourceId/targetId branched (REQ-004). Verified per scenario in spec.

**Can it run on live data?** Yes — the strangler-fig pattern (flag-gated)
ensures the legacy path stays authoritative throughout the migration. The
flag flips atomically only after every entity completes.

## Rollback Procedure

### Pre-flag rollback (migration failed mid-run)

```sql
-- Optional: drop the partial OR data to allow a clean retry.
DELETE FROM oc_openregister_objects
 WHERE register IN (SELECT id FROM oc_openregister_registers WHERE slug='openconnector');
```

Then re-run via OCC: `occ openconnector:migrate-storage`.

### Post-flag rollback (within the one-release transition window)

```bash
# Switch all mappers back to the legacy table path.
occ config:app:set openconnector storage_migrated --value=false
```

This atomically reverts every mapper to the legacy SQL path. Legacy tables
still hold authoritative data (read-only-by-convention since the flag flipped,
but the rows are intact). Any rows written through OR AFTER the flag flipped
do NOT auto-copy back; admins must manually export them via
`occ openconnector:export --entity=<slug>` (a feature added in this change's
OCC command set) and bulk-insert into the legacy tables.

### After the cleanup change ships (one release later)

Rollback path is removed. Legacy tables are dropped; storage_migrated flag is
removed. The cleanup change documents that operators MUST run the migration
in release N before upgrading to release N+1.

### Schema-level rollback (unlikely but possible)

If the chain-A descriptor is itself broken AND was already imported by this
change, the recovery is:

```sql
DELETE FROM oc_openregister_objects WHERE register IN (SELECT id FROM oc_openregister_registers WHERE slug='openconnector');
DELETE FROM oc_openregister_schemas WHERE register IN (SELECT id FROM oc_openregister_registers WHERE slug='openconnector');
DELETE FROM oc_openregister_registers WHERE slug='openconnector';
```

Then ship a fixed chain-A descriptor and re-run the migration. The legacy
tables provide the source-of-truth throughout.

## Validation

| Check                                                                                         | Method                                                                       | Expected result                                  |
|-----------------------------------------------------------------------------------------------|------------------------------------------------------------------------------|--------------------------------------------------|
| Register row created                                                                          | `SELECT * FROM oc_openregister_registers WHERE slug='openconnector'`         | One row                                          |
| 15 schemas provisioned                                                                        | `SELECT count(*) FROM oc_openregister_schemas WHERE register=<oc-register-pk>` | `15`                                             |
| Row count parity, source                                                                      | Compare `count(*)` legacy vs OR for source                                   | Equal                                            |
| Row count parity, all 15 entities                                                             | Compare `count(*)` per entity (15 queries)                                   | All equal                                        |
| UUID preservation                                                                             | Sample 10 random uuids from legacy table; assert each exists in OR `oc_openregister_objects` | All 10 found                       |
| FK rewrite: call_log.source                                                                   | `jsonb_path_query_array(object, '$.source')` on 100 random CallLog OR objects | All non-null, all match a known source uuid     |
| sourceId branching: integer-PK case                                                           | Find a sync row with `source_id='42'` legacy; assert OR object has `sourceId` = uuid of source #42 | Match                          |
| sourceId branching: register/schema case                                                      | Find a sync row with `source_id='zaken/zaak'` legacy; assert OR object has `sourceId='zaken/zaak'` | Match unchanged                |
| Append-only enforcement on logs                                                                | `UPDATE oc_openregister_objects SET object=jsonb_set(object,'{message}','"hacked"') WHERE schema=<call_log-pk> LIMIT 1` | Reject (`AppendOnlyException`) |
| Flag set                                                                                      | `occ config:app:get openconnector storage_migrated`                          | `true`                                            |
| Rollback works                                                                                 | Set flag false; call `SourceMapper::find(1)` from a quick `occ` shim          | Returns legacy-table row                          |
| Encrypted columns intact                                                                       | Decrypt a known `Source.apikey` via the existing decrypt service against OR object's `apikey` field | Same plaintext               |
| Audit-trail bulk entry                                                                         | `SELECT * FROM oc_openregister_audit_trail WHERE action='migrate' ORDER BY created DESC LIMIT 1` | One entry with `perEntity` JSON |
