# Proposal: retry-and-circuit-breaker-policies

## Summary
Add a configurable retry policy (max attempts, backoff strategy, jitter, retryable HTTP codes/timeouts) and a generalized circuit breaker to `CallService`'s outbound HTTP dispatch, extend the dead-letter/replay pattern already shipped for events to per-item synchronization failures, and correct/harden cron-sweep isolation in `JobService`. This closes the gap between Integriq and competitor iPaaS reliability features (n8n per-node retries/error triggers, Camel/NiFi DLQ, Workato enterprise error handling) called out in issue #863, and verifies/documents the fixes already shipped for #1005 (one throwing job aborting the whole cron pass) and #1006 (cross-job session-identity bleed).

## Motivation
Today, outbound calls have no generic retry policy — `CallService` only does source-level rate-limit bookkeeping (synthetic 409/429 `CallLog`s) with no attempt/backoff loop, and the only circuit breaker in the codebase lives entirely inside `PdokConnector` (APCu-backed, single global key, not reusable by any other Source). Synchronization item failures (mapping/write errors for one source object) are not isolated: `SynchronizationService::processSynchronizationObject()` is called in an un-guarded `foreach` inside `synchronizeExternToIntern()` — one item's exception aborts the entire sync pass for every remaining object, with no captured record of what failed or a way to replay just that item. Event delivery already has a full dead-letter/replay UI and backoff schedule (`openconnector-dead-letter-replay`, `openconnector-event-retry-hardening`) but nothing analogous exists for synchronization data. This is table-stakes reliability tooling other iPaaS/integration platforms ship natively.

## Affected Projects
- [x] Project: `integriq` — RetryPolicy schema fields, generalized circuit breaker in `CallService`, per-source breaker state + manual trip/reset endpoints, sync-item dead-letter schema + replay endpoints + UI, cron isolation verification/hardening in `JobService`, new Prometheus breaker metric.

## Scope

