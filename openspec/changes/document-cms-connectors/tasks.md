# Tasks: document-cms-connectors

> Sub-bullets describe the work per task. Each top-level checkbox is the unit Hydra tracks; flip when the whole task (implementation + tests) is done. ADR-032 cap respected (≤20).

## Task 1: Deduplication check (ADR-012)
- **spec_ref**: `openspec/changes/document-cms-connectors/design.md#reuse-analysis`
- **files**: (read-only audit — no new files)
- **acceptance_criteria**:
  - GIVEN the OpenConnector codebase WHEN searched for existing Zenya DOC connectors THEN no overlap found — document findings
  - GIVEN OpenRegister services WHEN reviewed THEN list of reused services documented (CallService, AuthenticationService, SynchronizationService, ObjectService, JobService)
- Search `openspec/specs/`, `lib/Service/`, and `lib/Controller/` for any existing Zenya DOC or ZMS document connector; search for any existing IntegrationContract or component contract service; document "no overlap found" or justify why new code is needed
- [ ] Task complete

## Task 2: Schema registration — ZenyaDocument, IntegrationContract, ContractValidationResult (REQ-DOC-001, REQ-CIS-001)
- **spec_ref**: `specs/document-cms-connectors/spec.md`
- **files**: `lib/Settings/openconnector_register.json`
- **acceptance_criteria**:
  - GIVEN openconnector_register.json is imported WHEN schemas loaded THEN ZenyaDocument, IntegrationContract, ContractValidationResult schemas exist in OpenRegister
  - GIVEN import run twice WHEN schemas already exist THEN idempotent (no duplicates, matched by slug)
- Add three new schemas to `openconnector_register.json` (schema.org vocabulary, required flags, description fields per ADR-011); verify `x-openregister.type: "application"` marker; run idempotency check
- [ ] Task complete

## Task 3: Seed data — 3-5 objects per schema (ADR-001 seed data requirement)
- **spec_ref**: `openspec/changes/document-cms-connectors/design.md#seed-data`
- **files**: `lib/Settings/openconnector_register.json`
- **acceptance_criteria**:
  - GIVEN register imported WHEN ZenyaDocument schema inspected THEN 4 seed objects with Dutch values exist
  - GIVEN register imported WHEN IntegrationContract schema inspected THEN 3 seed objects exist
  - GIVEN register imported WHEN ContractValidationResult schema inspected THEN 2 seed objects exist
  - GIVEN import run twice THEN seed objects are idempotent (matched by slug)
- Add `components.objects[]` with `@self` envelope for each schema; use real Dutch organisation context (gemeente, afdeling); verify slugs are unique and human-readable per ADR-001 seed data rules
- [ ] Task complete

## Task 4: ZenyaConnectorService — connection and document sync (REQ-DOC-001, REQ-DOC-002)
- **spec_ref**: `specs/document-cms-connectors/spec.md#req-doc-001`
- **files**: `lib/Service/ZenyaConnectorService.php`
- **acceptance_criteria**:
  - GIVEN Zenya DOC source configured WHEN test connection clicked THEN Source status set to active on 200, error on 401/timeout
  - GIVEN sync job runs WHEN new documents exist THEN ZenyaDocument objects created/updated via ObjectService
  - GIVEN Zenya DOC returns rate-limit headers WHEN processed THEN CallService.sourceRateLimit() updates Source fields
- Implement `ZenyaConnectorService` with: `testConnection()` via `CallService`, `syncDocuments()` upsert via `SynchronizationService`, `getDocuments()` paged retrieval; all API calls logged via CallLog; all `catch (\Throwable)` blocks log real error server-side and return static message
- [ ] Task complete

## Task 5: ZenyaConnectorService — transparent document access (REQ-DOC-003)
- **spec_ref**: `specs/document-cms-connectors/spec.md#req-doc-003`
- **files**: `lib/Service/ZenyaConnectorService.php`
- **acceptance_criteria**:
  - GIVEN OAuth2 SSO delegation configured WHEN getDirectUrl() called THEN signed URL returned without separate login required
  - GIVEN delegation token expired WHEN getDirectUrl() called THEN AuthenticationService refreshes token before URL generation
  - GIVEN API key auth only WHEN getDirectUrl() called THEN falls back to directUrl field value + UI notice
- Implement `getDirectUrl($documentId)`: OAuth2 path delegates token via `AuthenticationService`; API key fallback returns stored `directUrl`; no URL constructed from user input without `rawurlencode`
- [ ] Task complete

