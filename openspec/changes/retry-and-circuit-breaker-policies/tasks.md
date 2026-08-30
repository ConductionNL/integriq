# Tasks: retry-and-circuit-breaker-policies

## Implementation Tasks

### Task 1: Extend the register descriptor — RetryPolicy + circuit breaker fields on Source
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/http-call-engine/spec.md#requirement-configurable-retry-policy-for-outbound-dispatch-req-007`
- **files**: `lib/Settings/integriq_register.json` (source schema properties)
- **acceptance_criteria**:
  - GIVEN the `source` schema WHEN inspected THEN it carries `retryPolicy` (object; `maxAttempts`, `backoffStrategy`, `baseDelayMs`, `maxDelayMs`, `jitter`, `retryableStatusCodes`, `retryOnTimeout`), `circuitBreakerState`, `circuitBreakerFailureCount`, `circuitBreakerOpenedAt`, `circuitBreakerLastProbeAt`, `circuitBreakerThreshold` (default 5), `circuitBreakerCooldownSeconds` (default 30)
  - GIVEN an existing Source with none of these fields set WHEN read THEN defaults reproduce today's single-attempt, always-closed behavior (verify against `docs/schema/Source.json` regeneration)
- [ ] Implement
- [ ] Test

### Task 2: Extend the register descriptor — retryPolicyOverride on Synchronization + sync_item_dead_letter schema
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-listing-with-filters-and-pagination-req-dlr-007`
- **files**: `lib/Settings/integriq_register.json` (synchronization schema properties + new `sync_item_dead_letter` schema entry)
- **acceptance_criteria**:
  - GIVEN the `synchronization` schema WHEN inspected THEN it carries `retryPolicyOverride` (object, all keys optional)
  - GIVEN the new `sync_item_dead_letter` schema WHEN inspected THEN it mirrors `event_message`'s shape: `uuid`, `synchronization` (FK CASCADE), `synchronizationContract` (FK SET NULL, nullable), `originId`, `phase` (default `item-processing`), `payload`, `error`, `status` (enum `failed|replayed|discarded`), `retryCount`, `attempts[]`, `replayedBy`/`replayedAt`, `discardedBy`/`discardedAt`, `created`/`updated`
- [ ] Implement
- [ ] Test

### Task 3: RetryPolicy resolution + retry loop in CallService
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/http-call-engine/spec.md#requirement-configurable-retry-policy-for-outbound-dispatch-req-007`
- **files**: `lib/Service/CallService.php`
- **acceptance_criteria**:
  - GIVEN a Source with no `retryPolicy` WHEN `call()` runs against a failing upstream THEN exactly one HTTP request is dispatched (unchanged behavior)
  - GIVEN a Source with `retryPolicy.maxAttempts > 1` and a retryable status code WHEN `call()` runs THEN it retries up to `maxAttempts` with the configured backoff (fixed/exponential, optional jitter), and only the final attempt's CallLog is persisted
  - GIVEN `$config['retryPolicy']` is set by the caller WHEN `call()` resolves the effective policy THEN it overrides the Source's `retryPolicy` per-key
- [ ] Implement
- [ ] Test

### Task 4: Per-source circuit breaker in CallService (state resolve, short-circuit, record success/failure)
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/http-call-engine/spec.md#requirement-per-source-circuit-breaker-generalized-into-callservice-req-008`
- **files**: `lib/Service/CallService.php`
- **acceptance_criteria**:
  - GIVEN 5 consecutive retryable failures on a Source WHEN the 5th failure is recorded THEN `circuitBreakerState` becomes `open` and `circuitBreakerOpenedAt` is set (persisted via the same `saveObject` pattern as `sourceRateLimit()`)
  - GIVEN an open breaker within its cooldown window WHEN `call()` runs THEN a synthetic `503` CallLog ("Circuit breaker is open for this source") is persisted and no HTTP request is dispatched
  - GIVEN an open breaker past its cooldown WHEN `call()` runs THEN exactly one probe request is dispatched; success closes the breaker, failure reopens it with a fresh `circuitBreakerOpenedAt`
- [ ] Implement
- [ ] Test

