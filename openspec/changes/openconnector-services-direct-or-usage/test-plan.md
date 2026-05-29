# Test Plan: openconnector-services-direct-or-usage

## Overview

This test plan maps every scenario in the chain C spec to a named test case,
and adds regression, integration, and smoke-test cases to satisfy the
wire-format guarantee and cross-ADR obligations. Coverage thresholds:
**≥ 80% line / ≥ 70% branch** on rewritten services and controllers.

---

## Unit Tests

### TC-UNIT-001: SourceDto rejects missing required name field

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-15-input-dto-classes-must-be-introduced-for-write-side-validation`
- **type**: functional
- **preconditions**: `SourceDto` class exists at `lib/Db/Dto/SourceDto.php`
- **steps**: Call `SourceDto::fromArray([])` (empty array)
- **expected result**: `\InvalidArgumentException` is thrown with a message mentioning the missing `name` field

### TC-UNIT-002: SourceDto round-trip serialisation

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-15-input-dto-classes-must-be-introduced-for-write-side-validation`
- **type**: functional
- **preconditions**: `SourceDto` class exists
- **steps**: Call `SourceDto::fromArray(['name' => 'test', 'type' => 'api'])->toArray()`
- **expected result**: Returned array equals `['name' => 'test', 'type' => 'api']`; no `id`, `uuid`, `created`, `updated`, or `owner` keys are present

### TC-UNIT-003: SyncRefResolver resolves integer-PK sourceId

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-synchronizationsourceid-branching-logic-must-survive-intact`
- **type**: functional
- **preconditions**: `SyncRefResolver` constructed with a mocked `ObjectService`
- **steps**: Call `SyncRefResolver::resolve("42")`
- **expected result**: Returns `['value' => '42', 'variant' => 'integer-pk']`; `ObjectService::find('openconnector', 'source', '42')` is called once

### TC-UNIT-004: SyncRefResolver resolves register-schema slug-pair

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-synchronizationsourceid-branching-logic-must-survive-intact`
- **type**: functional
- **preconditions**: `SyncRefResolver` constructed with a mocked `ObjectService`
- **steps**: Call `SyncRefResolver::resolve("openconnector/source")`
- **expected result**: Returns a result with `'variant' => 'register-schema'`; no `ObjectService::find` call is made for this branch

### TC-UNIT-005: SyncRefResolver passes UUID through unchanged

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-synchronizationsourceid-branching-logic-must-survive-intact`
- **type**: functional
- **preconditions**: `SyncRefResolver` constructed with a mocked `ObjectService`
- **steps**: Call `SyncRefResolver::resolve("00000000-0000-0000-0000-000000000000")`
- **expected result**: Returns `['value' => '00000000-0000-0000-0000-000000000000', 'variant' => 'uuid']`

### TC-UNIT-006: SyncRefResolver does not throw on unrecognised sourceId

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-synchronizationsourceid-branching-logic-must-survive-intact`
- **type**: functional
- **preconditions**: `SyncRefResolver` constructed with a mocked `ObjectService`
- **steps**: Call `SyncRefResolver::resolve("")`
- **expected result**: Returns a result with `'variant' => 'unrecognised'`; no exception is thrown

### TC-UNIT-007: SourceService calls ObjectService::find correctly

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-service-must-be-rewritten-to-inject-objectservice-directly`
- **type**: functional
- **preconditions**: `SourceService` rewritten; `ObjectService` mocked to return a stub `ObjectEntity`
- **steps**: Call `SourceService::getSource("00000000-0000-0000-0000-000000000000")`
- **expected result**: `objectService->find('openconnector', 'source', '00000000-0000-0000-0000-000000000000')` is called exactly once; return value is the stub `ObjectEntity`

### TC-UNIT-008: JobService saves a job via ObjectService::saveObject

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-service-must-be-rewritten-to-inject-objectservice-directly`
- **type**: functional
- **preconditions**: `JobService` rewritten; `ObjectService` mocked
- **steps**: Call `JobService::createJob(['name' => 'test-job', 'interval' => '0 * * * *'])`
- **expected result**: `objectService->saveObject('openconnector', 'job', ['name' => 'test-job', 'interval' => '0 * * * *'])` is called once; returned `ObjectEntity` is the result

