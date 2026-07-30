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
### Requirement: Synchronization Management UI (REQ-UI-001)

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

### Requirement: Synchronization orchestration and direction routing (REQ-001)

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

### Requirement: Source object fetching and pagination (REQ-002)

The system SHALL fetch objects from a synchronization's source according to
`sourceType`. For `api` sources it SHALL resolve the `source` record, enforce the
source's rate-limit watermark before any call, apply Twig-templated endpoints,
and drive pagination via configurable strategies: an optimized parallel mode
(ReactPHP), a sequential fallback, and per-page single fetches, capped by a
safety limit of 50 pages. Next-page resolution SHALL support query-parameter
pagination, body-embedded next endpoints, and OData `$nextLink`. The system SHALL
fetch per-object extra/sub-resource data when configured. `array`,
`register/schema`, and `database` are recognised `sourceType` values for
which `getAllObjectsFromSource()`'s dispatch switch has no matching case;
each falls through with no objects fetched (no-op), identically to any other
unrecognised `sourceType`.

<!-- Previous behavior: this requirement claimed the system "SHALL support
     `array` (static) sources directly" and carried a scenario asserting
     array sources are read without an HTTP call. Neither was ever true —
     `getAllObjectsFromSource()`'s switch has never had a `case 'array':`
     (verified via `git log -S "case 'array':"` returning zero hits across
     all history), and `array` was never a selectable sourceType in the
     synchronization editor UI. This delta corrects the documentation to
     match observed code; it does not change `getAllObjectsFromSource()`'s
     behaviour. -->

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

#### Scenario: an unrecognised or not-yet-dispatched sourceType yields no fetched objects

- **GIVEN** a synchronization with `sourceType` set to `array`, `register/schema`, `database`, or any other value not matched by `getAllObjectsFromSource()`'s dispatch switch
- **WHEN** `getAllObjectsFromSource()` runs
- **THEN** it returns an empty array without making any HTTP call or throwing, and no items reach the per-item processing loop.

**Notes:**

- `getAllObjectsFromSource()` has empty `register/schema` and `database`
  branches marked `@todo: implement`, and no matching case at all for
  `array` — all three silently return an empty array.
- `getAllObjectsFromApi()` carries a `TODO` noting the endpoint-templating
  function is called twice in the flow, pending refactor.
- The 50-page cap (`DEFAULT_MAX_PAGES`) is a hard safety limit against runaway
  pagination loops; it is not configurable per source.
- `getAllObjectsFromArray()` exists and is exercised, but only as a helper
  called from within `getAllObjectsFromApi()`'s response-body parsing (to
  extract an item list via `sourceConfig.resultsPosition`), which is a
  distinct concern from `sourceType` dispatch and is unaffected by this
  delta.
- A synchronization whose source legitimately returns bare scalar items
  (e.g. a JSON array of strings/numbers under `sourceType: api`) is covered
  by the per-item scalar-coercion guard described in this change's
  proposal/design — that guard operates after this requirement's fetch
  stage has already returned the item list, at the per-item boundary shared
  by REQ-003/REQ-008.
- Methods: `getObjectFromSource()`, `getAllObjectsFromSource()`,
  `getAllObjectsFromApi()`, `getAllObjectsFromArray()`, `fetchAllPages()`,
  `fetchAllPagesOptimized()`, `fetchAllPagesSequential()`, `fetchSinglePage()`,
  `fetchSinglePageData()`, `getNextPage()`, `getNextEndpoint()`,
  `getNextPageInfo()`, `getNextlinkFromCall()`, `checkRateLimit()`,
  `getRateLimitHeaders()`, `fetchExtraDataForObject()`, `fetchMultipleExtraData()`.

### Requirement: Mapping, transformation and object identity (REQ-003)

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

### Requirement: Target write, deduplication and file handling (REQ-004)

The system SHALL write each transformed object to its target, branching to an
OpenRegister-specific write when the target is an OR register/schema, and SHALL
maintain one `SynchronizationContract` per object carrying origin/target ids and
hashes for incremental change detection. The system SHALL cascade contract
creation and id rewrites to sub-objects. It SHALL garbage-collect target objects
no longer present in the source (`deleteInvalidObjects()`) unless `force` opts
out, **and unless the run's fetch was incomplete, the run is a test
(`isTest: true`), the computed deletion ratio exceeds the configured
guard threshold without an explicit `forceDeletion` override (REQ-009,
REQ-010, REQ-011), or the Synchronization's `syncMode` is `incremental`
(REQ-018 — this last guard is unconditional and is never bypassed by
`forceDeletion`)**. The system SHALL fetch, persist, and clean up files
referenced by sync objects: download a file via `CallService`, validate the
target object id is a UUID, persist to storage, optionally run async batch
fetching (ReactPHP), and remove orphaned files/attachments no longer
referenced after a sync.

