# Tasks: sync-engine-scalar-items

## Implementation Tasks

### Task 1: Coerce bare-scalar source items at the per-item loop boundary
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008`
- **files**: `lib/Service/SynchronizationService.php` (`synchronizeExternToIntern()` per-item loop)
- **acceptance_criteria**:
  - GIVEN a source item that is a bare scalar (string/int/float/bool) WHEN the per-item loop processes it THEN it is coerced to `['value' => <scalar>]` before `processSynchronizationObject()` is called, and no `TypeError` is thrown
  - GIVEN a source item that is already an array WHEN the per-item loop processes it THEN it is passed through completely unchanged (no coercion, no extra allocation on this path)
- [x] Implement
- [x] Test

### Task 2: Document the `sourceConfig.idPosition: 'value'` contract for scalar sources
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008`
- **files**: `lib/Service/SynchronizationService.php` (docblocks on the coercion site and `getOriginId()`)
- **acceptance_criteria**:
  - GIVEN a developer reads the coercion docblock WHEN they configure a scalar-sourced synchronization THEN the docblock tells them to set `sourceConfig.idPosition` to `'value'`
- [x] Implement
- [x] Test

### Task 3: Correct `synchronization-engine` spec REQ-002 (`array` sourceType is not dispatched)
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-source-object-fetching-and-pagination-req-002`
- **files**: `openspec/specs/synchronization-engine/spec.md` (header format normalization, prerequisite for this change's own delta to auto-merge on archive), `openspec/changes/sync-engine-scalar-items/specs/synchronization-engine/spec.md`
- **acceptance_criteria**:
  - GIVEN the corrected REQ-002 text WHEN read against `getAllObjectsFromSource()`'s actual switch statement THEN the SHALL text and scenarios match observed code (no `array` dispatch claimed)
  - GIVEN `openspec archive` runs THEN the MODIFIED requirement is located and merged into the canonical spec without a header-matching failure
- [x] Implement
- [x] Test

### Task 4: Unit test coverage for scalar coercion and mixed sources
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync-req-008`
- **files**: `tests/Unit/Service/SynchronizationServiceTest.php` (or nearest existing unit test file covering `synchronizeExternToIntern()`/`processSynchronizationObject()`)
- **acceptance_criteria**:
  - GIVEN a source list of pure scalars WHEN a sync run processes them THEN every item is coerced and synced (not dead-lettered)
  - GIVEN a mixed source list (some scalar, some array items) WHEN a sync run processes them THEN both shapes sync successfully
  - GIVEN a pure object/array source list (pre-existing behaviour) WHEN a sync run processes them THEN identity hashing, `idPosition` resolution, and dead-letter behaviour for genuine per-item failures are unchanged
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- [ ] Newman/Postman tests for new/changed API endpoints — N/A: no API surface changed, this is an internal per-item processing fix
- [ ] Browser tests (Playwright MCP) for UI changes — N/A: no frontend change (`EditSynchronization.vue` intentionally untouched, see proposal Out of Scope)
- [x] All tests pass (`php vendor/bin/phpunit` in-container, `php -l` on changed files)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` — N/A: internal engine robustness fix, no new user-facing feature or screen; scalar-source `idPosition` contract is documented in code docblocks and this change's proposal/design/spec
- [ ] Screenshot captured and committed to `docs/images/` — N/A: no UI change

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added — N/A: no new user-facing strings (backend-only fix)
