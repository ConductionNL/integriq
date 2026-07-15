# Tasks: sync-safety-guardrails

## Implementation Tasks

### Task 1: Detect non-2xx/failed page responses in `fetchSinglePageData()`
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009`
- **files**: `lib/Service/SynchronizationService.php` (`fetchSinglePageData()`, ~line 3360; add `DEFAULT_DELETION_RATIO_THRESHOLD` class constant near the existing `DEFAULT_MAX_PAGES` constant)
- **acceptance_criteria**:
  - GIVEN `callLogResponse()` returns `null` with no 429 status WHEN `fetchSinglePageData()` runs THEN it returns `['objects' => [], 'result' => [], 'failed' => true, 'statusCode' => null]`
  - GIVEN a response with `statusCode >= 400` WHEN `fetchSinglePageData()` parses it THEN it returns `failed => true, statusCode => <code>` before attempting body parsing
  - GIVEN a 2xx response whose body parses to zero records WHEN `fetchSinglePageData()` runs THEN `failed` is `false` (unchanged natural-end behaviour)
  - This method is `private` with only `fetchSinglePage()`/`fetchAllPagesOptimized()` as callers (verified via grep) — no public signature changes in this task
- [x] Implement
- [x] Test

### Task 2: Thread completeness through `fetchAllPagesOptimized()` / `fetchAllPages()` / `fetchSinglePage()`
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009`
- **files**: `lib/Service/SynchronizationService.php` (`fetchAllPagesOptimized()` ~3173, `fetchAllPages()` ~3124, `fetchSinglePage()` ~3319 — all `private`)
- **acceptance_criteria**:
  - GIVEN a page's `failed` flag is true WHEN `fetchAllPagesOptimized()`'s loop reaches it THEN the loop breaks immediately (before the existing `empty($pageObjects)` check) and the method's new return shape (`array{objects: array, complete: bool, failureReason: ?string}`) reports `complete: false`
  - GIVEN pagination reaches `DEFAULT_MAX_PAGES` (50) while a next page was still available WHEN the loop exits THEN `complete: false, failureReason: 'max_pages_reached'`
  - GIVEN pagination ends because no next page info was found (existing `$nextInfo === null` break) WHEN the loop exits THEN `complete: true`
  - `fetchAllPages()` and `fetchSinglePage()` propagate this shape from `fetchSinglePageData()`/`fetchAllPagesOptimized()` instead of returning a bare flat array internally
  - Test-mode's existing early-return (`isTest === true && !empty($pageObjects)`, line ~3200) is unaffected — it still returns after the first object, marked `complete: true` (a test run intentionally only samples one object; this is not a failure)
- [x] Implement
- [x] Test

