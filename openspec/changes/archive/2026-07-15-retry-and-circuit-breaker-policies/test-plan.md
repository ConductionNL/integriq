# Test Plan: retry-and-circuit-breaker-policies

## Test Cases

### TC-1: Default retry policy preserves single-attempt behavior
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/http-call-engine/spec.md#requirement-configurable-retry-policy-for-outbound-dispatch-req-007`
- **type**: api
- **preconditions**: a Source with no `retryPolicy` configured; a mocked upstream returning `503` on every call
- **steps**: call `CallService::call()` against the Source
- **expected result**: exactly one HTTP request is dispatched; `call_log.statusCode = 503`
- **test command**: `/test-api`

### TC-2: Exponential backoff retries up to maxAttempts
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/http-call-engine/spec.md#requirement-configurable-retry-policy-for-outbound-dispatch-req-007`
- **type**: api
- **preconditions**: a Source with `retryPolicy = {maxAttempts: 3, backoffStrategy: "exponential", baseDelayMs: 100, retryableStatusCodes: [503]}`; upstream returns `503, 503, 200`
- **steps**: call `CallService::call()`
- **expected result**: three requests dispatched with increasing delays (~100ms, ~200ms); final `call_log.statusCode = 200`; only one CallLog row persisted
- **test command**: `/test-api`

### TC-3: Non-retryable status code short-circuits after one attempt
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/http-call-engine/spec.md#requirement-configurable-retry-policy-for-outbound-dispatch-req-007`
- **type**: api
- **preconditions**: a Source with `retryPolicy = {maxAttempts: 3, retryableStatusCodes: [429, 503]}`; upstream returns `404`
- **steps**: call `CallService::call()`
- **expected result**: exactly one request dispatched; `call_log.statusCode = 404`
- **test command**: `/test-api`

### TC-4: Synchronization-level retry override widens the retryable set
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/http-call-engine/spec.md#requirement-configurable-retry-policy-for-outbound-dispatch-req-007`
- **type**: api
- **preconditions**: Source `retryPolicy = {maxAttempts: 1}`; Synchronization `retryPolicyOverride = {maxAttempts: 2, retryableStatusCodes: [500]}`
- **steps**: run the synchronization against an upstream returning `500` then `200`; separately, call the Source directly (no synchronization context)
- **expected result**: synchronization-context call dispatches twice and succeeds; direct call still dispatches once
- **test command**: `/test-api`

### TC-5: Five consecutive failures open the circuit breaker
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/http-call-engine/spec.md#requirement-per-source-circuit-breaker-generalized-into-callservice-req-008`
- **type**: api
- **preconditions**: a Source with default breaker thresholds; upstream returns `503` on every call
- **steps**: dispatch five calls sequentially
- **expected result**: after the fifth failure, `Source.circuitBreakerState = 'open'` and `circuitBreakerOpenedAt` is set
- **test command**: `/test-api`

### TC-6: Open breaker short-circuits without dispatching
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/http-call-engine/spec.md#requirement-per-source-circuit-breaker-generalized-into-callservice-req-008`
- **type**: api
- **preconditions**: Source with `circuitBreakerState = 'open'`, `circuitBreakerOpenedAt` 10s ago (cooldown 30s)
- **steps**: call `CallService::call()`
- **expected result**: no HTTP request dispatched; `call_log.statusCode = 503`, message "Circuit breaker is open for this source"
- **test command**: `/test-api`

### TC-7: Half-open probe closes the breaker on success, reopens on failure
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/http-call-engine/spec.md#requirement-per-source-circuit-breaker-generalized-into-callservice-req-008`
- **type**: api
- **preconditions**: Source with `circuitBreakerState = 'open'`, `circuitBreakerOpenedAt` 35s ago (cooldown 30s)
- **steps**: (a) call with upstream returning `200`; (b) separately, call with upstream returning `503`
- **expected result**: (a) breaker closes, failure count resets to 0; (b) breaker reopens with a fresh `circuitBreakerOpenedAt`
- **test command**: `/test-api`

