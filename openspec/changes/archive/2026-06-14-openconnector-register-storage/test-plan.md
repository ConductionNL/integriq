# Test Plan: openconnector-register-storage

## Test Cases

---

### TC-01: Migration class provisions register on fresh install
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-001-migration-class-must-provision-the-register-via-importfromapp`
- **type**: api
- **persona**: Sem (developer / operator)
- **preconditions**: Fresh Nextcloud install; openconnector enabled; no `oc_openregister_registers` row with `slug='openconnector'`
- **steps**: Run `occ upgrade` (or `occ app:enable openconnector`); query `SELECT count(*) FROM oc_openregister_registers WHERE slug='openconnector'`; query `SELECT count(*) FROM oc_openregister_schemas WHERE register = (SELECT id FROM oc_openregister_registers WHERE slug='openconnector')`
- **expected result**: `oc_openregister_registers` count = 1; `oc_openregister_schemas` count = 15; no exception in the migration log
- **test command**: /test-api

---

### TC-02: Migration class is idempotent on re-run
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-001-migration-class-must-provision-the-register-via-importfromapp`
- **type**: api
- **persona**: Sem (developer / operator)
- **preconditions**: `oc_openregister_registers` already has `slug='openconnector'`; 15 schemas already provisioned
- **steps**: Invoke `postSchemaChange` a second time (simulate via direct OCC or migration runner); re-query both counts
- **expected result**: No duplicate rows; counts remain 1 register + 15 schemas; no exception thrown
- **test command**: /test-api

---

### TC-03: Migrator batches at configured chunk boundaries
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-002-migrator-must-copy-all-legacy-rows-preserving-uuids`
- **type**: regression
- **preconditions**: `LegacyToRegisterMigrator` unit test; mocked `IDBConnection`; source entity seeded with 25,000 rows; `$batchSize=10000`
- **steps**: Call `migrateAll(dryRun=false, entitySlug='source', batchSize=10000)`; capture SQL calls on the mocked connection
- **expected result**: Exactly 3 batch SELECT calls (offsets 0, 10000, 20000); exactly 3 INSERT calls; per-entity result reports `legacyCount=25000`, `migratedCount=25000`
- **test command**: /test-api

---

### TC-04: Migrator is resumable when interrupted mid-batch
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-002-migrator-must-copy-all-legacy-rows-preserving-uuids`
- **type**: regression
- **preconditions**: Unit test; mocked `IDBConnection`; first run throws exception on batch 2 of source; `storage_migrated` flag NOT set; partial OR objects present
- **steps**: First call raises mid-batch; second call via `--entity=source` from offset 0; mock returns rows normally; check final count
- **expected result**: Second run completes without duplicate-key error (uses INSERT IGNORE or equivalent); final `migratedCount` equals `legacyCount`; flag remains unset (single-entity run does not flip flag per REQ-005)
- **test command**: /test-api

---

