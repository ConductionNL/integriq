# Test Plan: openconnector-comprehensive-tests

Maps every spec scenario in `specs/openconnector-comprehensive-tests/spec.md` (12 requirements) to a concrete test case. This change is meta — it builds the test infrastructure that verifies chains A→D2. The test cases below verify that the infrastructure itself works; the test infrastructure then verifies the chain.

Spec ref format: `openspec/changes/openconnector-comprehensive-tests/specs/openconnector-comprehensive-tests/spec.md#requirement-<N>`.

## Test Cases

### TC-01: ObjectServiceMockBuilder helper exists and pre-configures sensible defaults
- **spec_ref**: `…/spec.md#requirement-1`
- **type**: regression
- **preconditions**: `tests/Helpers/ObjectServiceMockBuilder.php` exists after Phase 0; `phpunit.xml` autoloads `tests/` directory
- **steps**: Instantiate via `ObjectServiceMockBuilder::make($this)` inside a fresh PHPUnit test case; do not configure any additional expectations
- **expected result**: The returned mock responds to `find`, `findAll`, `saveObject`, `deleteObject` without throwing; default `find` returns null, default `findAll` returns empty array
- **test command**: /test-api

### TC-02: SourceServiceTest covers getSource happy path
- **spec_ref**: `…/spec.md#requirement-2`
- **type**: regression
- **preconditions**: `SourceService` constructed with a mocked `ObjectService`; mock `find` returns a fixture object for uuid `00000000-0000-0000-0000-000000000001`
- **steps**: Call `SourceService::getSource('00000000-0000-0000-0000-000000000001')`
- **expected result**: `ObjectService::find('openconnector', 'source', '00000000-0000-0000-0000-000000000001')` invoked exactly once; the fixture object is forwarded to the caller; no other ObjectService methods invoked
- **test command**: /test-api

### TC-03: SourceServiceTest covers getSource not-found path
- **spec_ref**: `…/spec.md#requirement-2`
- **type**: regression
- **preconditions**: `ObjectService::find` mocked to throw `DoesNotExistException`
- **steps**: Call `SourceService::getSource('00000000-0000-0000-0000-000000000001')`
- **expected result**: `DoesNotExistException` propagates unwrapped; no rescue blocks intercept; no logger calls suppress the failure
- **test command**: /test-api

### TC-04: Every chain-C service has a test file under tests/Unit/Service/
- **spec_ref**: `…/spec.md#requirement-2`
- **type**: regression
- **preconditions**: CI runs a structural grep over `lib/Service/` and `tests/Unit/Service/`
- **steps**: For each `lib/Service/<Name>Service.php` matched by the chain-C task list, assert `tests/Unit/Service/<Name>ServiceTest.php` exists
- **expected result**: All 9 expected service test files present (`CallService`, `EndpointService`, `EventService`, `JobService`, `MappingService`, `RuleService`, `SourceService`, `SynchronizationService`, `LegacyToRegisterMigrator`); CI fails the build if any file is missing
- **test command**: /test-api

### TC-05: Each chain-C service test covers at least one error path per public method
- **spec_ref**: `…/spec.md#requirement-2`
- **type**: regression
- **preconditions**: PHPUnit run with `--coverage-clover`; reflection-based assertion that every public method on a chain-C service has at least one test method whose name contains `error`, `fail`, `throws`, or `notFound`
- **steps**: Reflection pass over `lib/Service/<Name>Service::class`; for each public method (non-magic), assert a matching test method exists
- **expected result**: Zero missing error-path tests; CI fails on the first missing one with the service/method name in the failure message
- **test command**: /test-api

### TC-06: Every write-side DTO has a PHPUnit test file
- **spec_ref**: `…/spec.md#requirement-3`
- **type**: regression
- **preconditions**: 15 DTOs introduced by chain C live under `lib/Dto/`; their tests live under `tests/Unit/Dto/`
- **steps**: Structural grep; assert each `lib/Dto/<Name>Dto.php` has a matching `tests/Unit/Dto/<Name>DtoTest.php`
- **expected result**: All 15 DTO test files present; CI fails if any missing
- **test command**: /test-api

