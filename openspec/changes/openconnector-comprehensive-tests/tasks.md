# Tasks: openconnector-comprehensive-tests

## Implementation Tasks

### Task 1: Create ObjectServiceMockBuilder helper
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-001-objectservicemockbuilder-helper-must-exist`
- **files**: `tests/Helpers/ObjectServiceMockBuilder.php`
- **acceptance_criteria**:
  - GIVEN `ObjectServiceMockBuilder::make($this)` is called inside any PHPUnit test WHEN the returned mock's `find` method is configured THEN no additional boilerplate is needed in the test
  - Mock provides defaults for `find`, `findAll`, `saveObject`, `deleteObject`
- [ ] Implement
- [ ] Test

### Task 2: Add PHPUnit tests for SourceService and EndpointService
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-002-every-chain-c-service-class-must-have-a-corresponding-phpunit-test-file`
- **files**: `tests/Unit/Service/SourceServiceTest.php`, `tests/Unit/Service/EndpointServiceTest.php`
- **acceptance_criteria**:
  - GIVEN `SourceService` is mocked WHEN `getSource` is called with a valid UUID THEN `ObjectService::find` is invoked once
  - GIVEN `ObjectService::find` throws `DoesNotExistException` WHEN `getSource` is called THEN the exception is re-thrown
  - GIVEN `EndpointService` is mocked WHEN `handleEndpointRequest` is called with `targetType='register/schema'` THEN the OR CRUD path is dispatched
- [ ] Implement
- [ ] Test

### Task 3: Add PHPUnit tests for JobService, MappingService, and RuleService
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-002-every-chain-c-service-class-must-have-a-corresponding-phpunit-test-file`
- **files**: `tests/Unit/Service/JobServiceTest.php`, `tests/Unit/Service/MappingServiceTest.php`, `tests/Unit/Service/RuleServiceTest.php`
- **acceptance_criteria**:
  - GIVEN `JobService::createJob` is called with a valid payload WHEN the mock ObjectService runs THEN `saveObject` is called once with the correct register/schema triple
  - GIVEN `MappingService::applyMapping` is called with a source object WHEN transformation rules run THEN the mapped output matches the expected fixture
  - GIVEN `RuleService::evaluateRule` is called with an event WHEN the rule condition matches THEN the rule action is invoked
- [ ] Implement
- [ ] Test

### Task 4: Add PHPUnit tests for CallService, EventService, and SynchronizationService
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-002-every-chain-c-service-class-must-have-a-corresponding-phpunit-test-file`
- **files**: `tests/Unit/Service/CallServiceTest.php`, `tests/Unit/Service/EventServiceTest.php`, `tests/Unit/Service/SynchronizationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN `CallService` reads a source's `apikey` WHEN the call is made THEN `EncryptionService::decrypt` is called before passing the key to the HTTP client
  - GIVEN `EventService` receives a webhook payload WHEN routing THEN the correct consumer's handler is invoked
  - GIVEN `SynchronizationService::runSync` is called WHEN OR storage is active THEN objects are fetched via `ObjectService::findAll` not legacy mapper
- [ ] Implement
- [ ] Test

### Task 5: Add PHPUnit test for LegacyToRegisterMigrator
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-012-legacytoregistermigratorrtest-must-verify-chain-b-migration-paths`
- **files**: `tests/Unit/Service/LegacyToRegisterMigratorTest.php`
- **acceptance_criteria**:
  - GIVEN a synchronization row with `source_id='42'` and a source row with `id=42, uuid='00000000-...-0042'` WHEN `migrateAll` runs for the synchronization entity THEN the OR object's `sourceId` equals `'00000000-...-0042'`
  - GIVEN `source_id='zaken/zaak'` WHEN migrated THEN `sourceId='zaken/zaak'` unchanged (register-schema variant)
  - GIVEN `source_id='a1b2c3d4-...'` WHEN migrated THEN `sourceId` unchanged (UUID passthrough)
  - GIVEN `source_id='not-recognised'` WHEN migrated THEN row is still migrated but unrecognised counter incremented