### TC-05: Dual-platform JSON build — PostgreSQL path
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-002-migrator-must-copy-all-legacy-rows-preserving-uuids`
- **type**: regression
- **preconditions**: Unit test; `IDBConnection::getDatabasePlatform()` mocked to return `Doctrine\DBAL\Platforms\PostgreSQLPlatform`; 1 source row
- **steps**: Call `migrateAll(dryRun=false, entitySlug='source', batchSize=10000)`; capture the INSERT SQL string
- **expected result**: SQL contains `jsonb_build_object(`; SQL does NOT contain `JSON_OBJECT(`
- **test command**: /test-api

---

### TC-06: Dual-platform JSON build — MySQL path
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-002-migrator-must-copy-all-legacy-rows-preserving-uuids`
- **type**: regression
- **preconditions**: Unit test; `IDBConnection::getDatabasePlatform()` mocked to return `Doctrine\DBAL\Platforms\AbstractMySQLPlatform`; 1 source row
- **steps**: Call `migrateAll(dryRun=false, entitySlug='source', batchSize=10000)`; capture the INSERT SQL string
- **expected result**: SQL contains `JSON_OBJECT(`; SQL does NOT contain `jsonb_build_object(`
- **test command**: /test-api

---

### TC-07: UUID is preserved byte-for-byte in migrated object
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-002-migrator-must-copy-all-legacy-rows-preserving-uuids`
- **type**: regression
- **preconditions**: Unit test; source row with `uuid='00000000-0000-0000-0000-000000000123'`; mocked `IDBConnection`
- **steps**: Run migrator for `entity='source'`; capture INSERT SQL or the VALUES array passed to the mock
- **expected result**: The `uuid` column in the INSERT equals `'00000000-0000-0000-0000-000000000123'` verbatim; no new UUID is generated
- **test command**: /test-api

---

### TC-08: FK rewrite — CallLog.sourceId resolves to source uuid
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-003-fk-rewrite-pass-must-translate-6-integer-fk-columns-to-uuids`
- **type**: regression
- **preconditions**: Unit test; mocked `IDBConnection`; `call_log` row with `source_id=42` migrated with `{"sourceId": 42}`; source #42 has `uuid='00000000-…-0042'`
- **steps**: Run FK rewrite pass for `call_log.source_id`; inspect the UPDATE SQL
- **expected result**: UPDATE sets `source = '00000000-…-0042'` in the JSON object column; `sourceId = 42` field is retained; no rows skipped
- **test command**: /test-api

---

### TC-09: FK rewrite — CallLog.synchronizationId resolves to synchronization uuid
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-003-fk-rewrite-pass-must-translate-6-integer-fk-columns-to-uuids`
- **type**: regression
- **preconditions**: Unit test; `call_log` row with `synchronization_id=7`; synchronization #7 has `uuid='…-0007'`
- **steps**: Run FK rewrite pass for `call_log.synchronization_id`; inspect UPDATE SQL
- **expected result**: `synchronization = '…-0007'` set; `synchronizationId = 7` retained
- **test command**: /test-api

---

### TC-10: FK rewrite — EventMessage 3 FKs (event, consumer, subscription)
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-003-fk-rewrite-pass-must-translate-6-integer-fk-columns-to-uuids`
- **type**: regression
- **preconditions**: Unit test; `event_message` row with `event_id=1`, `consumer_id=2`, `subscription_id=3`; matching target rows present with known uuids
- **steps**: Run FK rewrite pass for all three `event_message` FK columns
- **expected result**: `event`, `consumer`, `subscription` fields populated with resolved uuids; original `eventId`, `consumerId`, `subscriptionId` integer fields retained
- **test command**: /test-api

---

### TC-11: FK rewrite — missing target row triggers skip and log
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-003-fk-rewrite-pass-must-translate-6-integer-fk-columns-to-uuids`
- **type**: regression
- **preconditions**: Unit test; `call_log` row with `source_id=99`; no `oc_openconnector_sources.id=99`
- **steps**: Run FK rewrite pass; check the logger mock and the returned `fkRewrites` counter
- **expected result**: `source` field NOT set on the OR object; logger called with message containing `"sourceId=99 has no target"` and the row uuid; `fkRewrites` counter NOT incremented for that row
- **test command**: /test-api

---

### TC-12: CallLog.actionId migrated as opaque integer, no FK rewrite attempted
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-003-fk-rewrite-pass-must-translate-6-integer-fk-columns-to-uuids`
- **type**: regression
- **preconditions**: Unit test; `call_log` row with `action_id=5`
- **steps**: Run full migrator for `call_log`; inspect SQL calls
- **expected result**: No FK rewrite UPDATE issued for `action_id`; `actionId=5` (integer) preserved in the OR object; a parallel `action` string field is added with no `$ref` annotation; no error
- **test command**: /test-api

---

### TC-13: Synchronization.sourceId — integer PK resolved to uuid
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-004-synchronization-sourceid-targetid-branching-must-handle-3-formats`
- **type**: regression
- **preconditions**: Unit test; synchronization row `source_id='42'`; source #42 has `uuid='00000000-…-0042'`
- **steps**: Call `resolveSyncRef('42')` (or exercise it via `migrateAll(entitySlug='synchronization')`); inspect resulting OR object
- **expected result**: `sourceId = '00000000-…-0042'`; variant logged as `integer-pk`
- **test command**: /test-api

---

### TC-14: Synchronization.sourceId — register/schema slug pair preserved unchanged
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-004-synchronization-sourceid-targetid-branching-must-handle-3-formats`
- **type**: regression
- **preconditions**: Unit test; synchronization row `source_id='zaken/zaak'`
- **steps**: Migrate synchronization entity; inspect OR object
- **expected result**: `sourceId = 'zaken/zaak'` unchanged; variant logged as `register-schema`
- **test command**: /test-api

---

### TC-15: Synchronization.sourceId — uuid variant passes through unchanged
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-004-synchronization-sourceid-targetid-branching-must-handle-3-formats`
- **type**: regression
- **preconditions**: Unit test; synchronization row `source_id='a1b2c3d4-…'` (valid RFC 4122 uuid)
- **steps**: Migrate synchronization entity; inspect OR object
- **expected result**: `sourceId` value unchanged; variant logged as `uuid`
- **test command**: /test-api

---

### TC-16: Synchronization.sourceId — unrecognised format is logged and skipped (not silently corrupted)
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-004-synchronization-sourceid-targetid-branching-must-handle-3-formats`
- **type**: regression
- **preconditions**: Unit test; synchronization row `source_id='not-a-recognised-format'`; row uuid known
- **steps**: Migrate synchronization entity; check logger mock and returned tally
- **expected result**: Logger records skip with row uuid + raw value; `unrecognised` counter = 1; OR object's `sourceId` = raw legacy value (not NULL, not corrupted); row IS migrated (only the FK-rewrite is skipped)
- **test command**: /test-api

---

### TC-17: targetId column branched identically to sourceId
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-004-synchronization-sourceid-targetid-branching-must-handle-3-formats`
- **type**: regression
- **preconditions**: Unit test; synchronization row with `source_id='42'` (integer) and `target_id='zaken/zaak'` (slug pair)
- **steps**: Migrate synchronization entity; inspect both fields on the resulting OR object
- **expected result**: `sourceId` resolved to uuid; `targetId = 'zaken/zaak'` preserved; per-variant counts accumulated separately and logged for both columns
- **test command**: /test-api

---

### TC-18: storage_migrated flag is set only after all 15 entities succeed
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-005-migrator-must-set-the-storage-migrated-app-config-flag-on-success`
- **type**: regression
- **preconditions**: Unit test; mocked `IAppConfig`; all 15 entities migrate without error
- **steps**: Call `migrateAll(dryRun=false, entitySlug=null, batchSize=10000)`; verify `IAppConfig::setValue` call
- **expected result**: `IAppConfig::setValue('openconnector', 'storage_migrated', 'true')` called exactly once; returned result array has no failed entities
- **test command**: /test-api

---

### TC-19: storage_migrated flag NOT set when any entity raises an exception
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-005-migrator-must-set-the-storage-migrated-app-config-flag-on-success`
- **type**: regression
- **preconditions**: Unit test; mocked `IDBConnection` throws on entity #7 (job) during bulk INSERT; mocked `IAppConfig`
- **steps**: Call `migrateAll(...)` and catch any thrown exception
- **expected result**: `IAppConfig::setValue('openconnector', 'storage_migrated', 'true')` is NEVER called; mappers would still use the legacy path
- **test command**: /test-api

---

### TC-20: storage_migrated flag NOT set on single-entity retry
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-005-migrator-must-set-the-storage-migrated-app-config-flag-on-success`
- **type**: regression
- **preconditions**: Unit test; mocked `IAppConfig`; only `entitySlug='source'` passed
- **steps**: Call `migrateAll(dryRun=false, entitySlug='source', batchSize=10000)`
- **expected result**: Flag NOT flipped even on success; `IAppConfig::setValue` NOT called with `'true'`
- **test command**: /test-api

---

### TC-21: ObjectMapperFacade.find(int) resolves via OR, populates cache
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-006-objectmapperfacade-must-translate-the-mapper-api-to-objectservice`
- **type**: regression
- **preconditions**: Unit test; mocked `ObjectService`; OR object for `(openconnector, source, uuid=U)` with `"id": 42` in payload; fresh facade with empty cache
- **steps**: Call `find('openconnector', 'source', 42)` twice; count `ObjectService::find` invocations
- **expected result**: First call queries OR with `['id' => 42]`, caches `42 → U`, returns a hydrated `Source` entity; second call returns from cache without issuing a new OR query — `ObjectService::find` called exactly 1 time total
- **test command**: /test-api

---

### TC-22: ObjectMapperFacade.find(int) — cache miss triggers single lookup
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-006-objectmapperfacade-must-translate-the-mapper-api-to-objectservice`
- **type**: regression
- **preconditions**: Unit test; mocked `ObjectService`; cache empty; OR object NOT yet fetched for id=55
- **steps**: Call `find('openconnector', 'source', 55)`; assert exactly one OR query; call again; assert still exactly one OR query (cache hit)
- **expected result**: One `ObjectService` call on first invocation; zero additional calls on second; returned entity is `Source` instance
- **test command**: /test-api

---

### TC-23: ObjectMapperFacade.createFromArray hydrates returned typed entity
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-006-objectmapperfacade-must-translate-the-mapper-api-to-objectservice`
- **type**: regression
- **preconditions**: Unit test; mocked `ObjectService::saveObject` returns `ObjectEntity` with `object = ['name' => 'New Source', 'type' => 'api']`
- **steps**: Call `createFromArray('openconnector', 'source', ['name' => 'New Source', 'type' => 'api'], Source::class)`
- **expected result**: Returns a `Source` instance with `name='New Source'`; cache for `(openconnector, source)` is invalidated (cleared)
- **test command**: /test-api

---

### TC-24: ObjectMapperFacade.delete invalidates the int-id→uuid cache
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-006-objectmapperfacade-must-translate-the-mapper-api-to-objectservice`
- **type**: regression
- **preconditions**: Unit test; facade cache contains `42 → U` for `(openconnector, source)`; mocked `ObjectService`
- **steps**: Call `delete('openconnector', 'source', U)`; then call `find('openconnector', 'source', 42)` again; count OR queries
- **expected result**: After delete, cache is cleared; `find(42)` re-queries OR (2 total OR calls — initial find + post-delete find)
- **test command**: /test-api

---

### TC-25: ObjectMapperFacade re-raises DoesNotExistException from OR
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-006-objectmapperfacade-must-translate-the-mapper-api-to-objectservice`
- **type**: regression
- **preconditions**: Unit test; mocked `ObjectService::find` throws `OCP\AppFramework\Db\DoesNotExistException`
- **steps**: Call `findByUuid('openconnector', 'source', 'nonexistent-uuid')` and catch the exception
- **expected result**: `OCP\AppFramework\Db\DoesNotExistException` is re-thrown (same class); no wrapping in a different exception type
- **test command**: /test-api

---

### TC-26: SourceMapper routes to legacy path when flag is false
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-007-15-mappers-must-delegate-to-the-facade-behind-a-flag`
- **type**: regression
- **preconditions**: Unit test; `IAppConfig::getValue('openconnector', 'storage_migrated', ...)` returns `'false'`; mocked `ObjectMapperFacade` and legacy `QBMapper`
- **steps**: Construct `SourceMapper`; call `find(42)`; assert which path executes
- **expected result**: Legacy SQL path runs; `QBMapper::find` invoked; `ObjectMapperFacade::find` NOT invoked
- **test command**: /test-api

---

### TC-27: SourceMapper routes to facade path when flag is true
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-007-15-mappers-must-delegate-to-the-facade-behind-a-flag`
- **type**: regression
- **preconditions**: Unit test; `IAppConfig::getValue` returns `'true'`; mocked `ObjectMapperFacade`
- **steps**: Construct `SourceMapper`; call `find(42)`
- **expected result**: `ObjectMapperFacade::find('openconnector', 'source', 42)` invoked; returns `Source` entity; legacy SQL NOT invoked
- **test command**: /test-api

---

### TC-28: Append-only enforcement on log mapper (flag true)
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-007-15-mappers-must-delegate-to-the-facade-behind-a-flag`
- **type**: regression
- **preconditions**: Unit test; `storage_migrated = 'true'`; mocked `ObjectMapperFacade` raises `AppendOnlyException` on `updateFromArray`
- **steps**: Construct `JobLogMapper` with flag=true; call `updateFromArray(uuid, [...])`; catch thrown exception
- **expected result**: `LogicException` or `AppendOnlyException` raised (per design.md: facade may translate); NO update is issued
- **test command**: /test-api

---

### TC-29: Round-trip via facade (integration) — write Source, read back as Source
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-006-objectmapperfacade-must-translate-the-mapper-api-to-objectservice`
- **type**: regression
- **preconditions**: Integration test; real Nextcloud test container; `storage_migrated = 'true'`; OR register + source schema provisioned
- **steps**: Call `SourceMapper::createFromArray(['name' => 'IntegrationTestSource', 'type' => 'json-api'])` via the facade path; retrieve via `SourceMapper::findByUuid($uuid)` on the returned uuid
- **expected result**: Returned entity is a `Source` instance (not `ObjectEntity`); `name = 'IntegrationTestSource'`; uuid matches
- **test command**: /test-api

---

### TC-30: Append-only enforcement via OR (integration)
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-007-15-mappers-must-delegate-to-the-facade-behind-a-flag`
- **type**: regression
- **preconditions**: Integration test; real Nextcloud container; `storage_migrated = 'true'`; write a `CallLog` row through `CallLogMapper::createFromArray`
- **steps**: Attempt `CallLogMapper::updateFromArray($uuid, ['message' => 'tampered'])`; catch exception
- **expected result**: `AppendOnlyException` or `LogicException` raised; OR raises this via its `lib/Exception/AppendOnlyException.php`; row in `oc_openregister_objects` is unchanged
- **test command**: /test-api

---

### TC-31: Rollback flag switches reads back to legacy tables (integration)
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-009-legacy-tables-must-stay-readable-for-one-release-as-rollback-buffer`
- **type**: regression
- **preconditions**: Integration test; migration completed; `storage_migrated = 'true'`; a known source row exists in both `oc_openconnector_sources` and `oc_openregister_objects`
- **steps**: Set `storage_migrated = 'false'` via `IAppConfig`; re-construct `SourceMapper` (or clear the cached flag); call `SourceMapper::find($intId)` for the known row
- **expected result**: Query hits `oc_openconnector_sources`; returns the same `Source` entity as before migration; no exception
- **test command**: /test-api

---

### TC-32: Cross-mapper FK resolution (integration) — JobLog with extend=job
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-003-fk-rewrite-pass-must-translate-6-integer-fk-columns-to-uuids`
- **type**: api
- **persona**: Sem (developer)
- **preconditions**: Integration test; `storage_migrated = 'true'`; a `Job` object and a `JobLog` referencing it exist in OR; `?extend=job` parameter supported by OR's relations machinery
- **steps**: Call `GET /api/objects/openconnector/job_log/{uuid}?extend=job`; inspect response body
- **expected result**: Response contains an inlined `job` object (OR's `inversedBy` wiring); the inlined object is the related `Job` entity; no 404 or unresolved relation
- **test command**: /test-api

---

### TC-33: OCC command — dry-run reports counts without writing
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-008-occ-command-must-allow-manual-re-runnability`
- **type**: api
- **persona**: Sem (developer / operator)
- **preconditions**: `oc_openconnector_sources` has 12 rows; OR has no source rows yet
- **steps**: Run `occ openconnector:migrate-storage --entity=source --dry-run`; check exit code; check OR count; check `storage_migrated` value
- **expected result**: Exit 0; output reports "source: 12 rows would migrate"; `oc_openregister_objects` count for source schema = 0 (unchanged); `storage_migrated` unchanged
- **test command**: /test-api

---

### TC-34: OCC command — --entity filters to a single schema
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-008-occ-command-must-allow-manual-re-runnability`
- **type**: api
- **persona**: Sem (developer / operator)
- **preconditions**: Legacy tables populated for all 15 entities
- **steps**: Run `occ openconnector:migrate-storage --entity=source`; check which legacy tables were queried
- **expected result**: Only `oc_openconnector_sources` rows migrated; all other legacy tables untouched; exit 0; no flag flip
- **test command**: /test-api

---

### TC-35: OCC command — --batch-size override honoured
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-008-occ-command-must-allow-manual-re-runnability`
- **type**: regression
- **preconditions**: Unit test on `MigrateToOpenRegister` command; mocked migrator; `--batch-size=100` passed
- **steps**: Execute command with `--batch-size=100`; assert migrator called with `batchSize=100`
- **expected result**: `LegacyToRegisterMigrator::migrateAll` called with `batchSize=100`; no validation error
- **test command**: /test-api

---

### TC-36: OCC command — verbose progress emits one line per chunk
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-008-occ-command-must-allow-manual-re-runnability`
- **type**: regression
- **preconditions**: Unit test; mocked migrator emitting 3 batch-progress events for `source` (3 batches of 10k from 25k rows); Symfony Console output buffer captured
- **steps**: Run command with `-v`; inspect captured output
- **expected result**: Output contains 3 progress lines for the source entity, each referencing the batch offset/count; overall summary line present at the end
- **test command**: /test-api

---

### TC-37: OCC command — invalid entity slug is rejected
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-008-occ-command-must-allow-manual-re-runnability`
- **type**: regression
- **preconditions**: Unit test on `MigrateToOpenRegister` command
- **steps**: Run `occ openconnector:migrate-storage --entity=nonexistent_slug`; capture exit code and stdout
- **expected result**: Command exits non-zero; output lists the 15 valid slugs; no migrator call is made
- **test command**: /test-api

---

### TC-38: OCC command — batch-size outside [100, 100000] is rejected
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-008-occ-command-must-allow-manual-re-runnability`
- **type**: regression
- **preconditions**: Unit test; `--batch-size=50` (too low); `--batch-size=200000` (too high)
- **steps**: Test both values; capture exit code
- **expected result**: Both exit non-zero with a validation error message; no migrator call
- **test command**: /test-api

---

### TC-39: Legacy tables still present and readable after successful migration
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-009-legacy-tables-must-stay-readable-for-one-release-as-rollback-buffer`
- **type**: regression
- **preconditions**: Integration test or manual step; migration completed; `storage_migrated = 'true'`
- **steps**: Execute `SELECT count(*) FROM oc_openconnector_sources`; compare to pre-migration count
- **expected result**: Table exists; row count matches pre-migration count (no rows removed or truncated)
- **test command**: /test-api

---

### TC-40: Encrypted column passthrough — column-level encryption verified, bytes copied verbatim
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-010-encrypted-columns-on-source-must-be-preserved-byte-for-byte`
- **type**: security
- **preconditions**: Unit test; migrator startup assertion logic; `lib/Db/Source.php` has no encryption setter hooks; `EncryptionService` invoked at mapper-layer (column-level)
- **steps**: Instantiate migrator; observe startup assertion outcome; migrate one `Source` row with a known encrypted `apikey` value; compare OR object's `apikey` bytes to legacy bytes
- **expected result**: Startup assertion concludes "column-level"; bytes in OR object's `apikey` field are byte-for-byte equal to the encrypted value in `oc_openconnector_sources`; no plaintext ever appears in migration logs
- **test command**: /test-security

---

### TC-41: Encrypted column passthrough — storage-level encryption aborts migration
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-010-encrypted-columns-on-source-must-be-preserved-byte-for-byte`
- **type**: security
- **preconditions**: Unit test; mock simulates storage-level encryption detection (inspection returns "storage-level"); no decrypt+re-encrypt path wired
- **steps**: Attempt to instantiate and call the migrator; catch thrown exception
- **expected result**: `\LogicException("storage-level encryption requires decrypt+re-encrypt path; abort")` raised BEFORE any INSERT into OR; `oc_openregister_objects` count unchanged
- **test command**: /test-security

---

### TC-42: Owner column is null for entities with userId
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#requirement-owner-field-must-be-left-null-on-every-migrated-object`
- **type**: regression
- **preconditions**: Unit test; job row with `user_id='ruben'`; mocked `IDBConnection`
- **steps**: Migrate `job` entity; inspect the `owner` column AND the object JSON body in the INSERT
- **expected result**: `owner IS NULL` on the inserted row; the object JSON body retains `userId: 'ruben'` as a regular property (provenance preserved, ownership not derived from userId)
- **test command**: /test-api

---

### TC-43: Owner column is null for entities without userId
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#requirement-owner-field-must-be-left-null-on-every-migrated-object`
- **type**: regression
- **preconditions**: Unit test; source row (Source has no `userId` column); mocked `IDBConnection`
- **steps**: Migrate `source` entity; inspect `owner` value
- **expected result**: `owner IS NULL` on the inserted row; no admin UID lookup is performed (no `IConfig` / `IUserManager` calls during migration)
- **test command**: /test-api

---

### TC-44: Single summary audit-trail entry per migration run
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-012-migrator-must-not-emit-per-object-audit-trail-entries`
- **type**: regression
- **preconditions**: Unit test; mocked OR audit-trail service; migrator processes 3 entities (source 12 rows, job 5 rows, call_log 100 rows)
- **steps**: Run `migrateAll(...)` for the 3 entities; count audit-trail write invocations and inspect payload
- **expected result**: Exactly 1 audit-trail entry written; its payload contains a `perEntity` JSON array with 3 items covering the 3 entities' `legacyCount`, `migratedCount`, `skipped`, `fkRewrites`, `elapsedMs`; no per-object audit entries
- **test command**: /test-api

---

### TC-45: POST /api/admin/openconnector/migrate-storage — unauthenticated returns 401
- **spec_ref**: `openspec/changes/openconnector-register-storage/contract.md`
- **type**: security
- **preconditions**: Newman collection; no session cookie
- **steps**: POST `{baseUrl}/api/admin/openconnector/migrate-storage` with `{"dryRun": false}`; no auth header
- **expected result**: HTTP 401
- **test command**: /test-security

---

### TC-46: POST /api/admin/openconnector/migrate-storage — non-admin returns 403
- **spec_ref**: `openspec/changes/openconnector-register-storage/contract.md`
- **type**: security
- **persona**: Fatima (case handler — non-admin Nextcloud user)
- **preconditions**: Newman collection; session cookie for a valid non-admin user
- **steps**: POST same endpoint with a valid JSON body; user is authenticated but not an admin
- **expected result**: HTTP 403
- **test command**: /test-security

---

### TC-47: POST /api/admin/openconnector/migrate-storage — invalid entity slug returns 400
- **spec_ref**: `openspec/changes/openconnector-register-storage/contract.md`
- **type**: api
- **preconditions**: Newman collection; admin session
- **steps**: POST `{"dryRun": false, "entity": "not_a_valid_slug", "batchSize": 10000}`
- **expected result**: HTTP 400
- **test command**: /test-api

---

### TC-48: POST /api/admin/openconnector/migrate-storage — returns 409 when already migrated
- **spec_ref**: `openspec/changes/openconnector-register-storage/contract.md`
- **type**: api
- **preconditions**: Newman collection; admin session; `storage_migrated = 'true'`; body has `entity = null`
- **steps**: POST `{"dryRun": false, "entity": null, "batchSize": 10000}`
- **expected result**: HTTP 409 with a hint message suggesting use of `entity` for targeted retry
- **test command**: /test-api

---

### TC-49: GET /api/admin/openconnector/migrate-storage/status returns current state
- **spec_ref**: `openspec/changes/openconnector-register-storage/contract.md`
- **type**: api
- **persona**: Sem (developer / operator)
- **preconditions**: Newman collection; admin session; migration completed; `storage_migrated = 'true'`
- **steps**: GET `{baseUrl}/api/admin/openconnector/migrate-storage/status`
- **expected result**: HTTP 200; response body contains `storageMigrated: true`, `flagSetAt` (ISO-8601 timestamp), `perEntityRowCounts` array with 15 entries, `readOnlyLockActive: true`
- **test command**: /test-api

---

### TC-50: Manual smoke — migration completes cleanly on populated dev container
- **spec_ref**: `openspec/changes/openconnector-register-storage/migration.md` (Validation table)
- **type**: regression
- **persona**: Sem (developer / operator)
- **preconditions**: Dev container running; openconnector populated with chain-A seed data (33 records across 15 entities); `storage_migrated` NOT set
- **steps**: `docker exec -u www-data nextcloud php occ maintenance:repair`; observe output for errors; run `docker exec nextcloud php occ openconnector:migrate-storage --dry-run`; run full migration; run `docker exec nextcloud php occ config:app:get openconnector storage_migrated`
- **expected result**: No exceptions; dry-run shows correct row counts; full migration exits 0; `storage_migrated = 'true'`
- **test command**: /test-api

---

### TC-51: Manual smoke — list-sources returns same count before and after migration
- **spec_ref**: `openspec/changes/openconnector-register-storage/migration.md` (Validation table)
- **type**: regression
- **persona**: Sem (developer / operator)
- **preconditions**: Dev container; openconnector source rows exist in legacy table; migration not yet run
- **steps**: Record `SELECT count(*) FROM oc_openconnector_sources` pre-migration; run migration; record `SELECT count(*) FROM oc_openregister_objects WHERE schema = (SELECT id FROM oc_openregister_schemas WHERE slug='source' AND register=(SELECT id FROM oc_openregister_registers WHERE slug='openconnector'))`
- **expected result**: Both counts are equal; no rows lost or duplicated
- **test command**: /test-api

---

### TC-52: Manual smoke — SQL spot-check row count parity for call_log
- **spec_ref**: `openspec/changes/openconnector-register-storage/migration.md` (Validation table)
- **type**: performance
- **persona**: Sem (developer / operator)
- **preconditions**: Dev container; `call_log` entity migrated
- **steps**: `SELECT COUNT(*) FROM oc_openconnector_call_logs`; `SELECT COUNT(*) FROM oc_openregister_objects WHERE schema = '<call_log-schema-uuid>'`
- **expected result**: Both counts equal; deviation only for documented "skipped" rows (FK missing, sourceId unrecognised — each individually logged)
- **test command**: /test-performance

---

### TC-53: Performance gate — 100k call_log rows migrate within 5 minutes
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md` (Non-Functional Requirements)
- **type**: performance
- **preconditions**: Dev container; `oc_openconnector_call_logs` seeded with 100,000 rows; SSD-backed Postgres
- **steps**: Run `occ openconnector:migrate-storage --entity=call_log`; measure wall-clock time from command start to exit
- **expected result**: Command completes in < 300 seconds (5 minutes); per-batch progress lines show ≥ 333 rows/sec sustained throughput; no OOM or timeout errors
- **test command**: /test-performance

---

### TC-54: Performance gate — facade findAll() within 2x legacy direct-SQL baseline
- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md` (Non-Functional Requirements — ≤1.5× p95 for find(int); risk-5 baseline ≤50% overhead)
- **type**: performance
- **preconditions**: PHPUnit performance test; 500 source rows in OR; `storage_migrated = 'true'`; baseline measured against legacy SQL `SourceMapper::findAll()` with `storage_migrated = 'false'`
- **steps**: Measure p95 latency for 1,000 consecutive `SourceMapper::findAll()` calls on both paths; compare
- **expected result**: Facade p95 latency ≤ 2× legacy p95 baseline; absolute p95 < 200ms for findAll on 500 rows; result logged as pass/fail in PHPUnit output
- **test command**: /test-performance

---

### TC-55: Admin HTTP endpoint — migration response includes per-entity tally on mid-run failure
- **spec_ref**: `openspec/changes/openconnector-register-storage/contract.md`
- **type**: api
- **preconditions**: Newman collection; admin session; migrator mock throws mid-run after 3 entities succeed
- **steps**: POST `/api/admin/openconnector/migrate-storage`; inspect response
- **expected result**: HTTP 500; response body includes `perEntity` array showing the 3 completed entities with counts; includes an `error` field with a truncated message (no full stack trace exposed to client)
- **test command**: /test-api

---

### TC-56: Migration row-count verification helper returns parity result
- **spec_ref**: `openspec/changes/openconnector-register-storage/tasks.md#task-17-verify-post-migration-row-counts-match`
- **type**: regression
- **preconditions**: Unit test; migrator with mocked connection returning known counts; 3 entities: source (legacy=12, OR=12), job (legacy=5, OR=5), call_log (legacy=100, OR=99, skipped=1)
- **steps**: Call `verifyRowCounts()` after migration; inspect returned array
- **expected result**: source `equal=true`; job `equal=true`; call_log `equal=true` (legacy 100 - skipped 1 = 99 = OR 99); OCC `--verify-only` exits 0
- **test command**: /test-api

---

## Coverage Summary

| Requirement | Description | Test Cases | Status |
|-------------|-------------|------------|--------|
| REQ-001 | Migration class provisions register via importFromApp (fresh install + idempotent re-run) | TC-01, TC-02 | Covered |
| REQ-002 | Migrator copies all legacy rows preserving uuids (batching, resumability, dual-platform JSON, uuid preservation) | TC-03, TC-04, TC-05, TC-06, TC-07 | Covered |
| REQ-003 | FK rewrite pass translates 6 integer FK columns to uuids (per-column, missing-target skip, actionId opaque) | TC-08, TC-09, TC-10, TC-11, TC-12 | Covered |
| REQ-004 | Synchronization.sourceId/targetId branching handles 3 formats + unrecognised skip | TC-13, TC-14, TC-15, TC-16, TC-17 | Covered |
| REQ-005 | storage_migrated flag set only on full success; not on error; not on single-entity retry | TC-18, TC-19, TC-20 | Covered |
| REQ-006 | ObjectMapperFacade translates mapper API to ObjectService (cache hit/miss, hydration, delete invalidation, exception pass-through) | TC-21, TC-22, TC-23, TC-24, TC-25 | Covered |
| REQ-007 | 15 mappers delegate to facade behind flag (legacy path, OR path, append-only enforcement) | TC-26, TC-27, TC-28, TC-29, TC-30 | Covered |
| REQ-008 | OCC command allows manual re-runnability (dry-run, --entity, --batch-size, verbose progress, validation) | TC-33, TC-34, TC-35, TC-36, TC-37, TC-38 | Covered |
| REQ-009 | Legacy tables stay readable for one release as rollback buffer (present post-migration, rollback flag switch) | TC-31, TC-39 | Covered |
| REQ-010 | Encrypted columns on Source preserved byte-for-byte (column-level passthrough, storage-level abort) | TC-40, TC-41 | Covered |
| REQ-011 | Owner field left null on every migrated object; legacy userId preserved in JSON body | TC-42, TC-43 | Covered |
| REQ-012 | Migrator does NOT emit per-object audit-trail entries; emits one summary entry | TC-44 | Covered |
| Contract: Admin HTTP endpoints | POST migrate-storage (auth, 400, 409, 500) + GET status | TC-45, TC-46, TC-47, TC-48, TC-49, TC-55 | Covered |
| Non-functional: Performance | 100k call_log in < 5 min; findAll() within 2× legacy baseline | TC-52, TC-53, TC-54 | Covered |
| Non-functional: Manual smoke | Dev-container end-to-end, row count parity SQL spot-check | TC-50, TC-51 | Covered |
| Cross-mapper FK resolution via OR relations | extend=job on JobLog inlines related Job via OR inversedBy | TC-32 | Covered |
| Row-count verification helper | verifyRowCounts() / --verify-only per Task-17 | TC-56 | Covered |

## Out of Scope

- **Browser / Playwright tests**: this change ships no Vue or user-facing UI surface. The migration trigger is admin-only (HTTP + OCC). No accessibility or functional browser tests are required.
- **i18n tests**: no user-facing strings are added (OCC outputs English-only to operators; HTTP endpoints return JSON). Internationalization is explicitly out of scope per the spec's Non-Functional Requirements section.
- **Cleanup-change tests**: dropping `oc_openconnector_*` tables and removing the `storage_migrated` flag are deferred to the follow-up cleanup change. Tests for that path are out of scope here.
- **FK rename tests**: the `*Id` → target-schema-name rename is deferred. The coexistence of both field forms (integer `sourceId` and uuid `source`) is verified here (TC-08, TC-09, TC-10) but the rename itself is not.
- **CallLog.actionId resolution**: `actionId` is deliberately migrated as an opaque integer in this change (TC-12). The resolution to a real `$ref` relation is deferred; tests for the resolved form belong in the follow-up change.
- **Frontend Vue store validation**: Vue stores are unchanged; no store-level tests are in scope.
- **Performance profiling at production scale (> 1M rows)**: TC-53 covers the 100k gate. Multi-million-row production profiling is out of scope for the change's test plan and is addressed operationally via the OCC command and the runbook documented in tasks.md.
