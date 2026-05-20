# Tasks: openconnector-register-storage

<!-- Apply order: foundations first. Each task is sized for one Ralph Wiggum
     iteration. ADR-031: declarative behaviour (relations, archival, appendOnly,
     immutable) ships in chain A — NOT here. ADR-001 seed-data: this change
     deviates because the seed set IS the migrated live data; deviation
     rationale lives in design.md "Seed Data" section. -->

## Implementation Tasks

### Task 1: Add Nextcloud migration class Version2Date20260520xxxxxx

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-001-migration-class-must-provision-the-register-via-importfromapp`
- **files**: `lib/Migration/Version2Date20260520xxxxxx.php`
- **acceptance_criteria**:
  - GIVEN openconnector is upgraded WHEN `occ upgrade` runs THEN `postSchemaChange` calls `ConfigurationService::importFromApp('openconnector', <abs-path-to-lib/Settings/openconnector_register.json>, <app-version>, false)` exactly once
  - GIVEN the register already exists WHEN the migration re-runs THEN it completes without error and creates no duplicate rows (idempotent)
  - The migration class implements `OCP\Migration\IMigrationStep`, returns `null` from `changeSchema` (no schema diff), and in `postSchemaChange` calls `LegacyToRegisterMigrator::migrateAll(dryRun=false, entitySlug=null, batchSize=10000)` then sets the `openconnector.storage_migrated` flag on full success only
  - Constructor injects: `IDBConnection`, `IAppConfig`, `IConfig`, `LoggerInterface`, `OCA\OpenRegister\Service\ConfigurationService`, `LegacyToRegisterMigrator`
- [ ] Implement
- [ ] Test

### Task 2: Add LegacyToRegisterMigrator service skeleton + entity registry

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-002-migrator-must-copy-all-legacy-rows-preserving-uuids`
- **files**: `lib/Service/Migration/LegacyToRegisterMigrator.php`
- **acceptance_criteria**:
  - GIVEN the service is instantiated WHEN `migrateAll(bool $dryRun, ?string $entitySlug, int $batchSize): array` is called THEN it iterates the 15 entities in the order defined in `migration.md` step 5 (sources/consumer/endpoint/event/event_subscription/job/mapping/rule first, then synchronization, synchronization_contract, event_message, call_log, job_log, synchronization_log, synchronization_contract_log)
  - Each entity descriptor in the registry holds: legacy table name, schema slug, column→JSON-key mapping, FK descriptors, optional sourceId/targetId branching flag
  - GIVEN `$entitySlug` is non-null WHEN it is not one of the 15 valid slugs THEN the migrator raises `InvalidArgumentException`
  - GIVEN `$batchSize` is outside [100, 100000] WHEN passed in THEN the migrator raises `InvalidArgumentException`
  - Returns a per-entity result array: `slug, legacyCount, migratedCount, skipped, skippedReason, fkRewrites, elapsedMs`
- [ ] Implement
- [ ] Test

