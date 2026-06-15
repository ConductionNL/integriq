# Contract: openconnector-register-storage

## Consumers

This change introduces two new HTTP surfaces (admin-only migration trigger) and
preserves the existing mapper-level PHP API used by every openconnector
controller and service. Cross-app consumers gain access to openconnector
entities through OR's standard `/api/objects/openconnector/{schema}/{uuid}`
surface AFTER this change ships.

- `openconnector` (internal) — 20+ controllers and services use the existing
  mapper API; this contract guarantees that API is preserved across the
  migration. They do not need to know which storage path is active.
- `decidesk`, `pipelinq`, `procest`, `docudesk` (downstream apps) — gain
  read/write access to openconnector entities through OR's existing
  `/api/objects/{register}/{schema}/{uuid}` endpoints. No openconnector-side
  endpoint is added for them.
- Nextcloud administrators (operators) — invoke the migration via OCC or HTTP.

## Endpoints

### `POST /api/admin/openconnector/migrate-storage`
**Auth**: Nextcloud admin session (`@AuthorizedAdminSetting(settings=Admin::class)`).

**Request:**
```json
{
  "dryRun": false,
  "entity": null,
  "batchSize": 10000
}
```

| Field      | Type                 | Required | Default | Description                                                         |
|------------|----------------------|----------|---------|---------------------------------------------------------------------|
| dryRun     | boolean              | no       | false   | If true, count rows but do not write to OR; useful for sizing.      |
| entity     | string \| null       | no       | null    | One of the 15 schema slugs to migrate; null = all entities.         |
| batchSize  | integer (100–100000) | no       | 10000   | Rows per INSERT…SELECT batch.                                       |

**Response (200):**
```json
{
  "started": "2026-05-20T12:00:00+00:00",
  "completed": "2026-05-20T12:04:37+00:00",
  "dryRun": false,
  "perEntity": [
    {
      "slug": "source",
      "legacyCount": 12,
      "migratedCount": 12,
      "skipped": 0,
      "skippedReason": null,
      "fkRewrites": 0,
      "elapsedMs": 47
    },
    {
      "slug": "synchronization",
      "legacyCount": 8,
      "migratedCount": 7,
      "skipped": 1,
      "skippedReason": "sourceId format unrecognised (row id=4)",
      "fkRewrites": 0,
      "elapsedMs": 12
    },
    {
      "slug": "call_log",
      "legacyCount": 4193847,
      "migratedCount": 4193847,
      "skipped": 0,
      "skippedReason": null,
      "fkRewrites": 8387694,
      "elapsedMs": 273482
    }
  ],
  "flagSet": true
}
```

**Errors:**

| Code | Condition                                                                  |
|------|----------------------------------------------------------------------------|
| 400  | `entity` not one of 15 valid slugs, `batchSize` outside [100, 100000], malformed body |
| 401  | Caller not authenticated                                                   |
| 403  | Caller authenticated but not admin                                         |
| 409  | `storage_migrated` flag already `true` AND `entity` not specified (use `entity` to retry a single slug) |
| 500  | Migration failure mid-run; response includes per-entity tally up to failure point AND `error` field with stack-trace-truncated message |

### `GET /api/admin/openconnector/migrate-storage/status`
**Auth**: Admin only (same annotation).

**Request:** no body.

**Response (200):**
```json
{
  "storageMigrated": true,
  "flagSetAt": "2026-05-20T12:04:37+00:00",
  "perEntityRowCounts": [
    {"slug": "source", "legacy": 12, "register": 12},
    {"slug": "call_log", "legacy": 4193847, "register": 4193847}
  ],
  "readOnlyLockActive": true
}
```

**Errors:**
| Code | Condition                       |
|------|----------------------------------|
| 401  | Not authenticated                |
| 403  | Not admin                        |

## Error Codes

| Code | Meaning                                  | Condition                                                              |
|------|------------------------------------------|------------------------------------------------------------------------|
| 400  | Bad request                              | Malformed body, invalid entity slug, batchSize out of bounds          |
| 401  | Unauthenticated                          | No Nextcloud session                                                   |
| 403  | Not authorised                           | Session present but not an admin                                      |
| 409  | Conflict                                 | Migration already flag-flipped; full-run retry blocked                |
| 500  | Internal migrator failure                | Mid-run exception; partial results returned                            |

