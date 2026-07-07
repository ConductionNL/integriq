# synchronization-files Specification

**Status**: in-progress
**Scope**: openconnector
**OpenSpec changes**:
- parallel-file-fetch

## Purpose

Extends the `synchronization-files` capability (introduced by
`stream-file-content`) with concurrent fetching of a single object's multiple
files. The fetch phase issues per-file asynchronous HTTP requests through the
existing Guzzle async path and settles them within a bounded concurrency window,
while saves are pipelined behind the fetch window and remain serialized. The goal
is to cut synchronization wall-clock time for objects with many attachments
without weakening error isolation, memory bounds, or write consistency. This
capability depends on `stream-file-content`: each concurrent fetch streams into
its own disk-backed `php://temp` handle.

## ADDED Requirements

### Requirement: A single object's multiple files SHALL be fetched concurrently

The system MUST fetch the multiple files of a single synchronized object
concurrently rather than one at a time, by issuing per-file asynchronous HTTP
requests via `CallService::callSourceObject(..., asynchronous: true)` (which
reuses the existing Guzzle async transport, auth, client-certificate handling,
rate limiting, and call logging) and settling them together. Each concurrent
fetch MUST stream into its own disk-backed `php://temp` handle (per
`stream-file-content`) so concurrency does not multiply peak memory by file size.
The system MUST NOT reimplement the HTTP, auth, certificate, or logging behaviour
already provided by `CallService`, and MUST NOT introduce `react/http`.

#### Scenario: N files for one object are fetched concurrently
- GIVEN a single object whose synchronized data references several file endpoints
- WHEN the multi-file path fetches those files
- THEN a per-file asynchronous request is issued via `callSourceObject(..., asynchronous: true)`
- AND the requests are settled together through a Guzzle concurrency primitive rather than one blocking call after another
- AND each request streams its response body into its own `php://temp` handle

#### Scenario: Concurrent fetch reuses CallService behaviour
- GIVEN the source requires authentication, a client certificate, or is rate-limited
- WHEN the files are fetched concurrently
- THEN each async request retains CallService's auth, certificate cleanup, rate limiting, and call logging
- AND no separate HTTP client or `react/http` is used

### Requirement: Concurrency SHALL be capped and configurable

The system MUST cap the number of in-flight file fetches for one object at a
configurable limit, defaulting to 5 and never exceeding a hard maximum of 10, so
that file descriptors are not exhausted and the source is not overloaded. When
requests are held back because the cap is reached, the system MUST log that
throttling occurred.

#### Scenario: In-flight fetches never exceed the configured cap
- GIVEN an object with more file endpoints than the configured concurrency cap
- WHEN the files are fetched concurrently
- THEN the number of simultaneously in-flight requests never exceeds the configured cap
- AND remaining files are started only as in-flight requests complete

#### Scenario: Throttling is logged
- GIVEN more files than the concurrency cap are queued for one object
- WHEN requests are held back to respect the cap
- THEN a log entry records that fetches were throttled

### Requirement: Saves SHALL be pipelined behind the fetch window and remain serialized

The system MUST attach the file-save logic (the existing FileService save plus
filename, tags, and publish handling) to each fetch promise's `then()` callback
so that a resolved download is persisted while its sibling downloads are still in
flight, making net wall-clock time approach `max(fetch-window, Σ saves)` rather
than `Σ fetch + Σ saves`. Saves MUST remain serialized within the single PHP
process — the system MUST NOT perform concurrent OpenRegister object or file
writes, because PHP is single-threaded and Nextcloud uses one shared database
connection. The md5 change-detection skip from `stream-file-content` remains the
primary lever for reducing save-side cost, since an unchanged file incurs no
write.

#### Scenario: A resolved fetch is saved via the then() pipeline
- GIVEN one file's download resolves while other files are still downloading
- WHEN its fetch promise settles
- THEN its save runs from the promise's `then()` callback without waiting for the other downloads to finish
- AND the saved file carries its filename, tags, and publish state exactly as the sequential path produced

#### Scenario: Saves are not run concurrently
- GIVEN several file downloads resolve close together
- WHEN their saves are pipelined
- THEN the OpenRegister writes are executed one at a time, not concurrently
- AND an unchanged file (matching md5) performs no write

### Requirement: One file's failure SHALL NOT abort the others or the object

The system MUST isolate per-file fetch and save failures: a failure of one
file's download or save MUST NOT abort the remaining files or the object,
composing with the existing `fetchFileSafely` error handling. Failure handling
MUST be attached to each promise (for example via `otherwise()`) so a rejected
fetch is logged and skipped while sibling files continue.

#### Scenario: A failed fetch does not stop the others
- GIVEN one file endpoint returns an error while the others succeed
- WHEN the files are fetched concurrently
- THEN the failing file's error is isolated and logged
- AND the other files are still fetched and saved
- AND the object continues processing

#### Scenario: A failed save does not stop the others
- GIVEN one file's save throws while the other files save successfully
- WHEN the saves are pipelined
- THEN the failing save's error is isolated and logged
- AND the remaining files are still saved

### Requirement: Concurrency SHALL NOT depend on source ordering or split source load

The system MUST run this concurrency over an already-fully-fetched object file
list and MUST NOT introduce internal batching or paging (no
fetch-page-1 / process / fetch-page-2). Because the object's file list is
complete before concurrent fetching begins, the behaviour MUST NOT depend on any
consistent ordering from the source (zaaksysteem provides none) and MUST NOT
carry missing-file or double-sync risk.

#### Scenario: Unordered source still produces the complete file set
- GIVEN a source that returns file endpoints in no consistent order
- WHEN the object's files are fetched concurrently
- THEN every referenced file is fetched and saved exactly once
- AND no file is missed or fetched twice regardless of settle order

## Non-Functional Requirements

- **Performance:** Net wall-clock time to fetch and save one object's files MUST
  approach `max(fetch-window, Σ saves)` rather than `Σ fetch + Σ saves`, bounded
  by the concurrency cap (default 5, maximum 10).
- **Memory:** Peak additional memory MUST stay bounded by the concurrency cap
  times the `php://temp` in-memory threshold (~2 MB each), independent of file
  sizes, because each concurrent fetch streams to disk per `stream-file-content`.
- **Internationalization:** No user-facing strings are introduced; Dutch and
  English support (hydra ADR-007) is unaffected.

## Acceptance Criteria

- N files for one object are fetched concurrently (verified via a Pool/settle over mocked async promises).
- A resolved fetch is saved via the `then()` pipeline while siblings download.
- In-flight fetches never exceed the configured concurrency cap.
- One file's fetch or save failure does not stop the others or the object.
- Saves execute serially; an unchanged file (md5 match) performs no write.

## Notes

- Depends on `stream-file-content` (per-file streaming into `php://temp`; widened
  `FileService` `string|resource` content type). No new cross-repo signature
  change is introduced here.
- Transport is Guzzle async (`GuzzleHttp\Pool` / `Utils::settle`), not ReactPHP.
  `react/http` is absent and `react/async` cannot parallelise blocking curl; the
  ReactPHP requirement was deliberately dropped. See `design.md`.
- Parallel saves are a deliberate non-goal (single PHP thread, one shared DB
  connection). Truly parallel saves would require a multi-process worker
  architecture, out of scope.
- Independent from `sync-object-error-isolation` but must not regress it.