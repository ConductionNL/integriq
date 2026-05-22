# Spec: ipaas-reliability

**Status:** proposed
**Scope:** openconnector
**Tier:** feature
**Depends on:** openconnector (CallLog per ADR-003), hydra ADR-022 (apps consume OR abstractions), hydra ADR-031 (schema-declarative business logic), prometheus-metrics (openconnector, for SLA monitoring), openregister (for ReliabilityProfile, DLQEntry, Quota schemas), NEN-ISO/IEC 27001 (audit log retention), Archiefwet (government retention mandates)

## ADDED Requirements

### REQ-001: Deterministic idempotency keys prevent double-effects from retries

Every outbound call gains an idempotency key derived from a deterministic hash of (adapter_id, endpoint_slug, canonical_json(payload)). Idempotency is tracked in Redis at key `oc:idem:{adapter}:{hash}` with a configurable TTL (default 86,400 seconds / 24h, max 604,800 seconds / 7 days). If an identical call is retried within the idempotency window and the original request is not in-flight, openconnector returns the cached response without re-executing the call.

#### Scenario: Deterministic key matches across retries

- **GIVEN** an adapter with ReliabilityProfile `idempotency_strategy = deterministic` and `idempotency_ttl_seconds = 86400`
- **WHEN** the adapter is invoked twice with the same payload targeting the same endpoint
- **THEN** openconnector computes the idempotency key (SHA256 of adapter + endpoint + canonical payload), looks up Redis at `oc:idem:{adapter}:{key}`; on the second call, the key is found, the cached response status and body are returned without re-executing, and the CallLog records two entries (both with the same idempotency_key, one with `retry_attempt = 0`, one with `cached_response = true`)

#### Scenario: In-flight request blocks retry until completion

- **GIVEN** an async source such that a call is still in-flight (first attempt executing)
- **WHEN** a retry is triggered due to transient network failure before the first attempt returns
- **THEN** openconnector sets `in_flight = true` on the Redis entry, waits up to 10 seconds for the original call to complete, and returns the cached result when available; if the original call completes in that window, the retry uses the result; if not, the retry proceeds as normal (not an error)

#### Scenario: Reviewer confirms idempotency is deterministic, not user-supplied

- **GIVEN** any ReliabilityProfile configuration
- **WHEN** inspected
- **THEN** no `idempotency_key_header` field exists and `idempotency_strategy` is one of `deterministic`, `none` (not `client-supplied`); client-supplied keys are a future REQ

### REQ-002: Exponential backoff with jitter retries transient failures

Every outbound call that fails with a status code or error class in the ReliabilityProfile's `retry_on_status[]` or `retry_on_error_classes[]` lists is automatically retried. The retry delay follows the formula `min(initial_delay * 2^attempt, max_delay)` milliseconds, plus jitter. Jitter is computed per the ReliabilityProfile's `retry_jitter` field: `full` = random in [0, delay], `equal` = delay/2 + random in [0, delay/2], `none` = 0. Retries continue until the call succeeds, the retry-attempt count exceeds `retry_max_attempts`, or a non-retryable error is encountered (e.g., 401 Unauthorized).

#### Scenario: Exponential backoff formula is applied correctly

- **GIVEN** a ReliabilityProfile with `retry_initial_delay_ms = 200`, `retry_max_delay_ms = 30000`, `retry_max_attempts = 5`, `retry_jitter = none`
- **WHEN** a call fails with status 503 (in the retry list) and is retried
- **THEN** the delays between attempts are approximately [0ms (initial), 200ms, 400ms, 800ms, 1600ms, 3200ms] (capped at 30,000ms); CallLog records five entries with `retry_attempt = 0, 1, 2, 3, 4`

#### Scenario: Retry-After header overrides computed delay

- **GIVEN** a failed response carrying `Retry-After: 120` (seconds)
- **WHEN** the retry policy is applied
- **THEN** openconnector waits 120 seconds before the next attempt, regardless of the computed exponential delay; the CallLog entry records `retry_delay_ms_override = 120000`

