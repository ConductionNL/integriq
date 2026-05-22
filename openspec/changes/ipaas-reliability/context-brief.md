---
status: draft
spec: ipaas-reliability
app: openconnector
owner: openconnector-core
depends_on:
  - openconnector-base
---

# iPaaS Reliability

## Purpose

Enterprise iPaaS platforms (MuleSoft, Boomi, Workato, Tibco Cloud Integration, SnapLogic) differentiate themselves from naive HTTP-glue libraries by delivering reliability primitives end-to-end: idempotency, retries, dead-letter queues, message replay, circuit-breaking, SLA monitoring, audit logging, and consumer quotas. Without these, every integration becomes a fragile cron-job whose failures are invisible until a downstream report comes up empty, a finance reconciliation misses an invoice, or a citizen's BRP mutation never reaches the zaaksysteem. openconnector today provides solid request/response plumbing — adapters, sources, synchronizations, schema mappings — but lacks the operational guarantees needed to displace these commercial iPaaS suites in tender-driven government and enterprise procurement, where reliability features are scored as hard pass/fail criteria.

This spec adds the full reliability tier as first-class openconnector primitives, configurable per adapter and per source. Every outbound call gains an idempotency key derived from a deterministic hash of (adapter, endpoint, payload-canonical-form), preventing double-effects when retries fire after a partial response. Every call participates in an exponential-backoff retry policy with configurable jitter to prevent thundering herds against recovering downstream systems. Every terminal failure lands in a per-adapter dead-letter queue (DLQ) where it can be inspected, edited, and replayed — operators are no longer forced to choose between losing data and writing custom recovery scripts. Circuit breakers protect both openconnector and downstream systems from cascading failure: when a source's error rate crosses a configured threshold, the breaker trips and subsequent calls fast-fail with a typed error rather than piling up requests against a struggling backend.

SLA monitoring exposes per-endpoint p50/p95/p99 latency and rolling error-rate to Prometheus and surfaces violations to the openconnector dashboard with severity tiers (warning at threshold, critical at 1.5× threshold, page at 2×). Every call — successful or not, fast-failed or retried, replayed from DLQ or fresh — is recorded in an immutable, append-only audit log retained for the configured period (default 1 year, configurable up to 7 years for government use under the Archiefwet). Consumer quotas (per API key, per tenant, or per upstream system) prevent any single workflow from monopolising a connection pool or blowing through a downstream provider's rate budget; quota exhaustion returns HTTP 429 with accurate `Retry-After` hints rather than silent drops.

The reliability tier is opt-in per adapter via configuration; existing adapters work unchanged but gain the full feature set with a single `reliability_profile` reference. Sensible defaults (5 retries, exponential 200ms–30s, 24h idempotency TTL, 50% error-rate breaker trip) make the feature genuinely no-config for most integrations, while every parameter is overridable for the high-volume or compliance-sensitive cases that need bespoke tuning.

## Data Model

- **ReliabilityProfile** (openregister schema): id, slug, retry_max_attempts (default 5), retry_initial_delay_ms (default 200), retry_max_delay_ms (default 30_000), retry_jitter (full|equal|none), retry_on_status[] (default [408,429,500,502,503,504]), retry_on_error_classes[], idempotency_strategy (deterministic|client-supplied|none), idempotency_ttl_seconds (default 86_400), circuit_breaker_threshold (default 0.5), circuit_breaker_window_seconds (default 60), circuit_breaker_min_calls (default 20), circuit_breaker_open_seconds (default 30), dlq_enabled (bool), dlq_max_age_days (default 30), sla_target_p95_ms, sla_target_error_rate.
- **IdempotencyRecord** (Redis): key = `oc:idem:{adapter}:{hash}`, value = {first_seen, response_status, response_body_ref, in_flight (bool)}, TTL = profile.idempotency_ttl_seconds.
- **DLQEntry** (openregister, append-only): id, adapter_id, source_id, original_payload, original_headers, attempts, last_error, last_attempt_at, status (pending|replayed|discarded|expired), replay_of (DLQEntry ref).
- **CircuitBreakerState** (Redis): per (adapter, source) — state (closed|open|half-open), failures, successes, opened_at, half_open_test_in_flight.
- **CallLog** (openregister, append-only — extension of base CallLog): + idempotency_key, retry_attempt, breaker_state_at_call, dlq_entry_id (nullable), consumer_id, quota_remaining.
- **Quota** (openregister): id, consumer_id, scope (per_adapter|per_source|global), period (minute|hour|day), limit, current, reset_at.

## Requirements

### REQ-001: Deterministic idempotency keys

- **GIVEN** an adapter with ReliabilityProfile `idempotency_strategy = deterministic`
- **WHEN** the adapter is invoked with payload P targeting endpoint E
- **THEN** openconnector computes `idempotency_key = SHA256(adapter_id || endpoint || canonical_json(P))`, looks up Redis at `oc:idem:{adapter}:{key}`; if an entry exists and is not in-flight, returns the cached response; if in-flight, waits up to 10s for completion; if absent, marks in-flight, executes the call, stores the result, and returns it

