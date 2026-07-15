---
status: done
retrofit: true
---

# Synchronization Engine

## Purpose

Synchronize data between OpenConnector and external systems in both directions
(extern→intern pull, intern→extern push), event-driven and on-demand, using the
ADR-005 Source → Synchronization → SynchronizationContract triad for incremental
change detection. Covers orchestration, source fetching with pagination and
rate-limiting, mapping/transformation, target writes with deduplication and
cascade, file handling, a sync-side rule pipeline, and the REST + ADR-019
integration surface that drives it.

This spec retroactively describes 97 existing methods across
`SynchronizationService`, two controllers, and the integration provider. It is a
behavioral retrofit — REQ language matches observed code, and the Notes sections
flag observed-but-suspicious behavior rather than silently correcting it.
## Requirements

### REQ-UI-001: Synchronization Management UI

OpenConnector MUST provide a Synchronizations section in its SPA where administrators
can browse, create, edit, and manage synchronization configurations and view their
contracts and logs.

#### Scenario: synchronizations list page mounts and shows content

- GIVEN an authenticated admin visits the openconnector app
- WHEN they navigate to the Synchronizations section via the sidebar or direct URL
- THEN the Synchronizations index page renders inside the app-content area

#### Scenario: Add Synchronization button opens the creation modal

- GIVEN the Synchronizations index page is loaded
- WHEN the user clicks the "Add Synchronization" button
- THEN a modal/dialog opens containing the synchronization creation form

#### Scenario: Synchronization contracts sub-page mounts

- GIVEN an authenticated admin
- WHEN they navigate to the Synchronization contracts page
- THEN the page mounts and renders the app-content area

#### Scenario: Synchronization logs sub-page mounts

- GIVEN an authenticated admin
- WHEN they navigate to the Synchronization logs page
- THEN the page mounts and renders the app-content area

### REQ-001: Synchronization orchestration and direction routing

The system SHALL run a synchronization given a `Synchronization` object,
selecting the direction from `sourceType`: when `sourceType` is `register/schema`
and an in-memory `$object` is supplied it pushes that object intern→extern; in
all other cases it pulls extern→intern. The orchestrator SHALL create a
`synchronization_log` object first (so its uuid is available for per-contract
logs), validate any supplied `mutationType` against `create|update|delete`,
record execution time, and finalize the log with a success message and a
retention-based expiry. The system SHALL also drive event-driven sync: when an
OpenRegister object changes, it SHALL find all synchronizations whose source is
that register/schema (direct) plus those configured to trigger from related
objects, evaluate per-synchronization trigger conditions
(`sourceConfig.triggerOnlyOnEvents`), resolve parent objects up the relation
graph where required, and run each matching synchronization with `force: true`.

@e2e exclude backend sync engine internals — covered by PHPUnit/Newman, not browser UI

#### Scenario: intern-to-extern push for register/schema source

- **GIVEN** a synchronization with `sourceType: register/schema` and an in-memory object
- **WHEN** `synchronize()` is called
- **THEN** a `synchronization_log` of type `internToExtern` is created and `synchronizeInternToExtern()` runs.

#### Scenario: extern-to-intern pull for other source types

- **GIVEN** a synchronization with any other `sourceType`
- **WHEN** `synchronize()` is called
- **THEN** a `synchronization_log` of type `externToIntern` is created, `synchronizeExternToIntern()` runs, and the log is finalized with execution time and a success expiry.

#### Scenario: invalid mutation type is rejected

- **GIVEN** an invalid `mutationType` (not one of create/update/delete)
- **WHEN** `synchronize()` is called
- **THEN** it throws an `Exception` naming the allowed mutation types.

#### Scenario: object change event drives matching synchronizations

- **GIVEN** an OpenRegister object create/update/delete event
- **WHEN** `handleObjectEventSynchronization()` runs
- **THEN** every direct and related-object synchronization that passes `shouldTriggerOnEvent()` is run with `force: true`, and a delete event is forwarded with `mutationType: delete`.

#### Scenario: trigger conditions skip non-matching synchronizations

- **GIVEN** a synchronization configured with `triggerOnlyOnEvents` that does not list the current mutation type
- **WHEN** the event fires
- **THEN** `shouldTriggerOnEvent()` returns false and that synchronization is skipped.

