# Proposal: ipaas-reliability

`kind: feature` per ADR-032 — enterprise integration reliability primitives (idempotency, retries, dead-letter queues, circuit-breaking, SLA monitoring, quotas) are first-class openconnector features, not bolted-on middleware.

## Summary

openconnector today provides solid request/response plumbing — adapters, sources, synchronizations, schema mappings — but lacks the operational guarantees that differentiate enterprise iPaaS platforms (MuleSoft, Boomi, Workato, Tibco, SnapLogic) from naive HTTP-glue libraries. Without reliability features, every integration becomes a fragile cron-job whose failures are invisible until a downstream report comes up empty, a finance reconciliation misses an invoice, or a citizen's BRP mutation never reaches the zaaksysteem.

This spec adds the full reliability tier as first-class openconnector primitives, configurable per adapter and per source:

- **Idempotency**: Every outbound call gains an idempotency key derived from a deterministic hash, preventing double-effects when retries fire after a partial response.
- **Retries**: Every call participates in an exponential-backoff retry policy with configurable jitter to prevent thundering herds.
- **Dead-Letter Queue (DLQ)**: Every terminal failure lands in a per-adapter queue where it can be inspected, edited, and replayed — operators are no longer forced to choose between losing data and writing custom recovery scripts.
- **Circuit Breaker**: Protects both openconnector and downstream systems from cascading failure by fast-failing calls when a source's error rate crosses a threshold.
- **SLA Monitoring**: Exposes per-endpoint p50/p95/p99 latency and rolling error-rate to Prometheus and surfaces violations on the openconnector dashboard.
- **Consumer Quotas**: Per API key, per tenant, or per upstream system to prevent any single workflow from monopolising a connection pool or blowing through a downstream provider's rate budget.
- **Audit Logging**: Every call — successful, retried, replayed, or fast-failed — is recorded in an immutable, append-only audit log retained for the configured period (default 1 year, configurable up to 7 years for government use).
- **Bulkhead Isolation**: Per-adapter concurrency caps protect the shared thread/connection pool from starvation.

The reliability tier is opt-in per adapter via configuration; existing adapters work unchanged but gain the full feature set with a single `reliability_profile` reference. Sensible defaults make the feature no-config for most integrations, while every parameter is overridable for high-volume or compliance-sensitive cases.

## Motivation

Government and enterprise procurement tenders (municipality digitisation, waterschap integration) include reliability features as hard pass/fail scoring criteria. Without them, openconnector cannot displace commercial iPaaS suites. Operators of mission-critical synchronisations today resort to manual recovery scripts (re-fetch from log, edit JSON, curl back) because openconnector offers no DLQ. Integration engineers lack SLA visibility; CISO/security teams lack immutable audit trails. This spec closes the gap.

## Affected Projects

- [x] **openconnector** — adds ReliabilityProfile, IdempotencyRecord, DLQEntry, CircuitBreakerState, CallLog extension, Quota, and all supporting call-interception logic. All features are opt-in per adapter via configuration.
- [ ] **openregister** — consumes the existing audit-trail-immutable abstraction per ADR-022 for CallLog retention; no source changes required.
- [ ] **mydash** — consumes SLA violation events and metrics from openconnector's Prometheus endpoint; no source changes required.
- [ ] **pipelinq** — consumes DLQ entries as failed-task states and offers one-click replay; no source changes required unless workflow-level reliability profiles are added later.
- [ ] **hydra** — event-bus consumer of `dlq.entry.created`, `circuit.state.changed`, `sla.violation`, `dlq.entry.expired`, `dlq.entry.replayed` domain events; no source changes required.
- [ ] **Sibling apps** (docudesk, decidesk, procest, opencatalogi, shillinq) — inherit reliable integrations automatically; no source changes required.

## Scope

### In Scope

