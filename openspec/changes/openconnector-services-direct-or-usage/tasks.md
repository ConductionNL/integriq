# Tasks: openconnector-services-direct-or-usage

## Overview

This task list drives the apply phase for chain C. Tasks are ordered bottom-up
(additive work first, deletions last). Each task is atomic enough to ship behind
`composer check:strict` — the gate MUST be green before moving to the next task.

Per ADR-031 this is a pure imperative refactor: no declarative schema/seed work
is included. Per proposal.md, no PR/merge/archive steps are listed here.

---

## Phase 0 — Pre-flight and DI plumbing

### Task 1: Add pre-flight storage_migrated assertion to Application.php

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-applicationphp-di-bindings-must-be-updated`
- **files**: `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN `openconnector.storage_migrated` is absent or `'false'` WHEN the app boots THEN `\LogicException` is thrown with a message containing `occ openconnector:migrate-storage`
  - GIVEN the env var `OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT=1` is set WHEN the app boots THEN no exception is thrown regardless of the flag value
  - GIVEN `storage_migrated === 'true'` WHEN the app boots THEN `Application::register()` completes normally
- [ ] Implement
- [ ] Test

---

## Phase 1 — Additive work (DTOs and helper service)

### Task 2: Create 15 input DTO classes under lib/Db/Dto/

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-15-input-dto-classes-must-be-introduced-for-write-side-validation`
- **files**:
  - `lib/Db/Dto/CallLogDto.php`
  - `lib/Db/Dto/ConsumerDto.php`
  - `lib/Db/Dto/EndpointDto.php`
  - `lib/Db/Dto/EventDto.php`
  - `lib/Db/Dto/EventMessageDto.php`
  - `lib/Db/Dto/EventSubscriptionDto.php`
  - `lib/Db/Dto/JobDto.php`
  - `lib/Db/Dto/JobLogDto.php`
  - `lib/Db/Dto/MappingDto.php`
  - `lib/Db/Dto/RuleDto.php`
  - `lib/Db/Dto/SourceDto.php`
  - `lib/Db/Dto/SynchronizationContractDto.php`
  - `lib/Db/Dto/SynchronizationContractLogDto.php`
  - `lib/Db/Dto/SynchronizationDto.php`
  - `lib/Db/Dto/SynchronizationLogDto.php`
- **acceptance_criteria**:
  - Each DTO is `final class`, has typed read-only constructor properties, `static fromArray(array): self`, and `toArray(): array`
  - No DTO includes `id`, `uuid`, `created`, `updated`, or `owner` properties
  - `SourceDto::fromArray([])` throws `\InvalidArgumentException` (missing `name`)
  - `SourceDto::fromArray(['name' => 'test', 'type' => 'api'])->toArray()` returns `['name' => 'test', 'type' => 'api']`
  - `composer check:strict` passes after addition
- [ ] Implement
- [ ] Test

### Task 3: Create SyncRefResolver helper service

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-synchronizationsourceid-branching-logic-must-survive-intact`
- **files**: `lib/Service/Helper/SyncRefResolver.php`
- **design_ref**: design.md D6 — `SyncRefResolver` extracted from chain B's `LegacyToRegisterMigrator::resolveSyncRef`
- **acceptance_criteria**:
  - `SyncRefResolver::resolve("42")` returns `['value' => '42', 'variant' => 'integer-pk']`
  - `SyncRefResolver::resolve("openconnector/source")` returns `['value' => 'openconnector/source', 'variant' => 'register-schema']`
  - `SyncRefResolver::resolve("00000000-0000-0000-0000-000000000000")` returns `['value' => '00000000-0000-0000-0000-000000000000', 'variant' => 'uuid']`
  - `SyncRefResolver::resolve("")` returns a result with `'variant' => 'unrecognised'` without throwing
  - Constructor injects `\OCA\OpenRegister\Service\ObjectService` for the integer-PK branch lookup
  - `composer check:strict` passes
- [x] Implement <!-- lib/Service/Helper/SyncRefResolver.php extracted from LegacyToRegisterMigrator::resolveSyncRef; covers all 4 variants with ObjectService injection -->
- [x] Test <!-- tests/Unit/Service/Helper/SyncRefResolverTest.php — 7 tests covering integer-pk (resolved+unresolved+OS-failure), register-schema, uuid, empty-string, unknown-shape -->