#### Scenario: Non-retryable errors bypass retry

- **GIVEN** a call fails with status 401 (Unauthorized) or 403 (Forbidden)
- **WHEN** the retry policy is consulted
- **THEN** the error is terminal; no retries occur (unless explicitly listed in `retry_on_status[]`); the CallLog records `retry_eligible = false`

### REQ-003: Terminal failures enqueue to the dead-letter queue for recovery

When an outbound call exhausts all retries (or receives a non-retryable error) and the ReliabilityProfile has `dlq_enabled = true`, openconnector writes a DLQEntry capturing the original_payload, original_headers, all attempt metadata, the final error, and timestamps. The entry is assigned `status = pending` and emitted as a domain event `dlq.entry.created`. Operators can later inspect, edit, and replay the entry.

#### Scenario: Failed call is enqueued to DLQ

- **GIVEN** a call to a Salesforce adapter with `dlq_enabled = true` that fails after 5 retries
- **WHEN** the final attempt fails
- **THEN** a DLQEntry is created with `adapter_id`, `source_id`, `original_payload`, `original_headers`, `attempts = 5`, `last_error`, `last_attempt_at`, `status = pending`; a domain event `dlq.entry.created` is emitted with the DLQEntry ID; the caller receives a typed error `DLQEnqueued` (not a generic 500)

#### Scenario: DLQ is disabled; failures are not enqueued

- **GIVEN** a ReliabilityProfile with `dlq_enabled = false`
- **WHEN** a call fails after all retries
- **THEN** no DLQEntry is created; the caller receives the final error (500, timeout, etc.) as normal

#### Scenario: Reviewer confirms DLQ is not a catch-all event sink

- **GIVEN** any successful call
- **WHEN** inspected in the CallLog
- **THEN** it does NOT create a DLQEntry; DLQ is terminal-failure-only (not a side-effect sink)

### REQ-004: Dead-letter queue entries can be replayed after manual inspection and optional editing

An operator (or scheduled replay job) selects one or more DLQEntries in `pending` or `failed` status and triggers replay. Replay re-executes the original (or edited) payload against the original endpoint using the current ReliabilityProfile configuration. On success, the entry transitions to `status = replayed` and a new CallLog entry is created with `dlq_entry_id` and `replay_of` pointing to the original DLQEntry. On failure, the entry remains `pending`, `attempts` is incremented, and a new CallLog entry records the failed replay; the entry is not re-enqueued (DLQ does not nest).

#### Scenario: Single manual replay succeeds

- **GIVEN** a pending DLQEntry for a StUF adapter and an operator clicks "replay"
- **WHEN** the replay executes
- **THEN** the original payload is sent to the original endpoint; if successful, the DLQEntry transitions to `status = replayed`, a new CallLog entry is created with `replay_of = original_dlq_entry_id`, and a domain event `dlq.entry.replayed` is emitted

#### Scenario: Bulk replay with filter

- **GIVEN** a filter "adapter = 'salesforce' AND status = 'pending' AND last_attempt_at > now() - 24h"
- **WHEN** an operator clicks "replay all"
- **THEN** openconnector matches all DLQEntries against the filter, queues each for replay (via OR ScheduledWorkflow or synchronously if ≤ 100 entries), and returns a batch ID; the operator can monitor replay progress via the dashboard

#### Scenario: Replay never duplicates DLQEntry creation

- **GIVEN** a successful replay from a DLQEntry
- **WHEN** the replay completes
- **THEN** the original DLQEntry is updated with `status = replayed`; a new DLQEntry is NOT created (no nesting); the CallLog records the replay with `replay_of` pointing to the original entry

### REQ-005: Edited-then-replayed DLQEntries preserve provenance chains

An operator can edit the payload of a DLQEntry (e.g., to fix a validation error, correct a typo, or adjust a field) before replay. When edited, a new DLQEntry is created with `replay_of = original.id` and the edited payload; the original is marked `status = replayed` without execution. Replay then proceeds against the new entry. The UI renders the full chain (original → edited → replayed) with diffs of payload changes and operator identity.

