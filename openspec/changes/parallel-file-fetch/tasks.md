# Tasks: parallel-file-fetch

> **Design corrections (2026-07-29), applied after `stream-file-content` shipped:**
>
> 1. **The sink is a temp-file PATH, not a `php://temp` handle.** Guzzle wraps a
>    resource-typed `sink` in a PSR-7 Stream and closes that resource on destruct,
>    handing the caller back a closed handle. That defect made every synced object
>    fail; see `design.md` → "The sink is a PATH, never a handle". Async makes it
>    worse, not better, because the response stream's lifetime is outside the
>    caller's control.
> 2. **There is no working async surface at all.** The original design assumed
>    `callSourceObject(..., asynchronous: true)` existed. It does not — and the layer
>    below is worse than missing, it is broken: `CallService::call()` is declared
>    `): ObjectEntity` yet its `if ($asynchronous === true)` branch returns a Guzzle
>    Promise, which is an unconditional `TypeError`. Nothing exercises it (the only
>    `asynchronous: true` in `lib/` is `call()`'s own hand-off to
>    `dispatchRequest`), so the branch has never run. New Task 0 adds **sibling
>    async methods** rather than widening the synchronous ones — see the design's
>    "Sibling async methods, not union returns".
> 3. **Temp-file cleanup must be restated per file.** Splitting fetch from save
>    moves the release out of `fetchFile`'s `finally`; with N concurrent fetches and
>    partial failures, every leg must release its handle and unlink its path.

## Implementation Tasks

### Task 0: Add sibling async methods and delete the dead async branch
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently`
- **files**: `lib/Service/CallService.php`, `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN `CallService::call()` is declared `): ObjectEntity` and its `$asynchronous === true` branch returns a Guzzle Promise WHEN that branch is reached THEN it is an unconditional `TypeError` — so the branch is removed and a sibling `callAsync(): PromiseInterface` is added instead
  - GIVEN the pre-dispatch pipeline (early-error guard, circuit-breaker guard, credential resolution, source-config merge, method/URL resolution) WHEN both `call()` and `callAsync()` run THEN they share ONE extracted implementation — the async path must not fork auth, certificate, rate-limit or call-logging behaviour
  - GIVEN every existing caller of `call()` WHEN this lands THEN no signature they use changes and the return is still `ObjectEntity` — no union return, no narrowing required at ~30 call sites
  - GIVEN `SynchronizationService::callSourceObjectAsync()` WHEN it is called THEN it resolves the source exactly as `callSourceObject()` does (shared extraction: transient bridge, uuid-then-id addressing, `findSourceObject`) and returns a `PromiseInterface`
  - GIVEN the promise resolves WHEN `then()` runs THEN it yields the same call-log `ObjectEntity` shape the synchronous path returns, so the save phase consumes one shape
  - GIVEN a caller still passes `asynchronous: true` to `call()` after this change WHEN it runs THEN it fails loudly (pointing at `callAsync()`) rather than fataling on a return-type mismatch
- [x] Implement — started in `eaa6c466`, completed here:
  - [x] `SynchronizationService::resolveSourceObjectForCall()` extracted from `callSourceObject()` (transient ad-hoc bridge REQ-012, uuid-then-legacy-id addressing, `findSourceObject()`), so the async sibling cannot fork source resolution
  - [x] Dead `if ($asynchronous === true) { return $response; }` branch removed from `CallService::call()`; the flag now throws `InvalidArgumentException` naming the async sibling. Tracked separately as ocon#1088
  - [x] Extracted `call()`'s pre-dispatch pipeline into `CallService::prepareCall()` (ocon#215 raw re-resolve, expiry values, retry-policy pull-out, enabled/location/rate-limit guards, circuit-breaker guard, source-config merge, method resolution, brokered/injected credentials, preRequest hook, Twig+cert normalisation, URL assembly) returning 13 values plus `shortCircuit`
  - [x] Extracted the post-dispatch pipeline into `CallService::finalizeCall()` (response decode, CallLog persistence per ADR-003, trace step, postRequest hook) — the async path could not otherwise honour ADR-003's "every outbound call produces a CallLog"
  - [x] Added `CallService::callAsync(): PromiseInterface` and `SynchronizationService::callSourceObjectAsync(): PromiseInterface` on top of those extractions
  - [x] Added `CallService::recordBreakerOutcome()` so async classifies breaker outcomes by the SAME retryable-status set `dispatchWithRetry()` uses — a non-retryable 4xx records neither success nor failure on either path