### Task 5: Manual circuit breaker trip/reset endpoints
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009`
- **files**: `lib/Controller/SourcesController.php`, `appinfo/routes.php`, `lib/Service/CallService.php` (trip/reset helpers)
- **acceptance_criteria**:
  - GIVEN an admin WHEN they call `POST .../sources/{id}/circuit-breaker/trip` THEN the Source's `circuitBreakerState` becomes `open` immediately, regardless of prior failure count
  - GIVEN an admin WHEN they call `POST .../sources/{id}/circuit-breaker/reset` THEN the Source's `circuitBreakerState` becomes `closed` with `circuitBreakerFailureCount = 0`
  - GIVEN a non-admin WHEN they call either endpoint THEN the request is rejected by NC's admin requirement (no `@NoAdminRequired`/`@NoCSRFRequired` on these methods)
  - GIVEN an unknown source id WHEN either endpoint is called THEN the response is 404
- [ ] Implement
- [ ] Test

### Task 6: Circuit breaker state badge on Source detail UI
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/design.md#decision-2-circuit-breaker-state-persisted-on-the-source-or-object-not-apcuicache`
- **files**: `src/` Source detail view/component (existing `SourceDetail`-equivalent), new `src/components/CircuitBreakerBadge.vue` (or equivalent per existing component conventions)
- **acceptance_criteria**:
  - GIVEN a Source with `circuitBreakerState = 'open'` WHEN an admin opens its detail page THEN a badge shows "Circuit open" with the failure count and cooldown countdown
  - GIVEN the badge is visible WHEN an admin clicks "Reset breaker" THEN it calls the reset endpoint (Task 5) and the badge updates to "Closed" on success
- [ ] Implement
- [ ] Test

### Task 7: Circuit breaker Prometheus gauge
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/prometheus-metrics/spec.md#req-prom-011-circuit-breaker-state-gauge`
- **files**: `lib/Controller/MetricsController.php`
- **acceptance_criteria**:
  - GIVEN sources with mixed breaker states WHEN `GET /api/metrics` is called THEN `integriq_circuit_breaker_state{source="<name>"}` reports `1` for open, `0` for closed/unset
  - GIVEN the breaker-state query fails WHEN metrics are collected THEN a zero-value fallback is emitted with a warning logged and the endpoint still returns 200
- [ ] Implement
- [ ] Test

### Task 8: Per-item isolation in SynchronizationService's object loop
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/synchronization-engine/spec.md#req-008-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync`
- **files**: `lib/Service/SynchronizationService.php` (`synchronizeExternToIntern()` object loop)
- **acceptance_criteria**:
  - GIVEN 10 fetched objects where object #4 throws during `processSynchronizationObject()` WHEN `synchronize()` runs THEN objects #1-3 and #5-10 are still processed, `result['objects']['invalid']` increments by 1, and the pass completes with a persisted `synchronization_log`
  - GIVEN the same failure WHEN inspected THEN a `sync_item_dead_letter` entry is created via `SyncItemDeadLetterService` (Task 9) with the raw payload, error message, and `phase: 'item-processing'`
- [ ] Implement
- [ ] Test

### Task 9: SyncItemDeadLetterService (capture, replay, discard)
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/dead-letter-replay/spec.md#requirement-audited-manual-replay-of-a-dead-lettered-sync-item-req-dlr-009`
- **files**: `lib/Service/SyncItemDeadLetterService.php` (new)
- **acceptance_criteria**:
  - GIVEN a captured failure WHEN `recordFailure()` is called THEN a `sync_item_dead_letter` object is persisted per the schema in Task 2
  - GIVEN a `failed` entry WHEN `replayMessage()` is called THEN `processSynchronizationObject()` is re-invoked for its payload; success sets `status='replayed'` + `replayedBy`/`replayedAt` preserving `attempts[]`; renewed failure appends an `attempts[]` entry and increments `retryCount` while remaining `failed`
  - GIVEN a `failed` entry WHEN `discardMessage()` is called THEN `status='discarded'` is set with `discardedBy`/`discardedAt`, never hard-deleted
  - GIVEN an entry in `replayed`/`discarded` state WHEN replay or discard is called again THEN a 409-equivalent exception is thrown