#### Scenario: Operator edits a malformed payload and replays

- **GIVEN** a DLQEntry with original_payload containing an invalid postal code (5-digit instead of required 6-digit)
- **WHEN** the operator edits the payload in the UI and clicks "save and replay"
- **THEN** a new DLQEntry is created with `replay_of = original.id`, the edited payload, and `status = pending`; the original DLQEntry transitions to `status = replayed` (without actual replay); the UI renders both entries linked in the provenance chain with the payload diff highlighted

#### Scenario: Reviewer confirms edited payloads are validated against adapter schema

- **GIVEN** a DLQEntry edit form
- **WHEN** the operator submits the edited payload
- **THEN** openconnector validates it against the adapter's request schema; if invalid, the edit is rejected with a field-level error message; if valid, the new DLQEntry is created

### REQ-006: Circuit breaker prevents cascading failures to slow or unavailable upstream systems

A circuit breaker is maintained per (adapter, source) pair in Redis. The breaker tracks the rolling error rate over the past `circuit_breaker_window_seconds` (default 60). If the error rate exceeds `circuit_breaker_threshold` (default 0.5 = 50%) AND at least `circuit_breaker_min_calls` (default 20) have been recorded in the window, the breaker transitions to `open` state. All subsequent calls fast-fail with a typed `CircuitOpen` error for the next `circuit_breaker_open_seconds` (default 30). After this interval, the breaker transitions to `half-open` and allows exactly one test call; if successful, the breaker closes; if it fails, the breaker re-opens. State transitions emit domain events `circuit.state.changed`.

#### Scenario: Circuit breaker trips on error-rate threshold

- **GIVEN** a ReliabilityProfile with `circuit_breaker_threshold = 0.5`, `circuit_breaker_window_seconds = 60`, `circuit_breaker_min_calls = 20`
- **WHEN** the rolling error rate in the past 60 seconds exceeds 50% (e.g., 12 failures out of 24 calls total) and ≥ 20 calls have been recorded
- **THEN** the breaker state transitions from `closed` to `open`; a domain event `circuit.state.changed` is emitted; all subsequent calls immediately return `CircuitOpen` error (fast-fail, no retry)

#### Scenario: Half-open state allows one test call

- **GIVEN** a breaker in `open` state for 30 seconds
- **WHEN** the `circuit_breaker_open_seconds` interval elapses
- **THEN** the breaker transitions to `half-open` state; the next call is allowed to execute (not fast-failed); if it succeeds, the breaker transitions to `closed` and subsequent calls proceed normally; if it fails, the breaker re-opens

#### Scenario: Reviewer confirms breaker state is per-(adapter, source), not per-endpoint

- **GIVEN** two endpoints on the same Salesforce source (Account, Contact)
- **WHEN** the Account endpoint is slow but Contact is healthy
- **THEN** the circuit breaker is per-source, not per-endpoint; if Contact has a healthy error rate, the breaker stays closed and Account calls proceed normally (not blocked); per-endpoint breakers are a future REQ

### REQ-007: SLA monitoring exposes per-endpoint latency and error-rate to Prometheus and surfaces violations

When a ReliabilityProfile has `sla_target_p95_ms` and/or `sla_target_error_rate` set, openconnector emits Prometheus histogram metrics for every endpoint: `openconnector_adapter_latency_seconds` (per-endpoint, with buckets for p50/p95/p99) and `openconnector_adapter_error_rate` (rolling 5-minute rate). Violations (when p95 exceeds the target or error rate exceeds the target) are recorded as SLA violation events `sla.violation` and surfaced on the openconnector dashboard with severity tiers: warning at the threshold, critical at 1.5×, page at 2×.

#### Scenario: SLA target triggers dashboard alert