### REQ-002: Exponential backoff with jitter

- **GIVEN** an outbound call fails with a status code or error class in `retry_on_*` lists
- **WHEN** the retry policy is invoked
- **THEN** openconnector waits `min(initial_delay * 2^attempt, max_delay)` milliseconds plus jitter (full = random[0, delay], equal = delay/2 + random[0, delay/2], none = 0), retries up to `retry_max_attempts`, and emits a CallLog entry per attempt with `retry_attempt` incremented; `Retry-After` headers (seconds or HTTP-date) override the computed delay when present

### REQ-003: Dead-letter queue on terminal failure

- **GIVEN** an outbound call has exhausted all retries and `dlq_enabled = true`
- **WHEN** the final attempt fails
- **THEN** openconnector writes a DLQEntry capturing original_payload, original_headers, attempts, last_error, last_attempt_at, sets status = pending, emits a `dlq.entry.created` domain event, and returns a typed `DLQEnqueued` error to the caller

### REQ-004: Manual and bulk DLQ replay

- **GIVEN** one or more pending DLQEntries
- **WHEN** an operator triggers replay (single entry via UI, bulk via filter, or scheduled retry)
- **THEN** openconnector re-executes the original payload against the original endpoint using the current adapter configuration, records a new CallLog with `dlq_entry_id` and `replay_of` linking back to the DLQEntry, updates the DLQEntry status to `replayed` on success or leaves it `pending` on continued failure (incrementing attempts); replay never duplicates DLQEntries

### REQ-005: Edit-then-replay for malformed payloads

- **GIVEN** a DLQEntry whose original payload was rejected because of a schema or validation error
- **WHEN** an operator edits the payload in the DLQ UI and submits replay
- **THEN** openconnector validates the edited payload against the adapter's request schema, creates a new DLQEntry with `replay_of = original.id` and the edited payload, marks the original as `replayed`, and proceeds with the replay attempt

### REQ-006: Circuit breaker per (adapter, source)

- **GIVEN** the rolling error rate within `circuit_breaker_window_seconds` exceeds `circuit_breaker_threshold` and total calls in window >= `circuit_breaker_min_calls`
- **WHEN** the next call arrives
- **THEN** openconnector trips the breaker to `open`, fast-fails all subsequent calls with a typed `CircuitOpen` error for `circuit_breaker_open_seconds`, then transitions to `half-open` and allows exactly one test call; success returns to `closed`, failure re-opens for another window; breaker state changes emit a `circuit.state.changed` domain event

### REQ-007: SLA monitoring and surfacing

- **GIVEN** a ReliabilityProfile with `sla_target_p95_ms` and `sla_target_error_rate` set
- **WHEN** the rolling 5-minute window p95 latency or error rate exceeds either target
- **THEN** openconnector exposes per-(adapter,endpoint) histogram metrics on `/metrics` (Prometheus format), surfaces violations on the openconnector dashboard with severity (warning at threshold, critical at 1.5x threshold), and emits a `sla.violation` domain event

### REQ-008: Immutable audit log of every call

- **GIVEN** any outbound call (success, retry, failure, breaker fast-fail, DLQ enqueue, replay)
- **WHEN** the call completes (or fast-fails)
- **THEN** openconnector writes a CallLog entry with adapter_id, source_id, endpoint, request_hash, response_status, duration_ms, idempotency_key, retry_attempt, breaker_state_at_call, dlq_entry_id, consumer_id, quota_remaining, and correlation_id; CallLog entries are append-only and retained for the configured period (default 365 days, max 7 years)

### REQ-009: Consumer quotas with sliding-window enforcement

- **GIVEN** a Quota configured for consumer C with period=minute and limit=L
- **WHEN** consumer C invokes any covered adapter
- **THEN** openconnector counts requests in the trailing 60s using a Redis sliding-window counter; if current+1 > limit, returns HTTP 429 with `Retry-After: <seconds-until-window-rolls>` and `X-RateLimit-*` headers; if current+1 <= limit, allows the call and increments the counter

### REQ-010: Reliability profile inheritance and override

- **GIVEN** a default ReliabilityProfile at adapter scope and an endpoint-specific override
- **WHEN** that endpoint is called
- **THEN** the override applies for retries, idempotency, circuit-breaker, and SLA targets; unset override fields fall back to adapter scope, then global default; the effective profile is recorded in the CallLog as a JSON snapshot

### REQ-011: Correlation IDs propagated end-to-end

- **GIVEN** any inbound request to openconnector carrying `X-Correlation-Id` or W3C `traceparent`
- **WHEN** that request triggers one or more outbound adapter calls
- **THEN** openconnector propagates the correlation ID (or generates a UUIDv4 if absent), attaches it as a header to every outbound call, stamps it on every CallLog entry, includes it in every DLQEntry, and exposes it in every error response so operators can trace a single business operation across the full call graph