### Task 3: Surface `$fetchInfo` from `getAllObjectsFromApi()` / `getAllObjectsFromSource()` without changing their return type
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009`
- **files**: `lib/Service/SynchronizationService.php` (`getAllObjectsFromApi()` ~2984, `getAllObjectsFromSource()` ~2949 — both `public`)
- **acceptance_criteria**:
  - GIVEN `getAllObjectsFromApi(array $synchronization, ?bool $isTest=false, ?array $data=null, ?array &$fetchInfo=null): array` is called with a `$fetchInfo` variable WHEN it returns THEN `$fetchInfo` is `['complete' => bool, 'pagesFetched' => int, 'failureReason' => ?string]` and the method's own return value is still the flat object array (unchanged)
  - GIVEN the same method is called without a 4th argument (all 13 existing call sites in `tests/Unit/Service/SynchronizationServiceTest.php`) WHEN it runs THEN behaviour and return value are byte-for-byte identical to before this change — run `vendor/bin/phpunit tests/Unit/Service/SynchronizationServiceTest.php` and confirm all existing assertions still pass unmodified
  - `getAllObjectsFromSource()` gains the same `&$fetchInfo` parameter and passes it through to `getAllObjectsFromApi()` for the `api` branch; the `register/schema`/`database` (no-op) and this method's own dispatch logic set `$fetchInfo = ['complete' => true, 'pagesFetched' => 0, 'failureReason' => null]` for the other branches
- [x] Implement
- [x] Test

### Task 4: New `SynchronizationDeletionGuardedEvent` and lazy `IEventDispatcher` resolution
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010`
- **files**: `lib/Event/SynchronizationDeletionGuardedEvent.php` (new), `lib/Service/SynchronizationService.php` (constructor body, ~line 182, alongside the existing `SynchronizationContractLogService`/`SynchronizationContractService` lazy-resolution block)
- **acceptance_criteria**:
  - `SynchronizationDeletionGuardedEvent extends \OCP\EventDispatcher\Event`, constructor takes `synchronizationId, reason, ratio, threshold, candidateCount, totalContracts` (nullable where not applicable, e.g. `ratio`/`threshold` are `null` for the `fetch_incomplete` reason), with getters
  - `SynchronizationService`'s constructor resolves `IEventDispatcher` via `$this->containerInterface->get(IEventDispatcher::class)` and stores it only `if ($resolved instanceof IEventDispatcher)`, exactly mirroring the existing pattern for `SynchronizationContractLogService` — the public constructor's parameter list (8 args) is NOT changed
  - GIVEN a bare `ContainerInterface` mock that returns `null`/throws for unknown services (as used in every existing test fixture) WHEN the service is constructed THEN construction does not throw and the dispatcher is simply unavailable (dispatch calls are guarded with `if ($this->eventDispatcher !== null)`)
- [x] Implement
- [x] Test

### Task 5: Deletion-ratio guard, `fetchComplete`/`forceDeletion`/`&$guardInfo` params on `deleteInvalidObjects()`
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010`
- **files**: `lib/Service/SynchronizationService.php` (`deleteInvalidObjects()` ~2030)
- **acceptance_criteria**:
  - New signature: `deleteInvalidObjects(array|ObjectEntity $synchronization, ?array $synchronizedTargetIds=[], bool $deleteRestriction=false, array $data=[], bool $fetchComplete=true, bool $forceDeletion=false, ?array &$guardInfo=null): int` — return type stays `int`; `$fetchComplete` defaults `true` so the 6 existing direct calls in `tests/Unit/Service/SynchronizationServiceCleanupTest.php` (`$this->service->deleteInvalidObjects($sync, [])`) are unaffected and their `assertSame(<int>, $deleted)` assertions keep passing unmodified
  - GIVEN `$fetchComplete === false` WHEN the method is called THEN it returns `0` immediately (before the existing `switch ($type)`), logs a warning, dispatches `SynchronizationDeletionGuardedEvent(reason: 'fetch_incomplete')`, and sets `$guardInfo['guarded'] = true` — this check is unconditional, `$forceDeletion` does NOT bypass it
  - GIVEN `$deleteRestriction === false` and `count($allContractTargetIds) > 0` and the computed `$targetIdsToDelete` count divided by `$allContractTargetIds` count exceeds `$sourceConfig['deletionRatioThreshold'] ?? self::DEFAULT_DELETION_RATIO_THRESHOLD` (new constant `0.10`) and `$forceDeletion === false` WHEN the method reaches the point after computing `$targetIdsToDelete` (existing line ~2089) THEN it returns `0` without entering the per-target deletion loop, logs a warning, dispatches the event with `reason: 'ratio_threshold_exceeded'`, and populates `$guardInfo` with `ratio`/`threshold`/`candidateCount`/`totalContracts`
  - GIVEN the same over-threshold scenario but `$forceDeletion === true` WHEN the method runs THEN it proceeds through the existing per-target deletion loop unchanged
  - GIVEN `$deleteRestriction === true` (the single-object event-driven delete path) WHEN the method runs THEN the ratio guard is skipped entirely regardless of ratio — this path is unaffected by this task except for still respecting the `$fetchComplete` check above
  - `$sourceConfig` is read via the existing `applyConfigDot($synchronization['sourceConfig'] ?? [])` pattern already used for `restrictDeletion`
- [x] Implement
- [x] Test

### Task 6: Gate the `deleteInvalidObjects()` call site and the trailing `persistSynchronization()` on `isTest` in `synchronizeExternToIntern()`
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-test-runs-make-no-writes-req-011`
- **files**: `lib/Service/SynchronizationService.php` (`synchronizeExternToIntern()`, cleanup call ~line 1523, trailing persist ~line 1581)
- **acceptance_criteria**:
  - GIVEN `$isTest === true` WHEN `synchronizeExternToIntern()` reaches its Stage 5 cleanup THEN `deleteInvalidObjects()` is NOT called and `$result['objects']['deleted'] = 0`
  - GIVEN `$isTest === false` WHEN Stage 5 runs THEN `deleteInvalidObjects()` is called with `fetchComplete: ($rateLimitException === null && $fetchInfo['complete'] ?? true)` (wiring the Task 3 `$fetchInfo` output and the existing `$rateLimitException` catch together) and `forceDeletion: $forceDeletion` (new parameter threaded from Task 7), and `$result['objects']['deletionGuard']` records the `$guardInfo` from Task 5
  - GIVEN `$isTest === true` WHEN `synchronizeExternToIntern()` reaches its final `persistSynchronization()` call (~line 1581-1582) THEN that call and the preceding `$synchronization['targetLastSynced'] = ...` mutation are skipped entirely (wrapped in `if ($isTest === false)`)
  - Regression: GIVEN `$isTest === false` (existing behaviour) WHEN a normal run completes THEN `persistSynchronization()` still runs exactly as before