### TC-UNIT-009: Application pre-flight assertion fires on missing storage_migrated flag

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-applicationphp-di-bindings-must-be-updated`
- **type**: functional
- **preconditions**: `IAppConfig` mock returns `'false'` for `storage_migrated`; env var not set
- **steps**: Call `Application::register()` in a PHPUnit test that does NOT set `OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT`
- **expected result**: `\LogicException` is thrown; message contains `occ openconnector:migrate-storage`

### TC-UNIT-010: Application pre-flight bypassed by env var

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-applicationphp-di-bindings-must-be-updated`
- **type**: functional
- **preconditions**: `IAppConfig` mock returns `'false'`; env var `OPENCONNECTOR_SKIP_STORAGE_MIGRATED_ASSERT=1` is set
- **steps**: Call `Application::register()`
- **expected result**: No `\LogicException`; method completes normally

### TC-UNIT-011: CallService wraps apikey in EncryptionService::decrypt

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-source-credential-fields-must-be-handled-with-explicit-encryptionservice-calls`
- **type**: functional
- **preconditions**: `CallService` rewritten; `EncryptionService` mocked; stub `ObjectEntity` returns `['apikey' => 'encrypted-bytes']` from `getObject()`
- **steps**: Invoke `CallService` path that reads a source's `apikey` for Guzzle auth configuration
- **expected result**: `encryptionService->decrypt('encrypted-bytes')` is called; the raw `'encrypted-bytes'` value is NOT passed to Guzzle directly

### TC-UNIT-012: EndpointService dispatches register/schema targetType

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-endpoint-targettypetargetid-dispatch-logic-must-be-preserved`
- **type**: functional
- **preconditions**: `EndpointService` rewritten; an `ObjectEntity` stub with `getObject()` returning `['targetType' => 'register/schema', 'targetId' => '20/111']`
- **steps**: Call `EndpointService::handleEndpointRequest($endpointEntity, $request)`
- **expected result**: Code splits `targetId` on `/`, validates both parts as numeric, and dispatches to the OR ObjectService CRUD path

### TC-UNIT-013: EndpointService dispatches api targetType

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-endpoint-targettypetargetid-dispatch-logic-must-be-preserved`
- **type**: functional
- **preconditions**: `EndpointService` rewritten; endpoint stub with `targetType = 'api'` and `targetId = '00000000-0000-0000-0000-000000000000'`
- **steps**: Call `EndpointService::handleEndpointRequest($endpointEntity, $request)`
- **expected result**: `ObjectService::find('openconnector', 'source', '00000000-0000-0000-0000-000000000000')` is called once; `CallService` proxy is invoked

### TC-UNIT-014: EndpointService returns error on unknown targetType

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-endpoint-targettypetargetid-dispatch-logic-must-be-preserved`
- **type**: functional
- **preconditions**: endpoint stub with `targetType = 'unknown-type'`
- **steps**: Call `EndpointService::handleEndpointRequest($endpointEntity, $request)`
- **expected result**: Exception thrown with message identifying the unrecognised target type

---

## Integration / API Tests (Newman)

### TC-CHAIN-C-WIRE-PARITY: Sources list matches chain B contract fixture

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-newmanpostman-integration-tests-must-still-pass`
- **type**: api
- **preconditions**: Chain C deployed; Nextcloud running with `storage_migrated = 'true'`; at least one Source object in OR storage
- **steps**: Execute Newman test for `GET /api/sources`
- **expected result**: HTTP 200; response body has `results` array with `id`, `uuid`, `name`, `type`, `created`, `updated` fields; field names and value types match the stored contract fixture from contract.md

### TC-API-001: POST /api/sources creates a source

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-controller-must-receive-data-via-rewritten-services-not-via-mappers`
- **type**: api
- **preconditions**: Chain C deployed; authenticated admin session
- **steps**: POST to `/api/sources` with body `{"name": "test-source", "type": "api", "location": "https://example.example"}`
- **expected result**: HTTP 200 (or 201); response body contains `uuid`, `name = 'test-source'`, `type = 'api'`

### TC-API-002: POST /api/sources with missing name returns 400

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-controller-must-receive-data-via-rewritten-services-not-via-mappers`
- **type**: api
- **preconditions**: Chain C deployed; authenticated admin session
- **steps**: POST to `/api/sources` with body `{}` (no `name` field)
- **expected result**: HTTP 400; response body contains error message referencing the missing field

### TC-API-003: GET /api/sources/{id} returns 404 for unknown UUID

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-controller-must-receive-data-via-rewritten-services-not-via-mappers`
- **type**: api
- **preconditions**: Chain C deployed; authenticated session
- **steps**: GET `/api/sources/00000000-0000-0000-0000-000000000000` (UUID that does not exist)
- **expected result**: HTTP 404