### REQ-012: DLQ retention and automatic expiry

- **GIVEN** a DLQEntry with `dlq_max_age_days = 30` and status = pending for 31 days
- **WHEN** the daily DLQ-housekeeping job runs
- **THEN** openconnector transitions the entry to status = expired, emits a `dlq.entry.expired` event, removes the original_payload body but keeps the metadata (adapter_id, last_error, attempts) for audit, and notifies the configured operations channel; expired entries cannot be replayed without an explicit operator override

### REQ-013: Bulkhead isolation per adapter

- **GIVEN** a single openconnector instance hosting many adapters sharing a thread/connection pool
- **WHEN** one adapter starts blocking on a slow downstream
- **THEN** openconnector enforces a per-adapter concurrency cap (default 50 in-flight calls, configurable per ReliabilityProfile), rejects further calls to that adapter with a typed `BulkheadFull` error, and protects the remaining adapters from pool starvation; capped calls bypass retry and go directly to DLQ if `dlq_enabled = true`

### REQ-014: Replay history and provenance chain

- **GIVEN** a DLQEntry that has been replayed N times via various operator actions (some succeeded after edit, some failed again)
- **WHEN** an auditor inspects the entry
- **THEN** openconnector renders the full replay chain (each new DLQEntry links to its `replay_of` predecessor) as a directed acyclic graph; the UI exposes the diff between consecutive replay payloads, the operator identity, and the timestamp of each replay attempt; chain export is available as JSON for compliance archives

## Standards

- **RFC 7231** — HTTP/1.1 semantics (Retry-After, status codes)
- **RFC 6585** — HTTP 429 Too Many Requests
- **draft-ietf-httpapi-idempotency-key-header** — Idempotency-Key header convention
- **OpenTelemetry** — distributed tracing for correlation IDs
- **Prometheus exposition format** — `/metrics` endpoint
- **NEN-ISO/IEC 27001** — audit log retention requirements
- **NORA** (Nederlandse Overheid Referentie Architectuur) — logging and monitoring patterns
- **Microsoft Azure Architecture Center: Retry pattern, Circuit Breaker pattern** — naming and semantics aligned

## Cross-app Integration

- **openconnector adapters** — every adapter gains a `reliability_profile` reference; existing adapters opt in by setting one and inherit the full reliability tier
- **auth-protocol-suite** — reliability features interact cleanly with token expiry (a 401 triggers token refresh, not a retry; persistent 401 trips the breaker)
- **openregister** — ReliabilityProfile, DLQEntry, CallLog, and Quota are openregister schemas in the `openconnector` register, queryable through the standard object API
- **pipelinq** — orchestrated workflows surface DLQEntries as failed-task states and offer one-click replay from the workflow timeline; the workflow engine itself uses ReliabilityProfile semantics for step-level retry
- **mydash** — dashboards visualise SLA compliance, error-rate trends, DLQ depth per adapter, and breaker state changes as time-series
- **docudesk / decidesk / procest** — depend on circuit-breaker + retry behaviour for their integrations with eIDAS, signing providers, case-management systems, and BRP backends
- **hydra** — emits `dlq.entry.created`, `circuit.state.changed`, `sla.violation`, `dlq.entry.expired`, `dlq.entry.replayed` events on the shared event bus so cross-app subscribers can react
- **stuf-bg-zkn-bg-koppelvlak** — applies retries to StUF transport-level errors but not to permanent StUF fauts; DLQ captures both for operator triage

## Target Users

- **Integration engineers** running mission-critical synchronisations who need replay-on-failure rather than silent drops; today's manual recovery scripts (re-fetch from log, edit JSON, curl back to source) are exactly what the DLQ + edit-replay flow obsoletes
- **Operations / SRE teams** monitoring SLA compliance against contractual targets with upstream providers, with breaker state, error rates, and latency histograms exposed via Prometheus for Grafana/Alertmanager pipelines they already run
- **CISO / security officers** requiring immutable audit logs of all outbound integration traffic, retained for the period mandated by Archiefwet or sector-specific regulation
- **Government procurement teams** scoring openconnector against enterprise iPaaS tender requirements (DLQ, circuit-breaker, SLA tracking, idempotency, audit logging are standard checkboxes appearing in nearly every recent municipality and waterschap tender)
- **Platform tenants** in shared openconnector deployments who need per-tenant quotas and isolation so one noisy tenant cannot exhaust the shared connection pool to a downstream provider
- **Adapter authors** who want best-practice reliability behaviour without writing retry/breaker code per integration, freeing them to focus on payload mapping and business semantics
- **Workflow orchestrators (pipelinq, decidesk)** that need first-class failed-step visibility and one-click retry in their UIs, backed by the same DLQ store
- **Compliance auditors** running periodic reviews who want a single queryable audit log rather than chasing per-adapter Sentry/Loki/file-log fragments