### TC-07: DTO tests validate happy path + at least one invalid-input path
- **spec_ref**: `…/spec.md#requirement-3`
- **type**: regression
- **preconditions**: DTO test class extends `PHPUnit\Framework\TestCase`; uses the assertion API
- **steps**: Reflection pass; for each DTO test, assert it contains at least one method named `testValid*` and one named `testInvalid*`
- **expected result**: Both methods present per DTO test; missing pairs fail CI
- **test command**: /test-api

### TC-08: PHPUnit coverage threshold enforced at 80% line, 70% branch
- **spec_ref**: `…/spec.md#requirement-4`
- **type**: regression
- **preconditions**: `phpunit.xml` configures `<coverage>` with `pathCoverage="true"`; CI runs `phpunit --coverage-clover=coverage.xml`
- **steps**: CI workflow step parses `coverage.xml` (using `clover2text` or equivalent); compares against thresholds
- **expected result**: Build fails when line coverage < 80% OR branch coverage < 70%; PR comment shows current vs threshold for both
- **test command**: /test-api

### TC-09: Coverage report uploaded as CI artifact and posted as PR comment
- **spec_ref**: `…/spec.md#requirement-4`
- **type**: regression
- **preconditions**: GitHub Actions workflow runs on PR; uses an action like `codecov/codecov-action` or equivalent
- **steps**: Open a PR with a small change; observe the CI run
- **expected result**: Coverage XML attached to the workflow run; PR receives a sticky comment showing diff coverage; comment updates on subsequent pushes
- **test command**: /test-api

### TC-10: Newman collection exists at the expected path and is valid JSON
- **spec_ref**: `…/spec.md#requirement-5`
- **type**: api
- **preconditions**: `tests/postman/openconnector.postman_collection.json` and `…openconnector.postman_environment.json` exist; collection conforms to Postman Collection v2.1 schema
- **steps**: Run `node -e "require('./tests/postman/openconnector.postman_collection.json')"`; then `npx newman run tests/postman/openconnector.postman_collection.json --env-var baseUrl=http://localhost:8080 --reporters cli,json`
- **expected result**: JSON parses without error; Newman dry-run lists ≥ 2 test items per controller folder
- **test command**: /test-api

### TC-11: Newman collection covers every REST endpoint registered in appinfo/routes.php
- **spec_ref**: `…/spec.md#requirement-5`
- **type**: regression
- **preconditions**: A coverage script `tests/postman/coverage.js` parses `appinfo/routes.php` + iterates over the Postman collection items
- **steps**: Run `node tests/postman/coverage.js`
- **expected result**: Every route in `appinfo/routes.php` mapped to at least one happy-path request AND at least one error-path request in the collection; missing routes listed in stdout with non-zero exit
- **test command**: /test-api

### TC-12: `npm run test:newman` script exists and executes Newman against localhost:8080
- **spec_ref**: `…/spec.md#requirement-6`
- **type**: regression
- **preconditions**: `package.json` declares `"test:newman": "newman run …"` in scripts; dev container booted with `admin:admin` credentials
- **steps**: From repo root run `npm run test:newman`
- **expected result**: Newman exits 0; collection report writes to `tests/postman/results/newman-report.json`; happy-path requests return 200/201; error-path requests return their expected 4xx
- **test command**: /test-api

### TC-13: Newman captures p95 response-time baselines and fails on regression
- **spec_ref**: `…/spec.md#requirement-7`
- **type**: performance
- **preconditions**: Baseline file `tests/postman/baseline-p95.json` exists with current p95 per endpoint; CI compares against baseline with a 50% regression tolerance
- **steps**: Run `npm run test:newman` with `--reporters json,cli`; pipe result through `tests/postman/check-perf.js`
- **expected result**: For every endpoint, current p95 ≤ baseline × 1.5; PR comment shows ranked offenders; build fails when any endpoint regresses beyond tolerance
- **test command**: /test-performance

### TC-14: Playwright regression project covers all 10 resource pages
- **spec_ref**: `…/spec.md#requirement-8`
- **type**: functional
- **preconditions**: `tests/e2e/` contains one spec file per resource page (sources, endpoints, consumers, mappings, cloud-events, syncs, sync-contracts, rules, import, dashboard); each spec covers list/create/edit/delete/search
- **steps**: Run `npx playwright test --project=regression`
- **expected result**: All 10 specs pass; total wall time under 10 minutes; output reports per-page pass/fail
- **test command**: /test-functional

