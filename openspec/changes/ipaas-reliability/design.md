# Design — iPaaS Reliability

## Context

openconnector adapters today execute outbound calls with no operational guarantees: no idempotency, no retries on transient failures, no recovery pathway for permanent failures, no SLA visibility, no isolation when a downstream system is slow, no audit trail for compliance. Every integration failure is a manual incident requiring custom scripts and operator intervention.

Enterprise iPaaS platforms (MuleSoft, Boomi, Workato) differentiate themselves from HTTP-glue libraries by offering these guarantees as built-in, configurable features. Government and enterprise procurement tenders score them as hard pass/fail criteria.

This change adds the full reliability tier to openconnector as first-class primitives, optionally applied per adapter via a ReliabilityProfile configuration. It codifies the design decisions that make reliability _discoverable_ (operators read one config object, not scattered retry/breaker code) and _trustworthy_ (deterministic idempotency keys, immutable audit logs, domain events for cross-app subscribers).

The change is **spec-only**. Implementation lands via `opsx-apply`.

## Goals

- Express every reliability feature as **declarative configuration** in a ReliabilityProfile schema, not as imperative PHP per-adapter.
- Reuse **existing abstractions** (openconnector CallLog for audit, OR audit-trail-immutable for retention, Redis for transient state, Prometheus for metrics) rather than reimplementing.
- Make reliability **opt-in per adapter**, with **sensible defaults** (5 retries, exponential 200ms–30s, 24h idempotency TTL, 50% error-rate breaker trip) so most integrations need zero config.
- Ensure **every call is auditable** — CallLog captures success, retry, failure, breaker state, DLQ enqueue, replay, quota exhaustion, and all metadata needed to reconstruct the call's journey.
- Enable **safe DLQ replay** — operators can inspect, edit, and re-execute failed payloads without losing data or causing silent failures.
- Protect **downstream systems** from cascading failure via circuit breakers and quota enforcement.
- Protect **openconnector itself** from pool starvation via per-adapter bulkhead isolation.
- Support **compliance mandates** (Archiefwet, NEN-ISO/IEC 27001) with configurable audit retention (1–7 years) and immutable logging.

## Non-Goals

- Per-workflow or per-tenant overrides of reliability behaviour — profiles are scoped to adapters or globally, not per invocation.
- Distributed tracing integration beyond correlation ID propagation — the audit log is the primary observability surface; Jaeger/Zipkin integration is a follow-up.
- Custom retry predicates per adapter (e.g., retry-on-specific-API-error-code) — the retry rules are fixed per category (HTTP 5xx, timeout, etc.); per-adapter extensions are future REQs.
- Fallback chains or degradation policies — DLQ is the failure sink, not a step in a fallback sequence.

## Decisions

### D1 — ReliabilityProfile is declarative configuration, not imperative retry-and-breaker code per adapter

Per ADR-031, every reliability behaviour that can be declared via configuration MUST be. The ReliabilityProfile schema defines retry policy (max attempts, backoff curve, jitter), idempotency strategy (deterministic vs. none), circuit-breaker thresholds, DLQ retention, SLA targets, and bulkhead limits. No per-adapter PHP class writes retry logic; the profile IS the logic.

**Alternative considered**: Author per-adapter `StUFRetryPolicy`, `SnapLogicCircuitBreakerPolicy` PHP classes that compute behaviour. Rejected — that scattered reliability across dozens of files and violated DRY (every adapter copies the same retry formula). Declarative configuration keeps the reliability contract in one place.

### D2 — Idempotency keys are deterministic (adapter + endpoint + canonical payload), not client-supplied

REQ-001 specifies `SHA256(adapter_id || endpoint || canonical_json(payload))`. This ensures idempotency is **automatic and consistent** across retries — if the same operation is replayed, it gets the same key and hits the cache. Client-supplied keys (RFC draft pattern) are a future REQ if sibling apps need them; for now, deterministic keys avoid the risk of clients providing non-idempotent keys and operators accidentally causing double-effects.

**Alternative considered**: Accept Idempotency-Key header from caller, fall back to deterministic if absent. Rejected — mixing strategies creates audit confusion ("which key was used for this call?"). Start with deterministic; add client-supplied as an explicit opt-in.

### D3 — Retries are exponential backoff with three jitter strategies (full, equal, none), not linear or fixed

REQ-002 specifies backoff as `min(initial_delay * 2^attempt, max_delay)` plus jitter. Full jitter (random [0, delay]) is the AWS SDK default and prevents thundering herds on recovery. Equal jitter (delay/2 + random [0, delay/2]) smooths the curve without randomness extremes. None (zero jitter) is for testing. This avoids the "retry storm" anti-pattern where all failed instances retry simultaneously.

### D4 — DLQ is terminal-failure-only, not a catch-all event queue

