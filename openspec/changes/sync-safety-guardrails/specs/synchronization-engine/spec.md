# synchronization-engine Specification Delta — sync-safety-guardrails

This delta hardens the extern→intern pull cleanup path against data loss from
failed/partial fetches, test runs, and unbounded ad-hoc Source creation.
REQ numbers continue from the highest currently claimed by any in-flight
sibling change touching this capability (`bulk-gzip-jsonl-ingestion` claims
REQ-006, `markdown-and-html-source-fetchers` claims REQ-007,
`retry-and-circuit-breaker-policies` claims REQ-008; this change takes
REQ-009..REQ-013, `tables-bridge` takes REQ-014, and
`hitl-approval-rule-action` takes REQ-015).

## ADDED Requirements

### Requirement: Fetch-completeness tracking during source pagination (REQ-009)

The system SHALL track, for every extern→intern fetch of an `api` source,
whether the fetch completed (every page was retrieved successfully and
pagination ended because the source reported no further page) or was
incomplete (any page returned a non-2xx/unclassifiable response, any page
fetch failed outright, or the `DEFAULT_MAX_PAGES` safety cap was reached
while a further page was still available). This signal SHALL be available to
the caller of `getAllObjectsFromSource()` / `getAllObjectsFromApi()` without
changing either method's existing return type (an array of fetched objects).
A `429` rate-limit response SHALL continue to be surfaced as a thrown
`TooManyRequestsHttpException` (unchanged) and SHALL also be treated as an
incomplete fetch by the caller catching it.

#### Scenario: a non-2xx page response marks the fetch incomplete

- GIVEN an `api` source synchronization with 3 pages of data
- WHEN page 2 of 3 returns HTTP 500
- THEN the fetch stops at page 2 and is marked incomplete with a
  `failureReason` identifying the failed page
- AND the objects successfully retrieved from page 1 are still returned to
  the caller (so the fetch failure is visible without discarding partial
  results)

#### Scenario: a natural end of pagination is marked complete

- GIVEN an `api` source synchronization whose last page returns zero objects
  with HTTP 200
- WHEN pagination runs to completion
- THEN the fetch is marked complete
- AND this is indistinguishable in outcome from today's pre-existing correct
  behaviour for a genuinely-empty final page

#### Scenario: reaching the pagination safety cap marks the fetch incomplete

- GIVEN an `api` source synchronization whose source keeps returning
  non-empty pages past the `DEFAULT_MAX_PAGES` (50) safety limit
- WHEN the safety cap is reached
- THEN the fetch stops (unchanged — the cap remains a hard limit) and is
  marked incomplete with `failureReason: max_pages_reached`

#### Scenario: static and no-op source types are always complete

- GIVEN a synchronization with `sourceType: array` (static source) or
  `sourceType: register/schema`/`database` (currently no-op)
- WHEN a fetch runs
- THEN the fetch is marked complete (nothing can partially fail on these
  paths today)

### Requirement: Deletion is gated on fetch-completeness and a configurable deletion-ratio guard (REQ-010)

The system SHALL NOT run `deleteInvalidObjects()`'s garbage-collection pass
when the preceding fetch for that run was incomplete (REQ-009). When the
fetch was complete, the system SHALL compare the number of contracts that
would be deleted (contracts whose target id was not seen in the current run)
against the total number of existing contracts for that synchronization; when
this ratio exceeds a configurable per-synchronization threshold
(`sourceConfig.deletionRatioThreshold`, default `0.10`), the system SHALL
abort the deletion pass, log a warning-level message, and dispatch a
`SynchronizationDeletionGuardedEvent`, unless the caller supplied an explicit
`forceDeletion` override. This ratio guard SHALL NOT apply to the
`deleteRestriction` (single-object, event-driven) deletion path, which is
already scoped to an explicitly-identified object. The `forceDeletion`
override SHALL be a distinct parameter from the pre-existing `force`
parameter (which bypasses unchanged-hash skipping and is already applied
automatically by event-driven re-syncs) so that automatic re-syncs can never
silently bypass this guard.

#### Scenario: incomplete fetch blocks deletion entirely

- GIVEN a synchronization with 100 existing contracts
- WHEN a run's fetch is marked incomplete (REQ-009) for any reason
- THEN `deleteInvalidObjects()` is not invoked for that run
- AND 0 objects are deleted
- AND the run's log records the deletion was skipped due to an incomplete
  fetch

#### Scenario: a 429 rate-limit response blocks deletion

- GIVEN a synchronization with existing contracts
- WHEN the source returns HTTP 429 on the first page of a run
- THEN the run's fetch result is empty AND is treated as incomplete
- AND `deleteInvalidObjects()` is not invoked
- AND the caller still receives the `TooManyRequestsHttpException` (429,
  with rate-limit headers) as before — only the deletion side-effect changes