- [ ] Implement
- [ ] Test

### Task 10: Sync dead-letter controller endpoints (list/detail/replay/discard/bulk)
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011`
- **files**: `lib/Controller/SyncDeadLetterController.php` (new), `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN the endpoints defined in design.md's API Design section WHEN implemented THEN they mirror `EventsController`'s dead-letter method shapes (admin-only, CSRF-protected, no `@NoAdminRequired`)
  - GIVEN a bulk request with >100 ids WHEN posted THEN the response is 400
  - GIVEN a bulk request with mixed valid/invalid ids WHEN posted THEN per-id outcomes are reported and valid ids are still applied
- [ ] Implement
- [ ] Test

### Task 11: Sync dead-letter UI sub-view
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-ui-in-the-synchronizations-section-req-dlr-012`
- **files**: `src/views/synchronizations/SyncDeadLetter.vue` (new), `src/modals/SyncDeadLetterDetailModal.vue` (new), synchronizations section navigation/router entry
- **acceptance_criteria**:
  - GIVEN an admin on the Synchronizations section WHEN they navigate to "Sync dead letters" THEN the listing renders with status/synchronization/time filters
  - GIVEN one failed entry WHEN its detail modal is opened and "Replay" confirmed THEN the modal shows the payload + attempt timeline before the action, and the list reflects the outcome after
  - GIVEN no failed entries exist WHEN the view is opened THEN an empty state renders
- [ ] Implement
- [ ] Test

### Task 12: Correct the job-scheduling spec + add regression tests pinning #1005/#1006
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/job-scheduling/spec.md#requirement-job-scheduling-registration-and-execution-with-retention-bounded-logs-req-004`
- **files**: `tests/Unit/Service/JobServiceTest.php` (extend existing), no `lib/` changes expected (behavior already correct in HEAD — verify during implementation and file a follow-up if a gap is found)
- **acceptance_criteria**:
  - GIVEN two due jobs where the first (`userId`-scoped) throws WHEN `run()` processes both THEN the session user during/after the second job's execution is NOT the first job's configured user (pins #1006)
  - GIVEN three due jobs where the first throws WHEN `run()` processes all three THEN jobs two and three both execute and produce logs, and the first job's `nextRun` advances by its interval (pins #1005)
- [ ] Implement
- [ ] Test

### Task 13: Documentation — Source/Synchronization schema docs, dead-letter operator guide
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/design.md#database-changes`
- **files**: `docs/schema/Source.json` (regenerate), `docs/schema/Synchronization.json` (regenerate), `docs/` operator-facing page for circuit breaker + sync dead-letter usage
- **acceptance_criteria**:
  - GIVEN the schema docs generator WHEN run THEN `Source.json`/`Synchronization.json` reflect the new fields
  - GIVEN the operator docs page WHEN read THEN it explains RetryPolicy configuration, breaker states, and the sync dead-letter replay workflow with a screenshot
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate retry-and-circuit-breaker-policies --type change --strict` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/Service/CallServiceTest.php` — retry policy math + breaker state machine; `tests/Unit/Service/SyncItemDeadLetterServiceTest.php` — capture/replay/discard; `tests/Unit/Service/JobServiceTest.php` — #1005/#1006 regression pins)
- [ ] PHPUnit integration tests for cron isolation (`tests/Integration/Cron/JobServiceIsolationTest.php`) and sync-item isolation (`tests/Integration/Service/SynchronizationItemIsolationTest.php`)
- [ ] Newman/Postman tests for new API endpoints (circuit-breaker trip/reset, sync-dead-letter list/detail/replay/discard/bulk)
- [ ] Browser tests (Playwright MCP) for the Source detail breaker badge and the Sync dead letters UI sub-view
- [ ] All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` (RetryPolicy config, circuit breaker operations, sync dead-letter replay workflow)
- [ ] Screenshot captured and committed to `docs/images/` (Source detail breaker badge, Sync dead letters view)

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added for: circuit breaker badge/actions, sync dead-letter UI (listing, detail modal, replay/discard confirmations, empty state)
