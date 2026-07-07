# Tasks: parallel-file-fetch

## Implementation Tasks

### Task 1: Split `fetchFile` into a fetch phase and a save phase
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN `fetchFile` currently does fetch + save in one method WHEN it is refactored THEN a separable save step (FileService save + filename/tags/publish) can be invoked on an already-resolved download without re-fetching
  - GIVEN the sequential single-file path WHEN the split lands THEN its externally observable behaviour (saved filename, tags, publish state) is unchanged
- [ ] Implement
- [ ] Test

### Task 2: Fire capped-concurrency async fetches with per-file `php://temp` sinks
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN one object with several file endpoints WHEN `processMultipleFilesWithCleanup` runs THEN each file is requested via `callSourceObject(..., asynchronous: true)` with its own `fopen('php://temp/maxmemory:2097152','r+')` sink, settled through a Guzzle `Pool`/`Utils::settle`
  - GIVEN more files than the cap WHEN they are settled THEN in-flight requests never exceed the configurable cap (default 5, hard maximum 10) and throttling is logged
  - GIVEN async requests WHEN they run THEN CallService's auth, certificate, rate-limit, and call-logging behaviour is reused (no `react/http`, no reimplemented HTTP)
- [ ] Implement
- [ ] Test

### Task 3: Pipeline serialized saves via `then()` and isolate per-file failures via `otherwise()`
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a fetch resolves while siblings still download WHEN its promise settles THEN the save from Task 1 runs in `then()`, and saves execute one at a time (never concurrently)
  - GIVEN one file's fetch or save fails WHEN it is settled THEN the error is isolated and logged via `otherwise()`/`fetchFileSafely`, and the other files and the object continue
  - GIVEN an unchanged file (md5 match) WHEN it is saved THEN no write occurs (stream-file-content skip preserved)
- [ ] Implement
- [ ] Test

### Task 4: Unit tests for concurrency, pipelining, cap, and failure isolation
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-concurrency-shall-be-capped-and-configurable`
- **files**: `tests/Unit/Service/SynchronizationServiceTest.php`
- **acceptance_criteria**:
  - N files for one object are fetched concurrently, asserted via a Pool/settle over mocked async promises
  - A resolved fetch is saved via the `then()` pipeline while siblings are unresolved
  - In-flight requests never exceed the configured concurrency cap
  - One file failing does not stop the others or the object
- [ ] Implement
- [ ] Test

## Verification
- All tasks checked off
- `openspec validate parallel-file-fetch` passes
- Manual test: sync one object with several attachments and confirm reduced wall-clock time versus the sequential path, correct stored content, and bounded memory
- Code review against spec requirements and `design.md`

## Tests (company-wide ADR-009)
- PHPUnit unit tests for concurrent capped fetch, then()-pipelined save, cap enforcement, and per-file failure isolation (`tests/Unit/`)
- Newman/Postman tests — N/A (no API endpoints changed)
- Browser tests — N/A (no UI changes)
- All tests pass (`composer test`)

## Documentation (company-wide ADR-010)
- N/A — internal synchronization concurrency behaviour, no user-facing feature or UI surface

## i18n (company-wide hydra ADR-007)
- N/A — no new user-facing strings
