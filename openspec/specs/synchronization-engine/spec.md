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
out. The system SHALL fetch, persist, and clean up files referenced by sync
objects: download a file via `CallService`, validate the target object id is a
UUID, persist to storage, optionally run async batch fetching (ReactPHP), and
remove orphaned files/attachments no longer referenced after a sync.

@e2e exclude backend target-write internals — covered by PHPUnit/Newman, not browser UI

#### Scenario: OR target write records a contract

- **GIVEN** a transformed object whose target is an OR register/schema
- **WHEN** `updateTarget()` runs
- **THEN** it delegates to `updateTargetOpenRegister()` and a `SynchronizationContract` records the resulting origin/target ids and hashes.

#### Scenario: absent source objects are garbage-collected

- **GIVEN** a source no longer returns objects that previously had contracts
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
- Methods: `updateTarget()`, `updateTargetOpenRegister()`,
  `writeObjectToTarget()`, `deleteInvalidObjects()`, `processSyncContract()`,
  `updateContractsForSubObjects()`, `processSynchronizationObject()`,
  `writeFile()`, `fetchFile()`, `fetchFileSafely()`, `startAsyncFileFetching()`,
  `executeAsyncFileFetching()`, `processMultipleFilesWithCleanup()`,
  `cleanupOrphanedFiles()`, `cleanupFilesFromAttachments()`,
  `shouldPublishFile()`, `getFileContext()`, `getFilenameFromHeaders()`,
  `synchronizeToTarget()`.

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