REQ-003 specifies DLQ capture only AFTER all retries are exhausted and `dlq_enabled = true`. The DLQ is not a side-effect sink or event log; it's a recovery pathway for data loss prevention. This keeps the DLQ finite and focused on actionable failures.

### D5 — DLQ replay is operator-initiated or scheduled, not automatic

REQ-004 specifies that replay is a two-step process: inspect (in the UI or via API), then manually trigger replay (single, bulk, or via cron-backed scheduled replay). Automatic replay is dangerous because it risks infinite loops on logic errors. Scheduled replay (via OR ScheduledWorkflow) gives operators the "retry Monday morning at 2am" semantics without building custom cron.

### D6 — Circuit breaker is per-(adapter, source), not per endpoint

REQ-006 scopes the breaker to the (adapter, source) pair. This prevents a single slow endpoint from tripping the breaker for the entire source's other endpoints (which may be healthy). Alternative: per-endpoint breakers create N * M state machines. The (adapter, source) scope is operational: "the Salesforce source is unhealthy" is a simpler decision than "the Account endpoint is unhealthy but Contact is fine."

### D7 — SLA targets (latency + error rate) are optional per ReliabilityProfile

REQ-007 makes `sla_target_p95_ms` and `sla_target_error_rate` optional. If set, violations emit events and surface on the dashboard. If not set, no SLA monitoring occurs. This keeps the feature opt-in and avoids false positives on adapters with bespoke SLA expectations.

### D8 — Audit logging is append-only and immutable, extended via CallLog, not a separate event stream

REQ-008 extends the existing openconnector CallLog schema (already immutable per ADR-003) with idempotency_key, retry_attempt, breaker_state_at_call, dlq_entry_id, consumer_id, quota_remaining. No new event table. This reuses the existing audit infrastructure and ensures a single source of truth for call history.

### D9 — Quotas are sliding-window, not fixed-window, for stricter rate control

REQ-009 uses Redis sliding-window counters (count requests in the trailing period_duration, increment on each call, decrement expired requests). Sliding-window is stricter than fixed hourly/daily windows (which allow bursts at window boundaries) and more predictable. The tradeoff: slightly more Redis operations; the benefit: operators set one quota limit and know it's never exceeded within that window.

### D10 — Correlation IDs propagate end-to-end via headers and audit log