## PHP Internal API Contract (preserved, NOT changed)

The 15 `*Mapper.php` classes preserve their public method signatures
exactly. After this change ships, every method below remains callable with
identical args and identical return types — only the implementation is
swapped behind a flag.

| Class                              | Preserved methods (representative)                                          |
|------------------------------------|-----------------------------------------------------------------------------|
| `SourceMapper`                     | `find(int)`, `findAll(...)`, `findByUuid(string)`, `findBySlug(string)`, `createFromArray(array)`, `updateFromArray(int, array)`, `delete(Source)` |
| `ConsumerMapper`                   | `find(int)`, `findAll(...)`, `findByUuid(string)`, `createFromArray(array)`, `updateFromArray(int, array)`, `delete(Consumer)` |
| `EndpointMapper`                   | same shape                                                                  |
| `EventMapper`                      | same shape                                                                  |
| `EventMessageMapper`               | same shape + `findPending()`, `findByEventId(int)`                          |
| `EventSubscriptionMapper`          | same shape                                                                  |
| `JobMapper`                        | same shape + `findDueJobs()`                                                |
| `JobLogMapper`                     | same shape + append-only enforced (UPDATE/DELETE throws `LogicException`)   |
| `MappingMapper`                    | same shape                                                                  |
| `RuleMapper`                       | same shape + `findByTiming(string)`, `findByAction(string)`                 |
| `SynchronizationMapper`            | same shape + `findByReference(string)`                                       |
| `SynchronizationContractMapper`    | same shape + `findByOriginId(string)`, `findBySynchronizationId(string)`     |
| `SynchronizationContractLogMapper` | same shape + append-only enforced                                            |
| `SynchronizationLogMapper`         | same shape + append-only enforced                                            |
| `CallLogMapper`                    | same shape + append-only enforced + `findExpired()`                          |

A mapper method that previously raised `OCP\AppFramework\Db\DoesNotExistException`
will continue to raise that exact exception class after migration. A mapper
method that previously returned `Source` will return a `Source` after
migration. Type signatures are byte-for-byte preserved.

## Versioning

- **API version**: `v1` (path-implicit; no `/v1/` prefix in this change, matches
  existing openconnector convention).
- **Migration class version**: `Version2Date20260520xxxxxx` (final timestamp
  chosen at apply commit time). Versioned by Nextcloud's migration framework —
  one-shot, idempotent.
- **App-config flag**: `openconnector.storage_migrated` (string `"true"` /
  `"false"`).

## Breaking Change Policy

This change preserves the mapper PHP API. The only breaking surface is:

- **Append-only enforcement** on the 4 log mappers — code paths that
  previously called `JobLogMapper::updateFromArray()` (allowed before, no-op or
  error after migration) now raise `LogicException` (post-flag) or
  `OCA\OpenRegister\Exception\AppendOnlyException` (after legacy table
  read-only lock).
- **`oc_openconnector_*` table writes** — admin-tool direct SQL UPDATEs are
  blocked. Existing app code does not depend on this; documented for operator
  awareness.

Cleanup change (one release later) drops the legacy tables and removes the
`storage_migrated` flag — at that point the breaking surface is the gap
between the two releases (operators MUST run the migration in release N
before upgrading to release N+1). Documented in release notes.

## SLA

- **Migration runtime**: dependent on legacy data volume. Empirical baselines:
  - ~5,000 rows/sec INSERT…SELECT on dev laptop (postgres + SSD).
  - 1M CallLog rows ≈ 3 minutes; 10M rows ≈ 30 minutes.
  - FK rewrite pass is roughly equal time (one UPDATE per FK column per row).
- **Migrator HTTP timeout**: `POST /api/admin/openconnector/migrate-storage`
  may run for many minutes. Admin UI must use a background-job pattern (not a
  blocking request) for large datasets. The OCC command is the preferred path
  for production data volumes.
- **Read latency post-migration**: facade adds ≤50% overhead vs legacy SQL on
  `findById` (one extra hash lookup + ObjectService dispatch). Measured in
  Risk-5's perf test.
- **Write latency post-migration**: facade adds 50–200ms vs legacy SQL on
  `createFromArray` (OR validates, hashes, emits audit trail). Acceptable
  given audit-trail value.
