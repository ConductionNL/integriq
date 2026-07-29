# Tasks: parallel-file-fetch

> **Design corrections (2026-07-29), applied after `stream-file-content` shipped:**
>
> 1. **The sink is a temp-file PATH, not a `php://temp` handle.** Guzzle wraps a
>    resource-typed `sink` in a PSR-7 Stream and closes that resource on destruct,
>    handing the caller back a closed handle. That defect made every synced object
>    fail; see `design.md` → "The sink is a PATH, never a handle". Async makes it
>    worse, not better, because the response stream's lifetime is outside the
>    caller's control.
> 2. **`callSourceObject` has no `asynchronous` parameter.** The original design
>    assumed it did. It must be widened first (new Task 0) — the async capability
>    lives on `CallService::call`, one layer down.
> 3. **Temp-file cleanup must be restated per file.** Splitting fetch from save
>    moves the release out of `fetchFile`'s `finally`; with N concurrent fetches and
>    partial failures, every leg must release its handle and unlink its path.

## Implementation Tasks

### Task 0: Widen `callSourceObject` to expose the async path
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN `callSourceObject` currently has no `asynchronous` parameter and returns `ObjectEntity` WHEN it is widened THEN it accepts `bool $asynchronous=false`, threads it to `CallService::call`, and returns `ObjectEntity|PromiseInterface`
  - GIVEN any existing synchronous caller WHEN it calls `callSourceObject` without the new argument THEN behaviour is byte-for-byte unchanged and the return is still an `ObjectEntity`
  - GIVEN `asynchronous: true` WHEN the promise resolves THEN it yields the same call-log `ObjectEntity` shape the synchronous path returns, so the save phase consumes one shape
- [ ] Implement
- [ ] Test

### Task 1: Split `fetchFile` into a fetch phase and a save phase
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN `fetchFile` currently does fetch + save in one method WHEN it is refactored THEN a separable save step (FileService save + filename/tags/publish) can be invoked on an already-resolved download without re-fetching
  - GIVEN the sequential single-file path WHEN the split lands THEN its externally observable behaviour (saved filename, tags, publish state) is unchanged
- [ ] Implement
- [ ] Test

### Task 2: Fire capped-concurrency async fetches with per-file temp-file sinks
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN one object with several file endpoints WHEN `processMultipleFilesWithCleanup` runs THEN each file is requested via `callSourceObject(..., asynchronous: true)` (Task 0) with its own temp-file **path** as the sink, settled through a Guzzle `Pool`/`Utils::settle`
  - GIVEN a sink is passed WHEN the request is dispatched THEN it is a PATH and never a stream resource, so Guzzle owns and closes only its own handle (see the design corrections above)
  - GIVEN more files than the cap WHEN they are settled THEN in-flight requests never exceed the configurable cap (default 5, hard maximum 10) and throttling is logged
  - GIVEN async requests WHEN they run THEN CallService's auth, certificate, rate-limit, and call-logging behaviour is reused (no `react/http`, no reimplemented HTTP)
- [ ] Implement
- [ ] Test

### Task 2b: Release every per-file handle and temp file, on every leg
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a file's promise resolves WHEN its save completes THEN the save phase's read handle is closed and its temp file is unlinked
  - GIVEN a file's fetch or save rejects WHEN `otherwise()` runs THEN the same release happens, so a partial failure leaks neither a descriptor nor a temp file
  - GIVEN requests that never start because the pool was still throttling, or an object aborts mid-settle, WHEN the run unwinds THEN every allocated temp path is still unlinked
  - GIVEN N files are processed WHEN the object finishes THEN no `oc-stream-*` temp files remain for that object
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
  - The sink handed to `CallService::call` is a PATH, never a resource — assert on the argument type, mirroring `testFetchFileStreamsRawBinaryDownloadIntoASinkResource`
  - Every temp file is unlinked after both a successful and a failed leg (Task 2b)
  - `callSourceObject` without `asynchronous` still returns an `ObjectEntity` (Task 0 back-compat)
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