## Task 6: ZenyaController — REST API endpoints (REQ-DOC-001, REQ-DOC-002, REQ-DOC-003)
- **spec_ref**: `specs/document-cms-connectors/spec.md#req-doc-001`
- **files**: `lib/Controller/ZenyaController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN GET /api/zenya/documents WHEN called by authenticated user THEN paginated ZenyaDocument list returned
  - GIVEN GET /api/zenya/documents/{id}/url WHEN called THEN signed URL returned (or directUrl fallback)
  - GIVEN POST /api/zenya/connect WHEN called by admin THEN connection test runs and status returned
  - GIVEN error occurs WHEN any endpoint called THEN static error message returned (never $e->getMessage())
- Thin controller (<10 lines/method): routing, validation, response only; delegates to `ZenyaConnectorService`; `#[NoAdminRequired]` + per-object auth check on document URL endpoint; `#[AuthorizedAdminSetting]` on connect endpoint; all routes in `appinfo/routes.php`
- [ ] Task complete

## Task 7: Background sync job — periodic Zenya DOC document poll (REQ-DOC-002)
- **spec_ref**: `specs/document-cms-connectors/spec.md#req-doc-002`
- **files**: `lib/Cron/ZenyaSyncJob.php`
- **acceptance_criteria**:
  - GIVEN ZenyaSyncJob registered WHEN cron runs THEN ZenyaConnectorService::syncDocuments() called for each active Zenya Source
  - GIVEN sync fails for one Source WHEN job runs THEN error logged, other Sources continue, job does not abort
- Implement `ZenyaSyncJob` extending Nextcloud `TimedJob`; register in `Application::register()` via `IJobList`; job iterates active Zenya Sources and calls `syncDocuments()`; failures logged with `$this->logger->error()` — never bubble up to crash the job
- [ ] Task complete

## Task 8: IntegrationContractService — contract CRUD and validation (REQ-CIS-001, REQ-CIS-002)
- **spec_ref**: `specs/document-cms-connectors/spec.md#req-cis-001`
- **files**: `lib/Service/IntegrationContractService.php`
- **acceptance_criteria**:
  - GIVEN IntegrationContract saved WHEN validateComponent() called THEN ContractValidationResult persisted with passed/violations
  - GIVEN component missing required interface WHEN validation runs THEN passed: false + violation entry for missing interface
  - GIVEN dataSchemas defined in contract WHEN validateDataFlow() called THEN field-level type and required checks run
- Implement `IntegrationContractService` with: `validateComponent($contractSlug)` checking required interfaces; `validateDataFlow($contractSlug, $data)` checking data against `dataSchemas`; results persisted via `ObjectService::saveObject()`; stateless (no instance state between requests per ADR-003)
- [ ] Task complete

## Task 9: IntegrationContractService — breaking change detection (REQ-CIS-003)
- **spec_ref**: `specs/document-cms-connectors/spec.md#req-cis-003`
- **files**: `lib/Service/IntegrationContractService.php`
- **acceptance_criteria**:
  - GIVEN contract updated to rename required field WHEN detectBreakingChanges() called THEN rename reported as breaking
  - GIVEN contract updated to add optional field WHEN detectBreakingChanges() called THEN breakingChanges: [] (non-breaking)
  - GIVEN breaking change detected WHEN update attempted THEN change blocked until developer acknowledges
- Implement `detectBreakingChanges($oldContract, $newContract)`: compare `requiredInterfaces` and `dataSchemas` diffs; renames/removals of required fields = breaking; additions of optional fields = non-breaking; return structured result before saving
- [ ] Task complete

## Task 10: IntegrationContractController — REST API endpoints (REQ-CIS-001, REQ-CIS-002, REQ-CIS-003)
- **spec_ref**: `specs/document-cms-connectors/spec.md#req-cis-001`
- **files**: `lib/Controller/IntegrationContractController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN GET /api/integration-contracts WHEN called THEN list of contracts returned (any authenticated user)
  - GIVEN POST /api/integration-contracts/{id}/validate WHEN called by admin THEN validation runs and ContractValidationResult returned
  - GIVEN PUT /api/integration-contracts/{id} with breaking change WHEN called THEN 422 returned with breakingChanges list
- Thin controller; `#[NoAdminRequired]` on GET list; `#[AuthorizedAdminSetting]` on POST validate and PUT update; delegates to `IntegrationContractService`; never returns `$e->getMessage()` to API
- [ ] Task complete

