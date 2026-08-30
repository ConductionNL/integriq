# Test Plan: sync-safety-guardrails

## Test Cases

### TC-1: Non-2xx page response marks the fetch incomplete and preserves partial results
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009`
- **type**: regression
- **persona**: n/a (backend engine)
- **preconditions**: a synchronization configured against a stubbed `api` source that returns HTTP 200 with 5 objects on page 1 and HTTP 500 on page 2
- **steps**: mock `CallService::call()` to return this sequence; invoke `getAllObjectsFromApi()` with a `$fetchInfo` reference
- **expected result**: the 5 page-1 objects are returned; `$fetchInfo['complete'] === false` and `$fetchInfo['failureReason']` identifies the failed page
- **test command**: `/test-functional` is not applicable — this is `vendor/bin/phpunit tests/Unit/Service/SynchronizationServiceFetchCompletenessTest.php`

### TC-2: Natural end of pagination (final page returns zero objects with HTTP 200) is marked complete
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009`
- **type**: regression
- **preconditions**: stubbed source returning 2 non-empty pages then an empty HTTP 200 page 3
- **steps**: invoke `getAllObjectsFromApi()` with `$fetchInfo`
- **expected result**: `$fetchInfo['complete'] === true`; existing behaviour (objects from pages 1-2 returned) unchanged
- **test command**: `vendor/bin/phpunit --filter testNaturalEndOfPagination...`

### TC-3: Exhausting `DEFAULT_MAX_PAGES` while more pages remain marks the fetch incomplete
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-fetch-completeness-tracking-during-source-pagination-req-009`
- **type**: regression
- **preconditions**: stubbed source that always returns a non-empty page plus a valid next-page link, 51+ times
- **steps**: invoke `getAllObjectsFromApi()`; count `CallService::call()` invocations
- **expected result**: exactly 50 page fetches occur (cap unchanged); `$fetchInfo['complete'] === false`, `failureReason === 'max_pages_reached'`
- **test command**: `vendor/bin/phpunit --filter testMaxPagesCapMarksIncomplete...`

### TC-4: Incomplete fetch blocks `deleteInvalidObjects()` entirely (#1000)
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010`
- **type**: regression
- **preconditions**: synchronization with 20 existing contracts; a run whose fetch is marked incomplete via TC-1's HTTP-500 scenario
- **steps**: run `synchronizeExternToIntern()` (or call `deleteInvalidObjects()` directly with `fetchComplete: false`) with a partial mock isolating `updateTarget()`
- **expected result**: `updateTarget(..., action: 'delete')` is never invoked (`->expects($this->never())`); returned deleted count is `0`; all 20 pre-existing target objects remain resolvable
- **test command**: `vendor/bin/phpunit --filter testIncompleteFetchBlocksDeletion...`

### TC-5: HTTP 429 on page 1 blocks deletion but still surfaces the rate-limit exception (#1002)
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010`
- **type**: regression
- **preconditions**: synchronization with existing contracts; stubbed source returning HTTP 429 on page 1
- **steps**: call `synchronizeExternToIntern()`
- **expected result**: `TooManyRequestsHttpException` is thrown with rate-limit headers (unchanged); `deleteInvalidObjects()` was never invoked (assert via partial mock `->expects($this->never())->method('deleteInvalidObjects')` or an internal spy)
- **test command**: `vendor/bin/phpunit --filter testRateLimitBlocksDeletion...`

### TC-6: Deleting more than the default 10% threshold aborts and logs/dispatches
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010`
- **type**: regression
- **preconditions**: 100 existing contracts; a complete fetch whose `$synchronizedTargetIds` omits 15 of them (15%)
- **steps**: call `deleteInvalidObjects(..., fetchComplete: true)`
- **expected result**: returns `0`; `$guardInfo['guarded'] === true`, `reason === 'ratio_threshold_exceeded'`; `LoggerInterface::warning()` called once; `IEventDispatcher::dispatchTyped()` called once with `SynchronizationDeletionGuardedEvent`
- **test command**: `vendor/bin/phpunit tests/Unit/Service/SynchronizationServiceDeletionRatioGuardTest.php --filter testDefaultThresholdAborts...`

### TC-7: `forceDeletion: true` proceeds past the ratio guard
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010`
- **type**: regression
- **preconditions**: same as TC-6
- **steps**: call `deleteInvalidObjects(..., fetchComplete: true, forceDeletion: true)`
- **expected result**: returns `15`; the 15 target objects are deleted as before this change
- **test command**: `vendor/bin/phpunit --filter testForceDeletionOverridesRatioGuard...`

### TC-8: Per-synchronization `deletionRatioThreshold` override changes the guard point
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010`
- **type**: regression
- **preconditions**: `sourceConfig.deletionRatioThreshold: 0.5`; a run that would delete 30%
- **steps**: call `deleteInvalidObjects()`
- **expected result**: deletion proceeds without `forceDeletion` (30% < 50%)
- **test command**: `vendor/bin/phpunit --filter testPerSyncThresholdOverride...`

