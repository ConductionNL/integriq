---
kind: code
---

# Proposal: guard-deletion-on-failed-fetch

## Summary

Prevent the synchronization engine from DELETING target objects when the source
collection fetch failed. Today a transient source error (5xx, connection
failure, or an unexpected 4xx) during collection pagination silently yields an
empty object list, which the deletion stage then interprets as "the source has
no objects" and prunes target objects that still exist upstream. This change
aborts the whole synchronization run when a collection page fetch does not
return a success status, so the deletion stage never sees a false-empty list.

## Motivation

`SynchronizationService::fetchSinglePageData()` is the single choke point for
every collection page fetch. It special-cases HTTP 429 (throwing
`TooManyRequestsHttpException`), but for any other error it falls through: a
null response returns `['objects' => [], 'result' => []]`, and a non-null error
response parses to zero objects. Either way the page yields ZERO objects with
no abort. That empty list flows up to `objectList` and into Stage 5,
`deleteInvalidObjects()`, which computes
`array_diff($allContractTargetIds, $synchronizedTargetIds)` with NO
fetch-success guard — so every target object not re-synced this run is treated
as orphaned-at-source and DELETED. On a 500/503/no-response, that silently
prunes objects that still exist at the source: unrecoverable data loss on a
purely transient error. This is a correctness and data-safety defect that
should be closed regardless of the rest of the roadmap.

## Affected Projects

- [x] Project: `openconnector` — add a `SourceFetchException`; abort the run in
  `fetchSinglePageData()` when a collection page fetch returns no success
  status, before deletion can run.

## Scope

### In Scope

- A new exception class `lib/Exception/SourceFetchException.php`.
- A guard in `SynchronizationService::fetchSinglePageData()` that, AFTER the
  existing 429 special-case, aborts the whole `synchronize()` run when the
  collection page fetch returned no recorded status (connection failure) or a
  status `>= 400` (any 4xx other than the already-handled 429, plus all 5xx).
- Unit tests covering the failure, no-response, 4xx, 429-unchanged, legitimate
  empty-2xx regression guard, and single-object-4xx-does-not-abort cases.

### Out of Scope

- The single-object / sub-resource fetch paths (`fetchExtraDataForObject`,
  `fetchMultipleExtraData`, `fetchFile`) which call `callSourceObject` directly.
  Their 401/403/404 object-availability semantics are deliberately unchanged.
- The existing 429 rate-limit behaviour, which is left exactly as-is.
- Any internal batching or partial-list processing — deferred; this change
  aborts the whole run, consistent with existing 429 behaviour.

## Approach

In `fetchSinglePageData()`, after the 429 branch, read the status via
`callLogStatusCode(callLog: $callLog)`. When the status is `null` (source did
not respond) OR `>= 400`, throw the new `SourceFetchException` with source id,
endpoint, and status code logged. This propagates out of the pagination loop
and out of `synchronize()` before Stage 5 (`deleteInvalidObjects`) is reached.
A genuine 2xx response that contains zero objects is preserved: it still
returns empty and still allows deletion, because the source authoritatively
reported "empty".

## New Dependencies

None.

## Impact

- `lib/Service/SynchronizationService.php` — one guard added in
  `fetchSinglePageData()`; no other method changes behaviour.
- `lib/Exception/SourceFetchException.php` — new file.
- Behavioural change: a synchronization whose source collection fetch fails now
  ends in error instead of silently completing with deletions. This is the
  intended safety improvement.

## Cross-Project Dependencies

None. Self-contained within `openconnector`. Composes with (but does not depend
on) the sync-object-error-isolation change (#108).

## Risks

### Risk 1: A source that legitimately returns a 4xx on an empty collection

**Severity:** Low — **Mitigation:** Sources that return 4xx for "no results"
are non-conformant; the correct 2xx-with-empty-body case is explicitly
preserved and regression-tested. If such a source is discovered, it should be
fixed or mapped upstream rather than by weakening this guard.

## Rollback Strategy

Revert the guard block in `fetchSinglePageData()` and delete
`lib/Exception/SourceFetchException.php`. No schema or data migration is
involved, so rollback is a pure code revert.