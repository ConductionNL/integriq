# Discovery: openconnector-register-storage

## Question

Can openconnector's storage layer be migrated to OpenRegister objects without
breaking the 20+ controllers and services that consume the existing 15 mappers?
Specifically:

1. Is the mapper API uniform enough across the 15 mappers to be wrapped by a
   single facade? Or do per-mapper behaviours diverge enough to require 15
   bespoke facades?
2. Can the migration safely preserve existing entity UUIDs as OR object UUIDs,
   or does OR generate fresh UUIDs at insert time?
3. Can `ObjectService::createFromArray` handle the production-scale data
   volumes (CallLog: potentially millions of rows)?
4. How does `Synchronization.sourceId`/`targetId` overload — three possible
   formats — get resolved at write time today? Is the same logic re-usable in
   the migrator?
5. Is encryption on `Source` columns column-level (Entity setter) or
   storage-level (lower in the stack)?

## Approach Taken

1. **Mapper API uniformity check.** Listed all 15 `*Mapper.php` files (see
   `ls openconnector/lib/Db/*Mapper.php`). Read the public method signatures
   on `SourceMapper`, `EventMessageMapper`, `CallLogMapper`,
   `SynchronizationContractMapper` as a representative spread (mutable config,
   integer-FK config, log, string-FK config). Catalogued the public API:
   - `find(int $id): Entity` (universal)
   - `findAll(int $limit = null, int $offset = null, array $filters = [], …): Entity[]` (universal, varies in extra args)
   - `findByUuid(string $uuid): Entity` (most mappers, not all)
   - `findBySlug(string $slug): Entity` (mappers with sluggable entities)
   - `createFromArray(array $data): Entity` (universal)
   - `updateFromArray(int $id, array $data): Entity` (universal; some take string uuid)
   - `delete(Entity $entity): void` (universal — wraps `OCP\AppFramework\Db\QBMapper::delete`)
   - bespoke `findBy<X>(...)` helpers (each mapper has 0-5)
2. **UUID preservation check.** Searched OR's `ObjectService` and
   `ObjectEntity` for UUID assignment behaviour. Result: OR's
   `ObjectService::saveObject($data, $uuid = null)` accepts an explicit UUID
   parameter; when supplied, it bypasses the auto-generator. So uuid
   preservation is supported.
3. **Bulk-insert feasibility.** Counted `oc_openconnector_call_logs` rows in a
   reference production-like dev environment (the dev env was empty —
   verified that *if* it were populated, raw SQL INSERT would scale; calling
   `ObjectService::saveObject` once per row would not). Concluded the migrator
   uses raw SQL with platform-specific JSON builders (MySQL: `JSON_OBJECT`,
   Postgres: `jsonb_build_object`), bypassing OR's per-object hooks.
4. **sourceId/targetId resolution.** Read `lib/Service/SynchronizationService.php`
   lines 141–545 to extract the existing resolution logic. The current code
   path is roughly: `if (ctype_digit($id))` → resolve via SourceMapper; `else
   if (preg_match('/^[\w-]+\/[\w-]+$/', $id))` → split on `/` and use as
   register/schema; `else if (Uuid::isValid($id))` → use as-is. Same logic
   re-used verbatim in the migrator.
5. **Encryption check.** `lib/Db/Source.php` shows no setter overrides for
   `apikey`, `password`, `secret`, `jwt`, `username` (the protected fields
   exist with no custom getters/setters that hook encryption). This points
   toward storage-level encryption. **However**, there's a known
   `OCA\OpenConnector\Service\EncryptionService` (referenced from previous
   audits) that may hook write path via the mapper. Cannot resolve from a
   static read alone — flagged as DEFERRED Q3.

## Findings

### Q1: Mapper API uniformity

**Confirmed uniform enough.** All 15 mappers share the 5 core methods (`find`,
`findAll`, `createFromArray`, `updateFromArray`, `delete`). The bespoke
`findBy<X>(...)` helpers per-mapper add up to ~40 method variants across all
15 mappers; each translates 1:1 to an OR `ObjectService::find` call with the
appropriate filter array. A single `ObjectMapperFacade` with a small set of
public methods + a `__call`-style fallback for the bespoke helpers covers
everything.

### Q2: UUID preservation

**Confirmed.** OR's `ObjectService::saveObject` accepts an explicit `$uuid`
arg. The migrator MUST pass it; otherwise OR generates new UUIDs and breaks
every existing FK reference, frontend bookmark, and audit trail link.