---

## Phase 2 — Service rewrites (leaf services first, then mid-tier)

### Task 4: Rewrite MappingService

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-service-must-be-rewritten-to-inject-objectservice-directly`
- **files**: `lib/Service/MappingService.php`
- **acceptance_criteria**:
  - Constructor injects `\OCA\OpenRegister\Service\ObjectService`; no `MappingMapper` or `Mapping` entity reference remains
  - Read calls use `$objectService->find('openconnector', 'mapping', $uuid)`
  - Write calls use `$objectService->saveObject('openconnector', 'mapping', $data, $uuid)`
  - Delete calls use `$objectService->delete('openconnector', 'mapping', $uuid)`
  - `composer check:strict` passes; existing MappingService unit test passes after rewrite
- [ ] Implement
- [ ] Test

### Task 5: Rewrite RuleService

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-service-must-be-rewritten-to-inject-objectservice-directly`
- **files**: `lib/Service/RuleService.php`
- **acceptance_criteria**:
  - Constructor injects `ObjectService`; no `RuleMapper` or `Rule` entity reference remains
  - ADR-002 (mapping/rule engine stays app-local): rule processing logic in `RuleService` is preserved; only the persistence calls change
  - `composer check:strict` passes
- [ ] Implement
- [ ] Test

### Task 6: Rewrite EventService (and EventMessage, EventSubscription sub-resources)

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-service-must-be-rewritten-to-inject-objectservice-directly`
- **files**: `lib/Service/EventService.php`
- **acceptance_criteria**:
  - No `EventMapper`, `EventMessageMapper`, `EventSubscriptionMapper`, `Event`, `EventMessage`, or `EventSubscription` entity reference remains in `EventService`
  - All CRUD calls go through `ObjectService` with schema slugs `'event'`, `'event_message'`, `'event_subscription'`
  - `composer check:strict` passes
- [ ] Implement
- [ ] Test

### Task 7: Rewrite CallService

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-source-credential-fields-must-be-handled-with-explicit-encryptionservice-calls`
- **files**: `lib/Service/CallService.php`
- **design_ref**: design.md — ADR-003 (CallLog is the primary observability surface); ADR-007 (credentials stored as plaintext)
- **acceptance_criteria**:
  - No `CallLogMapper`, `SourceMapper`, `CallLog`, or `Source` entity reference remains
  - Every call site that reads `apikey`, `password`, `secret`, `jwt`, `jwtId`, or `username` from a Source `ObjectEntity` wraps the value in `$this->encryptionService->decrypt(...)` — even if `EncryptionService` is a no-op pass-through until fully wired
  - `CallLog` writes use `ObjectService::saveObject('openconnector', 'call_log', $data)` — per ADR-003, every outbound HTTP call MUST produce a CallLog
  - `composer check:strict` passes
- [ ] Implement
- [ ] Test

### Task 8: Rewrite JobService

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-service-must-be-rewritten-to-inject-objectservice-directly`
- **files**: `lib/Service/JobService.php`
- **acceptance_criteria**:
  - No `JobMapper`, `JobLogMapper`, `Job`, or `JobLog` entity reference remains
  - CRUD calls use schema slugs `'job'` and `'job_log'`
  - `composer check:strict` passes
- [ ] Implement
- [ ] Test

### Task 9: Rewrite EndpointService

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-endpoint-targettypetargetid-dispatch-logic-must-be-preserved`
- **files**: `lib/Service/EndpointService.php`
- **design_ref**: ADR-008 — `targetType` / `targetId` polymorphic dispatch is preserved; read `targetType` and `targetId` from `$obj->getObject()['targetType']` instead of `$endpoint->getTargetType()`
- **acceptance_criteria**:
  - `targetType = 'register/schema'` branch still splits `targetId` on `/` and dispatches to ObjectService CRUD
  - `targetType = 'api'` branch reads the Source via `ObjectService::find('openconnector', 'source', $targetId)`
  - No `EndpointMapper` or `Endpoint` entity reference remains
  - `composer check:strict` passes