- **ReliabilityProfile**: Configuration schema for retry policy (max attempts, backoff, jitter), idempotency strategy (deterministic, client-supplied, none), circuit-breaker thresholds, DLQ retention, SLA targets, and bulkhead limits. Stored in openregister.
- **Idempotency**: Deterministic keys derived from (adapter, endpoint, payload-canonical-form); Redis-backed caching with configurable TTL; in-flight waiting; response reuse.
- **Exponential Backoff with Jitter**: Full/equal/none jitter strategies; configurable initial and max delays; Retry-After header override.
- **Dead-Letter Queue**: Terminal-failure capture; original payload + headers + error metadata; per-adapter queuing; inspection and manual editing; bulk and single replay.
- **Circuit Breaker**: Per-(adapter, source) state machine (closed/open/half-open); rolling-window failure threshold; typed error returns; breaker-state domain events.
- **SLA Monitoring**: Per-endpoint latency histograms (p50/p95/p99) and rolling error-rate; Prometheus exposition; dashboard violations surfaced as warning/critical/page severity tiers.
- **Consumer Quotas**: Per API key, per tenant, per upstream system; sliding-window enforcement; HTTP 429 returns with accurate Retry-After hints; X-RateLimit-* headers.
- **Audit Logging**: CallLog extension with idempotency_key, retry_attempt, breaker_state, dlq_entry_id, consumer_id, quota_remaining, correlation_id; append-only; retention periods (1–7 years).
- **Correlation IDs**: Propagation of X-Correlation-Id / W3C traceparent headers end-to-end; UUIDv4 generation when absent.
- **Bulkhead Isolation**: Per-adapter concurrency caps (default 50, configurable); overflow rejection with typed error; DLQ bypass for capped calls.
- **Reliability Profile Inheritance**: Endpoint-specific overrides with fallback to adapter scope, then global default; effective profile snapshot in CallLog.
- **DLQ Retention and Expiry**: Automatic expiry after configured period (default 30 days); metadata preservation after payload purge; operator override capability.
- **Replay History and Provenance Chain**: DLQEntry linking via `replay_of` reference; diff rendering in UI; full chain export as JSON.

### Out of Scope

- Per-sibling-app customization of reliability behaviour — the profile is openconnector-side only.
- Request-level reliability (only outbound calls to external systems; inbound to openconnector is sibling-app responsibility).
- Idempotency key supplied by the caller (deterministic keys are computed server-side for consistency; client-supplied keys are a future REQ).
- Retry logic for auth/token-refresh errors (a 401 triggers token refresh, not a retry; persistent 401 trips the breaker).
- SLA targets for internal openconnector operations (CallLog writes, DLQ enqueues, etc.); SLA applies to the end-to-end call only.
- Per-workflow reliability overrides (workflows always inherit adapter/global profile; per-workflow customization is a follow-up).

## Approach

One multi-requirement spec (`ipaas-reliability/spec.md`) containing REQ-001 through REQ-014, covering the full reliability tier in dependency order:

1. **REQ-001–REQ-002**: Idempotency and retries — foundational call semantics.
2. **REQ-003–REQ-005**: DLQ — terminal-failure recovery pathway.
3. **REQ-006**: Circuit-breaker — cascading-failure prevention.
4. **REQ-007–REQ-009**: SLA, audit, quotas — operational visibility.
5. **REQ-010–REQ-014**: Configuration inheritance, correlation, retention, bulkhead, provenance.

Each requirement includes at least two scenarios (setup, reviewer-gate, integration-test expectations).

## New Dependencies

None. This change consumes:

- Existing openconnector `Source`, `Mapping`, `Synchronization`, `Job`, `CallLog` abstractions.
- Existing OR `audit-trail-immutable` per ADR-022 for CallLog retention.
- Existing Prometheus client library for metrics endpoint.
- Redis for IdempotencyRecord and CircuitBreakerState (already a dependency for other caching).

No new external libraries, no version bumps.

## Impact

- `openspec/changes/ipaas-reliability/` is a new folder containing `proposal.md`, `design.md`, `tasks.md`, and one `specs/ipaas-reliability/spec.md`.
- No runtime code changes in this spec; implementation follows via an `opsx-apply` cycle.
- Future changes to adapters will add optional `reliability_profile` references to their `Source` configuration; existing adapters remain unchanged.

## Cross-Project Dependencies

- **openregister** — depends on the existing `audit-trail-immutable` abstraction being callable for CallLog retention periods (already shipped per ADR-022).
- **hydra** — depends on the existing event-bus infrastructure to emit `dlq.entry.created`, `circuit.state.changed`, etc. (already shipped per ADR-013).
- **mydash** — may consume SLA violation events for dashboard surfacing (no blocking dependency; can be added in follow-up).
- **pipelinq** — may consume DLQ as a failed-task state source (no blocking dependency; can be added in follow-up).

## Risks