#### Scenario: deleting more than the default threshold aborts and is logged

- GIVEN a synchronization with 100 existing contracts and a complete fetch
  that would delete 15 of them (15%, above the 10% default)
- WHEN `deleteInvalidObjects()` runs
- THEN no objects are deleted
- AND a warning-level log entry and a `SynchronizationDeletionGuardedEvent`
  are recorded with the computed ratio and threshold

#### Scenario: an explicit `forceDeletion` override proceeds past the ratio guard

- GIVEN the same 100-contract/15%-deletion scenario above
- WHEN the run is invoked with `forceDeletion: true`
- THEN the 15 objects are deleted as they would have been before this change

#### Scenario: a per-synchronization threshold override changes the guard point

- GIVEN a synchronization with `sourceConfig.deletionRatioThreshold` set to
  `0.5`
- WHEN a complete fetch would delete 30% of existing contracts
- THEN the deletion proceeds without requiring `forceDeletion` (30% < 50%)

#### Scenario: the deleteRestriction single-object delete path is not ratio-guarded

- GIVEN an OpenRegister `ObjectDeletedEvent` triggers a synchronization run
  with `mutationType: delete` and `sourceConfig.restrictDeletion: true`
- WHEN `deleteInvalidObjects()` runs with that single object's origin id in
  `$data`
- THEN the single matching object is deleted regardless of what percentage
  of the synchronization's total contracts it represents

### Requirement: Test runs make no writes (REQ-011)

A synchronization run invoked with `isTest: true` SHALL NOT create, update,
or delete any `SynchronizationContract`, any target object, or any `Source`
object, and SHALL NOT persist any change to the `Synchronization` entity
itself (including its `targetLastSynced` timestamp).

#### Scenario: a test run against a synchronization with existing synced objects deletes nothing

- GIVEN a synchronization with 50 previously-synced objects and contracts
- WHEN `POST .../synchronizations/{id}/test` is called
- THEN 0 objects are deleted
- AND all 50 pre-existing target objects and contracts remain unchanged

#### Scenario: a test run does not persist the synchronization's own state

- GIVEN a synchronization with `targetLastSynced` set to a prior timestamp
- WHEN a test run completes
- THEN the synchronization's persisted `targetLastSynced` is unchanged
- AND the returned log/result reflects what the test observed without
  mutating stored state

#### Scenario: a test run against a changed object does not write the target

- GIVEN a source object whose content differs from its existing contract's
  `originHash`
- WHEN a test run processes that object
- THEN the transformed result is returned to the caller for inspection
- AND no target object is created or updated
- AND no contract is created or updated

### Requirement: Ad-hoc Source resolution does not persist a new Source (REQ-012)

The system SHALL use a transient, in-memory source configuration, and SHALL
NOT persist a new `Source` object, when a synchronization run is invoked with
an ad-hoc `source` location string that does not match any existing,
admin-configured Source's `location`. Resolution against an already-configured
Source (matching `location`) is unaffected and continues to reuse that
persisted Source, including its rate-limit watermark state.

#### Scenario: an ad-hoc location with no matching Source does not create one

- GIVEN no existing Source has `location` equal to
  `https://example.test/ad-hoc-feed`
- WHEN a synchronization run is invoked with
  `source: "https://example.test/ad-hoc-feed"`
- THEN the run fetches from that location successfully
- AND no new `Source` object is persisted in OpenRegister as a result

#### Scenario: an ad-hoc location that matches an existing Source reuses it unchanged

- GIVEN an existing, admin-configured Source with
  `location: "https://example.test/configured-feed"` and rate-limit state
- WHEN a synchronization run is invoked with
  `source: "https://example.test/configured-feed"`
- THEN that existing Source is reused, including its rate-limit watermark
- AND no new Source object is created

### Requirement: Duplicate synchronization contracts are surfaced, never silently removed (REQ-013)

The system SHALL log a warning identifying all duplicate contract ids, and
SHALL NOT automatically delete or merge any of them, when processing a
source object finds more than one `SynchronizationContract` for the same
`(synchronizationId, originId)` pair.

#### Scenario: a pre-existing duplicate contract pair is logged, not deleted

- GIVEN two `SynchronizationContract` objects exist for the same
  synchronization and the same `originId` (e.g. from data created before
  this change, or a race between two concurrent runs)
- WHEN a run processes a source object with that `originId`
- THEN a warning is logged identifying both contract ids
- AND neither contract nor its target object is deleted by this detection

#### Scenario: the common case of exactly one contract per originId is unaffected