- [ ] Implement
- [ ] Test

### Task 10: Rewrite SynchronizationService (uses SyncRefResolver)

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-synchronizationsourceid-branching-logic-must-survive-intact`
- **files**: `lib/Service/SynchronizationService.php`
- **design_ref**: design.md D6; ADR-005 (Source / Synchronization / Contract triad)
- **acceptance_criteria**:
  - Constructor injects `ObjectService` and `SyncRefResolver`
  - `resolveSyncRef` functionality is now delegated to `SyncRefResolver::resolve()`
  - No `SynchronizationMapper`, `SynchronizationContractMapper`, `SynchronizationLogMapper`, `SynchronizationContractLogMapper`, `Synchronization`, `SynchronizationContract`, `SynchronizationLog`, or `SynchronizationContractLog` entity reference remains
  - Per-object hash comparison (the change-detection primitive per ADR-005) is preserved
  - `composer check:strict` passes
- [ ] Implement
- [ ] Test

### Task 11: Rewrite remaining mid-tier services

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-service-must-be-rewritten-to-inject-objectservice-directly`
- **files** (verify which have mapper imports via `grep -rln "Mapper" lib/Service/`):
  - `lib/Service/AuthorizationService.php`
  - `lib/Service/ConfigurationService.php`
  - `lib/Service/EndpointCacheService.php`
  - `lib/Service/ExportService.php`
  - `lib/Service/ImportService.php`
  - `lib/Service/SearchService.php` (if applicable)
  - `lib/Service/ConsumerService.php` (if exists)
  - `lib/Service/ConfigurationHandlers/` — rewrite each handler file that imports a mapper
  - Any other service file matching `grep -ln "Mapper" lib/Service/`
