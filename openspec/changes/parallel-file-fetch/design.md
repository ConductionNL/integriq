# Design: parallel-file-fetch

## Architecture Overview

Today `SynchronizationService::processMultipleFilesWithCleanup` (~line 7813)
loops a single object's file endpoints and calls `fetchFile(...)` (~line 6090)
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
      each with sink: its own temp-file PATH (tempnam)              (stream-file-content)
  settle via Pool / windowed Utils::settle, concurrency cap = 5 (max 10)
      promise->then(fn => save(resolved download))   # save runs while siblings still download
      promise->otherwise(fn => log + skip)           # per-file error isolation
      finally         => fclose own handle + unlink the temp file   # per-file, always
  wall-clock ≈ max(fetch-window, Σ(save))
```

Scope is strictly the files **within one object**; object-level iteration is
unchanged. This builds on `stream-file-content`: each concurrent fetch streams to
its own temp file on disk, so N in-flight downloads cost N × file-size on disk and
effectively nothing in RAM — not N × file-size in memory.

### The sink is a PATH, never a handle (revised)

An earlier revision of this design specified `fopen('php://temp/maxmemory:2097152','r+')`
per fetch and passed that **handle** to Guzzle. That is now known to be unsafe and
was corrected in `stream-file-content`: Guzzle wraps a resource-typed `sink` in a
PSR-7 `Stream` and **closes the underlying resource** when that stream is
destructed. The caller is then holding a closed handle — `is_resource()` reports
false while `str_starts_with()` still rejects it as `resource given`, so the write
side silently took its string branch and threw a `TypeError`. Every synced object
was tallied `invalid`, no file was written, and (before REQ-021) no contract was
persisted, so re-runs duplicated objects.

Passing a path keeps ownership unambiguous: **Guzzle opens and closes its own
handle; we open ours for the save.** This matters more here than in the sequential
case, not less — with `requestAsync()` the response and its body stream are
destructed at a point the caller does not control, so N shared handles would be a
strictly worse version of the same defect. The path-based sink is therefore a
**prerequisite** of this change, not an incidental detail.

### Temp-file ownership across the promise boundary

Splitting fetch from save moves the release out of `fetchFile`'s `finally`, so
cleanup must be restated explicitly. For each file endpoint:

- the **fetch phase** allocates the temp path and owns it until the promise settles;
- the **save phase** (`then()`) opens its own read handle on that path, hands it to
  `FileService`, and closes it;
- **both** the `then()` and `otherwise()` legs — and any early rejection, including
  requests that never start because the pool was still throttling — must release
  the handle and `unlink` the path.

N concurrent fetches with partial failures is precisely the case where a missing
release leaks: the sequential path could rely on one `finally` per call, this one
cannot. Cleanup is per-file and unconditional.

### Interaction with REQ-021 (contract written before the after-rules)

File fetching runs inside the `after`-timed rules, and `synchronization-engine`
REQ-021 now persists the `originId` → `targetId` contract **before** those rules
run. A failure in any of N parallel fetches therefore cannot lose the mapping, so
parallelisation cannot reintroduce the duplicate-object class that ocon#109
describes. This change may rely on that ordering; it must not move the contract
write back behind the rules.

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
This change reuses that surface — it does NOT reimplement HTTP/auth/cert/logging
and does NOT add `react/http`. The pre-existing ReactPHP-based async docs
(`docs/async-file-fetching.md`) describe a fire-and-forget model that is
superseded by this capped, pipelined Guzzle model for the within-object path.

### Sibling async methods, not union returns (revised twice)

An earlier revision of this design asserted that
`callSourceObject(..., asynchronous: true)` already existed and could simply be
called. It does not. Its current signature is:

```php
private function callSourceObject(
    array $source, string $endpoint='', string $method='GET',
    array $config=[], bool $read=false, mixed $sink=null,
    ?ExecutionTraceContext $trace=null
): ObjectEntity
```

There is no `asynchronous` parameter, and the return type is `ObjectEntity` (the
call log) — not a promise. The async capability exists one layer down, on
`CallService::call(..., bool $asynchronous, ...)`, which returns a Guzzle promise
when that flag is set.

And the layer below is not merely missing — it is **broken**. `CallService::call()`
is declared `): ObjectEntity`, yet:

```php
if ($asynchronous === true) {
    // Async path returns the Promise directly (same as original behaviour).
    return $response;   // a GuzzleHttp Promise
}
```

Returning a promise from a method declared `): ObjectEntity` is an unconditional
`TypeError`. Nothing exercises it — the only `asynchronous: true` in `lib/` is
`call()`'s own hand-off to `dispatchRequest` — so the branch has never run. The
comment's "same as original behaviour" refers to a pre-cutover world where the
return type was presumably undeclared.

**Chosen approach: sibling async methods, not union returns.**

- `CallService::callAsync(): PromiseInterface` alongside `call(): ObjectEntity`;
- `SynchronizationService::callSourceObjectAsync(): PromiseInterface` alongside
  `callSourceObject(): ObjectEntity`;
- the shared pre-dispatch pipeline (guards, credential resolution, source-config
  merge, method/URL resolution) and the shared source resolution are **extracted
  once** and used by both, so the async path cannot fork auth, certificate,
  rate-limit or call-logging behaviour;
- the dead `$asynchronous === true` branch in `call()` is deleted rather than
  repaired, and passing that flag now fails loudly pointing at `callAsync()`.

Rejected: widening both returns to `ObjectEntity|PromiseInterface`. `call()` is the
app's central HTTP surface with ~30+ call sites (`SourceCallNode`, `PingAction`,
`NotuBizConnectorService`, `IBabsConnectorService`, `EventService`,
`PromotionService`, `SynchronizationService`, …), all of which rely on
`ObjectEntity`; a union return ripples through static analysis at every one, for no
behavioural gain. Siblings keep the blast radius inside this feature and remove a
latent fatal instead of preserving it.

This is the same shape of gap that `stream-file-content` hit: its design assumed
the OpenConnector side was "only `SynchronizationService`", and the CallService
`sink` option turned out to be a prerequisite. Recording it here so the work is not
planned as pure refactoring inside one method.

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
`stream-file-content` (see that change's `contract.md`); the save phase opens its
own read handle on the per-file temp path and passes that resource to the same
widened `saveFile`/`addFile` surface. Because nothing crosses the app boundary
anew, a separate `contract.md` for this change is intentionally omitted.

The in-app signature change to `callSourceObject` (see "must be widened first"
above) is private to `SynchronizationService` and crosses no app boundary.

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