<!-- Previous behavior (as of sync-safety-guardrails): deleteInvalidObjects()
     was gated on fetch-completeness, test-mode, and the deletion-ratio
     guard, but had no awareness of an incremental sync mode — this change
     adds the syncMode === 'incremental' guard as an unconditional
     precondition, on top of (not instead of) the pre-existing guards. -->

@e2e exclude backend target-write internals — covered by PHPUnit/Newman, not browser UI

#### Scenario: OR target write records a contract

- **GIVEN** a transformed object whose target is an OR register/schema
- **WHEN** `updateTarget()` runs
- **THEN** it delegates to `updateTargetOpenRegister()` and a `SynchronizationContract` records the resulting origin/target ids and hashes.

#### Scenario: absent source objects are garbage-collected when the fetch was complete, within the deletion-ratio guard, and syncMode is full

- **GIVEN** a source no longer returns objects that previously had contracts, a complete fetch (REQ-009), a non-test run, a deletion ratio within the configured threshold (REQ-010), and `syncMode` absent or `full` (REQ-018)
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
  detection behaviour layered onto this requirement, and REQ-016/REQ-017/
  REQ-018/REQ-019 (cdc-incremental-sync) for the incremental-mode fetch
  filtering, watermark, and unconditional deletion-block layered on top of
  those.**
- Methods: `updateTarget()`, `updateTargetOpenRegister()`,
  `writeObjectToTarget()`, `deleteInvalidObjects()`, `processSyncContract()`,
  `updateContractsForSubObjects()`, `processSynchronizationObject()`,
  `writeFile()`, `fetchFile()`, `fetchFileSafely()`, `startAsyncFileFetching()`,
  `executeAsyncFileFetching()`, `processMultipleFilesWithCleanup()`,
  `cleanupOrphanedFiles()`, `cleanupFilesFromAttachments()`,
  `shouldPublishFile()`, `getFileContext()`, `getFilenameFromHeaders()`,
  `synchronizeToTarget()`, `detectDuplicateContracts()`.

### Requirement: Sync rule pipeline and management/integration surface (REQ-005)

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

### Requirement: The contract is persisted before the after-rules run (REQ-021)

The system SHALL persist the `SynchronizationContract` as soon as the target write
has established a `targetId`, BEFORE the `after`-timed rules (which fetch files)
run, and SHALL then update that same contract row — matched on its `uuid`, so no
second row is created — with the rule outcomes and log references once those rules
complete.

A contract records ONLY that source object X maps to target object A, so that a
re-run writes X's changes to A instead of creating a second A. It is NOT a record
that everything downstream of the target write succeeded.

This tightens REQ-013, which only requires duplicates to be *detected* after the
fact. Persisting the mapping first makes the duplicate class structurally
impossible rather than merely reportable.

#### Scenario: a failure in the after-rules does not lose the mapping

- GIVEN a synchronization whose `after` rules fetch a file for each object
- AND the file fetch throws for a given source object (a missing filename, an
  unresolvable object id, an upstream 404 or timeout, or a failed save)
- WHEN the run processes that object
- THEN the target object has been written
- AND its contract exists, carrying the `originId` -> `targetId` mapping
- AND the item is reported as `invalid` with the reason logged
- AND a subsequent run matches that contract and UPDATES the same target object
- AND no second target object is created for that `originId`

#### Scenario: the happy path still writes exactly one contract row

- GIVEN a synchronization whose `after` rules complete without error
- WHEN a run creates a new target object
- THEN the contract is written once before the rules and updated once after them
- AND exactly one contract row exists for that `(synchronizationId, originId)`

#### Scenario: an unchanged object still skips without writing a contract

- GIVEN a source object whose hash matches its existing contract
- WHEN a run processes it
- THEN the run returns `skip` before the target write
- AND no contract write occurs

### Requirement: Batch-level approval gate before target writes (REQ-015)