### Task 3: Implement bulk INSERT pass with dual-platform JSON builder

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-002-migrator-must-copy-all-legacy-rows-preserving-uuids`
- **files**: `lib/Service/Migration/LegacyToRegisterMigrator.php`
- **acceptance_criteria**:
  - GIVEN PostgreSQL is the runtime WHEN the migrator emits an INSERT THEN the SQL uses `jsonb_build_object(col1_name, col1_value, …)` to assemble the `object` column
  - GIVEN MySQL/MariaDB is the runtime WHEN the migrator emits an INSERT THEN the SQL uses `JSON_OBJECT(col1_name, col1_value, …)`
  - Platform detection uses `$db->getDatabasePlatform()` (Doctrine `AbstractMySQLPlatform` vs `PostgreSQLPlatform`); MUST NOT use `getenv`/`gethostname` or any other heuristic
  - The INSERT preserves `uuid` byte-for-byte from the legacy row; populates `register` = openconnector register PK (cached at startup), `schema` = matching schema PK (cached at startup), `created`/`updated` from legacy row, `owner` per Task 6
  - Rows are streamed in batches of `$batchSize` (default 10,000); per-batch progress logged at INFO level
  - GIVEN `$dryRun=true` WHEN the migrator runs THEN no INSERT statements execute; row counts are still computed and returned
- [ ] Implement
- [ ] Test

### Task 4: Implement FK rewrite pass for 6 integer FK columns

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-003-fk-rewrite-pass-must-translate-6-integer-fk-columns-to-uuids`
- **files**: `lib/Service/Migration/LegacyToRegisterMigrator.php`
- **acceptance_criteria**:
  - After the bulk INSERT pass for `call_log` and `event_message`, the migrator runs an UPDATE per FK that JOINs `oc_openregister_objects` back to the legacy target table by integer id and sets the relation-named field to the target uuid via `jsonb_set` (Postgres) or `JSON_SET` (MySQL)
  - The 6 FKs handled: `call_log.source_id → source`, `call_log.synchronization_id → synchronization`, `event_message.event_id → event`, `event_message.consumer_id → consumer`, `event_message.subscription_id → subscription`
  - `call_log.action_id` is migrated as opaque integer; a parallel `action` string field is added with no `$ref` (per Notes in spec.md and DEFERRED Q2). No FK rewrite is attempted for `action_id`.
  - GIVEN a legacy row has a FK pointing to a missing target WHEN the rewrite runs THEN the relation-named field is left unset, the migration log records `"<entity> row {uuid}: <fk>=<int> has no target — skipped FK rewrite, legacy <fkId> preserved"`, and the entity's `fkRewrites` tally is NOT incremented for that row
  - GIVEN a successful rewrite WHEN the rewrite runs THEN BOTH the legacy `*Id` field (integer) AND the relation-named field (uuid) are present in the resulting OR object (per chain-A REQ-008)
- [ ] Implement
- [ ] Test

### Task 5: Implement Synchronization.sourceId/targetId branching

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-004-synchronization-sourceid-targetid-branching-must-handle-3-formats`
- **files**: `lib/Service/Migration/LegacyToRegisterMigrator.php`
- **acceptance_criteria**:
  - A private `resolveSyncRef(string $value): array{value: string, variant: 'integer-pk'|'register-schema'|'uuid'|'unrecognised'}` method matches the value against (in order): `^\d+$`, `^[\w-]+\/[\w-]+$`, RFC 4122 uuid regex; anything else yields `unrecognised`
  - GIVEN `source_id='42'` AND legacy source #42 has `uuid='…0042'` WHEN the migrator processes this row THEN the OR object's `sourceId` field is the resolved uuid string
  - GIVEN `source_id='zaken/zaak'` WHEN the migrator processes it THEN the OR object's `sourceId` field is `"zaken/zaak"` unchanged
  - GIVEN `source_id` is a valid uuid WHEN the migrator processes it THEN the OR object's `sourceId` field is unchanged
  - GIVEN `source_id='not-a-recognised-format'` WHEN the migrator processes it THEN the OR object's `sourceId` field is set to the raw legacy value, the migration log records the skip with the row uuid + raw value, and the `unrecognised` counter increments
  - Both `source_id` and `target_id` columns are branched identically; per-variant counts are accumulated separately and logged at the end of the synchronization migration (e.g. `"synchronization: integer-PK→uuid: 4, register/schema: 2, uuid: 1, unrecognised: 1 (skipped)"`)
- [ ] Implement
- [ ] Test

### Task 6: Set owner null + encryption layer assertion

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#requirement-encrypted-columns-on-source-must-be-preserved-byte-for-byte`, `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#requirement-owner-field-must-be-left-null-on-every-migrated-object`
- **files**: `lib/Service/Migration/LegacyToRegisterMigrator.php`
- **acceptance_criteria**:
  - GIVEN any legacy row WHEN inserted into `oc_openregister_objects` THEN the `owner` column is NULL
  - GIVEN a legacy row with a non-null `userId` column (CallLog/JobLog/EventMessage/Consumer/etc.) WHEN inserted THEN the object JSON body retains `userId` as a regular property (not promoted to `owner`)
  - GIVEN the migrator starts up WHEN it inspects `lib/Db/Source.php` setters and any `OCA\OpenConnector\Service\EncryptionService` references THEN it concludes either "column-level" (verbatim copy — provisional default) or "storage-level" (decrypt+re-encrypt required)
  - GIVEN the migrator detects storage-level encryption AND no decrypt+re-encrypt code path has been wired WHEN startup runs THEN it raises `\LogicException("storage-level encryption requires decrypt+re-encrypt path; abort")` BEFORE writing any rows to OR storage
