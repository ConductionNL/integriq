# Tasks: integriq-adapter-rostering-imports

## Implementation Tasks

### Task 1: Abstract roster client + deterministic mock
- **spec_ref**: `openspec/specs/rostering-import/spec.md#requirement-dormant-roster-import-client-with-deterministic-mock-default-req-001`
- **files**: `lib/Adapters/Roster/RosterImportClient.php`, `lib/Adapters/Roster/RosterImportClientMock.php`
- **acceptance_criteria**:
  - GIVEN a `RosterImportClientMock` WHEN `fetchLessons('roster-zermelo')` is called THEN it returns a deterministic lesson batch with no network I/O
  - GIVEN either client WHEN `flavour()` is called THEN it returns `mock`
- [x] Implement
- [x] Test

### Task 2: Source adapter + four seeded rostering Source rows
- **spec_ref**: `openspec/specs/rostering-import/spec.md#requirement-source-adapter-maps-a-roster-batch-onto-the-rostering-import-job-payload-req-002`
- **files**: `lib/Sources/Roster/RosterImportSourceAdapter.php`, `lib/sources.seed.json`
- **acceptance_criteria**:
  - GIVEN the mock client WHEN `importLessons('roster-zermelo')` is called THEN the payload uses the rostering-import job's field names
  - GIVEN `lib/sources.seed.json` after this change WHEN parsed THEN it contains the four rostering rows, all `isEnabled: false`
- [x] Implement
- [x] Test

### Task 3: Migration mapping preset registry + seed data
- **spec_ref**: `openspec/specs/migration-mapping-presets/spec.md#requirement-a-registry-of-named-incumbent-column-mapping-presets-req-001`
- **files**: `lib/Migration/MigrationMappingPresetRegistry.php`, `lib/migration-mapping-presets.seed.json`
- **acceptance_criteria**:
  - GIVEN the seed file WHEN `describeAll()` is called THEN it returns exactly four presets
  - GIVEN `get('parnassys-export')` WHEN resolved THEN it returns a `ColumnMapping` of kind `pupil` with a non-empty identifier column
- [x] Implement
- [x] Test

### Task 4: Presets HTTP endpoint + contract tests
- **spec_ref**: `openspec/specs/migration-mapping-presets/spec.md#requirement-an-operator-can-list-presets-over-the-existing-migration-sources-http-surface-req-002`
- **files**: `lib/Controller/MigrationSourcesController.php`, `appinfo/routes.php`, `tests/fixtures/roster/fixture-roster-batch.json`, `tests/Unit/Adapters/Roster/RosterImportClientMockTest.php`, `tests/Unit/Sources/Roster/RosterImportSourceAdapterTest.php`, `tests/Unit/Migration/MigrationMappingPresetRegistryTest.php`, `tests/Unit/Controller/MigrationSourcesControllerPresetsTest.php`
- **acceptance_criteria**:
  - GIVEN an authenticated non-admin caller WHEN `GET /api/migration-sources/column-mapping/presets` is called THEN it returns the four presets
  - GIVEN the roster fixture WHEN the mock client loads it THEN the shape matches REQ-001's field list
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria (unit-level, mock client + registry only)
- [ ] Code review against spec requirements (pending PR review)

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- N/A Newman/Postman — the one new endpoint is covered by PHPUnit `MigrationSourcesControllerPresetsTest`
- N/A Browser tests (Playwright MCP) — no UI in this change
- [x] All tests pass (`vendor/bin/phpunit --filter RosterImportClientMockTest|RosterImportSourceAdapterTest|MigrationMappingPresetRegistryTest|MigrationSourcesControllerPresetsTest|MigrationSourcesControllerTest`)

## Documentation (company-wide ADR-010)

- N/A Feature documentation — dormant backend adapter and an API-only presets endpoint, no operator-visible UI in this change
- N/A Screenshot — no UI

## i18n (company-wide hydra ADR-007)

- N/A no new user-facing strings — no admin UI in this change