- **GIVEN** a ReliabilityProfile for StUF with `sla_target_p95_ms = 1000` (1 second p95 latency)
- **WHEN** the rolling 5-minute p95 latency reaches 1200ms (above the target)
- **THEN** openconnector emits a `sla.violation` domain event with severity `warning`; the dashboard displays a warning tile; at 1500ms (1.5×), severity escalates to `critical`; at 2000ms (2×), to `page`

#### Scenario: Optional SLA targets do not trigger monitoring if absent

- **GIVEN** a ReliabilityProfile with no `sla_target_p95_ms` or `sla_target_error_rate` set
- **WHEN** latency and error rates are recorded
- **THEN** no SLA violation events are emitted; the metrics are still recorded to Prometheus but no dashboard alerts surface

#### Scenario: Reviewer confirms SLA metrics appear on /metrics endpoint

- **GIVEN** an openconnector instance with SLA-enabled adapters
- **WHEN** `GET /metrics` is called
- **THEN** the response includes `openconnector_adapter_latency_seconds{adapter="...",endpoint="...",quantile="0.95"}` and `openconnector_adapter_error_rate{adapter="...",endpoint="..."}` metrics in Prometheus format

### REQ-008: Immutable audit log of every call is recorded with full retry/breach/quota context

Every outbound call (success, retry, failure, breaker fast-fail, DLQ enqueue, replay) is recorded in the CallLog with adapter_id, source_id, endpoint, request_hash, response_status, duration_ms, idempotency_key, retry_attempt, breaker_state_at_call, dlq_entry_id, consumer_id, quota_remaining, correlation_id, and timestamp. CallLog entries are append-only and immutable. Retention is configurable via openregister's audit-trail-immutable abstraction (default 365 days, max 2555 days = 7 years per Archiefwet compliance).

#### Scenario: CallLog captures all retry attempts with attempt count

- **GIVEN** a call that fails twice and succeeds on the third attempt
- **WHEN** inspected in the CallLog
- **THEN** three CallLog entries exist for the same correlation_id with `retry_attempt = 0, 1, 2`; each records the response_status (failure, failure, success)

#### Scenario: CallLog includes idempotency cache hit

- **GIVEN** a cached idempotent response returned from Redis
- **WHEN** the CallLog entry is written
- **THEN** it includes `idempotency_key`, `cached_response = true`, and `response_status` from the cache

#### Scenario: Reviewer confirms CallLog is append-only and immutable

- **GIVEN** any CallLog entry
- **WHEN** queried via API or database
- **THEN** it carries a creation_timestamp and no update_timestamp (immutable); no UPDATE or DELETE operations are allowed on the table; retention is managed by expiry job, not by application mutation

### REQ-009: Consumer quotas prevent any single workflow from monopolising connection pools or downstream rate budgets

Quotas are stored as openregister Quota objects with scope (per_adapter, per_source, per_api_key, per_tenant), period (minute, hour, day), and limit. When a call arrives, openconnector increments a sliding-window counter in Redis for the (consumer, scope, period) tuple. If current+1 > limit, the call is rejected with HTTP 429 Too Many Requests and a `Retry-After` header indicating seconds until the window rolls. If current+1 <= limit, the call is allowed and the quota_remaining value is recorded in the CallLog.

#### Scenario: Sliding-window quota enforcement

- **GIVEN** a Quota with `scope = per_api_key`, `consumer_id = 'api-key-xyz'`, `period = minute`, `limit = 100`
- **WHEN** 99 calls have been recorded in the past 60 seconds and a 100th call arrives
- **THEN** the call is allowed; `quota_remaining = 0` is recorded in CallLog
- **WHEN** a 101st call arrives within the same 60-second window
- **THEN** the call is rejected with HTTP 429; `Retry-After: <seconds-until-oldest-call-expires>` header is set (e.g., if the oldest call was 45 seconds ago, `Retry-After: 15`)

#### Scenario: Quota resets when window rolls