- [ ] Implement
- [ ] Test

### Task 7: Set storage_migrated flag on full success only

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-005-migrator-must-set-the-storage-migrated-app-config-flag-on-success`
- **files**: `lib/Service/Migration/LegacyToRegisterMigrator.php`, `lib/Migration/Version2Date20260520xxxxxx.php`
- **acceptance_criteria**:
  - GIVEN all 15 entities migrate without skip/error AND `$entitySlug=null` WHEN `migrateAll()` returns THEN `IAppConfig::setValue('openconnector', 'storage_migrated', 'true')` has been called
  - GIVEN any entity raises an exception OR a per-entity row-count assertion fails WHEN `migrateAll()` returns or throws THEN `storage_migrated` is NOT set to `'true'` (remains unset or `'false'`)
  - GIVEN `$entitySlug` is non-null (single-entity retry) WHEN `migrateAll()` succeeds for that one entity THEN the flag is NOT flipped (full-run still required)
- [ ] Implement
- [ ] Test

### Task 8: Emit single summary audit-trail entry

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-012-migrator-must-not-emit-per-object-audit-trail-entries`
- **files**: `lib/Service/Migration/LegacyToRegisterMigrator.php`
- **acceptance_criteria**:
  - GIVEN the migrator completes a full or partial run WHEN it emits audit output THEN exactly ONE audit-trail entry is written to `oc_openregister_audit_trail` for the entire run
  - That entry's payload contains a `perEntity` JSON summary listing each entity's `legacyCount, migratedCount, skipped, fkRewrites, elapsedMs`
  - GIVEN the migrator processes any number of OR objects WHEN bulk INSERTs execute THEN OR's per-object audit-trail emission is bypassed (the migrator does NOT route through `ObjectService::saveObject`)
- [ ] Implement
- [ ] Test

### Task 9: Add ObjectMapperFacade service

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-006-objectmapperfacade-must-translate-the-mapper-api-to-objectservice`
- **files**: `lib/Service/Storage/ObjectMapperFacade.php`
- **acceptance_criteria**:
  - Exposes: `find(string $registerSlug, string $schemaSlug, int $id): Entity`; `findByUuid(string, string, string): Entity`; `findBySlug(string, string, string): Entity`; `findAll(string, string, ?int, ?int, array, ?string, ?array): Entity[]`; `createFromArray(string, string, array, string $entityClass): Entity`; `updateFromArray(string, string, string $uuid, array, string $entityClass): Entity`; `delete(string, string, string $uuid): void`
  - Each method delegates to OR's `ObjectService` with the appropriate filter, then hydrates the returned `ObjectEntity` into the openconnector typed entity (`Source`, `Job`, …) via that entity's existing `hydrate(array)` method
  - GIVEN a freshly-booted facade with empty cache WHEN `find(…, 'source', 42)` is called THEN it queries OR with filter `['id' => 42]`, retrieves the object, populates the int-id→uuid cache (`42 → U`) for that register/schema, and returns the hydrated `Source`
  - GIVEN the cache contains `42 → U` WHEN `find(…, 'source', 42)` is called again THEN no new OR query is issued — the cache returns the uuid directly
  - GIVEN the cache contains `42 → U` WHEN `delete(…, 'source', U)` or `createFromArray(…, 'source', …)` is called THEN the int-id→uuid cache for that register/schema is invalidated (cleared, not selectively dropped — simpler and rare write pattern)
  - GIVEN OR raises `DoesNotExistException` WHEN a `find*` method runs THEN the facade re-raises the same exception class so callers' catch blocks keep working
- [ ] Implement
- [ ] Test

### Task 10: Rewrite SourceMapper, ConsumerMapper, EndpointMapper as flag-gated facades

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-007-15-mappers-must-delegate-to-the-facade-behind-a-flag`
- **files**: `lib/Db/SourceMapper.php`, `lib/Db/ConsumerMapper.php`, `lib/Db/EndpointMapper.php`
- **acceptance_criteria**:
  - Each mapper reads `openconnector.storage_migrated` once at construction time via injected `IAppConfig` and caches it as a `bool $useFacade`
  - GIVEN `$useFacade === false` WHEN any public method is called THEN the existing legacy `QBMapper` SQL path runs unchanged
  - GIVEN `$useFacade === true` WHEN any public method is called THEN it delegates to the injected `ObjectMapperFacade` with the mapper's register slug `'openconnector'` and its bound schema slug (`'source'`, `'consumer'`, `'endpoint'`)
  - Public method signatures, exception types, and return types are byte-for-byte preserved (see contract.md for the canonical list — `find`, `findAll`, `findByUuid`, `findBySlug` where applicable, `createFromArray`, `updateFromArray`, `delete`)