### Risk 1: Idempotency TTL conflicts with slow distributed downstream systems

**Severity**: Low
**Mitigation**: REQ-001 makes TTL configurable per ReliabilityProfile (default 24h, max 7 days). Operators of systems with multi-day reply latencies set a longer TTL; the contract stays flexible.

### Risk 2: Circuit-breaker threshold may be too sensitive for upstream systems with natural error rate spikes

**Severity**: Medium
**Mitigation**: REQ-006 sets sensible defaults (50% error rate, 20 min-call window, 60s open period) and makes all parameters configurable. Operators tuning a new adapter observe the breaker in half-open state before deploying to production; if it trips too easily, the window or threshold is adjusted.

### Risk 3: DLQ replay without idempotency may cause double-effects on eventual-success flows

**Severity**: Medium
**Mitigation**: REQ-004 specifies that replay uses current adapter configuration; if idempotency is enabled at reply time, the replay is idempotent. Operators must enable idempotency for adapters with mutative endpoints before enabling DLQ-replay workflows.

### Risk 4: Audit log retention to 7 years may create compliance overhead

**Severity**: Low
**Mitigation**: REQ-008 sets the default to 1 year (aligned with GDPR); 7-year retention is opt-in for government use under Archiefwet. Operators not bound by archival law use the default.

### Risk 5: Per-adapter concurrency caps (bulkhead) may create surprising rejection behaviour for workflows

**Severity**: Low
**Mitigation**: REQ-013 sets a sensible default (50 in-flight per adapter) and documents the behaviour in the spec. Operators monitoring CallLog `BulkheadFull` errors adjust the cap per adapter; the default is safe for typical 4-8 core openconnector deployments.

## Rollback Strategy

Spec-only change. To roll back: revert the commit; delete the change folder. At implementation time, rollback means:

1. Remove ReliabilityProfile records from openregister.
2. Remove the DLQ-related migration (if it created new tables/columns).
3. Stop emitting domain events; sibling-app subscribers gracefully handle absence.
4. Remove CallLog extension columns (audit-trail-immutable handles the retraction).

No data loss because DLQ is transient (configurable expiry, max 7 years); existing CallLog entries remain queryable.

## Open Questions

1. **Idempotency key format**: Should the key include the correlation_id or only (adapter, endpoint, payload)? REQ-001 specifies only the latter for stability; correlation ID is stamped separately on the CallLog. Confirm this split is right.
2. **DLQ vs. Sentry/Loki**: Should the DLQ be the *only* failure surfacing or one of many? REQ-003 specifies DLQ as the primary replay mechanism; Sentry integration is a follow-up (operators can bridge DLQ to external alerting).
3. **Per-workflow reliability profiles**: Should pipelinq be able to override a source's ReliabilityProfile per orchestrated step? Deferred to a follow-up; this spec applies profiles at the source scope.
4. **Quota reset window**: REQ-009 specifies a "sliding window" using Redis counters. Alternative: fixed hourly/daily windows. Confirm sliding is the right default (it's stricter and more predictable).

## Success Criteria

- [ ] Spec passes architecture review (ADR compliance: ADR-022, ADR-031 for declarative config).
- [ ] First adapter (e.g., StUF, SnapLogic) ships with a ReliabilityProfile reference and passes opsx-apply.
- [ ] DLQ UI renders on the openconnector dashboard with inspect, edit, replay flows working end-to-end.
- [ ] Prometheus `/metrics` endpoint exposes per-endpoint latency and error-rate histograms.
- [ ] Audit auditor can query CallLog and reconstruct a 6-month replay chain from original failure to successful recovery.
- [ ] At least one government tender acceptance test (DLQ replay, SLA compliance, audit retention) passes using openconnector.

## Precedent

This spec is informed by:

- **Azure Architecture Center**: Retry, circuit-breaker, idempotency patterns.
- **RFC 7231, RFC 6585**: HTTP status codes, Retry-After header.
- **draft-ietf-httpapi-idempotency-key-header**: Idempotency-Key convention.
- **Temporal/Cadence**: Workflow replay semantics; deterministic hashing.
- **AWS SDK**: Exponential backoff with full/equal jitter variants.
- **Spring Cloud Circuit Breaker**: Per-service state machine, half-open testing.
- **Archiefwet / NEN-ISO/IEC 27001**: Audit log retention mandates for government.