- [ ] Implement
- [ ] Test

### Task 6: Add PHPUnit tests for all 15 DTO classes
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-003-every-write-side-dto-must-have-a-phpunit-test-file`
- **files**: `tests/Unit/Dto/SourceDtoTest.php`, `tests/Unit/Dto/EndpointDtoTest.php`, `tests/Unit/Dto/ConsumerDtoTest.php`, `tests/Unit/Dto/MappingDtoTest.php`, `tests/Unit/Dto/JobDtoTest.php`, `tests/Unit/Dto/RuleDtoTest.php`, `tests/Unit/Dto/SynchronizationDtoTest.php`, `tests/Unit/Dto/SynchronizationContractDtoTest.php`, `tests/Unit/Dto/CallLogDtoTest.php`, `tests/Unit/Dto/JobLogDtoTest.php`, `tests/Unit/Dto/SynchronizationLogDtoTest.php`, `tests/Unit/Dto/SynchronizationContractLogDtoTest.php`, `tests/Unit/Dto/CloudEventDtoTest.php`, `tests/Unit/Dto/EventMessageDtoTest.php`, `tests/Unit/Dto/EventSubscriptionDtoTest.php`
- **acceptance_criteria**:
  - GIVEN each DTO's `fromArray([])` is called WHEN required fields are absent THEN `\InvalidArgumentException` is thrown
  - GIVEN `fromArray($data)->toArray()` is called WHEN `$data` contains only domain fields THEN returned array equals `$data` with no injected `id/uuid/created/updated/owner` keys
- [ ] Implement
- [ ] Test

### Task 7: Update phpunit.xml and composer.json coverage threshold
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-004-ci-must-enforce-80-line-and-70-branch-coverage-as-merge-blocking-gates`
- **files**: `phpunit.xml`, `composer.json`
- **acceptance_criteria**:
  - GIVEN `phpunit.xml` is updated WHEN `composer test:coverage` runs THEN both `coverage/html/` and `coverage/clover.xml` are produced
  - GIVEN `composer coverage:check` runs against a report with 79% line coverage WHEN the script reads the clover file THEN exit code is non-zero
  - GIVEN the report has 80% line + 70% branch WHEN the script runs THEN exit code is 0
- [ ] Implement
- [ ] Test

### Task 8: Create Newman Postman collection and environment files
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-005-newman-collection-must-exist-and-cover-all-rest-endpoints`
- **files**: `tests/postman/openconnector.postman_collection.json`, `tests/postman/openconnector.postman_environment.json`, `tests/postman/baselines.json`
- **acceptance_criteria**:
  - GIVEN the collection JSON is valid Postman Collection v2.1 WHEN `newman run` is invoked against a seeded dev container THEN exit code is 0 with 0 failures
  - GIVEN no auth credentials WHEN `GET /api/sources` is requested THEN the response is 401
  - GIVEN a non-admin session WHEN `POST /api/sources` is requested THEN the response is 403
  - GIVEN valid admin credentials WHEN `POST /api/sources` with missing `name` is requested THEN the response is 400
- [ ] Implement
- [ ] Test

### Task 9: Add `test:newman` script to package.json
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-006-npm-run-testnewman-script-must-exist-in-packagejson`
- **files**: `package.json`
- **acceptance_criteria**:
  - GIVEN `package.json` is updated WHEN `npm run test:newman` is invoked with the dev container running THEN newman executes and exits 0 on a clean run
- [ ] Implement
- [ ] Test

### Task 10: Add Playwright spec files for sources and endpoints
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-008-playwright-regression-project-must-cover-all-10-resource-pages`
- **files**: `tests/e2e/sources.spec.ts`, `tests/e2e/endpoints.spec.ts`
- **acceptance_criteria**:
  - GIVEN the dev container is running WHEN `sources.spec.ts` runs THEN page loads, list renders, create/edit/delete/search all pass
  - GIVEN the dev container is running WHEN `endpoints.spec.ts` runs THEN all CRUD flows pass without console errors
- [ ] Implement
- [ ] Test

### Task 11: Add Playwright spec files for consumers, mappings, and cloud-events
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-008-playwright-regression-project-must-cover-all-10-resource-pages`
- **files**: `tests/e2e/consumers.spec.ts`, `tests/e2e/mappings.spec.ts`, `tests/e2e/cloud-events.spec.ts`
- **acceptance_criteria**:
  - GIVEN the dev container is running WHEN each spec runs THEN all CRUD flows pass