- [x] Test — verified behaviour-preserving against the existing suite (2094 tests, 7613 assertions; the only 2 failures are the `CloudEventListenerTest` pair inherited from `development` via `60f2f14e`/#1086). New async-surface tests land with Task 4.

**Two deliberate async/sync differences, both recorded in `callAsync()`'s docblock:**
1. **No retry loop.** `dispatchWithRetry()` sleeps between attempts, which would stall the shared curl-multi loop and serialize exactly the requests this change exists to overlap. Async is single-attempt; breaker bookkeeping still happens.
2. **A short-circuit resolves, it does not reject.** Guards already persist a synthetic CallLog carrying 409/429/503, so `callAsync()` fulfils with it — one consumed shape, and callers apply the same status check they already apply to `call()`. A rejection means a genuine transport failure.

### Task 1: Split `fetchFile` into a fetch phase and a save phase
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-saves-shall-be-pipelined-behind-the-fetch-window-and-remain-serialized`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN `fetchFile` currently does fetch + save in one method WHEN it is refactored THEN a separable save step (FileService save + filename/tags/publish) can be invoked on an already-resolved download without re-fetching
  - GIVEN the sequential single-file path WHEN the split lands THEN its externally observable behaviour (saved filename, tags, publish state) is unchanged
- [x] Implement — `fetchFile()` is now prepare + dispatch + save: `prepareFileFetch()` (endpoint trim, source-config render, transport choice, temp-path allocation), `saveFetchedFile()` (read handle, body decode, filename resolution, FileService save/addFile, publish), `releaseFileFetch()` (temp-file unlink, idempotent). The save phase owns only the READ handle; the temp file's release moved out so a promise's rejected leg can unlink it too.
- [x] Test — the two existing `fetchFile` transport-selection tests pass unchanged, which is the sequential path's behaviour-preservation check

### Task 2: Fire capped-concurrency async fetches with per-file temp-file sinks
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-a-single-object-s-multiple-files-shall-be-fetched-concurrently`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN one object with several file endpoints WHEN `processMultipleFilesWithCleanup` runs THEN each file is requested via `callSourceObject(..., asynchronous: true)` (Task 0) with its own temp-file **path** as the sink, settled through a Guzzle `Pool`/`Utils::settle`
  - GIVEN a sink is passed WHEN the request is dispatched THEN it is a PATH and never a stream resource, so Guzzle owns and closes only its own handle (see the design corrections above)
  - GIVEN more files than the cap WHEN they are settled THEN in-flight requests never exceed the per-source configurable cap (default 5, hard maximum 20) and throttling is logged
  - GIVEN attachments large enough that N x size would be excessive WHEN they are settled THEN a total in-flight BYTE budget (default ~256 MB, from `Content-Length` where present) also gates admission, falling back to count-only when the source omits it
  - GIVEN the cap is configured WHEN it is read THEN it comes from `source.configuration` (per-source politeness), not a global or a new top-level schema field
  - GIVEN async requests WHEN they run THEN CallService's auth, certificate, rate-limit, and call-logging behaviour is reused (no `react/http`, no reimplemented HTTP)
- [x] Implement — `resolveMultiFileWorkItems()` resolves the complete file list first; `fetchFilesConcurrently()` → `settleFileFetches()` drives a lazy generator through `Each::ofLimit()`; `fetchFileAsync()` dispatches each file via `callSourceObjectAsync()` with its own temp-file PATH sink. Cap and byte budget come from `source.configuration` (`maxConcurrentFetches`, `maxInFlightFetchBytes`) via `resolveFetchConcurrency()`, clamped by `FETCH_CONCURRENCY_MAX`.
- [x] Test — `testMultipleFilesForOneObjectAreFetchedConcurrently` (4/4 in flight; a sequential loop peaks at 1), `testInFlightFetchesNeverExceedTheConfiguredCap`, `testConcurrencyIsClampedToTheHardMaximum`, `testEveryConcurrentSinkIsAPathAndEveryTempFileIsRemoved`

**Byte budget — how `Content-Length` is actually obtained.** A size is only knowable once the response headers arrive, which is too late if admission reads it from the settled call log. So `onHeaders` was plumbed through `callAsync()` → `dispatchRequest()` into Guzzle's `on_headers` request option — kept out of `$config` for exactly the reason `sink` is, since `$config` is Twig-rendered, redacted and persisted and cannot carry a closure. `buildInFlightSizeRecorder()` tallies each declared size; `buildFetchAdmissionGate()` stops admitting once the tally exceeds the budget, and degrades to count-only against a source that omits the header.

**One correction worth recording:** the admission gate's first version special-cased `pending === 0` by returning `1`. `EachPromise` treats the return as the TOTAL allowed in flight and subtracts the pending count itself, so that floor capped the initial fill at one request and silently degenerated the pool to sequential dispatch — caught by `testMultipleFilesForOneObjectAreFetchedConcurrently` measuring a high-water mark of 1 instead of 4. The byte gate now applies only while something is already pending, which keeps both the deadlock and the larger-than-budget-attachment cases safe without touching the count fill.

### Task 2b: Release every per-file handle and temp file, on every leg
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a file's promise resolves WHEN its save completes THEN the save phase's read handle is closed and its temp file is unlinked
  - GIVEN a file's fetch or save rejects WHEN `otherwise()` runs THEN the same release happens, so a partial failure leaks neither a descriptor nor a temp file
  - GIVEN requests that never start because the pool was still throttling, or an object aborts mid-settle, WHEN the run unwinds THEN every allocated temp path is still unlinked
  - GIVEN N files are processed WHEN the object finishes THEN no `oc-stream-*` temp files remain for that object
- [x] Implement — `releaseFetchSlot()` (idempotent: drops the slot's share of the byte tally, then unlinks via `releaseFileFetch()`) is called from the `then()` leg's `finally`, from the `otherwise()` leg, and from the pre-dispatch catch. `releaseUnsettledFileFetches()` sweeps every still-allocated slot from `fetchFilesConcurrently()`'s own `finally`, covering an abort mid-settle and requests the gate was still holding back.
- [x] Test — temp-file absence is asserted after a fully successful run, after a rejected fetch, and after a throwing save

Slot indexes are taken from a monotonic `nextSlot` counter, never from `count($state['released'])` — slots are unset as they settle, so a count-derived index would collide with a live slot as soon as one file finished before another started.

### Task 3: Pipeline serialized saves via `then()` and isolate per-file failures via `otherwise()`
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-one-file-s-failure-shall-not-abort-the-others-or-the-object`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a fetch resolves while siblings still download WHEN its promise settles THEN the save from Task 1 runs in `then()`, and saves execute one at a time (never concurrently)
  - GIVEN one file's fetch or save fails WHEN it is settled THEN the error is isolated and logged via `otherwise()`/`fetchFileSafely`, and the other files and the object continue
  - GIVEN an unchanged file (md5 match) WHEN it is saved THEN no write occurs (stream-file-content skip preserved)
- [x] Implement — the save runs in `then()`, so it starts as soon as its own download resolves. Serialization is structural rather than enforced: promise callbacks run on Guzzle's single-threaded task queue, so exactly one OpenRegister write is ever in progress. Failures are isolated on three levels — a rejected fetch (`otherwise()`), a throwing save (`try/catch` inside `then()`), and a synchronous throw before dispatch (which would otherwise reject the AGGREGATE inside `EachPromise::advanceIterator()` and abort every file not yet started). The md5 skip is untouched; it lives on OpenRegister's write side.
- [x] Test — `testResolvedFetchIsSavedBeforeTheLastSiblingIsDispatched` (a `save:` event precedes the last `dispatch:` event in the recorded timeline), `testSavesAreNeverRunConcurrently` (re-entrancy probe), `testOneFailedFetchDoesNotStopTheOthers`, `testOneFailedSaveDoesNotStopTheOthers`

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
- [x] Implement — 10 new tests: 5 in `CallServiceTest` (async flag rejected by name, `callAsync` resolves to a CallLog, resource sink refused, `on_headers` reaches Guzzle but never the CallLog, short-circuit fulfils rather than rejects) and 5 in `SynchronizationServiceTest` (concurrency, cap, clamp, pipelining, serialization) plus the isolation, temp-file and back-compat cases above.
- [x] Test — 2108 tests / 7664 assertions; the only 2 failures are the `CloudEventListenerTest` pair inherited from `development` (#1086)

The async transport is doubled by a Guzzle promise whose WAIT function performs the simulated download, so `EachPromise`'s own settle order drives the test — the recorded timeline is the real interleaving, not a scripted one.

**Test-fidelity fix required along the way:** `tests/stubs/OCA/OpenRegister/Service/FileService.php` still declared `string $content` on `saveFile()`/`addFile()` where the real OpenRegister methods take `mixed`. `stream-file-content` widened that parameter precisely so a stream resource could be handed to the write side, so the stub rejected exactly the value production accepts and failed every streamed-save test for the wrong reason. Corrected to `mixed` with the `string|resource|null` contract in the docblock, matching the real service.

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