### TC-9: `deleteRestriction` (single-object event-driven delete) is exempt from the ratio guard
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010`
- **type**: regression
- **preconditions**: 2 existing contracts; an `ObjectDeletedEvent`-driven run with `mutationType: delete`, `sourceConfig.restrictDeletion: true`, targeting 1 of the 2 (50% — over any realistic default threshold)
- **steps**: call `deleteInvalidObjects(..., deleteRestriction: true, data: [...])`
- **expected result**: the 1 targeted object is deleted; guard is never evaluated for this path
- **test command**: `vendor/bin/phpunit --filter testDeleteRestrictionExemptFromRatioGuard...`

### TC-10: Zero existing contracts (first-ever sync) does not divide by zero or misfire the guard
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010`
- **type**: regression
- **preconditions**: synchronization with 0 existing contracts
- **steps**: call `deleteInvalidObjects()`
- **expected result**: no error/warning; returns `0`; `$guardInfo` reflects "not applicable" rather than a spurious trip
- **test command**: `vendor/bin/phpunit --filter testZeroExistingContractsNoDivisionByZero...`

### TC-11: Test run against a synchronization with 50 previously-synced objects deletes nothing (#1008, the most severe pre-existing bug)
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-test-runs-make-no-writes-req-011`
- **type**: regression
- **preconditions**: synchronization with 50 existing contracts/target objects
- **steps**: call `synchronize(synchronization: $sync, isTest: true)` (equivalently, `POST .../synchronizations/{id}/test`)
- **expected result**: `deleteInvalidObjects()` is never invoked; `$result['objects']['deleted'] === 0`; all 50 target objects remain resolvable via the mocked OR service afterward
- **test command**: `vendor/bin/phpunit tests/Unit/Service/SynchronizationServiceTestRunNoWriteTest.php --filter testTestRunDeletesNothing...`

### TC-12: Test run does not persist the synchronization's own `targetLastSynced`
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-test-runs-make-no-writes-req-011`
- **type**: regression
- **preconditions**: synchronization with a known prior `targetLastSynced` value
- **steps**: call `synchronize(isTest: true)`; inspect whether `persistSynchronization()`/the underlying OR save call was invoked
- **expected result**: not invoked; the stored value is unchanged
- **test command**: `vendor/bin/phpunit --filter testTestRunDoesNotPersistSynchronizationState...`

### TC-13: Test run against a changed object writes nothing to target/contract
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-test-runs-make-no-writes-req-011`
- **type**: regression
- **preconditions**: a source object whose hash differs from its existing contract's `originHash`
- **steps**: call `synchronize(isTest: true)` for that object
- **expected result**: transformed data returned to caller; no `updateTarget()`/`persistContract()` call reaches the OR mock (already correct pre-existing behaviour per Decision 4 — this TC is a non-regression control, not expected to find a new bug)
- **test command**: `vendor/bin/phpunit --filter testTestRunNoWriteOnChangedObject...`

### TC-14: Ad-hoc location with no matching Source does not persist a new Source (#1009)
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-ad-hoc-source-resolution-does-not-persist-a-new-source-req-012`
- **type**: regression
- **preconditions**: no existing Source has `location: https://example.test/ad-hoc-feed`
- **steps**: call `synchronize(synchronization: $sync, source: 'https://example.test/ad-hoc-feed')`
- **expected result**: the run fetches successfully from that location; `orObjectService->saveObject(..., schema: 'source', ...)` is never invoked
- **test command**: `vendor/bin/phpunit tests/Unit/Service/SynchronizationServiceAdHocSourceTest.php --filter testUnmatchedAdHocLocationDoesNotPersistSource...`

### TC-15: Ad-hoc location matching an existing configured Source reuses it unchanged
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-ad-hoc-source-resolution-does-not-persist-a-new-source-req-012`
- **type**: regression
- **preconditions**: an existing Source with `location: https://example.test/configured-feed` and rate-limit state
- **steps**: call `synchronize(source: 'https://example.test/configured-feed')`
- **expected result**: that Source is reused (its rate-limit fields are read by `checkRateLimit()`); no new Source is created
- **test command**: `vendor/bin/phpunit --filter testMatchedAdHocLocationReusesExistingSource...`