- GIVEN a synchronization where every `originId` has exactly one contract
- WHEN a run processes those objects
- THEN no duplicate-contract warning is logged
- AND behaviour is identical to before this change

## MODIFIED Requirements

### Requirement: Target write, deduplication and file handling (REQ-004)

The system SHALL write each transformed object to its target, branching to an
OpenRegister-specific write when the target is an OR register/schema, and SHALL
maintain one `SynchronizationContract` per object carrying origin/target ids and
hashes for incremental change detection. The system SHALL cascade contract
creation and id rewrites to sub-objects. It SHALL garbage-collect target objects
no longer present in the source (`deleteInvalidObjects()`) unless `force` opts
out, **and unless the run's fetch was incomplete, the run is a test
(`isTest: true`), or the computed deletion ratio exceeds the configured
guard threshold without an explicit `forceDeletion` override (REQ-009,
REQ-010, REQ-011)**. The system SHALL fetch, persist, and clean up files
referenced by sync objects: download a file via `CallService`, validate the
target object id is a UUID, persist to storage, optionally run async batch
fetching (ReactPHP), and remove orphaned files/attachments no longer
referenced after a sync.

<!-- Previous behavior: deleteInvalidObjects() ran unconditionally whenever
     synchronizeExternToIntern() reached its cleanup stage, with no guard for
     fetch failure, partial pagination, test-mode, or deletion volume. -->

@e2e exclude backend target-write internals — covered by PHPUnit/Newman, not browser UI

#### Scenario: OR target write records a contract

- **GIVEN** a transformed object whose target is an OR register/schema
- **WHEN** `updateTarget()` runs
- **THEN** it delegates to `updateTargetOpenRegister()` and a `SynchronizationContract` records the resulting origin/target ids and hashes.

#### Scenario: absent source objects are garbage-collected when the fetch was complete and within the deletion-ratio guard

- **GIVEN** a source no longer returns objects that previously had contracts, a complete fetch (REQ-009), a non-test run, and a deletion ratio within the configured threshold (REQ-010)
- **WHEN** `deleteInvalidObjects()` runs
- **THEN** the now-absent target objects are deleted (garbage-collected).

#### Scenario: referenced file is fetched and persisted

- **GIVEN** a sync object referencing a file URL
- **WHEN** `fetchFile()` runs
- **THEN** the file is downloaded via `CallService`, the object id is validated as a UUID before write, and the file is persisted to storage; a null response throws an `Exception`.

#### Scenario: batch file fetch with cleanup

- **GIVEN** a batch of file references
- **WHEN** `startAsyncFileFetching()` / `executeAsyncFileFetching()` / `processMultipleFilesWithCleanup()` run
- **THEN** files are fetched concurrently and orphaned files are cleaned up afterward via `cleanupOrphanedFiles()`.

#### Scenario: unreferenced attachments are removed

- **GIVEN** a previously-synced object whose attachments are no longer referenced
- **WHEN** `cleanupFilesFromAttachments()` runs
- **THEN** the stale attachments are removed from the object.

**Notes:**

- `fetchFile()` builds the request endpoint from source-supplied
  `location`/`sourceConfiguration` and substitutes `{{ originId }}` into a
  JSON-encoded config. The endpoint is attacker-influenceable via source
  configuration; combined with `base64_decode` of the response body this is a
  surface worth a dedicated SSRF/content-handling review (flagged, not changed).
- `fetchFileSafely()` wraps `fetchFile()` and swallows exceptions so an async
  batch continues past individual file failures — a silent-fail path; failed
  fetches are not surfaced to the caller as a structured error.
- `updateTargetOpenRegister()` is the only fully-wired target-write branch;
  non-OR targets are handled generically by `writeObjectToTarget()`.
- **See REQ-009/REQ-010/REQ-011/REQ-012/REQ-013 (sync-safety-guardrails) for
  the deletion-gating, test-run no-write, ad-hoc Source, and duplicate-contract
  detection behaviour layered onto this requirement.**
- Methods: `updateTarget()`, `updateTargetOpenRegister()`,
  `writeObjectToTarget()`, `deleteInvalidObjects()`, `processSyncContract()`,
  `updateContractsForSubObjects()`, `processSynchronizationObject()`,
  `writeFile()`, `fetchFile()`, `fetchFileSafely()`, `startAsyncFileFetching()`,
  `executeAsyncFileFetching()`, `processMultipleFilesWithCleanup()`,
  `cleanupOrphanedFiles()`, `cleanupFilesFromAttachments()`,
  `shouldPublishFile()`, `getFileContext()`, `getFilenameFromHeaders()`,
  `synchronizeToTarget()`, `detectDuplicateContracts()`.