- [x] Implement
- [x] Test

### Task 7: Thread `forceDeletion` through `synchronize()` and add it to `SynchronizationsController::run()`
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010`
- **files**: `lib/Service/SynchronizationService.php` (`synchronize()` ~1613), `lib/Controller/SynchronizationsController.php` (`run()` ~333)
- **acceptance_criteria**:
  - `synchronize()` gains `?bool $forceDeletion=false`, threaded to its `synchronizeExternToIntern()` call
  - `SynchronizationsController::run()` reads `$forceDeletion = filter_var(($parameters['forceDeletion'] ?? false), FILTER_VALIDATE_BOOLEAN);` alongside the existing `test`/`force`/`source`/`data` params and passes it to `synchronize(...)`
  - `test()` is NOT changed to accept `forceDeletion` — a test run never deletes (Task 6), there is nothing to force
  - GIVEN a `POST .../synchronizations/{id}/run` request with `forceDeletion=true` in the body/query WHEN the controller handles it THEN the value reaches `deleteInvalidObjects()`'s `$forceDeletion` parameter
- [x] Implement
- [x] Test

### Task 8: `findOrCreateSourceByLocation()` never persists a new Source; thread the resolved source through the fetch chain
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-ad-hoc-source-resolution-does-not-persist-a-new-source-req-012`
- **files**: `lib/Service/SynchronizationService.php` (`findOrCreateSourceByLocation()` ~685, `synchronizeExternToIntern()` ~1379, `getAllObjectsFromSource()` ~2949, `getAllObjectsFromApi()` ~2984)
- **acceptance_criteria**:
  - GIVEN `findOrCreateSourceByLocation()`'s existing location lookup (`orObjectService->findAll(...)`) finds no match WHEN it builds `$sourceData` (unchanged shape: `location`, `name`, `type: api`, `enabled: true`, merged `$defaultData`) THEN it generates a `uuid`/`id` via `Symfony\Component\Uid\Uuid::v4()` (matching the existing pattern at line ~2443) and returns the array directly WITHOUT calling `$this->orObjectService->saveObject(...)`
  - GIVEN the existing lookup DOES find a matching Source by `location` WHEN `findOrCreateSourceByLocation()` runs THEN behaviour is completely unchanged (returns the found, persisted Source)
  - `getAllObjectsFromSource()` and `getAllObjectsFromApi()` gain a new optional `?array $resolvedSource=null` parameter; when non-null, it is used directly in place of the existing `$this->findSource(id: ...)` call (~line 2991) and `checkRateLimit()` is called against it as before
  - `synchronizeExternToIntern()` passes the transient `$source` array it already builds at line ~1379-1382 (when the caller supplied an ad-hoc `source` string) as `$resolvedSource` into `getAllObjectsFromSource()`; for the normal (non-ad-hoc, `$source === null`) path, `$resolvedSource` stays `null` and the existing `findSource(id: ...)` lookup is unchanged
  - GIVEN an ad-hoc `source` location with no matching configured Source WHEN a run is invoked THEN the fetch succeeds against that location AND `orObjectService->saveObject()` is never called with `schema: 'source'` for it (assert via a mock expectation)