- **acceptance_criteria**:
  - `grep -rn "Mapper" lib/Service/` returns zero results for any of the 15 deleted mapper names
  - `grep -rn "OCA\\\\OpenConnector\\\\Db\\\\" lib/Service/` returns zero results for entity types (excluding `Dto\` imports)
  - `composer check:strict` passes
- [ ] Implement
- [ ] Test

---

## Phase 3 — Controller rewrites

### Task 12: Rewrite SourcesController

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-controller-must-receive-data-via-rewritten-services-not-via-mappers`
- **files**: `lib/Controller/SourcesController.php`
- **acceptance_criteria**:
  - Constructor injects `ObjectService` (or the rewritten service layer); no `SourceMapper` or `Source` entity dependency remains
  - `POST /api/sources` uses `SourceDto::fromArray($request->getParams())` before calling `ObjectService::saveObject(...)` — throws `\InvalidArgumentException` on missing required field (HTTP 400)
  - `GET /api/sources` returns response whose JSON is derived from `ObjectEntity::jsonSerialize()` — field names identical to chain B baseline
  - `GET /api/sources/{id}` returns HTTP 404 when `ObjectService::find()` throws `DoesNotExistException`
  - All existing `@AuthorizedAdminSetting` / `@NoCSRFRequired` annotations preserved
  - `composer check:strict` passes
- [ ] Implement
- [ ] Test

### Task 13: Rewrite all remaining controllers (~17 files)

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-controller-must-receive-data-via-rewritten-services-not-via-mappers`
- **files**:
  - `lib/Controller/ConsumersController.php`
  - `lib/Controller/EndpointsController.php`
  - `lib/Controller/EventsController.php`
  - `lib/Controller/ExportController.php`
  - `lib/Controller/ImportController.php`
  - `lib/Controller/JobsController.php`
  - `lib/Controller/LogsController.php`
  - `lib/Controller/MappingsController.php`
  - `lib/Controller/RulesController.php`
  - `lib/Controller/SettingsController.php`
  - `lib/Controller/SynchronizationContractsController.php`
  - `lib/Controller/SynchronizationsController.php`
  - `lib/Controller/DashboardController.php` (verify if mapper-consuming)
  - `lib/Controller/DSOController.php` (verify if mapper-consuming)
  - `lib/Controller/HealthController.php` (verify if mapper-consuming)
  - `lib/Controller/MetricsController.php` (verify if mapper-consuming)
  - `lib/Controller/UiController.php` (verify — likely mapper-free)
  - `lib/Controller/UserController.php` (verify — likely mapper-free)
- **acceptance_criteria**:
  - `grep -rn "Mapper \$" lib/Controller/` returns zero matches
  - `grep -rn "OCA\\\\OpenConnector\\\\Db\\\\" lib/Controller/` returns zero results for entity types (excluding `Dto\` imports)
  - HTTP wire format verified by Newman collection against the deployed chain C instance (all tests pass)
  - `composer check:strict` passes
- [ ] Implement
- [ ] Test

---

## Phase 4 — Cron task rewrites

### Task 14: Rewrite JobTask and LogCleanUpTask cron tasks

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-service-must-be-rewritten-to-inject-objectservice-directly`
- **files**:
  - `lib/Cron/JobTask.php`
  - `lib/Cron/LogCleanUpTask.php`
- **acceptance_criteria**:
  - `JobTask` queries due jobs via `ObjectService::findAll('openconnector', 'job', $filters)` instead of `JobMapper::findDue()`
  - `LogCleanUpTask` queries expired logs via `ObjectService::findAll('openconnector', '<log-schema>', $retentionFilters)` for each applicable log schema
  - No `JobMapper`, `JobLogMapper`, `SynchronizationLogMapper`, `CallLogMapper`, `Job`, or log entity references remain
  - `composer check:strict` passes
- [ ] Implement
- [ ] Test

---

## Phase 5 — Update DI bindings

### Task 15: Remove all mapper service registrations from Application.php

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-applicationphp-di-bindings-must-be-updated`
- **files**: `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - All `$context->registerService(<Resource>Mapper::class, …)` calls for the 15 deleted mapper types are removed
  - `ObjectService` is NOT manually registered in `Application.php` (it is provided by openregister's own container registration and resolved by constructor injection)
  - `SyncRefResolver` IS registered via `$context->registerService(SyncRefResolver::class, …)` if required (verify DI auto-wiring; if not auto-wired, add)
  - `composer check:strict` passes (Psalm verifies no dangling DI alias)
- [ ] Implement
- [ ] Test

---

## Phase 6 — Test rewrites

### Task 16: Rewrite all unit tests to mock ObjectService

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-existing-unit-tests-must-be-rewritten-to-mock-objectservice`
- **files** (all test files that import deleted types — grep first):
  - `tests/Unit/Controller/*.php` — approximately 4 files
  - `tests/Unit/Service/*.php` — approximately 10 files
  - `tests/Unit/Db/` — any fixtures importing deleted entity types
- **guidance**:
  - Replace `$mapper = $this->createMock(SourceMapper::class)` with `$objectService = $this->createMock(ObjectService::class)`
  - Replace entity fixtures (e.g. `new Source()`) with `$this->createMock(ObjectEntity::class)` configured with `getObject()` returning an array
  - Preserve assertion intent — verify the same business logic, just against the new call surface
- **acceptance_criteria**:
  - `grep -rn "OCA\\\\OpenConnector\\\\Db\\\\" tests/` returns zero results for entity/mapper types (excluding `Dto\` tests)
  - `composer phpunit` exits 0 with ≥ 80% line coverage and ≥ 70% branch coverage on rewritten services
  - Chain B's 4 `SyncRefResolver` scenarios pass in the new test location (`tests/Unit/Service/Helper/SyncRefResolverTest.php`)
- [ ] Implement
- [ ] Test

### Task 17: Add DTO unit tests under tests/Unit/Db/Dto/

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-15-input-dto-classes-must-be-introduced-for-write-side-validation`
- **files**: `tests/Unit/Db/Dto/*DtoTest.php` (15 files, one per DTO)
- **acceptance_criteria**:
  - Each DTO test covers: valid `fromArray()` round-trip, `fromArray()` with missing required field throws `\InvalidArgumentException`, `toArray()` returns only user-supplied fields (no OR metadata fields)
  - All 15 DTO tests pass
  - `composer phpunit` exits 0
- [ ] Implement
- [ ] Test

---

## Phase 7 — Delete deleted files and add quality gate

### Task 18: Delete all 31 mapper, entity, and facade files

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-all-15-mapper-files-must-be-deleted`
- **files**: all 31 files listed in migration.md "Per-File Deletion Checklist"
- **pre-conditions**:
  - ALL Phase 2–5 tasks are complete and `composer check:strict` is green
  - ALL Phase 6 tests pass
  - The pre-deletion grep gate passes: `grep -rn "OCA\\\\OpenConnector\\\\Db\\\\" lib/ tests/` returns only `Dto\` namespace imports
- **acceptance_criteria**:
  - `find lib/Db -maxdepth 1 -name '*.php' ! -path '*/Dto/*'` returns zero results
  - `find lib/Service/Storage -name 'ObjectMapperFacade.php' 2>/dev/null` returns zero results
  - `composer check:strict` exits 0 after deletion (autoload regenerated, no dangling class references)
  - `composer dump-autoload --dry-run` lists no deleted class names
- [ ] Implement (delete files, update composer.json autoload, psalm.xml/phpstan.neon exclusion entries)
- [ ] Test

### Task 19: Add quality gate to composer check:strict

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-no-file-under-lib-or-tests-may-reference-deleted-types-post-merge`
- **files**: `composer.json` (scripts section), and/or a PHPCS custom sniff file
- **design_ref**: design.md D4 — PHPCS sniff preferred; grep-based `composer.json` scripts entry as fallback
- **acceptance_criteria**:
  - `composer check:strict` includes a step that greps for all 30 deleted entity/mapper class names under `lib/` and `tests/`
  - Introducing `use OCA\OpenConnector\Db\Source;` into any file under `lib/` causes `composer check:strict` to exit non-zero with a human-readable error
  - Introducing `use OCA\OpenConnector\Db\Dto\SourceDto;` does NOT cause a failure (DTOs are permitted)
  - CI pipeline runs `composer check:strict` on every push to the chain C branch
- [ ] Implement
- [ ] Test

---

## Phase 8 — Verification

### Task 20: Run full verification suite

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-newmanpostman-integration-tests-must-still-pass`
- **files**: (no file changes — verification only)
- **acceptance_criteria**:
  - `composer check:strict` exits 0 (PHPCS, PHPMD, Psalm, PHPStan all pass)
  - `composer phpunit` exits 0 with ≥ 80% line / ≥ 70% branch coverage on rewritten services
  - Newman collection exits 0 (all REST endpoint tests pass, wire format unchanged)
  - The pre-flight assertion test passes in both `storage_migrated=true` and `OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT=1` modes
  - No match for deleted type names in `grep -rn "OCA\\\\OpenConnector\\\\Db\\\\" lib/ tests/` (excluding `Dto\`)
- [ ] Implement
- [ ] Test

---

## Compliance Checklist

### ADR Compliance

- [ ] **ADR-001**: No `lib/Db/<Entity>.php` domain-data classes remain after this change
- [ ] **ADR-002**: Mapping and rule engine logic in `MappingService`/`RuleService` preserved; only the persistence layer changes
- [ ] **ADR-003**: Every outbound HTTP call in `CallService` still produces a `CallLog` write via `ObjectService::saveObject('openconnector', 'call_log', ...)`
- [ ] **ADR-005**: `Source → Synchronization → SynchronizationContract` triad preserved; per-object hash comparison logic intact in `SynchronizationService`
- [ ] **ADR-007**: Every read of a credential field (`apikey`, `password`, `secret`, `jwt`, `jwtId`, `username`) from a Source `ObjectEntity` is wrapped in `EncryptionService::decrypt(...)`
- [ ] **ADR-008**: `EndpointService` `targetType`/`targetId` polymorphic dispatch branches preserved for all four known `targetType` values
- [ ] **ADR-009**: No new MySQL-specific raw SQL introduced; known pre-existing violations in `SettingsService` left in place (separate follow-up)
- [ ] **ADR-011**: `FlowToken` usage in endpoint and sync pipelines preserved; only underlying persistence calls change

### Spec Compliance

- [ ] All 13 requirements in `specs/openconnector-direct-or-usage/spec.md` have at least one passing test scenario
- [ ] Wire-format parity verified by Newman collection: same JSON in/out for all 15 resources × 5 CRUD methods
- [ ] Quality gate (Task 19) is active in CI