**Notes:**

- `handleObjectEventSynchronization()` catches every `\Exception` per
  synchronization, logs it, and continues to the next — a single failing
  synchronization does not abort the batch. This is observed behavior; callers
  receive no aggregate error signal (silent-continue). Flag for future
  tightening: the method returns `void` so the caller cannot tell which
  synchronizations failed.
- `shouldTriggerOnEvent()` treats an absent/empty `triggerOnlyOnEvents` as
  "always run". The comparison is case-insensitive.
- Methods: `synchronize()`, `synchronizeInternToExtern()`,
  `synchronizeExternToIntern()`, `synchronizeContract()`,
  `handleObjectEventSynchronization()`, `shouldTriggerOnEvent()`,
  `resolveParentObjectForRelatedObjectTrigger()`, `findAllBySourceId()`,
  `getSynchronization()`, `calculateExpires()`.

### REQ-002: Source object fetching and pagination

The system SHALL fetch objects from a synchronization's source according to
`sourceType`. For `api` sources it SHALL resolve the `source` record, enforce the
source's rate-limit watermark before any call, apply Twig-templated endpoints,
and drive pagination via configurable strategies: an optimized parallel mode
(ReactPHP), a sequential fallback, and per-page single fetches, capped by a
safety limit of 50 pages. Next-page resolution SHALL support query-parameter
pagination, body-embedded next endpoints, and OData `$nextLink`. The system SHALL
fetch per-object extra/sub-resource data when configured, and SHALL support
`array` (static) sources directly. `register/schema` and `database` source types
are recognised but not implemented (no-op).

@e2e exclude backend source-fetching internals — covered by PHPUnit/Newman, not browser UI

#### Scenario: rate-limit watermark cancels the fetch

- **GIVEN** an `api` source whose `rateLimitRemaining` is `<= 0` and whose `rateLimitReset` is in the future
- **WHEN** fetching begins
- **THEN** `checkRateLimit()` throws a `TooManyRequestsHttpException` (429) carrying `X-RateLimit-*` headers, and the synchronization is cancelled.

#### Scenario: multi-page api source is paginated

- **GIVEN** an `api` source returning multiple pages
- **WHEN** `getAllObjectsFromApi()` runs
- **THEN** pages are followed (parallel when available, sequential otherwise) until no next page is found or the 50-page safety cap is reached.

#### Scenario: OData next link drives pagination

- **GIVEN** a response body containing an OData `$nextLink`
- **WHEN** `getNextlinkFromCall()` / `getNextEndpoint()` evaluate it
- **THEN** the next endpoint is extracted and the loop continues; absence of a next link terminates pagination.

#### Scenario: extra sub-resource data is fetched and merged

- **GIVEN** a synchronization configured with `extraDataConfigs`
- **WHEN** an object is processed
- **THEN** `fetchExtraDataForObject()` / `fetchMultipleExtraData()` fetch the configured sub-resources and merge them per config (dynamic or static endpoint).

#### Scenario: array source is read without an HTTP call

- **GIVEN** a synchronization with `sourceType: array`
- **WHEN** fetching runs
- **THEN** objects are read directly from the static array source without an HTTP call.

**Notes:**

- `getAllObjectsFromSource()` has empty `register/schema` and `database`
  branches marked `@todo: implement` — these silently return an empty array.
- `getAllObjectsFromApi()` carries a `TODO` noting the endpoint-templating
  function is called twice in the flow, pending refactor.
- The 50-page cap (`DEFAULT_MAX_PAGES`) is a hard safety limit against runaway
  pagination loops; it is not configurable per source.
- Methods: `getObjectFromSource()`, `getAllObjectsFromSource()`,
  `getAllObjectsFromApi()`, `getAllObjectsFromArray()`, `fetchAllPages()`,
  `fetchAllPagesOptimized()`, `fetchAllPagesSequential()`, `fetchSinglePage()`,
  `fetchSinglePageData()`, `getNextPage()`, `getNextEndpoint()`,
  `getNextPageInfo()`, `getNextlinkFromCall()`, `checkRateLimit()`,
  `getRateLimitHeaders()`, `fetchExtraDataForObject()`, `fetchMultipleExtraData()`.