REQ-011 specifies that X-Correlation-Id or W3C traceparent headers are propagated to outbound calls and stamped on every CallLog entry. If absent, a UUIDv4 is generated. This enables operators to trace a single business operation (e.g., a citizen's BRP mutation) across the full call graph (inbound → openconnector → downstream → sibling app responses).

### D11 — DLQ retention is automatic expiry + operator override, not unbounded

REQ-012 sets a default 30-day max age; expired entries transition to status `expired`, payload body is purged (preserving metadata for audit), and operators CAN override if they need longer retention for specific entries. This balances storage costs with operator flexibility.

### D12 — Bulkhead isolation is per-adapter, not per-source

REQ-013 scopes concurrency caps to the adapter level (default 50 in-flight calls to *any source* of that adapter). This prevents a single noisy adapter from starving the thread pool for other adapters. Alternative: per-source bulkheads create finer-grained isolation at the cost of more coordination. Per-adapter is simpler and sufficient for typical deployments.

### D13 — Reliability profile inheritance uses three-level fallback: endpoint > source > global

REQ-010 specifies override precedence: endpoint-specific profile overrides source-level profile, which overrides the global default. Unset fields fall back to the next level. This avoids repetition (most sources use the global default) while allowing exceptions (e.g., Salesforce may need a tighter error-rate threshold than StUF).

### D14 — The replay provenance chain is a directed acyclic graph of DLQEntry records

REQ-014 specifies that each DLQEntry carries a `replay_of` reference to its predecessor (if replayed from another DLQEntry). The UI renders the full chain as a DAG; each link includes the operator identity, timestamp, and diff of edited payloads. This creates an auditable record of "what happened to this failed call" for compliance reviews.

## Reuse Analysis

| Capability needed | What already exists | iPaaS-reliability reuse strategy |
|---|---|---|
| Adapter invocation point (where to intercept for retries/idempotency/etc.) | openconnector existing adapter-call stack | Intercepted at `SourceService::invoke()` or similar existing entry point; no new call path |
| Call audit trail | openconnector existing `CallLog` table (per ADR-003) | Extended with idempotency_key, retry_attempt, breaker_state, dlq_entry_id, consumer_id, quota_remaining |
| Immutable audit log retention | OR `audit-trail-immutable` abstraction (per ADR-022) | Consumed for CallLog retention periods (1–7 years) |
| Idempotency result caching | Redis (already a dependency) | Used for IdempotencyRecord store with configurable TTL |
| Circuit breaker state machine | None (new) | Implemented in-app; state stored in Redis per (adapter, source) pair |
| SLA metrics exposition | openconnector existing Prometheus metrics endpoint | Extended additively per REQ-007; new `openconnector_adapter_latency_seconds` and `openconnector_adapter_error_rate` histograms |
| Operational metrics | Prometheus client (already a dependency) | Used for latency histograms and error-rate rolling windows |
| Domain events (DLQ, circuit, SLA) | openconnector existing event-bus per ADR-013 | Emits `dlq.entry.created`, `circuit.state.changed`, `sla.violation`, `dlq.entry.expired`, `dlq.entry.replayed` |
| ReliabilityProfile storage | openconnector existing `Source` / openregister schema storage | ReliabilityProfile stored as openregister schema; referenced by Source via slug |
| DLQEntry storage | openregister (for immutable append-only records) | New openregister schema `DLQEntry` with append-only lifecycle |
| Quota state | Redis (for fast sliding-window increments) | Used for per-(consumer, scope, period) counters with auto-expiry |
| Correlation ID propagation | Existing headers (X-Correlation-Id, traceparent) | Propagated to outbound calls; stamped on every CallLog entry; included in error responses |
| Replay scheduling | OR ScheduledWorkflow (per ADR-031 path 2) | Operators schedule DLQ replay batches via ScheduledWorkflow; no per-adapter cron |

**Net new code**: One ReliabilityProfile schema, one DLQEntry schema, one CircuitBreakerState Redis handler, one Quota schema, one CallLog schema extension, call-interception middleware, DLQ UI component (Vue), replay scheduler integration.

**Net new tests**: Unit tests for idempotency key computation, backoff formula, circuit-breaker state machine, quota sliding-window, DLQ replay chain diff computation.

## Declarative-vs-imperative decision (per ADR-031)

| Feature | Decision | Why |
|---|---|---|
| Retry policy (max attempts, backoff, jitter) | Declarative (ReliabilityProfile schema) | Configuration-driven; shared across adapters; no per-adapter PHP |
| Idempotency strategy | Declarative (ReliabilityProfile `idempotency_strategy`) | Enum choice with deterministic default; no per-adapter logic |
| Circuit-breaker thresholds | Declarative (ReliabilityProfile fields) | Numeric config; tuned per adapter; no state machine class |
| DLQ retention and expiry | Declarative (ReliabilityProfile `dlq_max_age_days`) | Retention period is a config number; expiry is automatic |
| SLA targets | Declarative (ReliabilityProfile `sla_target_*`) | Optional numeric targets; violations are events, not imperative alerts |
| Bulkhead concurrency cap | Declarative (ReliabilityProfile `concurrency_limit`) | Per-adapter numeric limit; overflow is a typed error |
| Quota limits and periods | Declarative (Quota schema) | Period (minute/hour/day) and limit are config fields |
| Correlation ID propagation | Imperative middleware (header copy on outbound calls) | Per ADR-031 exception: header propagation is a lightweight cross-cutting concern, not a data model |
| IdempotencyRecord lookup/store | Imperative (Redis interaction) | Per ADR-031 exception: Redis cache hit/miss logic is a thin layer, not a service class |
| CircuitBreakerState transitions | Imperative (state machine) | Per ADR-031 exception: Finite-state machine (closed → open → half-open) is algorithmic, not data-model-expressible; no config can replace the logic |

All other operational behaviours are declarative via ReliabilityProfile, Quota, and openregister schemas.

## Seed Data

This spec ships NO seed data. Per-adapter sources MAY ship optional ReliabilityProfile seeds (e.g., `lib/Settings/seeds/reliability-profiles/snaplogic.json`) showing a paused example profile for operators to review before activation.

Example seed ReliabilityProfile for a high-volume SaaS adapter:

```json
{
  "_meta": {
    "type": "reliability-profile",
    "adapter": "salesforce",
    "imported": "<iso-timestamp>"
  },
  "slug": "salesforce-bulk",
  "label": "Salesforce bulk operations",
  "lifecycleState": "paused",
  "retry_max_attempts": 10,
  "retry_initial_delay_ms": 500,
  "retry_max_delay_ms": 60000,
  "retry_jitter": "equal",
  "retry_on_status": [408, 429, 500, 502, 503, 504],
  "retry_on_error_classes": ["timeout", "connection_reset"],
  "idempotency_strategy": "deterministic",
  "idempotency_ttl_seconds": 604800,
  "circuit_breaker_threshold": 0.3,
  "circuit_breaker_window_seconds": 120,
  "circuit_breaker_min_calls": 30,
  "circuit_breaker_open_seconds": 60,
  "dlq_enabled": true,
  "dlq_max_age_days": 90,
  "sla_target_p95_ms": 2000,
  "sla_target_error_rate": 0.01,
  "concurrency_limit": 100
}
```

Operators review, adjust parameters per deployment constraints, and activate.

## Risks / Trade-offs

| Risk | Mitigation |
|---|---|
| Idempotency TTL too short for slow systems → missed cache hits → unnecessary re-executions | TTL is configurable (default 24h, max 7 days); operators of systems with slower reply windows set longer TTL per ReliabilityProfile |
| Circuit-breaker threshold too sensitive → trips on transient error spikes → cascading failures | Default 50% threshold + 20-call window are conservative; operators tune in half-open mode before prod; thresholds are per-ReliabilityProfile |
| DLQ replay without idempotency → double-effects on mutative endpoints | Operators MUST enable deterministic idempotency before enabling DLQ replay for mutative sources; documented in spec and per-adapter design docs |
| Bulkhead caps cause surprising "BulkheadFull" errors for workflows | Default 50 in-flight is safe for typical 4-8 core deployments; operators monitor CallLog errors and adjust per adapter; behaviour is deterministic (no silent drops) |
| 7-year audit log retention creates storage bloat | Default is 1 year (aligned with GDPR); 7-year retention is opt-in for Archiefwet compliance only; operators not bound by archival law use the default |
| Per-adapter SLA targets may be too strict/loose across different deployment sizes | SLA targets are optional per ReliabilityProfile; operators only set them if they have contractual SLAs; absence = no SLA monitoring |
| Replay provenance chain grows unbounded → storage costs for highly-retried entries | Provenance is metadata only (operator ID, timestamp, diff); payload bodies are purged after expiry; chain is queryable but bounded by DLQ retention policy |
| Distributed deployment: breaker state consistency across multiple openconnector instances | Circuit-breaker state is Redis-backed and shared across instances; all instances see the same breaker state; eventual consistency is acceptable (breaker may toggle briefly) |

## Migration Plan

Spec-only — no runtime migration in this change. When implementation lands:

1. **Schema migrations** (via OR):
   - Add ReliabilityProfile schema to openregister.
   - Add DLQEntry schema to openregister (append-only lifecycle).
   - Add Quota schema to openregister.
   - Extend CallLog schema with reliability-tier columns.

2. **Code changes** (openconnector):
   - Add call-interception middleware to compute idempotency keys, manage retries, populate CallLog.
   - Add circuit-breaker state machine (Redis-backed).
   - Add DLQ enqueueing and replay logic.
   - Add SLA monitoring (Prometheus metrics).
   - Add quota enforcement (sliding-window Redis counters).
   - Add bulkhead concurrency enforcement.
   - Add DLQ UI component (Vue).

3. **Per-adapter changes** (follow-up):
   - Add optional `reliability_profile` reference to Source configuration.
   - Existing adapters remain unchanged; new adapters MAY set a profile.

Down-direction: Remove the reliability-tier columns from CallLog (via migration); drop DLQEntry and Quota schemas; remove call-interception middleware. Existing CallLog entries remain queryable (columns are optional). The spec remains valid; reliability features are simply disabled.

## Open Questions

1. **Idempotency key format**: Is (adapter, endpoint, canonical-payload) sufficient, or should it include the consumer/tenant for isolation? Current spec uses only the call's own properties; cross-tenant collision is impossible but key space is smaller. Confirm scope is right.
2. **DLQ-to-Sentry bridge**: Should openconnector automatically sync DLQ entries to Sentry/Loki for external alerting? Deferred to a follow-up; DLQ dashboard is the primary interface for now.
3. **Per-workflow ReliabilityProfile overrides**: Should pipelinq be able to override a source's profile per orchestrated step? Deferred; profiles are scoped to adapters/globally for now.
4. **Quota hard-limit vs. soft-limit semantics**: REQ-009 specifies hard limits (HTTP 429 on quota exceed). Alternative: soft limits that warn but allow. Confirm hard limits are the right default (they prevent downstream provider overages).
5. **Replay history UI complexity**: Rendering a full DAG of replayed DLQEntries may be overwhelming for large chains. Should we collapse chains or show summary stats? Deferred; start with full chain rendering and simplify based on operator feedback.

## Success Metrics

- [ ] First adapter (StUF, SnapLogic, etc.) ships with a ReliabilityProfile reference and all reliability features work end-to-end.
- [ ] Government tender acceptance test: DLQ capture, manual replay, and SLA compliance monitoring all verified.
- [ ] Compliance audit: Auditor can query CallLog and reconstruct a 1-year replay chain for any source.
- [ ] Operational metric: openconnector `/metrics` endpoint exposes `openconnector_adapter_latency_seconds` and `openconnector_adapter_error_rate` per endpoint.
- [ ] Operator feedback: At least one SRE / integration-engineer persona confirms DLQ replay + circuit-breaker prevented a manual incident recovery.