The orchestrator (REQ-001) MUST, when a Synchronization's
`sourceConfig.requiresApproval` is `true`, after source fetch and mapping
complete and before the `updateTarget()` write loop (REQ-004) begins, check for an
existing `approved` `approval_request` whose `synchronizationId` matches
this synchronization and whose approval has not yet been consumed by a
write. If none exists, the system MUST create exactly one `approval_request`
for the run (with `synchronizationId` set instead of `endpointId`/`ruleId`,
per `approval-workflow`'s schema), notify the configured `approverGroup`
(`approval-workflow` REQ-002), finalize the `synchronization_log` with a
`pending_approval` outcome, and return without writing any target object.
If an approved, unconsumed `approval_request` exists for this
synchronization, the write loop MUST proceed normally and the
`approval_request` MUST be marked consumed on completion.

@e2e exclude backend sync engine internals — covered by PHPUnit/Newman, not browser UI

#### Scenario: a gated synchronization pauses before any writes

- **GIVEN** a Synchronization with `sourceConfig.requiresApproval: true` and
  no existing `approval_request` for it
- **WHEN** `synchronize()` runs and source fetch + mapping complete
- **THEN** an `approval_request` is created with this synchronization's id,
  the approver group is notified, the `synchronization_log` records a
  `pending_approval` outcome, and no target objects are written or
  garbage-collected

#### Scenario: approval resumes the batch write via a bypass token, not a re-serialized payload

- **GIVEN** an `approval_request` for a gated synchronization is approved
- **WHEN** `ApprovalService::resume()` re-invokes `synchronize()` with
  `force: true` and the approved request's id
- **THEN** the gate check finds the approved, unconsumed `approval_request`,
  the `updateTarget()` write loop proceeds for every fetched/mapped object,
  and the `approval_request` is marked consumed on completion

#### Scenario: an ungated synchronization is unaffected

- **GIVEN** a Synchronization with `sourceConfig.requiresApproval` absent or
  `false`
- **WHEN** `synchronize()` runs
- **THEN** the write loop proceeds exactly as before this change, with no
  `approval_request` created

#### Notes

- The gate is evaluated once per synchronization run, not per object —
  garbage collection (`deleteInvalidObjects()`) and per-object contract
  writes are both part of the gated write phase and do not run until
  approval.
- Rejecting or letting a gated synchronization's `approval_request` expire
  applies the same `onReject`/`onTimeout` outcomes as the endpoint-rule
  case (`approval-workflow` REQ-004/REQ-005); a `skip` outcome for a
  synchronization means the run completes with zero writes rather than
  skipping a single rule.

### Requirement: Bulk gzip/JSONL source ingestion (REQ-006)

`SynchronizationService::fetchSinglePageData()` MUST detect a
gzip-compressed response body via, in order: an explicit
`Source.configuration.decompress === "gzip"` hint; the fetched endpoint
ending in `.gz` (checked against the endpoint path or any query-string
value, to cover `/download?name=full.jsonl.gz`-shaped registry endpoints);
or an `application/gzip` response Content-Type header (case-insensitive
substring match). When any signal is present, the method MUST first
base64-decode the body when the call-log response's `encoding` field is
`"base64"` (the marker `CallService::buildResponseData()` already records
for any response body that fails UTF-8 validation, which gzip-compressed
binary always does), then gunzip it via `gzdecode()` before any parse
attempt. When `Synchronization.sourceConfig.format === "jsonl"`, the method
MUST parse the (decompressed, or already-plain) body as line-delimited
JSON — each non-empty, non-whitespace line independently `json_decode`d,
malformed or non-array lines skipped rather than aborting the page — instead
of the whole-document JSON/XML parse attempts, and MUST feed the resulting
records array through the existing `getAllObjectsFromArray()` /
`resultsPosition` extraction unchanged. An endpoint identified as a
`.tar.gz`/`.tar` archive (by the same endpoint-suffix check) MUST short-circuit
with a logged warning and an empty result instead of attempting to parse the
undecoded tar byte stream. A source presenting none of these signals MUST
take the exact pre-existing JSON-then-XML-fallback code path, unchanged.

#### Scenario: gzip-compressed JSONL bulk file is decompressed and parsed line-by-line

- **GIVEN** a source whose fetched response has `encoding: "base64"` (a
  non-UTF8 body) and a `.gz`-suffixed endpoint (or an `application/gzip`
  Content-Type, or `configuration.decompress: "gzip"`)
- **AND** `sourceConfig.format` is `"jsonl"`
- **WHEN** `fetchSinglePageData()` runs
- **THEN** the body is base64-decoded, gunzipped, and each non-empty line
  parsed as one JSON record
- **AND** a blank line between two records does not drop the record that follows it

#### Scenario: JSONL parsing works without gzip

- **GIVEN** a source with `sourceConfig.format: "jsonl"` and a plain
  (UTF-8, uncompressed) line-delimited JSON body
- **WHEN** `fetchSinglePageData()` runs
- **THEN** each line is parsed as one record, identically to the gzip case
  minus the decompression step

#### Scenario: gzip decompression works independently of JSONL, for an ordinary JSON body

- **GIVEN** a source with no `format` override, a gzip-compressed response
  body, and a `resultsPosition` pointing at a nested array
- **WHEN** `fetchSinglePageData()` runs
- **THEN** the body is gunzipped and then parsed as ordinary whole-document
  JSON through the pre-existing `resultsPosition` extraction

#### Scenario: a `.tar.gz` endpoint is refused with a logged warning, not silently mis-parsed

- **GIVEN** a source whose endpoint identifies a `.tar.gz`/`.tar` archive
- **WHEN** `fetchSinglePageData()` runs
- **THEN** a warning is logged naming the endpoint
- **AND** the method returns an empty `objects`/`result` pair without
  attempting to gunzip-then-parse the tar byte stream

#### Scenario: a source with none of the new signals is unaffected

- **GIVEN** a source with an ordinary JSON (or XML) body, no `.gz`/`.tar`
  suffix, no `application/gzip` Content-Type, no `configuration.decompress`,
  and no `sourceConfig.format`
- **WHEN** `fetchSinglePageData()` runs
- **THEN** the result is byte-identical to the pre-existing
  json_decode-then-simplexml-fallback behaviour
- @e2e exclude backend regression — covered by PHPUnit

**Notes:**

- `.tar.gz`/`.csv.tar.gz` archives are explicitly NOT unpacked by this
  requirement — gzip decompression alone yields a tar byte stream, not
  parseable JSON. A genuine tar-extraction capability is deferred; an
  ETL-style loader remains the documented workaround for `.tar.gz`-only
  sources (e.g. the OCP-mirror path for `croatia_eojn`) until/unless a
  follow-up change adds one.
- The JSONL parser tokenises line-by-line (`strtok`) rather than
  `explode()`-ing a second full in-memory copy of the body, but the body
  itself is still one fully-buffered string by the time this code runs
  (Guzzle/`CallService` already materialise the whole HTTP response) — this
  is a partial memory mitigation, not true streaming. A lower-level change
  to how `CallService` hands off the response body would be needed for
  genuine streaming decompression/parsing; out of scope here.
- Methods added: `isGzipPayload()`, `isTarGzEndpoint()`,
  `endpointSuggestsSuffix()`, `parseJsonLines()` (all private, alongside the
  existing `fetchSinglePageData()`).

### Requirement: Markdown and HTML source extraction (REQ-007)

`SynchronizationService::fetchSinglePageData()` MUST detect
`Source.configuration.format === "markdown"` (case-insensitive) and, when
present, parse the response body as a markdown bullet list: each line
matching `- [Name](url) - description \`Tag1\` \`Tag2\`` (a leading
`-`/`*`/`+` list marker, a `[name](url)` link, and an optional trailing
free-text description followed by zero or more backtick-wrapped tags)
becomes one record with `name`, `url`, `description`, and a positional
`tags` array; a line that does not match this shape (a heading, blank line,
prose, or a link-less list item) MUST be skipped without aborting the page.

`fetchSinglePageData()` MUST also detect `Source.configuration.format ===
"html"` (case-insensitive) and, when present, extract records via CSS
selectors: `Source.configuration.htmlSelector` identifies the repeating
record container (each match becomes one record), and
`Source.configuration.htmlFields` (a `fieldName => selector` map) extracts
one value per field relative to that container — a selector suffixed with
`@attributeName` (e.g. `a@href`) MUST extract that DOM attribute instead of
the (default) trimmed text content. A `htmlSelector` matching nothing MUST
yield zero records; a `htmlFields` sub-selector matching nothing within a
container MUST yield a `null` value for that field, without aborting the
container or the page.

Both branches MUST feed their resulting records array through the existing
`getAllObjectsFromArray()` / `resultsPosition` extraction unchanged, and
both MUST be evaluated using `Source.configuration.format` (a per-source
property) — NOT `Synchronization.sourceConfig.format` (the existing,
distinct per-synchronization key REQ-006 uses for `"jsonl"`). A source
presenting neither `configuration.format` value MUST take the exact
pre-existing JSON-then-XML-fallback (and REQ-006 gzip/JSONL) code path,
unchanged.

#### Scenario: a markdown "awesome list" source is parsed into one record per list item

- **GIVEN** a source with `configuration.format: "markdown"`
- **AND** a response body containing markdown list items of the form
  `- [Name](url) - description \`Tag1\` \`Tag2\``, interspersed with
  headings, blank lines, and a link-less bullet
- **WHEN** `fetchSinglePageData()` runs
- **THEN** each matching list item becomes one record with the correct
  `name`, `url`, `description`, and `tags` (an array of 0 or more entries)
- **AND** the non-matching lines produce no records and do not raise an
  error

#### Scenario: an HTML source extracts records via CSS selectors, including attribute values

- **GIVEN** a source with `configuration.format: "html"`, a
  `configuration.htmlSelector` matching a repeating row/card element, and
  `configuration.htmlFields` mapping field names to selectors — at least one
  of which uses the `selector@attr` syntax
- **WHEN** `fetchSinglePageData()` runs
- **THEN** one record is returned per matched container
- **AND** each field is populated with either the sub-selected node's
  trimmed text content, or — for a `selector@attr` field — the named
  attribute's value

#### Scenario: a markdown list item pointing at an in-document anchor or relative URL is skipped

- **GIVEN** a source with `configuration.format: "markdown"`
- **AND** a response body containing a `- [Name](url)` list item whose `url`
  is an in-document anchor (e.g. `#software`) or otherwise carries no URI
  scheme (a relative link) — the shape of a table-of-contents entry or a
  "back to top" navigation link, as opposed to a data record
- **WHEN** `fetchSinglePageData()` runs
- **THEN** that list item produces no record
- **AND** a sibling list item whose `url` is an absolute URI (carries a
  scheme, e.g. `https://...`) still produces its record unaffected
- **AND** no error is raised

#### Scenario: a source with neither new `format` value is unaffected

- **GIVEN** a source with `configuration.format` absent, or set to
  anything other than `"markdown"`/`"html"`
- **WHEN** `fetchSinglePageData()` runs
- **THEN** the result is byte-identical to the pre-existing behaviour
  (REQ-002's JSON/XML parsing, and REQ-006's gzip/JSONL handling where
  applicable)
- @e2e exclude backend regression — covered by PHPUnit

**Notes:**

- `tags` is intentionally positional and unlabelled — this requirement does
  not assign semantic meaning to tag position (e.g. "position 0 is always a
  license"). A source's downstream mapping/rules configuration is
  responsible for naming positional tags, not the fetch-engine parser.
- The markdown line pattern is a single built-in regex tuned to the
  awesome-selfhosted README shape; it is not source-configurable in this
  requirement (no second markdown-shaped source was in scope to validate a
  configurable pattern against).
- Methods added: `parseMarkdownResponse()`, `parseHtmlResponse()`,
  `extractHtmlField()` (all private, alongside the existing
  `fetchSinglePageData()`). Uses `Symfony\Component\DomCrawler\Crawler`
  (`symfony/dom-crawler` + `symfony/css-selector`, pinned `^7.2` for PHP 8.3
  compatibility).

### Requirement: Incremental sync mode selects a cursor-filtered fetch request (REQ-016)

`SynchronizationService` SHALL support `syncMode: incremental` on a
Synchronization, in addition to the existing (default, unchanged) `full`
mode. When `syncMode` is `incremental` and `sourceType` is `api`,
`getAllObjectsFromApi()` MUST make the Synchronization's stored
`cursorWatermark` — or an empty string when no watermark has been set yet —
available as a `cursor` key in the Twig context already passed to
`MappingService::renderTemplateString()` when rendering `sourceConfig.endpoint`
(alongside the existing `data` key). `getAllObjectsFromApi()` MUST also
apply the identical `{{`/`}}`-presence-detection-then-`renderTemplateString()`
treatment already used for `sourceConfig.endpoint` to each scalar value in
`sourceConfig.query`, using the same `['data' => ..., 'cursor' => ...]`
context, so a source that takes its cursor as a query parameter rather than
an endpoint path segment can also reference `{{ cursor }}`. A Synchronization
with `syncMode` absent or `full` MUST take the exact pre-existing code path —
no `cursor` context key is added and `sourceConfig.query` values are passed
through unrendered, unchanged from current behavior.

#### Scenario: an incremental run injects the stored watermark into a templated endpoint

- GIVEN a Synchronization with `syncMode: incremental`, `sourceConfig.endpoint:
  ".../items?updatedAfter={{ cursor }}"`, and a stored `cursorWatermark` of
  `"2026-07-01T00:00:00Z"`
- WHEN `getAllObjectsFromApi()` runs
- THEN the rendered request endpoint is
  `.../items?updatedAfter=2026-07-01T00:00:00Z`

#### Scenario: an incremental run injects the stored watermark into a templated query parameter

- GIVEN a Synchronization with `syncMode: incremental`,
  `sourceConfig.query.updatedAfter: "{{ cursor }}"`, and a stored
  `cursorWatermark` of `"42"`
- WHEN `getAllObjectsFromApi()` runs
- THEN the outbound request's `updatedAfter` query parameter is rendered to
  `"42"` before the call is made

#### Scenario: an incremental run with no prior watermark passes an empty cursor

- GIVEN a Synchronization with `syncMode: incremental` and no
  `cursorWatermark` set (its first-ever incremental run)
- WHEN `getAllObjectsFromApi()` runs
- THEN `{{ cursor }}` renders to an empty string
- AND a source whose default/fallback (e.g. `{{ cursor|default('1970-01-01')
  }}`, a plain Twig filter requiring no engine change) treats an empty
  cursor as "everything" receives an effectively full fetch on this first
  incremental run

#### Scenario: a full-mode run is unaffected

- GIVEN a Synchronization with `syncMode` absent or `full`
- WHEN `getAllObjectsFromApi()` runs
- THEN the Twig context passed to `sourceConfig.endpoint` templating
  contains only `data` (no `cursor` key), and `sourceConfig.query` values
  are used exactly as configured, byte-identical to pre-existing behavior

**Notes:**

- This requirement extends the fetch-request-shaping mechanics of REQ-002
  (source object fetching and pagination) for the `api` branch only; it does
  not change REQ-002's pagination, rate-limiting, or next-page resolution
  behavior.
- `sourceConfig.cursorField` (a dotted-path lookup mirroring REQ-003's
  `idPosition`/`getOriginId()` convention) identifies which field of a
  fetched record is the comparable cursor value; it is read by REQ-017's
  watermark computation, not by this requirement.
- Methods: `getAllObjectsFromApi()` (extended), `MappingService::
  renderTemplateString()` (reused, unchanged).

### Requirement: Cursor watermark advances only after a complete, successful fetch (REQ-017)

`synchronizeExternToIntern()` MUST, for a Synchronization with `syncMode:
incremental`, compute a new high-watermark value from the fetched records'
configured `sourceConfig.cursorField` and persist it as the Synchronization's
`cursorWatermark` **only when** that run's fetch was marked complete per
REQ-009 (`fetchInfo.complete === true`) **and** no `TooManyRequestsHttpException`
was thrown during the fetch — the same `$fetchComplete` computation REQ-010
already performs at the same point in the method. When the fetch was
incomplete for any reason (partial pagination, a failed page, a rate-limit
response, or the pagination safety cap), the system MUST NOT persist any
change to `cursorWatermark`, so the next run retries from the same
watermark rather than silently skipping the unfetched remainder. A run
invoked with `isTest: true` MUST NOT persist a watermark change regardless
of fetch completeness, consistent with REQ-011 (test runs make no writes).
A record whose configured `cursorField` resolves to `null` MUST cause the
run to throw, mirroring REQ-003's `getOriginId()` behavior for a missing
`idPosition` — silently computing a watermark from an incomplete field
would risk producing an incorrect (too-low) high-watermark that
permanently skips sibling records on every subsequent run.

#### Scenario: watermark advances after a complete fetch

- GIVEN a Synchronization with `syncMode: incremental`,
  `sourceConfig.cursorField: "updatedAt"`, and a fetch that completes
  successfully, returning records with `updatedAt` values up to
  `"2026-07-15T09:00:00Z"`
- WHEN `synchronizeExternToIntern()` finishes the run
- THEN the Synchronization's `cursorWatermark` is persisted as
  `"2026-07-15T09:00:00Z"`

#### Scenario: watermark does not advance after a page failure mid-fetch

- GIVEN a Synchronization with `syncMode: incremental` and an existing
  `cursorWatermark` of `"2026-07-01T00:00:00Z"`
- WHEN a run's fetch is marked incomplete (REQ-009) because page 2 of 3
  returned HTTP 500
- THEN the Synchronization's `cursorWatermark` remains
  `"2026-07-01T00:00:00Z"` after the run, unchanged
- AND the next run requests records with `cursor` still resolving to
  `"2026-07-01T00:00:00Z"`

#### Scenario: watermark does not advance after a 429 rate-limit

- GIVEN a Synchronization with `syncMode: incremental`
- WHEN the source returns HTTP 429 on the first page of a run
- THEN the run's fetch is treated as incomplete (REQ-009)
- AND the Synchronization's `cursorWatermark` is not modified
- AND the caller still receives the `TooManyRequestsHttpException` as before
  (REQ-010's existing behavior for the deletion side is unchanged; this
  requirement adds the equivalent guarantee for the watermark side)

#### Scenario: watermark does not advance for a test run even when the fetch is complete

- GIVEN a Synchronization with `syncMode: incremental`
- WHEN `POST .../synchronizations/{id}/test` runs and its fetch completes
  successfully
- THEN the Synchronization's persisted `cursorWatermark` is unchanged
  (REQ-011: test runs persist no Synchronization state)

#### Scenario: a record missing the configured cursorField throws rather than silently computing a wrong watermark

- GIVEN a Synchronization with `syncMode: incremental` and
  `sourceConfig.cursorField: "updatedAt"`
- WHEN a fetched record has no value at the `updatedAt` path
- THEN the run throws an `Exception` naming the missing cursor field
- AND no `cursorWatermark` change is persisted for that run

**Notes:**

- This requirement composes directly with REQ-009/REQ-010's existing
  `$fetchComplete` computation in `synchronizeExternToIntern()` — it does
  not introduce a second completeness signal.
- Watermark computation takes the maximum `cursorField` value across all
  fetched records in the run (not the last record processed), so
  out-of-order pagination or concurrent per-page fetching (REQ-002's
  optimized parallel mode) cannot regress the watermark.
- Methods added: `computeCursorWatermark()` (private, alongside the
  existing `getOriginId()`/`hashObject()` identity helpers).

### Requirement: Deletion garbage-collection never runs for an incremental sync (REQ-018)

`synchronizeExternToIntern()` MUST NOT invoke `deleteInvalidObjects()` for
any run whose Synchronization has `syncMode: incremental` — unconditionally,
regardless of that run's fetch-completeness (REQ-009), the computed
deletion ratio (REQ-010), or an explicit `forceDeletion` override. An
incremental fetch is, by construction, a filtered subset of the source; the
absence of a target id from `$synchronizedTargetIds` on an incremental run
is not evidence that the corresponding source record was deleted — it may
simply be outside the cursor filter. `deleteInvalidObjects()` MUST also
independently refuse to run when passed a Synchronization whose `syncMode`
is `incremental`, regardless of caller, so a future caller that invokes it
directly (bypassing `synchronizeExternToIntern()`'s gate) cannot
accidentally delete against a partial incremental fetch. On this refusal,
`deleteInvalidObjects()` MUST log a warning-level message and dispatch a
`SynchronizationDeletionGuardedEvent` with `reason: incremental_mode`,
mirroring its existing `fetch_incomplete`-reason guard (REQ-010), and MUST
return `0`.

#### Scenario: incremental mode blocks deletion even on a complete fetch

- GIVEN a Synchronization with `syncMode: incremental`, 100 existing
  contracts, and a run whose fetch completes successfully but — because it
  is cursor-filtered — returns only 5 changed records
- WHEN `synchronizeExternToIntern()` reaches its cleanup stage
- THEN `deleteInvalidObjects()` is not invoked
- AND 0 objects are deleted
- AND the run's `result.objects.deletionGuard` records
  `reason: incremental_mode`

#### Scenario: forceDeletion cannot override the incremental-mode block

- GIVEN the same Synchronization as above
- WHEN the run is invoked with `forceDeletion: true`
- THEN deletion is still not invoked — `forceDeletion` only overrides
  REQ-010's ratio guard on a `full`-mode run and has no effect on this
  unconditional incremental-mode block

#### Scenario: deleteInvalidObjects() called directly against an incremental Synchronization still refuses

- GIVEN a Synchronization with `syncMode: incremental`
- WHEN `deleteInvalidObjects()` is invoked directly (not via
  `synchronizeExternToIntern()`) with `fetchComplete: true` and
  `forceDeletion: true`
- THEN it still returns `0` and deletes nothing
- AND a warning is logged and a `SynchronizationDeletionGuardedEvent` with
  `reason: incremental_mode` is dispatched

#### Scenario: the deleteRestriction single-object delete path is unaffected

- GIVEN an OpenRegister `ObjectDeletedEvent` triggers a synchronization run
  with `mutationType: delete` and `sourceConfig.restrictDeletion: true`
  against a Synchronization with `syncMode: incremental`
- WHEN `synchronizeExternToIntern()` runs
- THEN the single-object delete path (`$data !== null && $mutationType ===
  'delete'`) is taken — this path never calls `deleteInvalidObjects()`'s
  bulk source-diff branch regardless of `syncMode`, so this requirement
  introduces no new behavior here; it is called out only to confirm the
  event-driven single-object delete is not accidentally caught by this
  guard

**Notes:**

- This requirement composes with, and takes priority over, REQ-010's
  ratio/`forceDeletion` guard: the `syncMode` check happens first and
  unconditionally, before REQ-010's `fetchComplete`/ratio logic is ever
  reached, for an incremental Synchronization.
- Restoring deletion-based garbage collection for a Synchronization
  currently in `incremental` mode requires explicitly switching its
  `syncMode` back to `full` — REQ-019's reset-cursor action does not do
  this (see REQ-019).
- Methods: `deleteInvalidObjects()` (extended with the new guard clause,
  ahead of its existing `fetchComplete === false` early return),
  `synchronizeExternToIntern()` (extended call-site check).

### Requirement: Reset-cursor action clears the stored watermark (REQ-019)

`SynchronizationsController` MUST expose `POST
/api/synchronizations/{id}/reset-cursor`, which clears the target
Synchronization's `cursorWatermark` (to `null`/absent) and persists that
change, without altering `syncMode` or any other Synchronization field.
This action MUST NOT itself delete, create, or update any target object or
`SynchronizationContract` — it only clears stored cursor state. Following a
reset, the Synchronization's next run resolves `{{ cursor }}` to an empty
string (REQ-016's "no prior watermark" case), which — for a source whose
templated request treats an absent cursor as unfiltered — yields a
full-equivalent fetch that re-evaluates every currently-reachable source
record for create/update via the existing hash-diff contract mechanism
(REQ-003). This action MUST NOT re-enable `deleteInvalidObjects()` for that
Synchronization: REQ-018's guard is keyed on `syncMode`, not on cursor
state, and a reset-cursor call does not change `syncMode`.

#### Scenario: reset-cursor clears the watermark

- GIVEN a Synchronization with `syncMode: incremental` and
  `cursorWatermark: "2026-07-10T00:00:00Z"`
- WHEN `POST /api/synchronizations/{id}/reset-cursor` is called
- THEN the Synchronization's `cursorWatermark` is persisted as
  `null`/absent
- AND `syncMode` remains `incremental`, unchanged

#### Scenario: the next run after a reset requests an unfiltered fetch

- GIVEN a Synchronization whose `cursorWatermark` was just cleared via
  reset-cursor, with `sourceConfig.endpoint: ".../items?updatedAfter={{
  cursor }}"`
- WHEN the next `synchronize()` run's `getAllObjectsFromApi()` executes
- THEN the rendered endpoint is `.../items?updatedAfter=` (empty cursor
  value)

#### Scenario: reset-cursor does not perform or re-enable deletion

- GIVEN a Synchronization with `syncMode: incremental` and 100 existing
  contracts
- WHEN `POST /api/synchronizations/{id}/reset-cursor` is called, and then
  the Synchronization's next run executes and — because the source
  honored the empty cursor — refetches all currently-existing source
  records
- THEN `reset-cursor` itself deletes nothing
- AND the subsequent run also does not invoke `deleteInvalidObjects()`
  (REQ-018 still applies — `syncMode` is still `incremental`)
- AND restoring deletion detection requires a separate, explicit change of
  `syncMode` to `full`

#### Scenario: a missing synchronization id returns 404

- GIVEN no Synchronization exists with the given `id`
- WHEN `POST /api/synchronizations/{id}/reset-cursor` is called
- THEN the response is `404`, mirroring the existing `run()`/`test()`
  action's not-found handling

**Notes:**

- This action follows the existing `activate`/`deactivate`/`run`/`test`
  action-route convention on `SynchronizationsController`
  (`/api/synchronizations/{id}/<action>`, `POST`).
- **SECURITY:** per REQ-005's existing, pre-existing IDOR note on this
  controller, `reset-cursor` inherits the same `@NoAdminRequired` +
  `@NoCSRFRequired` + no-per-object-ownership-guard posture as every other
  action on `SynchronizationsController` today. This is observed,
  pre-existing behavior this change does not alter or worsen (clearing a
  watermark is a low-severity action relative to `run`/`test`/`execute`
  already available on the same unguarded surface) — flagged for the same
  future authorization follow-up already noted under REQ-005, not
  addressed here.
- Methods added: `SynchronizationsController::resetCursor()`.

### Requirement: `nextcloud-form` source dispatch (REQ-020)

`SynchronizationService::getAllObjectsFromSource()` MUST dispatch
`sourceType: nextcloud-form` to the Forms source adapter (see
`nextcloud-forms-connector` REQ-002) instead of falling through with no
matching `case`. `SynchronizationService::updateTarget()` MUST NOT gain a
`nextcloud-form` branch — a synchronization configured with
`targetType: nextcloud-form` MUST continue to throw `Unsupported target
type: nextcloud-form`, unchanged from the base spec's existing `default`
branch behaviour (`nextcloud-forms-connector` REQ-002 explicitly excludes a
target/write direction).

#### Scenario: source fetch dispatches to the Forms adapter

- **GIVEN** a synchronization with `sourceType: nextcloud-form`
- **WHEN** `getAllObjectsFromSource()` runs
- **THEN** the Forms source adapter is invoked and its returned submissions
  are used as the fetched objects, exactly as the `api` branch returns
  `getAllObjectsFromApi()`'s result

#### Scenario: nextcloud-form is not a recognised target type

- **GIVEN** a synchronization with `targetType: nextcloud-form`
- **WHEN** `updateTarget()` runs
- **THEN** it throws `Unsupported target type: nextcloud-form`, identical
  in shape to any other unrecognised target type (unlike
  `nextcloud-table`, which REQ-014 explicitly carves out as a recognised
  target)

#### Scenario: an unrecognised source type remains a silent no-op, unchanged

- **GIVEN** a synchronization with `sourceType: some-future-type` that is
  neither `register/schema`, `api`, `database`, `nextcloud-table`, nor
  `nextcloud-form`
- **WHEN** `getAllObjectsFromSource()` runs
- **THEN** the `switch` matches no `case` and an empty array is returned,
  identical to the base spec REQ-002's documented no-op behaviour for
  `register/schema`/`database` — this requirement does not change that
  fallthrough behaviour for any type outside `nextcloud-form`