### TC-API-004: POST /api/synchronizations/{id}/run still executable

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-newmanpostman-integration-tests-must-still-pass`
- **type**: api
- **preconditions**: A Synchronization object exists in OR storage; chain C deployed
- **steps**: POST to `/api/synchronizations/{uuid}/run`
- **expected result**: HTTP 200 (or 202); no PHP fatal error; response matches pre-chain-C response shape

### TC-API-005: Full Newman collection passes against chain C

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-newmanpostman-integration-tests-must-still-pass`
- **type**: regression
- **preconditions**: Chain C deployed; Nextcloud running; Newman installed; all 15 resources have at least one seeded object
- **steps**: Run `newman run tests/Http/openconnector.postman_collection.json --environment tests/Http/local.postman_environment.json`
- **expected result**: 0 failures; all assertions pass across all 15 resource CRUD suites

---

## Regression Tests

### TC-REG-001: No mapper file exists after chain C merge

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-all-15-mapper-files-must-be-deleted`
- **type**: regression
- **preconditions**: Chain C merge commit applied
- **steps**: Run `find lib/Db -maxdepth 1 -name '*Mapper.php'`
- **expected result**: Zero output

### TC-REG-002: No entity file exists after chain C merge

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-all-15-entity-files-must-be-deleted`
- **type**: regression
- **preconditions**: Chain C merge commit applied
- **steps**: Run `find lib/Db -maxdepth 1 -name '*.php' ! -path '*/Dto/*'`
- **expected result**: Zero output

### TC-REG-003: ObjectMapperFacade is gone

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-the-objectmapperfacade-must-be-deleted`
- **type**: regression
- **preconditions**: Chain C merge commit applied
- **steps**: Run `find lib/Service/Storage -name 'ObjectMapperFacade.php' 2>/dev/null`
- **expected result**: Zero output

### TC-REG-004: Quality gate blocks forbidden entity import

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-no-file-under-lib-or-tests-may-reference-deleted-types-post-merge`
- **type**: regression
- **preconditions**: Quality gate installed (Task 19 complete)
- **steps**: Temporarily add `use OCA\OpenConnector\Db\Source;` to any `lib/` file; run `composer check:strict`
- **expected result**: Exit code 1; human-readable error identifying the forbidden import; revert the temporary change

### TC-REG-005: Quality gate does not flag DTO imports

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-no-file-under-lib-or-tests-may-reference-deleted-types-post-merge`
- **type**: regression
- **preconditions**: Quality gate installed
- **steps**: Verify a `lib/Db/Dto/SourceDto.php` file contains `use OCA\OpenConnector\Db\Dto\SourceDto;` (self-reference or in a test); run `composer check:strict`
- **expected result**: Exit code 0 (DTO imports do not trigger the gate)

### TC-REG-006: No new MySQL-specific SQL introduced

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-multi-platform-db-compatibility-must-be-preserved`
- **type**: regression
- **preconditions**: Chain C merge applied
- **steps**: Run `grep -rn "DATE_ADD\|SHOW COLUMNS" lib/Service/`; note pre-existing results in `SettingsService.php`
- **expected result**: All matches are in the pre-existing `SettingsService.php` lines documented in ADR-009; zero new occurrences in rewritten files

### TC-REG-007: composer check:strict passes end-to-end

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-composercheckstrict-must-pass-with-all-deleted-files-removed`
- **type**: regression
- **preconditions**: Chain C fully applied (all 31 files deleted, all callers rewritten)
- **steps**: Run `composer check:strict` from the openconnector repository root
- **expected result**: Exit code 0 (PHPCS, PHPMD, Psalm, PHPStan all pass)

---

## Smoke Test (Playwright — End-to-End)

### TC-SMOKE-001: Sources index page loads and lists sources

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-controller-must-receive-data-via-rewritten-services-not-via-mappers`
- **type**: functional
- **persona**: Sem (developer / integration admin)
- **preconditions**: Chain C deployed in docker dev environment; at least one Source in OR storage; Nextcloud running at `http://localhost:3000`
- **steps**:
  1. Log in as admin at `http://localhost:3000`
  2. Navigate to the OpenConnector app
  3. Click "Sources" in the sidebar
  4. Verify the sources list loads without error
  5. Click one source row to open the detail sidebar
  6. Verify the source name and type are displayed correctly
- **expected result**: Sources index page loads; source detail sidebar shows the correct data fetched via `GET /api/sources/{uuid}` returning the chain C wire format