## Task 11: Frontend — ZenyaView document listing (REQ-DOC-002)
- **spec_ref**: `specs/document-cms-connectors/spec.md#req-doc-002`
- **files**: `src/views/ZenyaView.vue`, `src/store/modules/zenyaDocument.js`
- **acceptance_criteria**:
  - GIVEN employee navigates to /documents WHEN ZenyaView renders THEN documents listed with name, documentType, dateModified columns
  - GIVEN filter applied WHEN documentType filter used THEN list filtered via OpenRegister standard filter params
  - GIVEN document row clicked WHEN getDirectUrl endpoint responds THEN browser opens URL in new tab
  - GIVEN store action throws WHEN catch fires THEN user-facing error shown (never silent failure)
- Implement `ZenyaView.vue` using `CnIndexPage` + `useListView`; Pinia store via `createObjectStore('zenya-document')`; row click calls `/api/zenya/documents/{id}/url` via `@nextcloud/axios`; all `await store.action()` calls in `try/catch`; all strings via `t(appName, '...')`; import only from `@conduction/nextcloud-vue`
- [ ] Task complete

## Task 12: Frontend — IntegrationContractsView (REQ-CIS-001)
- **spec_ref**: `specs/document-cms-connectors/spec.md#req-cis-001`
- **files**: `src/views/IntegrationContractsView.vue`, `src/store/modules/integrationContract.js`
- **acceptance_criteria**:
  - GIVEN admin navigates to /integration-contracts WHEN view renders THEN contracts listed with name, componentName, status, lastValidatedAt
  - GIVEN admin clicks "Validate" WHEN POST /api/integration-contracts/{id}/validate responds THEN result shown inline
  - GIVEN breaking change in contract update WHEN API returns 422 THEN user-facing dialog explains breaking changes
- Implement `IntegrationContractsView.vue` using `CnIndexPage`; contract edit via `CnFormDialog` (schema-driven); breaking-change 422 response shown in `NcDialog`; no `window.confirm()` or `window.alert()`
- [ ] Task complete

## Task 13: Unit tests (ADR-008)
- **spec_ref**: ADR-008
- **files**: `tests/Unit/Service/ZenyaConnectorServiceTest.php`, `tests/Unit/Service/IntegrationContractServiceTest.php`
- **acceptance_criteria**:
  - GIVEN ZenyaConnectorServiceTest WHEN run THEN ≥3 test methods covering testConnection (success, 401, timeout), syncDocuments, getDirectUrl (OAuth2 path + API key fallback)
  - GIVEN IntegrationContractServiceTest WHEN run THEN ≥3 methods covering validateComponent (pass, fail-missing-interface), detectBreakingChanges (breaking rename, non-breaking addition)
- PHPUnit tests in `tests/Unit/`; mock `CallService` and `ObjectService`; cover error paths per ADR-008; `composer check:strict` must pass
- [ ] Task complete

## Task 14: SPDX headers and spec traceability tags (ADR-014, ADR-003)
- **spec_ref**: ADR-014; ADR-003
- **files**: all new PHP, Vue, and JS files
- **acceptance_criteria**:
  - GIVEN all new PHP files WHEN inspected THEN @author, @copyright, @license EUPL-1.2, @link, @spec PHPDoc tags present
  - GIVEN all new Vue/JS files WHEN inspected THEN SPDX-License-Identifier: EUPL-1.2 header on first line
  - GIVEN all new classes and public methods WHEN inspected THEN @spec openspec/changes/document-cms-connectors/tasks.md#task-N tag present
- Apply SPDX headers to every new file; add @spec tags to every new class and public method; run `grep -rL 'SPDX-License-Identifier' src/ lib/` and fix all gaps before committing
- [ ] Task complete

## Task 15: API documentation (ADR-009)
- **spec_ref**: ADR-009
- **files**: `docs/features/document-cms-connectors.md`
- **acceptance_criteria**:
  - GIVEN documentation file exists WHEN reviewed THEN covers Zenya DOC setup (Source config, credentials, sync interval) and component integration spec (contract creation, validation, breaking-change workflow)
- Write English-primary docs with Dutch translations recommended; include configuration guide (Source entity fields, OAuth2 vs API key), sync job setup, and contract validation workflow; screenshots from running app if available
- [ ] Task complete
