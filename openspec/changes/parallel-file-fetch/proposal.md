---
kind: code
depends_on: [stream-file-content]
---

# Proposal: parallel-file-fetch

## Summary
Fetch a single synchronized object's multiple files concurrently instead of one
at a time, to cut synchronization wall-clock time. The fetch phase fires
per-file asynchronous HTTP requests through Integriq's existing Guzzle
async path (`CallService::callSourceObject(..., asynchronous: true)`) with a
capped concurrency window, and each resolved download is saved via the promise's
`then()` callback so a completed file is persisted while its siblings are still
downloading. Net wall-clock for one object's files drops from
`Σ(fetch) + Σ(save)` toward `max(fetch-window, Σ(save))`. This builds directly
on `stream-file-content`: each concurrent fetch streams into its own
disk-backed temp file addressed by path, so N in-flight downloads do not multiply peak
memory.

## Motivation
`SynchronizationService::processMultipleFilesWithCleanup` currently loops a
single object's file endpoints and calls `fetchFile(...)` one at a time. Each
`fetchFile` blocks on a full HTTP round-trip before the next begins, so an object
with many attachments (a common shape for zaaksysteem `zaak` documents) pays the
sum of every download's latency serially. Because the HTTP client already has an
async capability that preserves all of `CallService`'s behaviour (auth, client
certificates, rate limiting, call logging), and because `stream-file-content`
now makes each fetch stream to disk rather than buffer in memory, we can settle
several downloads concurrently within one PHP thread and pipeline the saves —
turning a latency-bound serial loop into a bounded concurrent window. Now is the
right time because the async transport and the per-file streaming it depends on
are both in place.

## Affected Projects
- [ ] Project: `integriq` — refactor the within-one-object multi-file path
  (`processMultipleFilesWithCleanup`, splitting `fetchFile` into a fetch phase
  and a save phase) to fire capped-concurrency async requests and pipeline the
  saves behind the fetch window.

## Scope

### In Scope
- Parallelise the FETCH phase for the files of a single object: fire per-file
  `callSourceObject(..., asynchronous: true)` requests, each carrying its own
  `sink => <temp-file path>` (per
  `stream-file-content`).
- Settle the requests concurrently with a configurable concurrency cap (Guzzle
  `Pool` or a windowed `Utils::settle`, e.g. 5–10 in flight) so file descriptors
  are not exhausted and the source is not hammered.
- Pipeline the saves: attach the existing FileService save + filename / tags /
  publish logic (from `fetchFile`) to each promise's `then()` callback so a
  resolved download is saved while siblings still download. Saves remain
  serialized.
- Preserve error isolation: one file's fetch or save failure MUST NOT abort the
  other files or the object, composing with the existing `fetchFileSafely`
  handling.
- Unit tests for concurrent fetch, `then()`-pipelined save, concurrency cap, and
  per-file failure isolation.

### Out of Scope
- **Parallel saves.** Saves stay serialized. PHP is single-threaded and Nextcloud
  uses one shared PDO/DB connection, so concurrent OpenRegister object/file
  writes in one process are not safe. Truly parallel saves would require a
  multi-process background-worker architecture, which conflicts with the
  synchronous no-batching consistency model and is deliberately deferred.
- **ReactPHP / `react/http`.** Not introduced — see Approach for why Guzzle async
  is used instead.
- **Internal batching / paging.** No fetch-page-1 / process / fetch-page-2. This
  is within-run concurrency over an already-fully-fetched object file list.
- **Cross-object parallelism.** Scope is strictly the files WITHIN one object;
  object-level iteration is unchanged.
- **The base64-in-JSON streaming follow-up** inherited from `stream-file-content`
  remains out of scope.

## Approach
Split `fetchFile` conceptually into a fetch step and a save step. In
`processMultipleFilesWithCleanup`, build one async request per file endpoint via
`callSourceObject(..., asynchronous: true)`, each with its own temp-file path as sink,
and settle them through a Guzzle concurrency primitive (`GuzzleHttp\Pool` or a
windowed `Utils::settle`) capped at a configurable limit. Attach the save logic
to each promise's `then()` so persistence is pipelined behind the fetch window;
attach failure handling to `otherwise()` so a single file's error is isolated.
The concurrency cap is configurable and throttling is logged.