### TC-15: Each resource-page spec covers the full CRUD lifecycle
- **spec_ref**: `…/spec.md#requirement-8`
- **type**: functional
- **preconditions**: Dev container booted with seed data; admin:admin login; each spec uses page-object fixtures from `tests/e2e/fixtures/`
- **steps**: For one representative spec (`sources.spec.ts`), run `npx playwright test tests/e2e/sources.spec.ts --headed`
- **expected result**: Spec demonstrates: page loads, list shows ≥ 1 seed row, create dialog opens, save returns to list with new row, edit modifies a field and persists, delete removes the row, search filters the list. All assertions pass.
- **test command**: /test-functional

### TC-16: Playwright workers=4 in the regression project config
- **spec_ref**: `…/spec.md#requirement-9`
- **type**: regression
- **preconditions**: `playwright.config.ts` declares a `regression` project with `workers: 4`
- **steps**: Parse the config via `node -e "const c = require('./playwright.config.ts'); console.log(c.default.projects.find(p => p.name === 'regression').workers)"`
- **expected result**: Returns `4`; full regression suite runs in parallel across 4 worker processes
- **test command**: /test-functional

### TC-17: Playwright migration round-trip spec verifies post-migration data integrity
- **spec_ref**: `…/spec.md#requirement-10`
- **type**: regression
- **preconditions**: `tests/e2e/migration-round-trip.spec.ts` exists; dev container restored to a snapshot containing legacy `oc_openconnector_*` table data (≥ 1 row per entity); `occ openconnector:migrate-storage` available on PATH
- **steps**: Spec executes (a) capture row counts from legacy tables; (b) run migrate command; (c) load each resource page; (d) assert same row counts visible in UI; (e) cross-check ≥ 1 sample row's content
- **expected result**: All row counts match pre/post migration; sample content identical; spec passes within 5 minutes
- **test command**: /test-functional

### TC-18: CI uploads Playwright failure artifacts (traces + screenshots)
- **spec_ref**: `…/spec.md#requirement-11`
- **type**: regression
- **preconditions**: GitHub Actions workflow has an `if: failure()` step that runs `actions/upload-artifact` against `tests/e2e/test-results/`
- **steps**: Introduce an intentional failure on a feature branch; open a PR; let CI run
- **expected result**: Failed run shows a downloadable artifact bundle containing `*.trace.zip`, `*.png`, and `*.webm`; artifact retention ≥ 7 days
- **test command**: /test-functional

### TC-19: LegacyToRegisterMigratorTest covers all 6 integer-FK translations
- **spec_ref**: `…/spec.md#requirement-12`
- **type**: regression
- **preconditions**: `tests/Unit/Service/Migration/LegacyToRegisterMigratorTest.php` exists; covers each of the 6 integer FK columns from chain B (`CallLog.sourceId`, `CallLog.actionId`, `CallLog.synchronizationId`, `EventMessage.eventId`, `EventMessage.consumerId`, `EventMessage.subscriptionId`)
- **steps**: Run `vendor/bin/phpunit tests/Unit/Service/Migration/LegacyToRegisterMigratorTest.php`
- **expected result**: All 6 FK-translation tests pass; each verifies the JOIN-lookup produces the correct target uuid; assertion failures point at the specific FK column
- **test command**: /test-api

### TC-20: LegacyToRegisterMigratorTest covers Synchronization.sourceId branching (3 formats)
- **spec_ref**: `…/spec.md#requirement-12`
- **type**: regression
- **preconditions**: Test class includes scenarios for all three documented value formats (integer PK, `register/schema` slug-pair, uuid) PLUS an unknown-shape input
- **steps**: Run the specific test methods; verify each produces the expected branching outcome
- **expected result**: Integer variant resolves to source uuid; slug-pair passes through unchanged; uuid passes through unchanged; unknown shape is logged and skipped (not silently corrupted); a counter increments per variant for the per-platform tally output
- **test command**: /test-api

### TC-21: LegacyToRegisterMigratorTest covers dual-platform JSON build
- **spec_ref**: `…/spec.md#requirement-12`
- **type**: regression
- **preconditions**: Test mocks `IDBConnection::getDatabasePlatform()` to return both `MySQLPlatform` and `PostgreSQLPlatform`
- **steps**: Run the platform-branching tests
- **expected result**: MySQL path emits `JSON_OBJECT(...)`; Postgres path emits `jsonb_build_object(...)`; produced object is equivalent in both paths; failing platform raises a clear `\LogicException`
- **test command**: /test-api