- [x] Implement
- [x] Test

### Task 9: `detectDuplicateContracts()` diagnostic wired into `processSynchronizationObject()`
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-duplicate-synchronization-contracts-are-surfaced-never-silently-removed-req-013`
- **files**: `lib/Service/SynchronizationService.php` (new private method near `findContractBySyncAndOrigin()` ~426; call site in `processSynchronizationObject()` ~5754-5758)
- **acceptance_criteria**:
  - New method `detectDuplicateContracts(string $synchronizationId, string $originId): array` — read-only; performs no delete/update. Reuses the array already returned by the existing `findAllContractObjects(['synchronizationId' => ..., 'originId' => ...])` call inside `findContractBySyncAndOrigin()` rather than issuing a second query (pass the already-fetched result in, or restructure `findContractBySyncAndOrigin()` to expose the full match list to its caller before it narrows to "first match")
  - GIVEN more than one contract object is returned for the same `(synchronizationId, originId)` pair WHEN `processSynchronizationObject()` processes that origin id THEN a warning is logged identifying all duplicate contract ids, and normal processing continues using the same single contract `findContractBySyncAndOrigin()` already selects today (no behaviour change to which contract is used — this task only adds visibility)
  - GIVEN exactly one contract exists for the pair (the common case) WHEN processing runs THEN no warning is logged and there is no measurable added query cost
- [x] Implement
- [x] Test

### Task 10: Document the new `sourceConfig.deletionRatioThreshold` key
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010`
- **files**: `lib/Settings/openconnector_register.json` (`components.schemas.synchronization.properties.sourceConfig.description`)
- **acceptance_criteria**:
  - GIVEN the existing free-form `sourceConfig` property description (which already documents `resultsPosition`, `format`, `paginationQuery`, `paginationIn` as "notable recognised keys") WHEN this task is done THEN it also documents `deletionRatioThreshold` (float 0.0-1.0, default 0.10) with a one-sentence description and a reference to this change/spec requirement
  - This is a documentation-only JSON string edit — `sourceConfig` remains `"type": "object"` (free-form); no schema version bump, no OpenRegister migration (confirmed in `migration.md`)
- [x] Implement
- [x] Test

### Task 11: Regression tests — mass-deletion from fetch failure/partial pagination/rate-limit (#1000/#1001/#1002)
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009`, `#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010`
- **files**: `tests/Unit/Service/SynchronizationServiceFetchCompletenessTest.php` (new), following the mocking conventions in `tests/Unit/Service/SynchronizationServiceCleanupTest.php` (`ObjectServiceMockBuilder`, partial-mock via `getMockBuilder(SynchronizationService::class)->onlyMethods([...])`)
- **acceptance_criteria**:
  - TC reproducing #1000: source returns HTTP 500 on the first (only) page of a run against a synchronization with existing contracts → assert 0 objects deleted and the pre-existing target objects still resolvable via the mocked `orObjectService->find()`
  - TC reproducing #1001: source returns 2 valid pages then a non-2xx on page 3 of an expected larger set → assert the run does not delete objects that existed from a previous, fully-successful run, even though this run's `$synchronizedTargetIds` only contains objects from pages 1-2
  - TC reproducing #1002: source returns HTTP 429 on page 1 → assert `deleteInvalidObjects()` is never invoked (mock expectation `->expects($this->never())`) and the `TooManyRequestsHttpException` is still thrown to the caller with rate-limit headers intact
  - TC: pagination exhausts `DEFAULT_MAX_PAGES` while more pages remain → assert deletion is skipped
  - TC: a fully successful, complete fetch with a within-threshold deletion count still deletes as before (non-regression control case)
  - Run `vendor/bin/phpunit tests/Unit/Service/SynchronizationServiceFetchCompletenessTest.php` and confirm all pass