- **GIVEN** a Quota with `period = minute` and 100 calls recorded
- **WHEN** 60 seconds pass
- **THEN** Redis auto-expiry removes the old sliding-window entries; the next call increments a fresh counter starting from 1

#### Scenario: Reviewer confirms X-RateLimit-* headers are set

- **GIVEN** any response from an openconnector endpoint subject to a quota
- **WHEN** inspected
- **THEN** headers include `X-RateLimit-Limit: <quota-limit>`, `X-RateLimit-Remaining: <quota-remaining>`, `X-RateLimit-Reset: <unix-timestamp>`

### REQ-010: Reliability profile inheritance allows per-endpoint overrides with fallback to adapter and global scope

A ReliabilityProfile can be defined at three scopes: global (app-wide default), adapter (per-adapter), and endpoint (per-specific endpoint). When a call is made, the effective profile is determined by precedence: endpoint overrides adapter, which overrides global. Unset fields in an override fall back to the next level. The effective profile (as a JSON snapshot) is recorded in the CallLog for auditability.

#### Scenario: Endpoint-specific override takes precedence

- **GIVEN** a global ReliabilityProfile with `retry_max_attempts = 5` and an endpoint-specific override with `retry_max_attempts = 10`
- **WHEN** the endpoint is called and fails
- **THEN** the endpoint-specific profile is used; the call is retried up to 10 times, not 5

#### Scenario: Unset override fields fall back to next level

- **GIVEN** an adapter-level ReliabilityProfile with `retry_max_delay_ms = 30000` and an endpoint-level override with only `idempotency_strategy` set (no retry fields)
- **WHEN** the endpoint is called
- **THEN** `retry_max_delay_ms` is inherited from the adapter profile (fallback); `idempotency_strategy` is used from the endpoint override

#### Scenario: CallLog records effective profile snapshot

- **GIVEN** a call using merged/overridden ReliabilityProfile
- **WHEN** the CallLog entry is created
- **THEN** it includes `effective_reliability_profile_snapshot` as a JSON object showing the final merged values

### REQ-011: Correlation IDs are propagated end-to-end from inbound request to all outbound calls

If an inbound request to openconnector carries an `X-Correlation-Id` or W3C `traceparent` header, openconnector propagates the correlation ID to every outbound adapter call by attaching it as a header. If the header is absent, openconnector generates a UUIDv4. The correlation ID is stamped on every CallLog entry, included in every DLQEntry, and exposed in every error response so operators can trace a single business operation across the call graph.

#### Scenario: Correlation ID is propagated to outbound calls

- **GIVEN** an inbound request with `X-Correlation-Id: 123e4567-e89b-12d3-a456-426614174000`
- **WHEN** openconnector makes outbound calls to multiple adapters
- **THEN** every outbound call includes the header `X-Correlation-Id: 123e4567-e89b-12d3-a456-426614174000`; every CallLog entry carries the same correlation_id

#### Scenario: Missing correlation ID is generated

- **GIVEN** an inbound request without `X-Correlation-Id` or `traceparent`
- **WHEN** openconnector processes the request
- **THEN** a new UUIDv4 is generated and used as the correlation_id for all downstream calls and CallLog entries

#### Scenario: Error response includes correlation ID for debugging

- **GIVEN** a call fails and is enqueued to DLQ
- **WHEN** the error response is returned to the caller
- **THEN** the response includes `X-Correlation-Id` header and the error body includes a `correlationId` field so the operator can later look up the DLQEntry

### REQ-012: Dead-letter queue entries expire after a configurable retention period with automatic cleanup

A ReliabilityProfile specifies `dlq_max_age_days` (default 30). A daily housekeeping job scans all DLQEntries with `status = pending` or `status = failed` and `created_at < now() - dlq_max_age_days`. These entries transition to `status = expired`, their original_payload body is purged (keeping metadata: adapter_id, last_error, attempts, created_at for audit), and a domain event `dlq.entry.expired` is emitted. Operators CAN override the expiry for specific entries if longer retention is needed.