### TC-16: Resyncing the same originId twice under the same synchronization results in exactly one contract (#1016 verification)
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-duplicate-synchronization-contracts-are-surfaced-never-silently-removed-req-013`
- **type**: regression
- **preconditions**: a source object with a stable `originId`, synced once (one contract created)
- **steps**: run `synchronize()` a second time against the same source object
- **expected result**: the second run's `findContractBySyncAndOrigin()` returns the existing contract (not a fresh id-less array); across both runs combined, exactly one `persistContract`-with-create-semantics call occurs
- **test command**: `vendor/bin/phpunit tests/Unit/Service/SynchronizationServiceOriginIdMatchingTest.php --filter testResyncSameOriginIdMatchesExistingContract...`
- **note**: this is exploratory verification per `design.md` Decision 5 — static review found no bug, but this TC is the authoritative check; if it fails, fixing the underlying flow is in scope for Task 14, not deferred.

### TC-17: Duplicate pre-existing contracts for one originId are logged, never auto-deleted
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-duplicate-synchronization-contracts-are-surfaced-never-silently-removed-req-013`
- **type**: regression
- **preconditions**: two `SynchronizationContract` objects pre-seeded for the same `(synchronizationId, originId)`
- **steps**: run a sync that processes an object with that `originId`
- **expected result**: `LoggerInterface::warning()` invoked once, identifying both contract ids; neither contract nor its target object is deleted as a result of this detection
- **test command**: `vendor/bin/phpunit --filter testDuplicateContractsLoggedNotDeleted...`

### TC-18: Full pre-existing suite passes unmodified (backward compatibility)
- **spec_ref**: `openspec/changes/sync-safety-guardrails/design.md#decisions`
- **type**: regression
- **preconditions**: all Tasks 1-14 implemented
- **steps**: `vendor/bin/phpunit tests/Unit/Service/SynchronizationServiceTest.php tests/Unit/Service/SynchronizationServiceCleanupTest.php tests/Unit/Service/SynchronizationContractServiceTest.php`
- **expected result**: every pre-existing test passes with zero edits to those three files
- **test command**: `vendor/bin/phpunit tests/Unit/Service/`

### TC-19: `forceDeletion` request parameter plumbs through the REST `run` endpoint without a 500
- **spec_ref**: `openspec/changes/sync-safety-guardrails/specs/synchronization-engine/spec.md#requirement-deletion-is-gated-on-fetch-completeness-and-a-configurable-deletion-ratio-guard-req-010`
- **type**: api
- **preconditions**: an authenticated Newman environment against a running dev instance, an existing synchronization id (reusing the collection's existing `{{syncId}}` setup/teardown flow)
- **steps**: `POST .../synchronizations/{{syncId}}/run` with `forceDeletion: true` in the request body (source-less sync, mirroring the existing "graceful 4xx, NOT 500" request in the collection's "6. Synchronization" folder)
- **expected result**: a graceful 4xx (missing source), never a 500 — confirms the new parameter doesn't break request parsing/routing
- **test command**: `/test-api` — `newman run tests/integration/openconnector.postman_collection.json`

## Coverage Summary

| Requirement | Covered by | Status |
|---|---|---|
| REQ-009 Fetch-completeness tracking | TC-1, TC-2, TC-3 | Covered |
| REQ-010 Deletion gated on completeness + ratio guard | TC-4, TC-5, TC-6, TC-7, TC-8, TC-9, TC-10, TC-19 | Covered |
| REQ-011 Test runs make no writes | TC-11, TC-12, TC-13 | Covered |
| REQ-012 Ad-hoc Source does not persist | TC-14, TC-15 | Covered |
| REQ-013 Duplicate contracts surfaced, not removed | TC-16, TC-17 | Covered |
| REQ-004 (MODIFIED) garbage-collection scenario | TC-4 through TC-10 (the modified scenario is the composite of the new guards) | Covered |
| Backward compatibility (design.md goal, not a numbered REQ) | TC-18 | Covered |

## Out of Scope

- Live end-to-end reproduction of #1000/#1001/#1002 against a real flaky external API is not attempted — PHPUnit mocks of `CallService`/`orObjectService` give precise, deterministic control over 429/500/partial-page conditions that a live Newman run cannot reliably reproduce; TC-19 only smoke-tests the new `forceDeletion` parameter's plumbing over the real REST layer.
- Retry/backoff behaviour after a failed fetch is explicitly out of scope for this change (`retry-and-circuit-breaker-policies`) and has no test cases here.
- A UI/browser test for the "Test (dry run)" button is not added — the fix is entirely in `SynchronizationService`/`SynchronizationsController`; the button already calls the existing `test` endpoint and needs no frontend change. TC-11 through TC-13 verify the fix at the layer where the bug actually lives.
