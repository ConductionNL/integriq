# Tasks: sync-object-error-isolation

## Implementation Tasks

### Task 1: Seed the `failed` counter in the objects result map
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-per-object-failure-isolation-during-synchronization`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN the run result map is initialized (around line 1634) WHEN a run starts THEN `objects.failed` is present and seeded to 0 alongside found/skipped/created/updated/deleted/invalid
  - The `failed` bucket is documented/kept distinct from `invalid`
- [ ] Implement
- [ ] Test

### Task 2: Wrap per-object processing in try/catch and continue on failure
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-per-object-failure-isolation-during-synchronization`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN the per-object loop (around line 1434) WHEN `processSynchronizationObject(...)` throws `OCA\OpenRegister\Exception\ValidationException` THEN it is caught, recorded, and the loop continues to the next object
  - GIVEN a non-ValidationException `\Throwable` is thrown WHEN the fallback catch runs THEN it is caught, recorded, and the loop continues
  - On failure the object's originId (via `getOriginId`) + synchronizationId + exception are logged via `$this->logger->error(...)`, a `{originId, message}` entry is appended to `$result['errors'][]`, and `$result['objects']['failed']` is incremented
  - The full-list-before-loop fetch model is unchanged (no internal batching)
- [ ] Implement
- [ ] Test

### Task 3: Unit tests for the error-isolation path
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-per-object-failure-isolation-during-synchronization`
- **files**: `tests/Unit/Service/SynchronizationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN an object list with one schema-nonconforming object and valid siblings WHEN the run executes THEN the bad object is skipped + logged + counted as failed while the siblings still synchronize
  - GIVEN a run with a failing object WHEN it completes THEN `objects.failed` is incremented and `errors[]` contains a `{originId, message}` entry
  - GIVEN at least one failing object WHEN the run finishes THEN it completes successfully instead of throwing / returning a 500
- [ ] Implement
- [ ] Test

## Verification
- All tasks checked off
- `openspec validate` passes
- Manual testing against acceptance criteria
- Code review against spec requirements

## Tests (company-wide ADR-009)
- PHPUnit unit tests for the new error-isolation logic (`tests/Unit/`)
- Newman/Postman tests: N/A — no new or changed API endpoint
- Browser tests (Playwright MCP): N/A — no UI change
- All tests pass (`composer test`)

## Documentation (company-wide ADR-010)
- Feature documentation in `docs/`: N/A — internal fault-isolation behavior, no user-facing feature surface
- Screenshot: N/A — no UI change

## i18n (company-wide hydra ADR-007)
- N/A — no new user-facing strings (log/error entries are diagnostic, not localized UI copy)