- [ ] Implement
- [ ] Test

### Task 11: Rewrite EventMapper, EventMessageMapper, EventSubscriptionMapper as flag-gated facades

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-007-15-mappers-must-delegate-to-the-facade-behind-a-flag`
- **files**: `lib/Db/EventMapper.php`, `lib/Db/EventMessageMapper.php`, `lib/Db/EventSubscriptionMapper.php`
- **acceptance_criteria**:
  - Same flag/facade pattern as Task 10
  - `EventMessageMapper` preserves the bespoke helpers `findPending()` and `findByEventId(int)`; on the facade path these translate to `ObjectMapperFacade::findAll` calls with the appropriate filter array
  - Public method signatures preserved byte-for-byte per contract.md
- [ ] Implement
- [ ] Test

### Task 12: Rewrite JobMapper, MappingMapper, RuleMapper as flag-gated facades

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-007-15-mappers-must-delegate-to-the-facade-behind-a-flag`
- **files**: `lib/Db/JobMapper.php`, `lib/Db/MappingMapper.php`, `lib/Db/RuleMapper.php`
- **acceptance_criteria**:
  - Same flag/facade pattern as Task 10
  - `JobMapper::findDueJobs()` translates to `ObjectMapperFacade::findAll` with a `nextRun <= now` filter
  - `RuleMapper::findByTiming(string)` and `findByAction(string)` translate to single-filter `findAll` calls
  - Public method signatures preserved byte-for-byte per contract.md
- [ ] Implement
- [ ] Test

