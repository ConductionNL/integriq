# Tasks: integriq-adapter-swv

## Implementation Tasks

### Task 1: Abstract SWV hand-off client + deterministic mock
- **spec_ref**: `openspec/specs/swv-handoff/spec.md#requirement-dormant-swv-hand-off-client-with-deterministic-mock-default-req-001`
- **files**: `lib/Adapters/Swv/SwvHandoffClient.php`, `lib/Adapters/Swv/SwvHandoffClientMock.php`
- **acceptance_criteria**:
  - GIVEN a `SwvHandoffClientMock` WHEN `handOff('swv-kindkans', $dossier)` is called THEN it returns a deterministic acknowledgement with no network I/O
  - GIVEN either client WHEN `flavour()` is called THEN it returns `mock`
- [ ] Implement
- [ ] Test

### Task 2: Source adapter with dossier mapping and the Privacyconvenant-holder record
- **spec_ref**: `openspec/specs/swv-handoff/spec.md#requirement-source-adapter-maps-an-already-composed-dossier-onto-the-receivers-envelope-req-002`
- **files**: `lib/Sources/Swv/SwvHandoffSourceAdapter.php`
- **acceptance_criteria**:
  - GIVEN the mock client WHEN `handOffDossier('swv-kindkans', $dossier)` is called THEN the debug log contains no `pupilReference` value
  - GIVEN `swv.privacyconvenant.holder` is unset WHEN `handOffDossier()` is called THEN the call still succeeds
- [ ] Implement
- [ ] Test

### Task 3: Seed two dormant Source rows
- **spec_ref**: `openspec/specs/swv-handoff/spec.md#requirement-two-dormant-source-rows-one-per-receiver-sharing-one-adapter-class-req-003`
- **files**: `lib/sources.seed.json`
- **acceptance_criteria**:
  - GIVEN `lib/sources.seed.json` after this change WHEN parsed THEN it contains `swv-kindkans`, `swv-ldos`, both `isEnabled: false`
- [ ] Implement
- [ ] Test

### Task 4: Contract tests against a recorded/representative dossier fixture
- **spec_ref**: `openspec/specs/swv-handoff/spec.md#acceptance-criteria`
- **files**: `tests/fixtures/swv/fixture-swv-dossier.json`, `tests/Unit/Adapters/Swv/SwvHandoffClientMockTest.php`, `tests/Unit/Sources/Swv/SwvHandoffSourceAdapterTest.php`
- **acceptance_criteria**:
  - GIVEN the fixture WHEN the mock client loads it THEN the returned shape matches REQ-001's field list
  - GIVEN the source adapter WHEN run against the fixture THEN no pupil-identifying key reaches the logger
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- N/A Newman/Postman — no new HTTP endpoint in this change
- N/A Browser tests (Playwright MCP) — no UI in this change
- [ ] All tests pass (`composer test`)

## Documentation (company-wide ADR-010)

- N/A Feature documentation — dormant backend adapter, no operator-visible feature until a live binding ships
- N/A Screenshot — no UI

## i18n (company-wide hydra ADR-007)

- N/A no new user-facing strings — no admin UI in this change
