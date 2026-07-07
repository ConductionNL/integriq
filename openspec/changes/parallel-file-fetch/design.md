# Design: parallel-file-fetch

## Architecture Overview

Today `SynchronizationService::processMultipleFilesWithCleanup` (~line 5588)
loops a single object's file endpoints and calls `fetchFile(...)` (~line 4134)
one at a time. `fetchFile` currently does fetch **and** save in one method, and
each iteration blocks on a full HTTP round-trip before the next begins:

```
BEFORE (sequential, within one object)
  for each file endpoint:
      fetchFile(endpoint)               # blocks:
          ├─ HTTP GET (blocking)  ──────── fetch
          └─ FileService save + tags ───── save
  wall-clock ≈ Σ(fetch) + Σ(save)
```

This change splits `fetchFile` into a fetch phase and a save phase, fires the
fetches concurrently through the existing Guzzle async path, and pipelines each
save behind its own resolved fetch:

```
AFTER (concurrent fetch, pipelined serialized saves)
  build one async request per file endpoint:
      callSourceObject(..., asynchronous: true)   → Guzzle Promise
      each with sink: fopen('php://temp/maxmemory:2097152','r+')   (stream-file-content)
  settle via Pool / windowed Utils::settle, concurrency cap = 5 (max 10)
      promise->then(fn => save(resolved download))   # save runs while siblings still download
      promise->otherwise(fn => log + skip)           # per-file error isolation
  wall-clock ≈ max(fetch-window, Σ(save))
```

Scope is strictly the files **within one object**; object-level iteration is
unchanged. This builds on `stream-file-content`: each concurrent fetch streams
into its own disk-backed `php://temp`, so N in-flight downloads cost roughly
N × 2 MB of memory ceiling plus disk, not N × file-size in RAM.

### Why Guzzle async, not ReactPHP

`react/async` + event-loop + promise are installed, but `react/http` (a
loop-native async HTTP client) is **absent**, and `react/async` **cannot**
parallelise blocking curl calls — a blocking call stalls the fiber/loop, giving
zero real concurrency. Guzzle's `requestAsync()` uses `CurlMultiHandler` under
the hood, giving genuine concurrent HTTP I/O in one PHP thread. `CallService`
already exposes this: `dispatchRequest(..., asynchronous: true)` (~line 862)
calls `$this->client->requestAsync(...)` and returns a Guzzle promise; the
promise's then/otherwise carry cert-cleanup and logging/rate-limit hygiene, so
async calls keep **all** of `CallService`'s behaviour.
`callSourceObject(..., asynchronous: true)` (~line 1356) exposes it. This change
reuses that surface — it does NOT reimplement HTTP/auth/cert/logging and does NOT
add `react/http`. The pre-existing ReactPHP-based async docs
(`docs/async-file-fetching.md`) describe a fire-and-forget model that is
superseded by this capped, pipelined Guzzle model for the within-object path.

### Why saves stay serialized

PHP is single-threaded and Nextcloud uses one shared PDO/database connection, so
concurrent OpenRegister object/file writes in one process are not safe. Saves are
therefore attached to each promise's `then()` and executed one at a time as
downloads resolve. Truly parallel saves would require a multi-process
background-worker architecture, which conflicts with the synchronous no-batching
consistency model and is out of scope. The md5 change-detection skip from
`stream-file-content` is the primary save-side lever: an unchanged file costs
zero save.

## API Design

No REST API endpoints are introduced or modified. The change is internal to
`SynchronizationService`.

## Database Changes

None. No tables, columns, migrations, or OpenRegister schemas change.

## Cross-Project Interface

No cross-repo signature change is introduced. This change consumes the
`FileService` `string|resource` content contract already specified by
`stream-file-content` (see that change's `contract.md`); the per-file `php://temp`
sink resource is passed to the same widened `saveFile`/`addFile` surface. Because
nothing crosses the app boundary anew, a separate `contract.md` for this change
is intentionally omitted.

## Nextcloud Integration
- Controllers: none.
- Services: `OCA\OpenConnector\Service\SynchronizationService` (refactored
  multi-file path); `OCA\OpenConnector\Service\CallService` (reused async path,
  unchanged); `OCA\OpenRegister\Service\FileService` (consumed via DI, unchanged
  contract).
- Mappers/Entities: none.
- Events/Hooks: none.
- HTTP concurrency: `GuzzleHttp\Pool` or `GuzzleHttp\Promise\Utils::settle`
  (already installed with Guzzle).

## Security Considerations

No new endpoints, auth, CORS, or CSRF surface. Executable-file blocking and the
md5 change-detection skip are unchanged — they live in OpenRegister's file
handlers on the save path (per `stream-file-content`) and run identically whether
the save is invoked sequentially or from a `then()` callback. Concurrency does
not bypass any validation. Resource-exhaustion is the only new consideration and
is mitigated by the concurrency cap (default 5, hard maximum 10) plus throttle
logging. Per-file failures are isolated and logged, never silently swallowed.

## File Structure
```
lib/
  Service/
    SynchronizationService.php   # processMultipleFilesWithCleanup: build async
                                 #   requests with per-file php://temp sink, settle
                                 #   via Pool/Utils::settle capped at 5 (max 10),
                                 #   pipeline saves in then(), isolate errors in otherwise().
                                 # fetchFile: split into fetch phase + save phase so the
                                 #   save can be attached to a resolved promise.
```

## Trade-offs

- **Guzzle `Pool` vs windowed `Utils::settle`.** Both cap concurrency. `Pool`
  offers a first-class `concurrency` option and lazy request generation; a
  windowed `Utils::settle` is simpler when the full request set is small and
  already materialised (an object's file list is fully known up front). Either
  satisfies the cap; the implementation picks whichever composes most cleanly
  with the existing `callSourceObject` promise return. Chosen: cap via a Guzzle
  primitive, exact primitive left to implementation.
- **Default cap 5 vs 10.** 5 balances wall-clock gain against source politeness
  (zaaksysteem); 10 is the hard ceiling. Higher risks file-descriptor exhaustion
  and hammering the source. Chosen: configurable, default 5, maximum 10.
- **Pipelined serialized saves vs collect-then-save vs parallel saves.**
  Collect-then-save wastes the fetch window (saves would wait for the slowest
  download). Parallel saves are unsafe (single thread, one DB connection).
  Pipelining saves in `then()` while keeping them serialized captures the
  wall-clock win at zero write-safety cost. Chosen: pipeline + serialize.
- **Reuse `callSourceObject(asynchronous: true)` vs a bespoke async client.**
  Reuse keeps auth, client certs, rate limiting, and call logging intact and
  avoids `react/http`. A bespoke client would duplicate all of that and risk
  divergence. Chosen: reuse.