### In Scope
- `RetryPolicy` config object stored on the `source` schema (`retryPolicy`: maxAttempts, backoffStrategy `fixed|exponential`, baseDelayMs, maxDelayMs, jitter, retryableStatusCodes, retryOnTimeout), with an optional per-`synchronization` override (`retryPolicyOverride`) merged in by the caller via `$config['retryPolicy']` on `CallService::call()`.
- Retry loop enforced centrally in `CallService` around the existing single-attempt `dispatchRequest()`, honouring the resolved policy (source default, synchronization override, or built-in default when neither is configured).
- Circuit breaker generalized into `CallService`, keyed per-Source (not global like `PdokConnector`'s), state persisted on the `source` OR object (`circuitBreakerState`, `circuitBreakerFailureCount`, `circuitBreakerOpenedAt`, `circuitBreakerLastProbeAt`) so it survives across PHP-FPM workers and cron processes — closed/open/half-open state machine mirroring `PdokConnector`'s existing threshold/cooldown/probe shape.
- Breaker state surfaced in the Source detail UI (badge + failure count + cooldown countdown) and via a new `integriq_circuit_breaker_state` Prometheus gauge.
- Manual trip/reset admin endpoints (`POST .../sources/{id}/circuit-breaker/trip` and `/reset`) for the "manually trigger circuit breaker for a failing upstream" user story.
- New `sync_item_dead_letter` OR schema + `SynchronizationsController` endpoints (list/detail/replay/discard, singular + bulk) reusing the `openconnector-dead-letter-replay` UI/API pattern, capturing failed per-object mapping/write attempts instead of aborting the whole sync pass.
- Per-item isolation in `SynchronizationService::synchronizeExternToIntern()`'s object loop: a `try/catch` around `processSynchronizationObject()` that captures the failure to `sync_item_dead_letter` and continues to the next object.
- Verification (and correction of the now-stale `job-scheduling` spec text) of the cron-isolation fixes already present in `JobService::run()` (#1005 per-job try/catch, schedule always advances) and `JobService::executeJob()` (#1006 prior-session-user restore in a `finally` block) — both confirmed already implemented in HEAD code, contrary to the original issue framing; this change documents and adds missing test coverage rather than re-implementing them.
- Unit tests for retry-policy math and breaker state-machine transitions; integration tests for cron isolation (one throwing job doesn't block the sweep) and sync-item isolation (one bad item doesn't abort the pass).

### Out of Scope
- Full execution-trace observability across retries/breaker trips (deferred to a future distributed-tracing change).
- Inbound rate limiting — already covered by `lib/Service/RateLimit/InboundRateLimitService`.
- Automatic scheduled retry sweeps for `sync_item_dead_letter` entries (unlike event delivery, item transformation/write failures are typically deterministic config/data errors, not transient — replay is a deliberate manual/bulk operator action, not a backoff-scheduled sweep).
- A distributed lock for the circuit breaker's half-open probe (concurrent requests during the cooldown window may each attempt a probe; documented as a known limitation, not solved here).

## Approach
Extend the `source` and `synchronization` OR schemas with the new config/state fields (register descriptor change, `openconnector-register-schema`). Wrap `CallService`'s existing single-shot `dispatchRequest()` call in a bounded retry loop that consults the resolved `RetryPolicy` and the per-source breaker state before each attempt, using the same `saveObject`-to-Source persistence pattern already used for rate-limit bookkeeping (`sourceRateLimit()`). Add a `SyncItemDeadLetterService` (mirroring `EventService`'s replay/discard machinery) plus controller endpoints and a Vue sub-view under Synchronizations, reusing the `EventsController`/`Event deliveries` UI pattern. Wrap the per-object loop in `SynchronizationService` in a try/catch that persists to the new dead-letter schema on failure. Add regression tests pinning the already-fixed `JobService` isolation/identity-restore behavior, and correct the `job-scheduling` spec's stale REQ-004 notes (which still describe the pre-fix session-clobber bug) to match HEAD.

## New Dependencies
None.

## Impact
- `lib/Service/CallService.php` — retry loop + circuit breaker integration around `dispatchRequest()`.
- `lib/Settings/integriq_register.json` — `source.retryPolicy`, `source.circuitBreakerState`/`circuitBreakerFailureCount`/`circuitBreakerOpenedAt`/`circuitBreakerLastProbeAt`, `synchronization.retryPolicyOverride`, new `sync_item_dead_letter` schema.
- `lib/Service/SynchronizationService.php` — per-item try/catch + dead-letter capture in `synchronizeExternToIntern()`.
- New `lib/Service/SyncItemDeadLetterService.php`, new controller endpoints (likely additions to `SynchronizationsController` or a new `SyncDeadLetterController`), new Vue sub-view under `src/` Synchronizations section.
- `lib/Controller/MetricsController.php` — new `integriq_circuit_breaker_state` gauge.
- `lib/Service/JobService.php` — test coverage only (behavior already correct); no functional change expected unless review surfaces a residual gap.
- Spec deltas: `http-call-engine`, `synchronization-engine`, `job-scheduling`, `dead-letter-replay`, `prometheus-metrics`.

## Cross-Project Dependencies
None. This is a self-contained Integriq change; no other apps-extra project consumes these new endpoints or schema fields directly.

## Risks

### Risk 1: Retry loop changes CallLog volume and outbound-call timing semantics for every existing Source
**Severity:** High — **Mitigation:** Default `RetryPolicy` (when unset on a Source) MUST reproduce today's single-attempt behavior exactly (maxAttempts=1, i.e. retries are strictly opt-in per Source). Ship with retries defaulting off; existing sources see zero behavior change until an operator explicitly configures a policy.

### Risk 2: Circuit breaker false-positives could make a healthy-but-slow Source appear "open" and block legitimate traffic
**Severity:** Medium — **Mitigation:** Reuse `PdokConnector`'s already-proven threshold (5 consecutive failures) and cooldown (30s) defaults as the Source-level default, expose both as configurable, and provide the manual reset endpoint as an operator escape hatch.

### Risk 3: Per-item dead-letter capture increases OR write volume during large sync passes with many failing items
**Severity:** Medium — **Mitigation:** Cap dead-letter writes analogous to the existing `attempts[]` bound on `event_message`, and rely on existing OR log-cleanup patterns (retention/expiry) for the new schema.

### Risk 4: Retrying non-idempotent verbs (POST) by default could cause double-submission against upstreams that don't dedupe
**Severity:** Low — **Mitigation:** `retryableStatusCodes` defaults to a conservative, idempotency-safe set (429, 502, 503, 504); operators opting a POST-heavy Source into retries on other codes do so explicitly and are documented as owning that risk (mirrors the existing "Observed; flagged" transparency style used elsewhere in `http-call-engine`).

## Rollback Strategy
Every new behavior is additive and schema-gated: `RetryPolicy` absence preserves today's single-attempt dispatch, breaker-state fields absence preserves today's always-closed behavior, and the new `sync_item_dead_letter` schema/endpoints/UI can be disabled by removing the route registrations and reverting the `SynchronizationService` try/catch without touching persisted data. A revert commit removing the register-descriptor additions and the `CallService`/`SynchronizationService` changes fully restores current behavior; no destructive migration is introduced.

## Open Questions
- Should the circuit breaker's manual trip/reset endpoints be exposed per-Source only, or also as a bulk "trip all sources hitting host X" operation? Deferred to design.md — default to per-Source only for v1.