### REQ-003: Mapping, transformation and object identity

The system SHALL compute a stable identity for each source object: it SHALL
extract an origin id from a configurable `idPosition` (default `id`, dotted-path
lookup) and compute an order-independent hash by recursively sorting the
canonicalised object before hashing. The system SHALL apply the synchronization's
mapping to transform source shape into target shape, and SHALL rewrite
related-object origin ids to their corresponding target ids via reverse contract
lookups (recursively for nested/sub-objects). The system SHALL provide array- and
XML-shape utilities (XML→array, key sanitisation, array-type classification) used
during transformation.

@e2e exclude backend mapping and identity internals — covered by PHPUnit/Newman, not browser UI

#### Scenario: origin id extracted from configured position

- **GIVEN** a source object and a `sourceConfig.idPosition` (or the default `id`)
- **WHEN** `getOriginId()` runs
- **THEN** the value at that dotted path is returned, or an `Exception` is thrown when the position resolves to null.

#### Scenario: order-independent identity hashing

- **GIVEN** two source objects with the same content in different key orders
- **WHEN** `hashObject()` / `sortNestedArray()` compute their hashes
- **THEN** the hashes are equal (order-independent identity).

#### Scenario: related origin ids rewritten to target ids

- **GIVEN** a source object containing related-origin ids that already have target contracts
- **WHEN** `replaceRelatedOriginIds()` runs
- **THEN** each origin id is rewritten to its target id via `findTargetIdByOriginId()`, recursively for nested arrays.

#### Scenario: mapping transforms source into target shape

- **GIVEN** a synchronization with a configured mapping
- **WHEN** `processMapping()` / `processMappingRule()` run
- **THEN** the source object is transformed into the target shape per the mapping definition.

#### Scenario: XML payload converted to a sanitised array

- **GIVEN** an XML source payload
- **WHEN** `xmlToArray()` converts it
- **THEN** a nested associative array is produced, and `encodeArrayKeys()` sanitises keys for downstream storage.

**Notes:**

- Identity hashing is the ADR-005 change-detection primitive; the order-stable
  sort exists specifically so semantically-equal objects do not produce spurious
  diffs.
- `replaceIdInString()` performs per-string id substitution and is the
  lowest-level rewrite primitive used by the sub-object cascade.
- Methods: `getOriginId()`, `mapHashObject()`, `hashObject()`,
  `sortNestedArray()`, `replaceRelatedOriginIds()`, `replaceIdInString()`,
  `findTargetIdByOriginId()`, `updateIdsOnSubObjects()`, `updateIdOnSubObject()`,
  `processMapping()`, `processMappingRule()`, `getArrayType()`,
  `isAssociativeArray()`, `xmlToArray()`, `encodeArrayKeys()`,
  `generatePlaceholderValues()`.

### REQ-004: Target write, deduplication and file handling

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

### REQ-005: Sync rule pipeline and management/integration surface

The system SHALL run a configurable, ordered rule pipeline at defined timings
during a sync (mirroring `EndpointService::processRules`): rules are loaded,
sorted by `order`, condition-checked, and dispatched by type
(`error`, `mapping`, `synchronization`, `save_object`, `fetch_file`,
`write_file`, `extend_input`); an `error` rule short-circuits the pipeline with a
500 `JSONResponse`, and an unsupported rule type throws. The system SHALL expose a
REST surface to manage and observe synchronizations (page/contracts/logs/test/
run/statistics/logsExport/deleteLog) and contracts (activate/deactivate/execute/
statistics/performance/export), and SHALL register an ADR-019
`IIntegrationProvider` (`SynchronizationContractProvider`) that lists sync
contracts for an object and reports id/label/icon/group/required-app/storage-
strategy/health/enablement.

@e2e exclude backend sync rule pipeline and integration provider — covered by PHPUnit/Newman, not browser UI

#### Scenario: ordered rule pipeline transforms running data

- **GIVEN** a synchronization with configured `actions` (rules)
- **WHEN** `processRules()` runs for a given timing
- **THEN** rules whose timing matches and whose conditions pass are applied in `order`, each transforming the running data.

#### Scenario: error rule short-circuits the pipeline