### TC-8: Admin manually trips and resets a breaker
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009`
- **type**: api
- **preconditions**: admin session; a healthy Source
- **steps**: `POST .../sources/{id}/circuit-breaker/trip`, verify subsequent calls short-circuit, then `POST .../sources/{id}/circuit-breaker/reset`
- **expected result**: trip sets `open` immediately; reset restores `closed` with `failureCount = 0`; next call dispatches normally
- **test command**: `/test-api`

### TC-9: Non-admin is rejected from breaker endpoints
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/http-call-engine/spec.md#requirement-manual-circuit-breaker-trip-and-reset-req-009`
- **type**: security
- **preconditions**: authenticated non-admin session
- **steps**: call trip and reset endpoints
- **expected result**: both rejected by NC's admin requirement
- **test command**: `/test-security`

### TC-10: Source detail page shows the circuit breaker badge and reset action
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/design.md#decision-2-circuit-breaker-state-persisted-on-the-source-or-object-not-apcuicache`
- **type**: functional
- **preconditions**: admin logged in; a Source with `circuitBreakerState = 'open'`
- **steps**: open the Source detail page; click "Reset breaker"
- **expected result**: badge shows "Circuit open" with failure count/cooldown; after reset, badge updates to "Closed"
- **test command**: `/test-functional`

### TC-11: One bad sync item does not abort the pass
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/synchronization-engine/spec.md#req-008-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync`
- **type**: api
- **preconditions**: a synchronization fetching 10 objects; object #4's mapping throws
- **steps**: run `synchronize()`
- **expected result**: objects 1-3 and 5-10 processed normally; `result.objects.invalid` +1; a `sync_item_dead_letter` entry captured for #4; `synchronization_log` persisted
- **test command**: `/test-api`

### TC-12: Dead-lettered sync items are not auto-retried
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/synchronization-engine/spec.md#req-008-per-item-isolation-and-dead-letter-capture-during-extern-to-intern-sync`
- **type**: regression
- **preconditions**: a `sync_item_dead_letter` entry with `status = 'failed'`
- **steps**: trigger the next scheduled run of the same synchronization
- **expected result**: no automatic re-attempt of the dead-lettered item occurs
- **test command**: `/test-regression`

### TC-13: Sync dead-letter listing, filters, and detail
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-listing-with-filters-and-pagination-req-dlr-007`
- **type**: api
- **preconditions**: entries in `failed`, `replayed`, `discarded` states across two synchronizations
- **steps**: `GET /api/sync-dead-letter` (default), then with `synchronizationId` filter, then `GET /api/sync-dead-letter/{id}`
- **expected result**: default returns only `failed`; filter narrows correctly; detail returns payload/error/attempts/phase/context
- **test command**: `/test-api`

### TC-14: Replay a dead-lettered sync item after fixing the root cause
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/dead-letter-replay/spec.md#requirement-audited-manual-replay-of-a-dead-lettered-sync-item-req-dlr-009`
- **type**: api
- **preconditions**: a `failed` entry whose mapping bug has been corrected
- **steps**: `POST /api/sync-dead-letter/{id}/replay`
- **expected result**: `status='replayed'`, `replayedBy` set, corresponding `synchronization_contract` created/updated
- **test command**: `/test-api`

### TC-15: Replay/discard reject invalid state transitions
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/dead-letter-replay/spec.md#requirement-audited-discard-of-a-dead-lettered-sync-item-req-dlr-010`
- **type**: api
- **preconditions**: an entry already in `replayed` or `discarded` state
- **steps**: call replay and discard on it again
- **expected result**: both return 409; entry unchanged
- **test command**: `/test-api`

### TC-16: Bulk replay/discard with mixed outcomes and >100-id rejection
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/dead-letter-replay/spec.md#requirement-bulk-replay-and-discard-for-sync-item-dead-letters-req-dlr-011`
- **type**: api
- **preconditions**: ids `[A (failed), B (discarded), C (nonexistent)]`; a separate request with 101 ids
- **steps**: `POST /api/sync-dead-letter/replay` with both payloads
- **expected result**: mixed-outcome request reports `A: ok, B: invalid-state, C: not-found` and A is replayed; 101-id request returns 400
- **test command**: `/test-api`