### TC-SMOKE-002: Create a new Source via the UI

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-controller-must-receive-data-via-rewritten-services-not-via-mappers`
- **type**: functional
- **persona**: Sem (developer / integration admin)
- **preconditions**: Chain C deployed; authenticated admin session
- **steps**:
  1. Navigate to Sources in OpenConnector
  2. Click "Add Source"
  3. Fill in `name = "smoke-test-source"`, `type = "api"`, `location = "https://example.example"`
  4. Submit the form
- **expected result**: New source appears in the list with the correct name and type; no PHP fatal error in the browser console or Nextcloud log

---

## Security Tests

### TC-SEC-001: Credential fields are not returned in plaintext to unauthenticated callers

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-source-credential-fields-must-be-handled-with-explicit-encryptionservice-calls`
- **type**: security
- **preconditions**: Chain C deployed; a Source with `apikey = 'YOUR_API_KEY_HERE'` exists
- **steps**: Make an unauthenticated GET request to `/api/sources` (no session cookie)
- **expected result**: HTTP 401 — credential values are never exposed to unauthenticated callers

### TC-SEC-002: Non-admin cannot create a Source

- **spec_ref**: `openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md#requirement-every-controller-must-receive-data-via-rewritten-services-not-via-mappers`
- **type**: security
- **preconditions**: Chain C deployed; a standard (non-admin) Nextcloud user session
- **steps**: POST to `/api/sources` with a valid Source body using non-admin credentials
- **expected result**: HTTP 403 (existing admin scope check preserved unchanged)

---

## Known Out-of-Scope Areas

| Area | Reason | Tracked By |
|------|--------|------------|
| `SettingsService::applyRetention()` MySQL-only SQL | Pre-existing ADR-009 violation; not introduced by chain C | ADR-009 follow-up ticket |
| EncryptionService full wiring | ADR-007 states credentials are stored as plaintext today; chain C adds explicit call sites but does not wire the encryption layer | Issue to be filed separately |
| Vue store / frontend changes | Out of scope per proposal.md; frontend `openconnector-frontend-vue-rewrite` is a separate change | `openconnector-frontend-vue-rewrite` |
| Dropping `oc_openconnector_*` legacy tables | Chain B cleanup ([#820](https://codeberg.org/Conduction/openconnector/issues/820)), gated on chain C shipping | [#820](https://codeberg.org/Conduction/openconnector/issues/820) |
| DTO auto-generation from chain A schemas | Issue C-001 filed as follow-up | Issue C-001 |

---

## Coverage Summary

| Requirement | Unit TCs | API TCs | Regression TCs | Smoke TCs | Status |
|---|---|---|---|---|---|
| 15 mapper files deleted | — | — | TC-REG-001 | — | Covered |
| 15 entity files deleted | — | — | TC-REG-002 | — | Covered |
| ObjectMapperFacade deleted | — | — | TC-REG-003 | — | Covered |
| Services inject ObjectService | TC-UNIT-007, TC-UNIT-008 | TC-API-001..004 | — | TC-SMOKE-002 | Covered |
| Controllers use rewritten services | — | TC-API-001..004, TC-CHAIN-C-WIRE-PARITY | — | TC-SMOKE-001, TC-SMOKE-002 | Covered |
| Application.php DI updated + pre-flight | TC-UNIT-009, TC-UNIT-010 | — | — | — | Covered |
| composer check:strict passes | — | — | TC-REG-007 | — | Covered |
| Quality gate blocks deleted types | — | — | TC-REG-004, TC-REG-005 | — | Covered |
| Unit tests mock ObjectService | TC-UNIT-007..008 | — | — | — | Covered |
| Newman/Postman tests pass | — | TC-API-005, TC-CHAIN-C-WIRE-PARITY | — | — | Covered |
| SynchronizationService sourceId branching | TC-UNIT-003..006 | TC-API-004 | — | — | Covered |
| Credential fields use explicit decrypt | TC-UNIT-011 | TC-SEC-001 | — | — | Covered |
| Endpoint targetType dispatch preserved | TC-UNIT-012..014 | — | — | — | Covered |
| Multi-platform DB compatibility | — | — | TC-REG-006 | — | Covered |
| 15 DTO classes introduced | TC-UNIT-001, TC-UNIT-002 | TC-API-002 | — | — | Covered |

All 13 spec requirements have at least one test case. The Newman suite (TC-API-005)
serves as the canonical wire-format regression gate and SHOULD be promoted to a
permanent test scenario (`/test-scenario-create`) after chain C ships, so it is
automatically picked up by `/test-app` and `/test-regression` in future runs.
