# Design: guard-deletion-on-failed-fetch

## Context

`SynchronizationService::fetchSinglePageData()` (~line 3337 of
`lib/Service/SynchronizationService.php`) is the single choke point for EVERY
collection page fetch. The pull path routes exclusively through it:
`getAllObjectsFromApi → fetchAllPages (~3109) → fetchSinglePage (~3304)` and
`fetchAllPagesOptimized (~3158)` both call `fetchSinglePageData`. Nothing else
routes through it.

Today it special-cases HTTP 429 by throwing `TooManyRequestsHttpException`
(~line 3345). For any other error it falls through: a `null` response returns
`['objects' => [], 'result' => []]` (~3353-3355), and a non-null error response
parses to zero objects. Either way the page yields ZERO objects with no abort.
That empty/partial list flows up to `objectList` and into Stage 5,
`deleteInvalidObjects()` (~line 2015), which at ~line 2074 computes
`$targetIdsToDelete = array_diff($allContractTargetIds, $synchronizedTargetIds)`
with NO fetch-success guard. On a 500/503/no-response, every not-re-synced
target is treated as orphaned-at-source and DELETED — silent data loss on a
transient error.

The helper `callLogStatusCode()` (~line 3672) reliably returns the status from
`body.statusCode` or `body.response.statusCode`, even when the parsed
`$response` is null (CallService turns a Guzzle `ConnectException` into a
Response with `statusCode` 503).

Constraints: this is a behavioural retrofit on an existing capability
(`synchronization-engine`, status `done`). No schema/DB change. No new
dependency. Independent of the sync-object-error-isolation change (#108).

## Goals / Non-Goals

**Goals**
- Never delete target objects when the source collection fetch failed.
- Abort the whole run on a failed collection fetch, consistent with 429.
- Preserve the legitimate 2xx-empty case (deletion still allowed).

**Non-Goals**
- No change to the 429 rate-limit path.
- No change to single-object / extra-data fetches (`callSourceObject` callers).
- No "process fetched + skip delete only" partial mode. No internal batching.

## Decisions

### Decision 1: Guard in `fetchSinglePageData()` only

Place the guard in `fetchSinglePageData()`, after the 429 branch. This is the
collection-only choke point, so catching the whole `>= 400` range plus
no-response is correct here and cannot affect single-object fetches.

- **Why:** Single-object / sub-resource fetches (`fetchExtraDataForObject`
  ~1810, `fetchMultipleExtraData` ~1945, `fetchFile` ~4134) call
  `callSourceObject` directly, never `fetchSinglePageData`, and do not build the
  authoritative list or drive deletion. Their 401/403/404 carry
  object-availability meaning that must be preserved.
- **Alternative considered:** Guarding in `deleteInvalidObjects()` by passing a
  "fetch succeeded" flag down the call chain. Rejected — it threads state
  through several methods and leaves the false-empty list flowing upward; the
  single choke point is cleaner and aborts earlier.

### Decision 2: Abort via a new `SourceFetchException`

Add `lib/Exception/SourceFetchException.php` (extends `\Exception`, SPDX header
per repo convention). After the 429 branch, compute
`$statusCode = $this->callLogStatusCode(callLog: $callLog)` and throw when
`$statusCode === null` OR `$statusCode >= 400`. Log source id + endpoint +
statusCode.

- **Why:** Mirrors the existing 429 abort pattern (throw an exception that
  unwinds the pagination loop and `synchronize()` before Stage 5). A dedicated
  exception type lets callers/tests distinguish a source-fetch failure from a
  rate-limit or generic error.
- **Alternative considered:** Reusing a generic `\Exception` — rejected because
  tests and future callers benefit from a typed, greppable failure.

### Decision 3: Preserve legitimate empty 2xx

Only `null` or `>= 400` aborts. A real 2xx that parses to zero objects returns
empty as before and STILL allows Stage 5 deletion — the source authoritatively
said "empty".

## Risks / Trade-offs

- [A source returns 4xx for an empty collection] → Such a source is
  non-conformant; the correct 2xx-empty path is preserved and regression-tested.
  Fix or map the source upstream rather than weaken the guard.
- [A previously-silent completion now errors] → Intended: the run correctly
  surfaces the source failure instead of destroying data.

## Migration Plan

Pure code change. Deploy the new exception class and the guard together.
Rollback = revert the guard block and delete the exception file. No data or
schema migration.

## Open Questions

None.