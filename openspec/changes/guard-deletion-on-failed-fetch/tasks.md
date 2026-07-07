# Tasks: guard-deletion-on-failed-fetch

## Implementation Tasks

### Task 1: Add SourceFetchException and the fetchSinglePageData guard
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-abort-synchronization-when-a-collection-page-fetch-fails-req-006`
- **files**: `lib/Exception/SourceFetchException.php`, `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a collection page fetch through `fetchSinglePageData()` WHEN, after the existing 429 branch, `callLogStatusCode()` returns null or `>= 400` THEN a `SourceFetchException` is thrown recording source id, endpoint, and status code
  - GIVEN HTTP 429 WHEN evaluated THEN the pre-existing `TooManyRequestsHttpException` still throws, unchanged, and no `SourceFetchException` is thrown
  - GIVEN a 2xx response with zero objects WHEN evaluated THEN no exception is thrown and the empty list is returned as before
- [ ] Implement
- [ ] Test

### Task 2: Confirm deleteInvalidObjects is unreachable on a failed fetch
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-abort-synchronization-when-a-collection-page-fetch-fails-req-006`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN the Task 1 throw aborts `synchronize()` before Stage 5 WHEN a collection fetch fails THEN `deleteInvalidObjects()` is never reached (belt-and-braces confirmation; no behaviour change required if the throw aborts first)
- [ ] Implement
- [ ] Test

### Task 3: Unit tests for all six cases
- **spec_ref**: `openspec/specs/synchronization-engine/spec.md#requirement-preserve-deletion-on-a-legitimate-empty-collection-and-preserve-single-object-semantics-req-007`
- **files**: `tests/Unit/Service/SynchronizationServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a 5xx during collection fetch THEN the run aborts and no deletion occurs
  - GIVEN a connection failure / no-response (503 / null status) THEN the run aborts and no deletion occurs
  - GIVEN a 4xx (e.g. 404/401) on the list endpoint THEN the run aborts and no deletion occurs
  - GIVEN HTTP 429 THEN the existing `TooManyRequestsHttpException` still throws, unchanged
  - GIVEN a legitimate 2xx with zero objects THEN `deleteInvalidObjects()` still prunes (regression guard)
  - GIVEN a single-object / extra-data 4xx THEN the run does NOT abort (semantics preserved)
- [ ] Implement
- [ ] Test

## Verification
- All tasks checked off
- `openspec validate` passes
- Code review against spec requirements REQ-006 and REQ-007

## Tests (company-wide ADR-009)
- PHPUnit unit tests for the guard and regression cases in `tests/Unit/Service/SynchronizationServiceTest.php`
- Newman/Postman tests: N/A — no API endpoint added or changed
- Browser tests: N/A — no UI change
- All tests pass (`composer test`)

## Documentation (company-wide ADR-010)
- N/A — no user-facing feature; behaviour is an internal sync-engine safety guard

## i18n (company-wide hydra ADR-007)
- N/A — no new user-facing strings; the exception message is a developer/log-facing diagnostic