### Task 13: Rewrite Synchronization* mappers (3 entities) as flag-gated facades

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-007-15-mappers-must-delegate-to-the-facade-behind-a-flag`
- **files**: `lib/Db/SynchronizationMapper.php`, `lib/Db/SynchronizationContractMapper.php`, `lib/Db/SynchronizationLogMapper.php`
- **acceptance_criteria**:
  - Same flag/facade pattern as Task 10
  - `SynchronizationMapper::findByReference(string)` translates to a single-filter `findAll`
  - `SynchronizationContractMapper::findByOriginId(string)` and `findBySynchronizationId(string)` translate to single-filter `findAll`
  - `SynchronizationLogMapper` UPDATE/DELETE paths propagate OR's `AppendOnlyException` unchanged when the facade is active (chain A declared `appendOnly: true` on log schemas)
  - Public method signatures preserved byte-for-byte per contract.md
- [ ] Implement
- [ ] Test

### Task 14: Rewrite log mappers (CallLogMapper, JobLogMapper, SynchronizationContractLogMapper) as flag-gated facades

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-007-15-mappers-must-delegate-to-the-facade-behind-a-flag`
- **files**: `lib/Db/CallLogMapper.php`, `lib/Db/JobLogMapper.php`, `lib/Db/SynchronizationContractLogMapper.php`
- **acceptance_criteria**:
  - Same flag/facade pattern as Task 10
  - `CallLogMapper::findExpired()` translates to a `findAll` with an `expires < now` filter (note: chain A declared `x-openregister-archival` on the call_log schema; this method's data is fed by that workflow post-migration but the method itself remains callable for legacy listing)
  - UPDATE / DELETE paths on all three log mappers propagate OR's `AppendOnlyException` when the facade is active. The facade MAY translate `AppendOnlyException` to `LogicException` so that callers that today catch `LogicException` keep working — document the chosen translation in the facade's PHPDoc
  - Public method signatures preserved byte-for-byte per contract.md
- [ ] Implement
- [ ] Test

### Task 15: Add MigrateToOpenRegister OCC command

- **spec_ref**: `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md#req-008-occ-command-must-allow-manual-re-runnability`
- **files**: `lib/Command/MigrateToOpenRegister.php`, `appinfo/info.xml` (register command)
- **acceptance_criteria**:
  - GIVEN `occ openconnector:migrate-storage` is invoked WHEN no flags are passed THEN it calls `LegacyToRegisterMigrator::migrateAll(dryRun=false, entitySlug=null, batchSize=10000)`, emits one progress line per batch to stdout, exits 0 on success, non-zero on partial/total failure
  - GIVEN `--dry-run` is passed WHEN the command runs THEN no rows are inserted into `oc_openregister_objects`, the command reports legacy counts only, the `storage_migrated` flag is unchanged
  - GIVEN `--entity=<slug>` is passed WHEN the value is not one of the 15 valid slugs THEN the command prints an error listing the valid slugs and exits non-zero
  - GIVEN `--batch-size=<n>` is passed WHEN `n` is outside [100, 100000] THEN the command prints an error and exits non-zero
  - Command output uses Symfony Console formatters; verbosity controlled by `-v`/`-vv`
- [ ] Implement
- [ ] Test

### Task 16: Add MigrateStorageController for admin HTTP trigger

- **spec_ref**: `openspec/changes/openconnector-register-storage/contract.md`
- **files**: `lib/Controller/MigrateStorageController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN an admin POSTs to `/api/admin/openconnector/migrate-storage` with a valid body WHEN auth passes THEN the controller calls `LegacyToRegisterMigrator::migrateAll(...)` with the supplied `dryRun`, `entity`, `batchSize` args
  - GIVEN a non-admin posts WHEN auth runs THEN response is 403
  - GIVEN no session WHEN posts THEN response is 401
  - GIVEN `entity` is not one of the 15 slugs OR `batchSize` is outside [100, 100000] OR body is malformed THEN response is 400
  - GIVEN `storage_migrated === 'true'` AND `entity` is null in the body WHEN posts THEN response is 409 with a hint about using `entity` for targeted retry
  - GIVEN the migrator raises mid-run WHEN posts THEN response is 500 with a per-entity tally up to the failure point AND an `error` field with a stack-trace-truncated message
  - `GET /api/admin/openconnector/migrate-storage/status` returns `storageMigrated`, `flagSetAt`, `perEntityRowCounts`, `readOnlyLockActive` per contract.md
- [ ] Implement
- [ ] Test

### Task 17: Verify post-migration row counts match (seed-data deviation per ADR-001)

- **spec_ref**: `openspec/changes/openconnector-register-storage/design.md` (Seed Data section), `openspec/changes/openconnector-register-storage/migration.md` (Validation table)
- **files**: `lib/Service/Migration/LegacyToRegisterMigrator.php` (verification helper), `tests/Unit/Service/Migration/LegacyToRegisterMigratorTest.php`
- **acceptance_criteria**:
  - **Note: ADR-001 deviation.** This change has no hand-crafted seed set — the seed IS the migrated live data. Deviation rationale documented in design.md "Seed Data" section. This task replaces the usual "seed JSON ships under config/" task with a row-count parity verification.
  - The migrator exposes a public `verifyRowCounts(): array` method that, per entity, returns `['slug' => $slug, 'legacy' => $legacyCount, 'register' => $registerCount, 'equal' => bool]`
  - GIVEN the migration completed WHEN `verifyRowCounts()` runs THEN every entity reports `equal === true` (modulo skipped rows accounted in the migrator return tally — those rows count toward `legacy` but NOT `register`, and the helper subtracts skipped to confirm parity)
  - The OCC command surfaces this via `occ openconnector:migrate-storage --verify-only`
  - In the dev container, after running the migration against chain-A's 33 seed objects, all 15 entity counts MUST match
- [ ] Implement
- [ ] Test

### Task 18: File follow-up GitHub issues for deferred work

- **spec_ref**: `openspec/changes/openconnector-register-storage/proposal.md` (Out of Scope), `openspec/changes/openconnector-register-storage/specs/openconnector-storage-migration/spec.md` (Notes section)
- **files**: GitHub issues (no repo files)
- **acceptance_criteria**:
  - Per user's "always file issues for deferred work" rule, the following issues MUST be filed on `ConductionNL/openconnector` BEFORE this change is marked applied:
    1. **Cleanup change**: drop `oc_openconnector_*` tables and remove `openconnector.storage_migrated` flag — scheduled for the release AFTER this change ships
    2. **FK rename**: rename `*Id` → target-schema-name fields in the descriptor and call sites once frontend Vue stores have been updated
    3. **CallLog.actionId resolution**: inspect `lib/Service/CallService.php` and `EndpointService.php` to determine the target schema for `actionId`; promote `action` to a real `$ref` relation
    4. **Frontend Vue store audit**: confirm no store relies on the legacy `*Id` form once the FK rename above is scheduled
  - Each issue links back to this change's slug and to the DEFERRED_QUESTIONS entry it resolves
- [ ] Implement
- [ ] Test

## Verification

- [ ] All tasks checked off
- [ ] `openspec validate openconnector-register-storage` passes
- [ ] Manual testing against acceptance criteria in the dev container (see test-plan.md "Manual verification" section)
- [ ] Code review against spec requirements (REQ-001 through REQ-012)
- [ ] Cross-check with chain A (`openconnector-register-schema-declaration`): register descriptor + 15 schemas present on disk; this change's migration class consumes them via `importFromApp`
- [ ] All 4 follow-up issues from Task 18 are filed and linked

## Tests (company-wide ADR-009)

<!-- Required for all changes. -->

- [ ] PHPUnit unit tests for new/changed business logic in `tests/Unit/Service/Migration/LegacyToRegisterMigratorTest.php` and `tests/Unit/Service/Storage/ObjectMapperFacadeTest.php`
- [ ] PHPUnit integration tests for the facade in `tests/Integration/Service/Storage/ObjectMapperFacadeTest.php`
- [ ] Newman/Postman tests for the new admin HTTP endpoints (`POST /api/admin/openconnector/migrate-storage`, `GET /api/admin/openconnector/migrate-storage/status`)
- [ ] Browser tests (Playwright MCP) — N/A: this change ships no Vue/UI surface (admin-only HTTP + OCC + migration class). Documented in test-plan.md "Out of Scope".
- [ ] All tests pass (`composer test`, `newman run tests/Postman/<collection>.json`)

## Documentation (company-wide ADR-010)

<!-- See `.claude/docs/writing-docs.md` for documentation principles. -->

- [ ] Migrator + OCC command documented in `docs/admin/storage-migration.md` (new file) — explains when to run, dry-run flow, retry pattern, rollback procedure (mirrors migration.md)
- [ ] Operator runbook entry in `docs/admin/rollback.md` covering the flag-flip rollback within the one-release transition window
- [ ] Screenshot N/A — no UI surface in this change
- [ ] Release notes for the cleanup change (one release out) must call out the "operators MUST run the migration in release N before upgrading to release N+1" gate per contract.md "Breaking Change Policy"

## i18n (company-wide hydra ADR-007)

<!-- Required when adding user-facing strings. -->

- [ ] N/A — this change adds no user-facing strings. The OCC command outputs in English only (operator audience); the admin HTTP endpoints return JSON consumed programmatically. Justification documented in spec.md "Non-Functional Requirements → Internationalization".