- [x] Implement
- [x] Test

### Task 12: Regression tests — deletion-ratio guard and `forceDeletion` override
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010`
- **files**: `tests/Unit/Service/SynchronizationServiceDeletionRatioGuardTest.php` (new)
- **acceptance_criteria**:
  - TC: 100 existing contracts, complete fetch, 15 would be deleted (15% > default 10%) → 0 deleted, warning logged, event dispatched (assert via a mock `IEventDispatcher` obtained through the container mock)
  - TC: same scenario with `forceDeletion: true` → 15 deleted
  - TC: `sourceConfig.deletionRatioThreshold: 0.5` with a 30% deletion → proceeds without `forceDeletion`
  - TC: `deleteRestriction: true` (single-object event-driven delete) with a high "ratio" (e.g. 1 of 2 total contracts) → the single object is still deleted, guard not applied
  - TC: 0 existing contracts (first-ever sync) → guard is not applicable (no division by zero), deletion proceeds normally for whatever is found invalid (should be none)
- [x] Implement
- [x] Test

### Task 13: Regression tests — test-run absolute no-write guarantee (#1008)
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-test-runs-make-no-writes-req-011`
- **files**: `tests/Unit/Service/SynchronizationServiceTestRunNoWriteTest.php` (new)
- **acceptance_criteria**:
  - TC: synchronization with N existing contracts/target objects, `isTest: true` run → assert `deleteInvalidObjects` (or the OR delete call it would trigger) is never invoked, 0 deletions reported
  - TC: `isTest: true` run against a changed source object → assert no `saveObject`/update call reaches the OR mock for the target or the contract
  - TC: `isTest: true` run → assert the synchronization's own `persistSynchronization`/save call for `targetLastSynced` is never invoked
  - TC: `isTest: true` run with an ad-hoc `source` location with no matching configured Source → assert no Source `saveObject` call occurs (overlaps with Task 8's own test but exercised specifically through the `isTest` path here)
- [x] Implement
- [x] Test

### Task 14: Regression tests — ad-hoc Source non-persistence (#1009) and originId contract matching (#1016)
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-ad-hoc-source-resolution-does-not-persist-a-new-source-req-012`, `#requirement-duplicate-synchronization-contracts-are-surfaced-never-silently-removed-req-013`
- **files**: `tests/Unit/Service/SynchronizationServiceAdHocSourceTest.php` (new), `tests/Unit/Service/SynchronizationServiceOriginIdMatchingTest.php` (new)
- **acceptance_criteria**:
  - `SynchronizationServiceAdHocSourceTest`: ad-hoc unmatched location → fetch succeeds, no `saveObject(schema: 'source', ...)` call; ad-hoc location matching an existing Source → that Source is reused and its rate-limit fields are read (unchanged path)
  - `SynchronizationServiceOriginIdMatchingTest`: resync the same `originId` twice under the same synchronization → assert `findContractBySyncAndOrigin()` returns the same existing contract on the second run (not a fresh, id-less array) and exactly one `persistContract`/create call happens across both runs combined
  - Same test with `sourceConfig.findContractByOriginIdOnly: true` → same single-contract assertion via the originId-only lookup branch
  - TC: a resync after the target object was deleted out-of-band (contract still exists, `orObjectService->find()` for the target throws `DoesNotExistException`) → assert the run does not error uncaught and recreates/reconciles rather than crashing (document actual observed behaviour if it differs from this expectation — this is exploratory verification per Decision 5, not a pre-asserted fix)
  - TC for `detectDuplicateContracts()`: two contracts pre-seeded for the same `(synchronizationId, originId)` → assert a warning is logged and neither is deleted
  - These tests are the concrete verification for the #1016 discrepancy noted in `design.md` Decision 5 — if any of them fail against the current (pre-this-change) `findContractBySyncAndOrigin()`/`processSynchronizationObject()` logic, that is a genuine bug the implementer MUST fix as part of this task (not defer); if they pass immediately, they still ship as permanent regression protection
- [x] Implement
- [x] Test

### Task 15: Full existing suite regression pass
- **spec_ref**: `openspec/changes/sync-safety-guardrails/design.md#decisions` (backward-compatibility goal)
- **files**: `tests/Unit/Service/SynchronizationServiceTest.php`, `tests/Unit/Service/SynchronizationServiceCleanupTest.php`, `tests/Unit/Service/SynchronizationContractServiceTest.php`
- **acceptance_criteria**:
  - GIVEN all Tasks 1-14 are implemented WHEN `vendor/bin/phpunit tests/Unit/Service/` runs THEN every pre-existing test in these three files still passes unmodified (no test file edits required by this task — if one needs an edit, that is a signal a "backward compatible" decision above was violated and MUST be reconciled, not silenced)
- [x] Implement
- [x] Test

## Verification
- [x] All tasks checked off
- [x] `openspec validate` passes
- [x] Manual testing against acceptance criteria
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/Service/SynchronizationService*Test.php` — Tasks 11-15)
- [x] Newman/Postman tests for new/changed API endpoints — add an assertion to the existing `tests/integration/openconnector.postman_collection.json` "6. Synchronization" folder's `synchronizations#run` request confirming a `forceDeletion` param in the request body does not cause a 500 (schema/plumbing smoke check only; the deep data-loss scenarios in Tasks 11-14 are covered by PHPUnit against mocked `CallService`/OR responses, which can simulate 429/500/partial-page conditions precisely — a live Newman run against a real external source cannot reliably reproduce those without a purpose-built stub source, which is out of scope for this change's smoke-test collection)
- [x] Browser tests (Playwright MCP) for UI changes — **N/A**: this change is backend-only (`SynchronizationService`/`SynchronizationsController`); no new UI surface. The existing "Test (dry run)" button in the Synchronizations UI (`sync-editor-ui` spec) continues to call the same `test` endpoint with no frontend changes required — its behaviour becoming safe is a backend fix, not a UI change.
- [x] All tests pass — `vendor/bin/phpunit -c phpunit-unit.xml`: 898 tests / 2702 assertions green (875 baseline + 23 new, zero pre-existing test edits). Newman: the `forceDeletion` smoke assertion was ADDED to the collection (see above); a live `newman run` needs a running dev instance and is executed by CI, not locally in this change (deep guard scenarios are PHPUnit-covered).

## Documentation (company-wide ADR-010)

- [x] Feature documentation updated in `docs/` — DONE: `docs/features/synchronizations.md` gained a "Deletion Safety Guards" section (deletionRatioThreshold, forceDeletion, test-mode no-write, ad-hoc Source non-persistence) and the Test-mode row was corrected. Original instruction retained below: add a short note to the existing synchronization/sync-safety documentation (if `docs/` has a synchronizations page) describing the `deletionRatioThreshold` config key and the `forceDeletion` run parameter for admins; if no such page exists yet, mark N/A with justification (internal safety-guard behaviour change, not a new user-facing feature requiring a new doc page) — **implementer must check `docs/` before deciding.**
- [x] Screenshot captured and committed to `docs/images/` — **N/A**: no new UI.

## i18n (company-wide hydra ADR-007)

- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added — **N/A**: no new user-facing strings; the deletion-guard warning is a server log message and event payload, not UI copy. If a future change adds a notification-centre consumer for `SynchronizationDeletionGuardedEvent` (see `proposal.md` Open Questions), that change will need i18n then.
