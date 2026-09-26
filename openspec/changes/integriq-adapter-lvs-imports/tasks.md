# Tasks: integriq-adapter-lvs-imports

## Implementation Tasks

### Task 1: Abstract UWLR client + deterministic mock
- **spec_ref**: `openspec/specs/lvs-result-import/spec.md#requirement-dormant-uwlr-result-import-client-with-deterministic-mock-default-req-001`
- **files**: `lib/Adapters/Lvs/UwlrResultImportClient.php`, `lib/Adapters/Lvs/UwlrResultImportClientMock.php`
- **acceptance_criteria**:
  - GIVEN a `UwlrResultImportClientMock` WHEN `fetchResults('lvs-boom')` is called THEN it returns a deterministic, UWLR-shaped record array with no network I/O
  - GIVEN either client WHEN `flavour()` is called THEN it returns `mock`
- [x] Implement
- [x] Test

### Task 2: Source adapter with lvs-import-contract mapping
- **spec_ref**: `openspec/specs/lvs-result-import/spec.md#requirement-source-adapter-maps-a-uwlr-shaped-batch-onto-the-lvs-import-contract-payload-req-002`
- **files**: `lib/Sources/Lvs/UwlrResultImportSourceAdapter.php`
- **acceptance_criteria**:
  - GIVEN the mock client WHEN `importResults('lvs-boom')` is called THEN the payload uses `lvs-import-contract` field names
  - GIVEN a batch with `leerlingReference` values WHEN the debug log is written THEN no pupil-identifying value appears in it
- [x] Implement
- [x] Test

### Task 3: Seed four dormant Source rows
- **spec_ref**: `openspec/specs/lvs-result-import/spec.md#requirement-four-dormant-source-rows-one-per-supplier-sharing-one-adapter-class-req-003`
- **files**: `lib/sources.seed.json`
- **acceptance_criteria**:
  - GIVEN `lib/sources.seed.json` after this change WHEN parsed THEN it contains `lvs-cito-dult`, `lvs-iep`, `lvs-boom`, `lvs-dia`, all `isEnabled: false`
- [x] Implement
- [x] Test

### Task 4: Contract tests against a recorded/representative UWLR fixture
- **spec_ref**: `openspec/specs/lvs-result-import/spec.md#acceptance-criteria`
- **files**: `tests/fixtures/lvs/fixture-uwlr-result-batch.json`, `tests/Unit/Adapters/Lvs/UwlrResultImportClientMockTest.php`, `tests/Unit/Sources/Lvs/UwlrResultImportSourceAdapterTest.php`
- **acceptance_criteria**:
  - GIVEN the fixture WHEN the mock client loads it THEN the returned shape matches REQ-001's field list
  - GIVEN the source adapter WHEN run against the fixture THEN the mapped payload matches REQ-002's field list exactly, with no extra pupil-identifying keys
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria (unit-level, mock client only)
- [ ] Code review against spec requirements (pending PR review)

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- N/A Newman/Postman — no new HTTP endpoint in this change
- N/A Browser tests (Playwright MCP) — no UI in this change
- [x] All tests pass (`vendor/bin/phpunit --filter UwlrResultImportClientMockTest|UwlrResultImportSourceAdapterTest`)

## Documentation (company-wide ADR-010)

- N/A Feature documentation — dormant backend adapter, no operator-visible feature until a live binding ships
- N/A Screenshot — no UI

## i18n (company-wide hydra ADR-007)

- N/A no new user-facing strings — no admin UI in this change