#### Scenario: Expired DLQ entry is automatically cleaned

- **GIVEN** a DLQEntry with `created_at` 31 days ago and `dlq_max_age_days = 30` in the ReliabilityProfile
- **WHEN** the daily housekeeping job runs
- **THEN** the entry transitions to `status = expired`, the original_payload is deleted (freed from storage), and a `dlq.entry.expired` event is emitted

#### Scenario: Expired entry retains metadata for audit

- **GIVEN** an expired DLQEntry
- **WHEN** queried via the API
- **THEN** the entry includes `adapter_id`, `last_error`, `attempts`, `created_at`, `status = expired` but original_payload is null or empty

#### Scenario: Operator can override expiry for specific entries

- **GIVEN** an expired DLQEntry that the operator needs to keep for compliance audit
- **WHEN** the operator clicks "retain indefinitely"
- **THEN** the entry is marked with `retain_indefinitely = true`; the housekeeping job skips it on future runs

### REQ-013: Bulkhead isolation per adapter prevents one slow adapter from starving the connection pool

A ReliabilityProfile specifies `concurrency_limit` (default 50 in-flight calls) per adapter. When this limit is reached, subsequent calls to that adapter are immediately rejected with a typed `BulkheadFull` error (not queued, not retried, not enqueued to DLQ unless `dlq_enabled = true`). The bulkhead limit protects the shared thread/connection pool from starvation; other adapters are unaffected. The CallLog records `bulkhead_rejected = true` and the call does not consume retry attempts.

#### Scenario: Bulkhead limit is enforced per adapter

- **GIVEN** a ReliabilityProfile with `concurrency_limit = 50` and 50 in-flight calls to Salesforce
- **WHEN** a 51st call to Salesforce arrives
- **THEN** it is rejected with `BulkheadFull` error immediately (no retry, no execution); the CallLog records `bulkhead_rejected = true`
- **WHEN** a call to a different adapter (StUF) arrives
- **THEN** it is allowed to execute normally (unaffected by Salesforce's bulkhead)

#### Scenario: Bulkhead-rejected calls can be enqueued to DLQ

- **GIVEN** a ReliabilityProfile with `dlq_enabled = true` and a bulkhead overflow
- **WHEN** the call is rejected for bulkhead
- **THEN** a DLQEntry is created (no retries, direct DLQ enqueue) with `dlq_entry.error = 'BulkheadFull'` so operators know it was a capacity issue, not a transient failure

### REQ-014: Replay provenance chains are queryable and exportable for compliance audits

When a DLQEntry is replayed (manually or edited), a new DLQEntry is created with `replay_of = original.id`. This creates a directed acyclic graph (DAG) of DLQEntries representing the full recovery journey. The UI renders the chain chronologically with operator identity, timestamp, and diff of edited payloads at each step. The entire chain can be exported as JSON for compliance archives.

#### Scenario: Replay chain is rendered with diffs

- **GIVEN** a DLQEntry that was edited by Operator Alice, replayed, failed again, edited by Operator Bob, and replayed successfully
- **WHEN** the entry is inspected in the UI
- **THEN** the chain displays as: original → [Alice's edit + 2024-01-15 14:32] → [Bob's edit + 2024-01-15 15:18] → [successful replay] with payload diffs highlighted at each step

#### Scenario: Replay chain can be exported as JSON for audit archive

- **GIVEN** a DLQEntry with a multi-step replay chain
- **WHEN** the operator clicks "export provenance chain"
- **THEN** a JSON file is downloaded with the full DAG structure: each DLQEntry linked via `replay_of`, all metadata, all payloads, and all operator audit trails; the file is timestamped and suitable for archival

#### Scenario: Reviewer confirms chain is acyclic and queryable

- **GIVEN** any DLQEntry
- **WHEN** the `replay_of` reference is followed to its predecessor and back recursively
- **THEN** the chain is acyclic (no cycles); the entire chain can be queried in one API call via `GET /dlq-entries/{id}/chain`