### TC-22: Owner field assertion — every migrated row has owner IS NULL
- **spec_ref**: `…/spec.md#requirement-12`
- **type**: security
- **preconditions**: Migrator runs against the test fixture set; result inspected via `ObjectService::findAll('openconnector', '<schema>')`
- **steps**: For each of the 15 schemas, fetch all migrated rows and assert `owner === null` on every one
- **expected result**: Zero rows with non-null owner; `userId` (where present in source) preserved inside the object JSON body for provenance
- **test command**: /test-security

### TC-23: Coverage summary — verify all 12 requirements mapped to tests
- **spec_ref**: spec-wide
- **type**: regression
- **preconditions**: This test-plan parsed by `tests/coverage-mapping.js`
- **steps**: Parse spec.md headings; cross-check that every `### REQ-NNN` (or `### Requirement:` after the format-cleanup) has ≥ 1 TC referencing it in `spec_ref`
- **expected result**: All 12 requirements covered by ≥ 1 TC; the script exits 0; no orphan requirements
- **test command**: /test-api

## Coverage Summary

| Requirement | Title | TCs | Status |
|---|---|---|---|
| REQ-001 | ObjectServiceMockBuilder helper exists | TC-01 | Covered |
| REQ-002 | Every chain-C service has a test file with happy + error paths | TC-02, TC-03, TC-04, TC-05 | Covered |
| REQ-003 | Every write-side DTO has a test file | TC-06, TC-07 | Covered |
| REQ-004 | CI enforces 80% line / 70% branch coverage | TC-08, TC-09 | Covered |
| REQ-005 | Newman collection exists and covers all REST endpoints | TC-10, TC-11 | Covered |
| REQ-006 | `npm run test:newman` script exists | TC-12 | Covered |
| REQ-007 | Newman captures p95 baselines | TC-13 | Covered |
| REQ-008 | Playwright regression project covers 10 resource pages | TC-14, TC-15 | Covered |
| REQ-009 | `playwright.config.ts` sets workers=4 | TC-16 | Covered |
| REQ-010 | Playwright migration round-trip spec | TC-17 | Covered |
| REQ-011 | CI uploads Playwright failure artifacts | TC-18 | Covered |
| REQ-012 | LegacyToRegisterMigratorTest verifies chain-B paths | TC-19, TC-20, TC-21, TC-22 | Covered |
| (meta) | Coverage mapping self-check | TC-23 | Covered |

## Out of Scope

- **Unit coverage of `lib/Service/SettingsService::applyRetention()`** — ADR-009 flagged MySQL-only SQL there. A targeted fix issue is tracked separately; this change does NOT add coverage that would mask that bug. Once `applyRetention` is portability-fixed, coverage tests follow in a follow-up change.
- **Encryption-layer tests for Source credentials** — ADR-007 documents that the `EncryptionService` class is currently absent and credentials are stored plaintext. Adding encryption tests here would assert behaviour that doesn't exist yet. Tracked as a separate follow-up `openconnector-credential-encryption` change.
- **Performance baselines for the Playwright suite** — captured separately under TC-13 for Newman. Playwright total-runtime gate (under 10 minutes) is the only perf assertion on the E2E side in this change.
- **Cross-DB matrix on the Playwright suite** — ADR-009 mandates MySQL + Postgres support. Postgres is the merge-gate DB; MySQL is verified by Newman + PHPUnit. Full Playwright on both engines is a follow-up.
- **Persona tests** — this change builds developer-facing test infrastructure. Persona testing flows through the resource pages stays scoped to D2's test-plan; no persona TCs introduced here.

## Promotion notes

After implementation, the following TCs should be promoted to reusable test scenarios via `/test-scenario-create` because they have ongoing regression value beyond this single change:

- TC-17 (migration round-trip) — the single canonical "did the migration work?" assertion; promote so future infrastructure changes can re-invoke it
- TC-22 (owner null assertion) — security-relevant invariant of the entire chain
- TC-15 (CRUD lifecycle through one resource page) — anchor scenario for new Cn-component upgrades on the frontend
