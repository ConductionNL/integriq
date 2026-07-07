# synchronization-engine — Delta: guard deletion on failed collection fetch

## Purpose

Closes a data-loss defect in the extern→intern pull path. Collection pagination
runs through the single choke point `fetchSinglePageData()`, which today only
special-cases HTTP 429 and otherwise falls through to an empty object list on
any error (5xx, connection failure, unexpected 4xx). That false-empty list
reaches Stage 5 (`deleteInvalidObjects`), which then prunes target objects that
still exist upstream. This delta adds a fetch-success guard so a failed
collection fetch aborts the whole run before deletion, while preserving the
legitimate 2xx-empty case and the unrelated single-object fetch semantics
(ADR-005 sync triad, ADR-003 CallLog observability).

## ADDED Requirements

### Requirement: Abort synchronization when a collection page fetch fails (REQ-006)

The system MUST abort the entire `synchronize()` run when a collection page
fetch through `fetchSinglePageData()` does not return a success status. After
the existing HTTP 429 rate-limit special-case (which MUST be left unchanged),
the system MUST read the response status via `callLogStatusCode()` and, when the
status is `null` (the source did not respond / a connection failure produced no
recorded code) OR the status is `>= 400` (any 4xx other than the already-handled
429, plus all 5xx), it MUST throw a `SourceFetchException` that propagates out of
pagination and out of `synchronize()` BEFORE Stage 5 (`deleteInvalidObjects`) can
run. The thrown error MUST record the source id, the endpoint, and the status
code. No target object may be deleted on a run that aborts this way.

@e2e exclude backend sync engine internals — covered by PHPUnit, not browser UI

#### Scenario: a 5xx during collection fetch aborts with no deletion

- GIVEN a synchronization whose source collection endpoint returns HTTP 503
- WHEN `synchronize()` fetches a collection page through `fetchSinglePageData()`
- THEN a `SourceFetchException` is thrown and the run aborts
- AND `deleteInvalidObjects()` never runs, so no target object is deleted
- @e2e exclude backend sync engine internals — covered by PHPUnit

#### Scenario: a connection failure with no recorded status aborts with no deletion

- GIVEN a source whose connection fails so the CallLog has no `statusCode` (null)
- WHEN `fetchSinglePageData()` evaluates the fetch result
- THEN a `SourceFetchException` is thrown and the run aborts
- AND no target object is deleted
- @e2e exclude backend sync engine internals — covered by PHPUnit

#### Scenario: an unexpected 4xx on the list endpoint aborts with no deletion

- GIVEN a source collection endpoint that returns HTTP 404 or 401
- WHEN `fetchSinglePageData()` evaluates the fetch result
- THEN a `SourceFetchException` is thrown and the run aborts
- AND no target object is deleted
- @e2e exclude backend sync engine internals — covered by PHPUnit

#### Scenario: HTTP 429 keeps its existing rate-limit behaviour

- GIVEN a source collection endpoint that returns HTTP 429
- WHEN `fetchSinglePageData()` evaluates the fetch result
- THEN the pre-existing `TooManyRequestsHttpException` is thrown, unchanged
- AND no `SourceFetchException` is thrown for that status
- @e2e exclude backend sync engine internals — covered by PHPUnit

### Requirement: Preserve deletion on a legitimate empty collection and preserve single-object semantics (REQ-007)

The system MUST still allow `deleteInvalidObjects()` to prune target objects
when the source authoritatively returns a success (2xx) response that genuinely
contains zero objects — an empty 2xx result is a valid "the source has no
objects" signal and MUST NOT be treated as a failure. The guard MUST apply ONLY
to the collection pagination path (`fetchSinglePageData()`); the single-object
and extra-data fetch paths that call `callSourceObject()` directly
(`fetchExtraDataForObject`, `fetchMultipleExtraData`, `fetchFile`) MUST retain
their existing object-availability semantics and MUST NOT abort the run on a 4xx.

@e2e exclude backend sync engine internals — covered by PHPUnit, not browser UI

#### Scenario: a legitimate empty 2xx still allows deletion (regression guard)

- GIVEN a source collection endpoint that returns HTTP 200 with zero objects
- WHEN `synchronize()` completes the collection fetch
- THEN no `SourceFetchException` is thrown
- AND `deleteInvalidObjects()` runs and prunes target objects absent from the source
- @e2e exclude backend sync engine internals — covered by PHPUnit

#### Scenario: a single-object / extra-data 4xx does not abort the run

- GIVEN an extra-data or sub-resource fetch through `callSourceObject()` returns HTTP 404
- WHEN that fetch is evaluated
- THEN no `SourceFetchException` is thrown and the run is not aborted by this change
- AND the pre-existing single-object object-availability handling is preserved
- @e2e exclude backend sync engine internals — covered by PHPUnit

## Non-Functional Requirements

- **Performance:** The guard adds only one status-code read per collection page and MUST NOT add any network call.
- **Internationalization:** No user-facing UI strings are added; the exception message is a developer/log-facing diagnostic.

## Acceptance Criteria

- A 5xx, a no-response (null status), and an unexpected 4xx on the collection endpoint each abort the run with no deletion.
- HTTP 429 still throws the existing `TooManyRequestsHttpException`, unchanged.
- A legitimate 2xx-empty collection still allows `deleteInvalidObjects()` to prune.
- A single-object / extra-data 4xx does not abort the run.

## Notes

- Design decision (settled, do not re-litigate): abort the whole run on a failed
  collection fetch, consistent with the existing 429 behaviour — not "process
  fetched + skip delete only".
- Composes with, but does not depend on, the sync-object-error-isolation change
  (#108). ADR-003: the CallLog remains the primary observability surface for the
  failed fetch.