- **GIVEN** a rule of type `error` whose conditions pass
- **WHEN** the pipeline reaches it
- **THEN** `processErrorRule()` returns a `JSONResponse` and the pipeline returns that error immediately.

#### Scenario: run endpoint executes a synchronization by id

- **GIVEN** an authenticated user calls `POST .../synchronizations/{id}/run`
- **WHEN** the controller resolves the synchronization by id
- **THEN** `synchronize()` runs and the log/contract result is returned; a missing id returns 404 and a sync error returns 400 (with rate-limit headers when present).

#### Scenario: ADR-019 provider lists contracts with metadata

- **GIVEN** a request to the ADR-019 provider's `list()` for an object id
- **WHEN** it runs
- **THEN** the synchronization contracts for that object are returned, alongside provider metadata from `getId/getLabel/getIcon/getGroup/getRequiredApp/getStorageStrategy/health/isEnabled`.

#### Scenario: unsupported rule type fails as a 500

- **GIVEN** a rule type not in the supported set
- **WHEN** `processRules()` dispatches it
- **THEN** an `Exception` is thrown, caught, logged, and returned as a 500 `JSONResponse` (`Rule processing failed`).

**Notes:**

- **SECURITY (IDOR / OWASP A01:2021):** every method on
  `SynchronizationsController` and `SynchronizationContractsController` carries
  `@NoAdminRequired` + `@NoCSRFRequired` and resolves its target by an arbitrary
  caller-supplied id with no per-object ownership/admin guard in the body
  (verified on `run()`: it calls `orObjectService->find(id: $id, ...)` and
  immediately executes `synchronize()`). Any authenticated Nextcloud user can
  trigger arbitrary sync runs, dry-runs, contract execution/activation, log
  export, and log deletion against any synchronization id. This is observed
  behavior, flagged here per the openconnector security-finding history (IDOR /
  silent-swallow / XXE class); it is NOT fixed in this retrofit. Recommend a
  follow-up authorization change (ADR-005 / ADR-023 action-level authz).
- `processRules()` catches every `Exception` and returns a 500 `JSONResponse`
  describing the failure; the message includes `$e->getMessage()`, which may leak
  internal detail to the caller — flag for review.
- Statistics helpers (`statistics()`, `logsStatistics()`, `performance()`,
  `calculateMedian()`, `getSlowestStage()`, `calculateEfficiencyRatio()`)
  aggregate timing/log data and are read-only.
- Methods: `processRules()`, `getRuleById()`, `checkRuleConditions()`,
  `processSaveObjectRule()`, `processExtendInputRule()`, `processSyncRule()`,
  `processFetchFileRule()`, `processWriteFileRule()`, `processErrorRule()`,
  `page()`, `contracts()`, `logs()`, `test()`, `run()`, `statistics()`,
  `logsStatistics()`, `logsExport()`, `deleteLog()`, `activate()`,
  `deactivate()`, `execute()`, `performance()`, `export()`,
  `calculateMedian()`, `getSlowestStage()`, `calculateEfficiencyRatio()`,
  `list()`, `getId()`, `getLabel()`, `getIcon()`, `getGroup()`,
  `getRequiredApp()`, `getStorageStrategy()`, `health()`, `isEnabled()`.

### Requirement: `nextcloud-table` source/target dispatch (REQ-014)