Guzzle async is chosen over ReactPHP deliberately. `react/async` +
event-loop + promise are installed, but `react/http` (a loop-native async HTTP
client) is absent, and `react/async` cannot parallelise blocking curl calls — a
blocking call stalls the fiber/loop and yields zero real concurrency. Guzzle's
`requestAsync()` uses `CurlMultiHandler` under the hood, giving genuine
concurrent HTTP I/O in one PHP thread. `CallService` already supports this:
`dispatchRequest(..., asynchronous: true)` returns a Guzzle promise with
cert-cleanup, rate-limit, and call-logging hygiene attached to its
then/otherwise, so async calls keep all of `CallService`'s behaviour. We reuse
that surface and do NOT reimplement HTTP/auth/cert/logging.

## New Dependencies
None. Uses the already-installed Guzzle (`GuzzleHttp\Pool` / `GuzzleHttp\Promise\Utils`)
and the existing `CallService` async path; per-file streaming comes from
`stream-file-content`.

## Impact
- `integriq`: `lib/Service/SynchronizationService.php`
  (`processMultipleFilesWithCleanup`, and splitting `fetchFile` into fetch + save
  phases). No API endpoints, routes, DB tables, or OpenRegister schemas change.
- No change to OpenRegister; this change consumes the `FileService` contract
  already specified by `stream-file-content`.

## Cross-Project Dependencies
Depends on `stream-file-content` (declared in frontmatter `depends_on`). Each
concurrent fetch streams into its own disk-backed temp file, which relies on
the per-file streaming and the widened `string|resource` `FileService` content
type that `stream-file-content` establishes. No new cross-repo signature change
is introduced here — the OpenRegister `FileService` contract is unchanged from
`stream-file-content`.

## Risks

### Risk 1: Source overload from unbounded concurrency
**Severity:** Medium — **Mitigation:** Concurrency is capped and configurable per
source (Guzzle `Pool` concurrency or a windowed `Utils::settle`), defaulting to 5
with a hard maximum of 20 in flight, plus a total in-flight byte budget
(default ~256 MB) so that a few very large attachments cannot saturate disk under a
count-only cap. Throttling is logged so operators can observe when requests are
queued.

Note that file-descriptor exhaustion — the original framing of this risk — is not
the real constraint: each fetch costs 2 descriptors (socket plus the transport's own
temp handle), so 40 at the hard maximum, against a measured `ulimit -n` of 1024.
The binding constraints are upstream politeness and, because saves stay serialized,
`Σ saves`.

### Risk 2: Regressing error isolation
**Severity:** Medium — **Mitigation:** Compose with the existing
`fetchFileSafely` handling; attach per-file failure handling to `otherwise()`.
A unit test asserts one file's failure does not stop the others or the object.
This is independent from the `sync-object-error-isolation` change but must not
regress it.

### Risk 3: Concurrent streams multiplying memory
**Severity:** Low — **Mitigation:** `stream-file-content` streams each fetch into
its own temp file on disk, so N concurrent downloads
cost roughly N × 2 MB of memory ceiling plus disk, not N × file-size in RAM. The
concurrency cap bounds N.

### Risk 4: Source has no consistent ordering
**Severity:** Low — **Mitigation:** This change does not depend on ordering.
Zaaksysteem has no consistent ordering; because concurrency runs over an
already-fully-fetched object file list (no source-side paging split), it carries
no missing/double-sync risk.

## Rollback Strategy
Revert the single Integriq commit that refactors
`processMultipleFilesWithCleanup` / `fetchFile`. The change is self-contained
within `SynchronizationService`; reverting restores the sequential
one-at-a-time loop. No data migration is involved, and the `stream-file-content`
streaming behaviour is unaffected by the revert.

## Open Questions
None — the concurrency cap defaults to 5 (configurable) with a hard maximum of
10; no higher, to keep the source politely loaded.