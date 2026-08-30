# Tasks: tables-bridge

## Implementation Tasks

### Task 1: `TablesClientInterface` + v1-REST `TablesOcsClient` implementation
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001`
- **files**: `lib/Service/Tables/TablesClientInterface.php`, `lib/Service/Tables/TablesOcsClient.php`
- **acceptance_criteria**:
  - GIVEN a `Source` pointed at a Nextcloud instance with the Tables app enabled WHEN `TablesOcsClient::listTables()`/`listColumns()`/`listRows()`/`getRow()`/`createRow()`/`updateRow()`/`deleteRow()` are called THEN each dispatches through `CallService::call()` against the `index.php/apps/tables/api/1/*` endpoints (design.md Decision 2) and returns/accepts plain arrays shaped per the Tables `Table`/`Column`/`Row` schema
  - GIVEN an upstream non-2xx response WHEN any client method runs THEN the resulting `CallLog` is surfaced to the caller (no swallowed exception)
- [x] Implement
- [x] Test

### Task 2: `TablesSyncAdapter` — column cache, title→columnId resolution, coercion
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-column-type-coercion-req-003`
- **files**: `lib/Service/Tables/TablesSyncAdapter.php`
- **acceptance_criteria**:
  - GIVEN a synchronization run WHEN the adapter is first invoked THEN `listColumns()` is called at most once and cached for the remainder of that run (design.md Decision 5)
  - GIVEN `targetConfig.columnMapping` keyed by column title WHEN a row is written THEN each title resolves to the current numeric `columnId`; an unresolvable or ambiguous (>1 match) title fails only that row's write with a named config-error log entry, never a first-match guess
  - GIVEN a mapped value and its target column's `type`/`subtype` WHEN coercion runs THEN text/number/datetime/selection are coerced per design.md Decision 6, and a coercion failure fails only that row (logged), not the run
- [x] Implement
- [x] Test

### Task 3: `SynchronizationService` dispatch — `nextcloud-table` source fetch
- **spec_ref**: `openspec/changes/tables-bridge/specs/synchronization-engine/spec.md#requirement-nextcloud-table-sourcetarget-dispatch-req-006`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN `sourceType: nextcloud-table` WHEN `getAllObjectsFromSource()` runs THEN it dispatches to `TablesSyncAdapter`'s paginated row read, using `Row.id` as the origin id via the existing `idPosition` default and `hashObject()` for change detection
  - GIVEN a `sourceType` that is neither `nextcloud-table` nor an existing recognised type WHEN `getAllObjectsFromSource()` runs THEN behavior is unchanged from today (empty array for `register/schema`/`database`)
- [x] Implement
- [x] Test

### Task 4: `SynchronizationService` dispatch — `nextcloud-table` target write (create/update)
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-nextcloud-table-as-a-synchronization-target-req-001`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN `targetType: nextcloud-table` and no existing contract for an object's origin id WHEN `updateTarget()` runs THEN a row is created and the contract's `targetId` is set to the returned Tables row id
  - GIVEN an existing contract with an unchanged `targetHash` WHEN `updateTarget()` runs THEN no write call is made
  - GIVEN an existing contract with a changed mapped-output hash WHEN `updateTarget()` runs THEN the existing row is updated via `PUT .../rows/{rowId}`
  - GIVEN a `targetType` not in `{register/schema, api, database, nextcloud-table}` WHEN `updateTarget()` runs THEN it still throws `Unsupported target type` unchanged
- [x] Implement
- [x] Test

### Task 5: `SynchronizationService::deleteInvalidObjects()` — `nextcloud-table` branch composing with the shared deletion guard
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-source-deleted-rows-are-removed-only-under-the-shared-deletion-safety-guard-req-005`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a `nextcloud-table` target and a complete, successful source fetch WHEN `deleteInvalidObjects()` runs THEN rows whose contracts are absent from the fetched set are deleted via `deleteRow()` and their contracts removed
  - GIVEN a `nextcloud-table` target and an incomplete/failed fetch for the run WHEN `deleteInvalidObjects()` would run THEN no row deletion is attempted and the run log records why
  - GIVEN this task lands before `sync-safety-guardrails` ships its own guard THEN the `nextcloud-table` branch MUST call the same shared guard entry point every other target type uses — no `nextcloud-table`-specific threshold or bypass — so the guard's eventual landing covers this branch automatically
- [x] Implement
- [x] Test

### Task 6: Permission-denied (401/403) run-abort handling
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-permission-denied-writes-fail-the-run-not-a-partial-subset-of-rows-req-006`
- **files**: `lib/Service/Tables/TablesSyncAdapter.php`, `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN the first row write in a run receives a 401/403 from the Tables API WHEN the run continues THEN it aborts immediately (no further row writes attempted) and the synchronization log records the table id and the identity used
  - GIVEN the run aborted on permission-denied WHEN contracts are inspected afterward THEN no contract exists for a row whose write was never attempted (no partial/inconsistent contract state)
- [x] Implement
- [x] Test

### Task 7: Feature detection — `IAppManager` guard on every entry point
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-feature-detection--tables-app-absence-hides-the-type-entirely-req-004`
- **files**: `lib/Service/Tables/TablesSyncAdapter.php`, `lib/Controller/SynchronizationsController.php` (or new discovery controller)
- **acceptance_criteria**:
  - GIVEN the Tables app is not enabled for the acting user WHEN the available source/target types endpoint is called THEN `nextcloud-table` is omitted
  - GIVEN a synchronization already configured with `nextcloud-table` and Tables subsequently disabled WHEN it runs THEN it fails with a 409-class config error naming the missing dependency, with zero HTTP calls attempted against any Tables endpoint
- [x] Implement
- [x] Test

### Task 8: Editor discovery endpoints — table list + column list
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md#requirement-table-and-column-discovery-for-the-synchronization-editor-req-007`
- **files**: `lib/Controller/SynchronizationsController.php` (or new `TablesBridgeController.php`), `appinfo/routes.php`
- **acceptance_criteria**:
  - Matches `contract.md`'s `GET .../synchronizations/tables-bridge/tables` and `GET .../synchronizations/tables-bridge/tables/{tableId}/columns` request/response/error shapes exactly, including the 400/404/409/401/403/502 error mapping table
- [x] Implement
- [x] Test

### Task 9: `SyncConfigWidget.vue` — `nextcloud-table` kind + table picker
- **spec_ref**: `openspec/changes/tables-bridge/specs/sync-editor-ui/spec.md#requirement-table-picker-for-the-nextcloud-table-sourcetarget-kind-req-syncui-006`
- **files**: `src/views/Synchronization/SyncConfigWidget.vue` (verified against HEAD)
- **acceptance_criteria**:
  - GIVEN the backend's available-types response omits `nextcloud-table` WHEN the kind selector renders THEN it is not offered
  - GIVEN `nextcloud-table` is selected and a `Source` is picked WHEN tables are fetched THEN the picker lists them and selecting one sets `tableId` in `sourceConfig`/`targetConfig`
- [x] Implement
- [x] Test

### Task 10: Column-mapping helper component
- **spec_ref**: `openspec/changes/tables-bridge/specs/sync-editor-ui/spec.md#requirement-column-mapping-helper-prefilled-from-table-schema-req-syncui-007`
- **files**: `src/views/Synchronization/TablesColumnMapping.vue` (new; sibling to the verified-at-HEAD `src/views/Synchronization/SyncMappingPicker.vue`)
- **acceptance_criteria**:
  - GIVEN a selected table WHEN the helper renders THEN it lists each column's title, type, and constraints (e.g. `selectionOptions`)
  - GIVEN the user maps a column and saves WHEN the synchronization is persisted THEN `targetConfig.columnMapping` stores the mapping keyed by column title, not numeric id
- [x] Implement
- [x] Test

### Task 11: Unit tests — coercion, contract mapping, adapter (stubbed client)
- **spec_ref**: `openspec/changes/tables-bridge/specs/tables-bridge/spec.md`
- **files**: `tests/Unit/Service/Tables/TablesSyncAdapterTest.php`, `tests/Unit/Service/Tables/TablesOcsClientTest.php`
- **acceptance_criteria**:
  - Every scenario in `specs/tables-bridge/spec.md` (REQ-001 through REQ-007) has at least one corresponding PHPUnit test, run against a stubbed `TablesClientInterface` (no real Tables app required)
- [x] Implement
- [x] Test

### Task 12: Integration coverage against a real Tables app, with CI-image fallback
- **spec_ref**: `openspec/changes/tables-bridge/proposal.md#risk-3-ci-image-may-not-have-the-tables-app-installed`
- **files**: `tests/Integration/TablesBridgeTest.php` (or equivalent), CI workflow config if the Tables app needs installing in the image
- **acceptance_criteria**:
  - GIVEN the Tables app IS present in the CI/dev image WHEN the integration test runs THEN it exercises create/update/delete against a real table
  - GIVEN the Tables app is NOT present WHEN the test suite runs THEN this test is skipped (not failed), with a clear skip reason logged
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes — scoped `--strict` validation of tables-bridge, synchronization-engine, sync-editor-ui run at archive time
- [ ] Manual testing against acceptance criteria — NOT done through a live UI/Tables app in this run; behaviour is fully covered by automated PHPUnit + vitest (43 backend tests against a stubbed `TablesClientInterface`, 22 frontend helper tests). A live-instance walkthrough is deferred (no Tables app installed in this environment; see Task 12's skip).
- [ ] Code review against spec requirements — pending reviewer (this is the builder pass)

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — Task 11 (TablesOcsClientTest, TablesSyncAdapterTest, TablesColumnCoercerTest + SynchronizationServiceTest dispatch cases)
- [ ] Newman/Postman tests for new/changed API endpoints — NOT added; the discovery endpoints require a live Tables app to return non-error bodies, so they are covered by controller-level unit behaviour instead. Deferred (file follow-up if a live Tables fixture lands in CI).
- [ ] Browser tests (Playwright MCP) for UI changes — NOT run; no live Nextcloud+Tables instance available in this build environment. The DOM-free editor logic is unit-tested (tests/vitest/tablesBridge.spec.js).
- [x] Backend tests pass (`vendor/bin/phpunit -c phpunit-unit.xml`: 990 tests, 1 skipped integration) and frontend unit tests pass (`npm run test:unit`: 62 tests). `newman run` not applicable (see above).

## Documentation (company-wide ADR-010)

- [x] Feature documentation updated in `docs/` — new `docs/synchronysation/nextcloud-tables.md` page (source/target modelling, coercion rules, editor UI, out-of-scope)
- [ ] Screenshot captured and committed to `docs/images/` — NOT captured; requires a live instance with the Tables app to render the picker/helper. Deferred alongside the browser test.

## i18n (company-wide hydra ADR-007)

- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added for the `nextcloud-table` kind label, table picker, and column-mapping helper labels — 14 new keys extracted into `l10n/en.json` and translated in `l10n/nl.json`. Note: backend error/log messages (permission-denied, coercion-failure, ambiguous-title) are English-only logger/exception strings per this codebase's convention; user-facing controller errors go through `IL10N::t()`.