`SynchronizationService::getAllObjectsFromSource()` MUST dispatch
`sourceType: nextcloud-table` to the Tables source adapter (see
`tables-bridge` REQ-002) instead of falling through with no matching `case`.
`SynchronizationService::updateTarget()` MUST dispatch `targetType:
nextcloud-table` to the Tables target adapter (see `tables-bridge` REQ-001)
instead of throwing `Unsupported target type`. `SynchronizationService::
deleteInvalidObjects()` MUST dispatch `targetType: nextcloud-table` through
the same guarded deletion path described in `tables-bridge` REQ-005 — this
requirement does not itself define the deletion-safety guard (that is
`sync-safety-guardrails`'s concern); it only establishes that
`nextcloud-table` is a recognised branch of that shared dispatch, not a
type that silently no-ops or bypasses the guard.

#### Scenario: source fetch dispatches to the Tables adapter

- **GIVEN** a synchronization with `sourceType: nextcloud-table`
- **WHEN** `getAllObjectsFromSource()` runs
- **THEN** the Tables source adapter is invoked and its returned rows are
  used as the fetched objects, exactly as the `api` branch returns
  `getAllObjectsFromApi()`'s result

#### Scenario: target write dispatches to the Tables adapter instead of throwing

- **GIVEN** a synchronization with `targetType: nextcloud-table`
- **WHEN** `updateTarget()` runs
- **THEN** the Tables target adapter is invoked
- **AND** no `Unsupported target type` exception is thrown (unlike an
  unrecognised type, which still throws per the base spec's REQ-001
  `default` branch)

#### Scenario: an unrecognised type (not nextcloud-table) still throws

- **GIVEN** a synchronization with `targetType: some-future-type` that is
  neither `register/schema`, `api`, `database`, nor `nextcloud-table`
- **WHEN** `updateTarget()` runs
- **THEN** it still throws `Unsupported target type: some-future-type`,
  unchanged from the base spec's existing `default` branch behavior
### Requirement: Per-item isolation and dead-letter capture during extern-to-intern sync (REQ-008)

The system MUST wrap each per-object call to `processSynchronizationObject()`
inside `SynchronizationService::synchronizeExternToIntern()`'s object loop
(the `foreach ($objectList as $object)`) in a `try/catch (\Throwable)`. On a
caught exception, the system MUST persist a `sync_item_dead_letter` object
capturing: the synchronization's uuid, the best-effort `originId` (when
resolvable before the failure), the raw source `$object` as `payload`, the
exception message as `error`, `phase: 'item-processing'`, and
`status: 'failed'`; increment `result['objects']['invalid']`; and continue
processing the remaining objects in `$objectList`. A single item's failure
MUST NOT abort processing of the remaining objects in the same sync pass,
and MUST NOT prevent `synchronize()` from completing and persisting its
`synchronization_log` with a summary reflecting the partial success
(previously: an uncaught exception from `processSynchronizationObject()`
propagated through the un-guarded loop and aborted the entire pass for
every remaining object — verified absent in HEAD prior to this change).

Dead-lettered items are captured for **manual** replay only — the system
MUST NOT schedule an automatic retry sweep for `sync_item_dead_letter`
entries (unlike event delivery's `EventRetryJob`), since item transformation
and write failures are typically deterministic (mapping/config/data errors)
rather than transient.

#### Scenario: one bad item does not abort the sync pass

- **GIVEN** a synchronization fetching 10 objects from its source, where
  object #4's mapping throws an exception
- **WHEN** `synchronize()` runs
- **THEN** objects #1-3 and #5-10 SHALL be processed normally (contracts
  created/updated as applicable)
- **AND** object #4 SHALL be captured as a `sync_item_dead_letter` entry with
  `status = 'failed'`
- **AND** `result['objects']['invalid']` SHALL be incremented by 1
- **AND** the `synchronization_log` SHALL be persisted reflecting 9
  successfully-processed objects and 1 invalid

#### Scenario: dead-lettered items are not automatically retried

- **GIVEN** a `sync_item_dead_letter` entry with `status = 'failed'`
- **WHEN** the next scheduled run of the same synchronization occurs
- **THEN** no automatic re-attempt of the dead-lettered item SHALL occur
  outside of an explicit operator replay action (REQ-DLR-007/008 in
  `dead-letter-replay`)

#### Notes

- `phase` is fixed to the literal `'item-processing'` in this change —
  `processSynchronizationObject()` has no internal phase boundaries exposed
  to a caller today, and distinguishing `fetch`/`mapping`/`write` precisely
  would require refactoring internals of `SynchronizationService`
  (~6,700 lines). The field is a free-form string (not a locked enum) so a
  follow-up change can populate it more precisely without a schema
  migration. Observed limitation; flagged in design.md Open Questions.
- Fetch-stage failures (`TooManyRequestsHttpException` from
  `getAllObjectsFromSource()`) are already isolated one level higher (caught
  in `synchronizeExternToIntern()` before the object loop, per the existing
  REQ-002 rate-limit scenario) and are NOT captured as
  `sync_item_dead_letter` entries — they short-circuit the whole pass with
  `rateLimitException`, which is a distinct pre-existing behavior this
  change does not alter.
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