- [ ] Implement
- [ ] Test

### Task 12: Add Playwright spec files for synchronizations, sync-contracts, rules, import, dashboard
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-008-playwright-regression-project-must-cover-all-10-resource-pages`
- **files**: `tests/e2e/synchronizations.spec.ts`, `tests/e2e/sync-contracts.spec.ts`, `tests/e2e/rules.spec.ts`, `tests/e2e/import.spec.ts`, `tests/e2e/dashboard.spec.ts`
- **acceptance_criteria**:
  - GIVEN the dev container is running WHEN each spec runs THEN all flows pass without console errors or unhandled exceptions
- [ ] Implement
- [ ] Test

### Task 13: Add migration round-trip Playwright spec
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-010-playwright-must-include-a-migration-round-trip-spec`
- **files**: `tests/e2e/migration-round-trip.spec.ts`
- **acceptance_criteria**:
  - GIVEN 3 Sources exist in legacy `oc_openconnector_sources` WHEN `occ openconnector:migrate-storage` is run via `docker exec` THEN the Sources list page shows 3 sources with the same names
  - GIVEN `storage_migrated` is set to `true` after migration WHEN all 10 resource pages are visited THEN none shows an error or empty state unexpectedly
- [ ] Implement
- [ ] Test

### Task 14: Update playwright.config.ts — add regression project with workers: 4
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-009-playwrightconfigts-must-set-workers-to-4-for-the-regression-project`
- **files**: `playwright.config.ts`
- **acceptance_criteria**:
  - GIVEN `playwright.config.ts` is updated WHEN `npx playwright test --project regression` is run THEN all resource spec files are included and `docs-screenshots.spec.ts` is excluded
  - GIVEN the updated config WHEN checked via `npx playwright --version` THEN the regression project lists `workers: 4`
- [ ] Implement
- [ ] Test

### Task 15: Create GitHub Actions tests.yml workflow
- **spec_ref**: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#req-004-ci-must-enforce-80-line-and-70-branch-coverage-as-merge-blocking-gates`
- **files**: `.github/workflows/tests.yml`
- **acceptance_criteria**:
  - GIVEN `.github/workflows/tests.yml` is added WHEN a PR is opened THEN three parallel CI jobs run: `phpunit`, `newman`, `playwright`
  - GIVEN the phpunit job runs WHEN coverage is below 80% line THEN the job fails and the PR is blocked
  - GIVEN the playwright job fails WHEN any spec assertion fails THEN `tests/e2e/playwright-report/**` is uploaded as a CI artifact
- [ ] Implement
- [ ] Test

## Verification

- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] `composer test:coverage` exits 0 with ≥ 80% line / ≥ 70% branch
- [ ] `newman run tests/postman/openconnector.postman_collection.json --environment tests/postman/openconnector.postman_environment.json` exits 0
- [ ] `npx playwright test --project regression` exits 0
- [ ] `.github/workflows/tests.yml` is present and all three jobs are merge-blocking
- [ ] No files under `lib/`, `src/`, or `appinfo/` were modified

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — this IS the task
- [ ] Newman/Postman tests for new/changed API endpoints — this IS the task
- [ ] Browser tests (Playwright MCP) for UI changes — this IS the task
- [ ] All tests pass (`composer test`, `npm run test:newman`, `npx playwright test --project regression`)

## Documentation (company-wide ADR-010)

- [ ] N/A — chain E is test infrastructure only; no new user-facing feature to document
- [ ] README section "Running the tests" updated to describe the three test suites

## i18n (company-wide hydra ADR-007)

- [ ] N/A — no new user-facing strings are added by chain E