### TC-17: Operator replays and discards from the Sync dead letters UI
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-ui-in-the-synchronizations-section-req-dlr-012`
- **type**: functional
- **preconditions**: admin logged in; one failed entry; three failed entries for bulk discard
- **steps**: open detail modal, confirm Replay; separately select three rows and confirm bulk Discard
- **expected result**: modal shows payload + attempt timeline before action; list updates after; bulk discard requires confirmation before sending
- **test command**: `/test-functional`

### TC-18: Empty dead-letter queue shows an empty state
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/dead-letter-replay/spec.md#requirement-sync-item-dead-letter-ui-in-the-synchronizations-section-req-dlr-012`
- **type**: functional
- **preconditions**: no failed sync-item entries
- **steps**: open the Sync dead letters view
- **expected result**: empty state rendered, not an empty table
- **test command**: `/test-functional`

### TC-19: openconnector_circuit_breaker_state gauge reflects breaker state
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/prometheus-metrics/spec.md#req-prom-011-circuit-breaker-state-gauge`
- **type**: api
- **preconditions**: sources with open, closed, and never-evaluated breaker states
- **steps**: `GET /api/metrics`
- **expected result**: gauge reports `1` for open, `0` for closed and never-evaluated
- **test command**: `/test-api`

### TC-20: Metrics endpoint degrades gracefully on breaker-state query failure
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/prometheus-metrics/spec.md#req-prom-011-circuit-breaker-state-gauge`
- **type**: api
- **preconditions**: simulated DB failure for the breaker-state query
- **steps**: `GET /api/metrics`
- **expected result**: 200 response, zero-value fallback for the breaker gauge, warning logged
- **test command**: `/test-api`

### TC-21: A throwing job's identity does not bleed into the next job (#1006 regression pin)
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/job-scheduling/spec.md#requirement-job-scheduling-registration-and-execution-with-retention-bounded-logs-req-004`
- **type**: regression
- **preconditions**: two due jobs in the same `run()` pass — job A (`userId: alice`, throws), job B (no `userId`)
- **steps**: invoke `JobService::run()`
- **expected result**: session user during job B's execution (and after `run()` returns) is NOT `alice`
- **test command**: `/test-regression`

### TC-22: A throwing job does not block the rest of the cron sweep (#1005 regression pin)
- **spec_ref**: `openspec/changes/retry-and-circuit-breaker-policies/specs/job-scheduling/spec.md#requirement-job-scheduling-registration-and-execution-with-retention-bounded-logs-req-004`
- **type**: regression
- **preconditions**: three due jobs — A (throws), B, C
- **steps**: invoke `JobService::run()`
- **expected result**: B and C both execute and produce logs; A's `nextRun` advances by its interval; returned array contains logs for A (ERROR), B, C
- **test command**: `/test-regression`

## Coverage Summary
- `http-call-engine` REQ-007 (retry policy): TC-1, TC-2, TC-3, TC-4 — covered
- `http-call-engine` REQ-008 (circuit breaker): TC-5, TC-6, TC-7, TC-10 — covered
- `http-call-engine` REQ-009 (manual trip/reset): TC-8, TC-9 — covered
- `synchronization-engine` REQ-008 (item isolation + dead-letter capture): TC-11, TC-12 — covered
- `dead-letter-replay` REQ-DLR-007/008 (listing/detail): TC-13 — covered
- `dead-letter-replay` REQ-DLR-009 (replay): TC-14, TC-15 — covered
- `dead-letter-replay` REQ-DLR-010 (discard): TC-15 — covered
- `dead-letter-replay` REQ-DLR-011 (bulk): TC-16 — covered
- `dead-letter-replay` REQ-DLR-012 (UI): TC-17, TC-18 — covered
- `prometheus-metrics` REQ-PROM-011 (breaker gauge): TC-19, TC-20 — covered
- `job-scheduling` REQ-004 (cron isolation, #1005/#1006 corrected text): TC-21, TC-22 — covered

## Out of Scope
- Automatic scheduled retry sweeps for `sync_item_dead_letter` — deliberately not built (see design.md Decision 5); no test case, since the negative behavior is already covered by TC-12.
- Distributed-lock correctness for the half-open probe under true concurrency (multiple simultaneous requests) — documented as an accepted limitation in design.md; not practically testable without a dedicated concurrency harness, deferred.
- Fine-grained `phase` classification (`fetch`/`mapping`/`write`) for dead-lettered sync items — not implemented in v1 (see design.md Decision 5), so not tested.