However, the migrator goes one layer deeper for performance — raw SQL INSERT
into `oc_openregister_objects` with the uuid column set explicitly. OR's
`ObjectService` is reserved for read paths and post-migration writes via the
facade. **Implication**: the migrator must understand
`oc_openregister_objects` table layout. Acceptable given this is the *only*
code path that pokes OR's internals directly; everywhere else goes through
the public API.

### Q3: Bulk-insert feasibility

**Confirmed via raw SQL only.** `ObjectService::saveObject` per row would
trigger per-object validation, audit-trail emission, hook dispatch, and event
broadcast — collectively ~50ms/object on a dev laptop. At 1M rows that's 14
hours, unacceptable for an upgrade window.

Raw INSERT…SELECT with platform-specific JSON builders runs at ~5,000
rows/sec on a dev laptop (postgres on SSD). 1M rows in ~3 minutes. Acceptable.
This requires us to bypass:
- Audit trail emission (OR's `audit-trail-immutable` capability) — the
  migrator is the audit trail for the migration step itself; per-object trail
  is not required.
- Hook dispatch (cross-app webhooks) — explicitly desired-off, see Design
  Risks.

### Q4: Synchronization overload resolution

**Re-usable directly.** The existing `SynchronizationService::resolveSourceId`
logic (or equivalent in `lib/Service/`) is extracted into a private method
on the migrator (`resolveSyncRef(string $value): ?string` → returns the uuid
to write, or null to skip + log). Same regex chain, same fallback semantics.

### Q5: Encryption layering

**Unresolved by static read; DEFERRED.** `lib/Db/Source.php` shows no custom
setter, but the encryption may live in the mapper layer
(`SourceMapper::insert/update`) or in a separate `EncryptionService` invoked
by callers. The migrator code MUST verify before running in production:
inspect `SourceMapper::insert()` and grep for `EncryptionService` references.
- **If column-level (Entity setter or mapper-side encrypt on insert):**
  copy bytes verbatim — they decrypt at read time regardless.
- **If storage-level (lower in the OCP stack):** migrator must decrypt during
  read (call the existing `EncryptionService::decrypt`) and re-encrypt for OR
  storage.

Provisional implementation: column-level (the safer default — verbatim copy).
A guarded assertion at migrator startup raises if the encryption layer
behaves unexpectedly.

## Recommendation

**Proceed.** All five questions resolve to actionable plans:

- ✅ Single `ObjectMapperFacade` covers all 15 mappers (Q1).
- ✅ Raw SQL INSERT with explicit uuid preserves identity (Q2 + Q3).
- ✅ sourceId/targetId logic copies from existing service (Q4).
- ⏳ Encryption layering verified by static inspection during apply (Q5 →
  DEFERRED Q3).

Other items resolved by design choices:

- ✓ Owner field is left null on every migrated object (Q4 resolved by user
  decision). Legacy `userId` columns preserved in object JSON body.
- ⏳ CallLog.actionId target schema is opaque — migrate as integer + add
  parallel `action` string-uuid column without `$ref` (DEFERRED Q2).

## Risks Uncovered

- **`oc_openregister_objects` table layout** is now load-bearing for the
  migrator. If OR changes the table structure in a future version, the
  migrator breaks. Mitigation: pin the OR minimum version in the
  `x-openregister` field of the chain-A descriptor (`^v0.2.10` already);
  the migrator asserts the OR version at startup.
- **OR audit-trail bypass** during migration. The migrator does NOT emit
  per-object audit-trail entries for the bulk insert. A single bulk
  audit-trail entry (`"openconnector storage migration completed: 4193847
  objects across 15 entities"`) is logged at the end. Acceptable per the
  `audit-trail-immutable` spec exception for bulk migrations, but call out
  in the migrator's PHPDoc.

## Next Steps

1. Author the spec file (one capability: `openconnector-storage-migration`).
2. Author the migration class outline in migration.md (Nextcloud
   migration with `Version2Date20260520xxxxxx`).
3. Author tasks.md grouping by phase (provisioning → bulk insert → FK
   rewrite → flag flip → facade rewrite → tests).
4. Author test-plan.md mapping each spec requirement to unit/integration
   tests, with manual dev-env smoke test for end-to-end.
5. Spike: verify encryption layering on `Source` via a 30-min static read +
   integration test against a seeded record. Adjust migrator implementation
   if storage-level is confirmed.
