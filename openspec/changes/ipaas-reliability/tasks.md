# Tasks — iPaaS Reliability

> **Spec-only change.** Per `proposal.md` Scope, implementation code is deliberately out of scope here. The tasks below describe the work that follows this spec through the standard `opsx-apply` implementation cycle — they are recorded now for impact visibility, dependency planning, and follow-up tracking.

## 0. Deduplication Check

### Task 0.1: Confirm no ipaas-reliability spec or conflicting reliability features already exist

- **spec_ref**: all existing specs, design docs, architecture ADRs
- **files**: `openspec/specs/**`, `openspec/changes/**`, `openspec/architecture/`, `lib/Service/`, `tests/`
- **acceptance_criteria**:
  - GIVEN `openspec/specs/` and `openspec/changes/` WHEN scanned THEN no existing `ipaas-reliability`, `reliability-features`, `retries-dlq`, or similar spec exists.
  - GIVEN openconnector `lib/Service/` and `lib/Entity/` WHEN scanned THEN no existing `ReliabilityProfile`, `DLQEntry`, `CircuitBreaker`, `IdempotencyRecord` classes exist (confirms greenfield implementation space).
  - GIVEN existing openconnector CallLog WHEN inspected THEN no reliability-related columns (`idempotency_key`, `retry_attempt`, `dlq_entry_id`) exist yet; extension is new.
  - GIVEN existing openconnector configuration WHEN scanned THEN no per-adapter `retry_policy` or `circuit_breaker` declarations exist (confirms this is the first reliability contract).
- [ ] Implement
- [ ] Test

## 1. Spec foundation (this change)

### Task 1.1: Author ipaas-reliability spec with all 14 requirements

- **spec_ref**: `openspec/changes/ipaas-reliability/specs/ipaas-reliability/spec.md`
- **files**: same path
- **acceptance_criteria**:
  - GIVEN the spec file WHEN opened THEN it carries `Status: proposed` / `Scope: openconnector` / `Tier: feature` header with all dependency citations.
  - GIVEN the spec WHEN scanned THEN all 14 requirements (REQ-001 through REQ-014) are present with RFC 2119 MUST/SHOULD/MAY keywords.
  - GIVEN each requirement WHEN inspected THEN at least one `#### Scenario:` block with GIVEN/WHEN/THEN exists (exactly 4 hashtags).
  - GIVEN the spec WHEN scanned THEN it explicitly cites ADR-003 (immutable CallLog), ADR-022 (OR abstractions), ADR-031 (declarative config), and relevant standards (RFC 7231, Archiefwet, NEN-ISO/IEC 27001).
  - GIVEN REQ-001–REQ-005 WHEN inspected THEN they cover idempotency, retries, DLQ capture, DLQ replay, and edited-replay provenance (foundation tier).
  - GIVEN REQ-006–REQ-009 WHEN inspected THEN they cover circuit-breaker, SLA monitoring, audit logging, and quotas (operational tier).
  - GIVEN REQ-010–REQ-014 WHEN inspected THEN they cover profile inheritance, correlation IDs, DLQ retention, bulkhead, and provenance chains (infrastructure tier).
- [x] Implement
- [ ] Test (`openspec validate` clean)

### Task 1.2: Author proposal.md and design.md for the change envelope

- **spec_ref**: change root
- **files**: `proposal.md`, `design.md`
- **acceptance_criteria**:
  - GIVEN `proposal.md` WHEN inspected THEN it identifies affected projects (openconnector primary; openregister, mydash, pipelinq, hydra as consumers; no source changes required for them), includes Motivation, Scope, Approach, Cross-Project Dependencies, Risks with mitigations, Rollback Strategy, Open Questions, and Success Criteria.
  - GIVEN `design.md` WHEN inspected THEN it includes Goals, Non-Goals, 14 Design Decisions (D1–D14) with alternatives-considered, Reuse Analysis table, Declarative-vs-imperative table per ADR-031 enforcement, Seed Data section (example ReliabilityProfile JSON), Migration Plan (schema + code + per-adapter follow-up), Open Questions, and Success Metrics.
  - GIVEN the Reuse Analysis WHEN scanned THEN every reliability capability is mapped to an existing abstraction (CallLog, OR audit-trail-immutable, Redis, Prometheus, event-bus, openregister); no parallel infrastructure is proposed.
  - GIVEN Design Decisions WHEN reviewed THEN each decision documents the chosen approach, the rationale, and a considered alternative with rejection reason.
- [x] Implement
- [ ] Test (peer review — SRE / integration-engineer personas read the design and confirm it's operationally sound)

## 2. OpenSpec artifact validation (this change)

### Task 2.1: Validate all artifacts pass `openspec validate`

- **spec_ref**: spec validator tool (part of openspec CLI)
- **files**: `openspec/changes/ipaas-reliability/` (proposal.md, design.md, specs/, tasks.md)
- **acceptance_criteria**:
  - GIVEN the change folder WHEN `openspec validate ipaas-reliability` is run THEN exit code is 0 (no errors).
  - GIVEN the spec file WHEN scanned THEN:
    - All REQ-XXX IDs are unique and sequential (REQ-001 through REQ-014).
    - Every REQ has a status (new for this change: all `ADDED`).
    - Every REQ has ≥1 Scenario block with GIVEN/WHEN/THEN.
    - All links to ADRs and external specs resolve or are documented in Open Questions.
  - GIVEN proposal.md and design.md WHEN scanned THEN front-matter headers are present and correct.
  - GIVEN tasks.md WHEN scanned THEN all task acceptance criteria are clear and testable.
- [ ] Implement (run validator)
- [ ] Test

## 3. Implementation cycle (per `opsx-apply`)

The following tasks are recorded for the implementation cycle; they do not block spec acceptance but are tracked here for visibility and planning.

### Task 3.1: Implement ReliabilityProfile schema and storage in openregister

- **spec_ref**: REQ-001 through REQ-010, REQ-013
- **files**: openconnector (new OR schema definition), openregister (schema record type)
- **acceptance_criteria**:
  - GIVEN a ReliabilityProfile WHEN created in openregister THEN it carries all fields: id, slug, retry_max_attempts, retry_initial_delay_ms, retry_max_delay_ms, retry_jitter, retry_on_status[], retry_on_error_classes[], idempotency_strategy, idempotency_ttl_seconds, circuit_breaker_threshold, circuit_breaker_window_seconds, circuit_breaker_min_calls, circuit_breaker_open_seconds, dlq_enabled, dlq_max_age_days, sla_target_p95_ms, sla_target_error_rate, concurrency_limit.
  - GIVEN a ReliabilityProfile WHEN queried THEN sensible defaults are applied (5 retries, 200ms init/30s max backoff, 24h idempotency TTL, 50% breaker threshold, 30-day DLQ retention).
  - GIVEN openconnector `Source` record WHEN inspected THEN it carries an optional `reliability_profile_slug` reference; existing Sources without this field use the global default or adapter-level profile.
- [ ] Implement (migration + schema)
- [ ] Test (PHPUnit: profile creation, field validation, defaults; integration: profile can be referenced from Source)

### Task 3.2: Implement idempotency key computation and Redis caching

- **spec_ref**: REQ-001
- **files**: openconnector (call-interception middleware, IdempotencyRecord handler)
- **acceptance_criteria**:
  - GIVEN a call with payload and endpoint WHEN intercepted THEN idempotency key is computed as SHA256(adapter_id || endpoint_slug || canonical_json(payload)); the result is deterministic (same payload → same key every time).
  - GIVEN an idempotency key WHEN looked up in Redis at `oc:idem:{adapter}:{key}` THEN:
    - If found and not in-flight: cached response is returned with `cached_response = true` in CallLog.
    - If found and in-flight: middleware waits up to 10 seconds for the original call to complete, then returns the cached result.
    - If not found: middleware sets `in_flight = true`, executes the call, stores the result with the configured TTL, and marks `in_flight = false`.
  - GIVEN a ReliabilityProfile with `idempotency_strategy = none` WHEN applied THEN no idempotency caching occurs; every call executes normally.
- [ ] Implement (idempotency middleware)
- [ ] Test (PHPUnit: key computation, cache hit/miss, in-flight waiting; integration: test end-to-end with a mock adapter)

### Task 3.3: Implement exponential backoff retry logic with jitter

- **spec_ref**: REQ-002
- **files**: openconnector (retry middleware, backoff formula)
- **acceptance_criteria**:
  - GIVEN a failed call with response status in `retry_on_status[]` WHEN the retry policy is consulted THEN:
    - Delay is computed as `min(initial_delay * 2^attempt, max_delay)` milliseconds.
    - Jitter is applied per ReliabilityProfile `retry_jitter` field: full (random [0, delay]), equal (delay/2 + random [0, delay/2]), or none (0).
    - Middleware waits for the computed delay, then re-executes the call.
  - GIVEN a Retry-After header WHEN present in the response THEN it overrides the computed delay; the header is parsed as seconds (decimal) or HTTP-date and respected.
  - GIVEN a non-retryable error (401, 403) WHEN encountered THEN no retries occur unless explicitly listed in `retry_on_error_classes[]`.
  - GIVEN `retry_max_attempts = 5` WHEN the call fails THEN at most 5 retries occur; each CallLog entry increments `retry_attempt = 0, 1, 2, ...`.
- [ ] Implement (retry middleware + backoff formula)
- [ ] Test (PHPUnit: backoff formula for all jitter types, Retry-After parsing, non-retryable errors; integration: test retry scenario with mock failing endpoint)

### Task 3.4: Implement dead-letter queue (DLQEntry schema and enqueue logic)

- **spec_ref**: REQ-003, REQ-012
- **files**: openconnector (DLQEntry schema in openregister, DLQ enqueue middleware), openregister (schema record type)
- **acceptance_criteria**:
  - GIVEN a call that fails after exhausting all retries and `dlq_enabled = true` WHEN the final failure occurs THEN a DLQEntry is created with: adapter_id, source_id, endpoint, original_payload, original_headers, attempts (count of all retries), last_error, last_attempt_at (ISO timestamp), created_at, status = pending.
  - GIVEN a DLQEntry WHEN queried THEN it carries a unique ID; the entry is immutable (no UPDATE allowed, only status transitions).
  - GIVEN a ReliabilityProfile with `dlq_enabled = false` WHEN applied THEN no DLQEntry is created; the failure is logged to CallLog but not enqueued.
  - GIVEN a DLQEntry creation WHEN completed THEN a domain event `dlq.entry.created` is emitted with the entry ID and basic metadata.
  - GIVEN `dlq_max_age_days = 30` WHEN a daily housekeeping job runs THEN entries older than 30 days transition to `status = expired`; their original_payload is purged; a `dlq.entry.expired` event is emitted.
- [ ] Implement (DLQEntry schema, enqueue middleware, housekeeping job)
- [ ] Test (PHPUnit: DLQEntry creation, immutability, expiry logic; integration: test DLQ enqueue on final failure)

### Task 3.5: Implement DLQ inspection and replay (manual, bulk, scheduled)

- **spec_ref**: REQ-004, REQ-005
- **files**: openconnector (DLQ replay controller, edit validation, provenance chaining)
- **acceptance_criteria**:
  - GIVEN a pending DLQEntry WHEN the operator clicks "replay" THEN:
    - The original payload is re-sent to the original endpoint.
    - On success: entry status transitions to `replayed`; a new CallLog entry is created with `replay_of = original_dlq_entry_id` and `dlq_entry_id = null` (replay itself does not create a new DLQEntry).
    - On failure: entry remains `pending`; attempts is incremented; a new CallLog entry records the failed replay.
  - GIVEN a filter (e.g., "adapter = 'salesforce' AND status = 'pending' AND created_at > now() - 24h") WHEN bulk replay is triggered THEN all matching DLQEntries are queued for replay; progress is trackable via a batch ID.
  - GIVEN an operator editing a DLQEntry payload WHEN the edit is submitted THEN:
    - The edited payload is validated against the adapter's request schema.
    - If valid: a new DLQEntry is created with `replay_of = original.id` and the edited payload; the original is marked `status = replayed` (no execution).
    - If invalid: the edit is rejected with field-level error messages.
  - GIVEN a chain of replayed DLQEntries (original → edited → replayed → edited again → replayed) WHEN the UI renders the chain THEN each link shows operator identity, timestamp, and diff of payload changes.
- [ ] Implement (replay controller, edit validation, chain rendering)
- [ ] Test (PHPUnit: replay success/failure, bulk replay filtering, edit validation; integration: test single and bulk replay end-to-end; browser test: chain rendering in UI)

### Task 3.6: Implement circuit-breaker state machine (per adapter, per source)

- **spec_ref**: REQ-006
- **files**: openconnector (circuit-breaker state machine, Redis state store, state-transition events)
- **acceptance_criteria**:
  - GIVEN a (adapter, source) pair WHEN calls are recorded THEN a Redis-backed state machine tracks: state (closed/open/half-open), rolling error rate over `circuit_breaker_window_seconds`, call count in the window, opened_at timestamp.
  - GIVEN error rate > `circuit_breaker_threshold` AND call count ≥ `circuit_breaker_min_calls` WHEN the condition is detected THEN the breaker transitions from `closed` to `open`; all subsequent calls fast-fail with typed `CircuitOpen` error.
  - GIVEN a breaker in `open` state WHEN `circuit_breaker_open_seconds` elapses THEN the breaker transitions to `half-open`; the next call is allowed (test call); success → closed; failure → open again.
  - GIVEN a state transition WHEN it occurs THEN a domain event `circuit.state.changed` is emitted with old state, new state, and reason (error-rate threshold exceeded, test success, test failure, etc.).
  - GIVEN a breaker in `closed` state WHEN calls succeed THEN the breaker remains closed (no constant re-checking).
- [ ] Implement (state machine, Redis coordination, event emission)
- [ ] Test (PHPUnit: state transitions, error-rate calculation, rolling window; integration: test state machine against mock endpoint with controlled failure rate)

### Task 3.7: Implement SLA monitoring (Prometheus metrics and violation events)

- **spec_ref**: REQ-007
- **files**: openconnector (SLA calculator, Prometheus metrics emission, violation event publisher)
- **acceptance_criteria**:
  - GIVEN a ReliabilityProfile with `sla_target_p95_ms` and `sla_target_error_rate` set WHEN calls are recorded THEN:
    - Latency histogram is computed per endpoint; p95 is tracked over a rolling 5-minute window.
    - Error rate is tracked as (failures / total) over the same window.
    - Metrics are exposed on `/metrics` endpoint as `openconnector_adapter_latency_seconds` (with `quantile="0.95"`) and `openconnector_adapter_error_rate`.
  - GIVEN a violation (p95 > target) WHEN detected THEN a domain event `sla.violation` is emitted with severity `warning`; at 1.5× target, severity escalates to `critical`; at 2×, to `page`.
  - GIVEN a ReliabilityProfile without `sla_target_p95_ms` and `sla_target_error_rate` WHEN applied THEN metrics are still recorded but no violation events are emitted.
  - GIVEN the `/metrics` endpoint WHEN queried THEN it includes Prometheus-format metrics with labels `{adapter="...",endpoint="...",quantile="0.95"}`.
- [ ] Implement (SLA calculator, Prometheus integration, violation publisher)
- [ ] Test (PHPUnit: p95/error-rate calculation; integration: test violation event for various thresholds; browser: confirm metrics appear on Prometheus scrape)

### Task 3.8: Implement CallLog extension with reliability context

- **spec_ref**: REQ-008, REQ-010, REQ-011
- **files**: openconnector (CallLog schema extension, middleware to populate fields)
- **acceptance_criteria**:
  - GIVEN the CallLog schema WHEN extended THEN new columns include: idempotency_key, cached_response (bool), retry_attempt (int), breaker_state_at_call (string), dlq_entry_id (nullable, FK), consumer_id, quota_remaining, correlation_id, effective_reliability_profile_snapshot (JSON).
  - GIVEN any call WHEN recorded to CallLog THEN all fields are populated: idempotency_key (or null if strategy = none), retry_attempt (0 for first attempt, 1+ for retries), breaker_state_at_call (closed/open/half-open), correlation_id (propagated or generated).
  - GIVEN a call that is cached WHEN recorded THEN cached_response = true and response data comes from Redis, not from re-execution.
  - GIVEN a ReliabilityProfile inheritance scenario (endpoint > adapter > global) WHEN the call is made THEN effective_reliability_profile_snapshot is the merged/final profile as JSON.
  - GIVEN a CallLog entry WHEN queried THEN all fields are queryable for audit and replay-chain reconstruction.
- [ ] Implement (schema migration, middleware to populate fields)
- [ ] Test (PHPUnit: CallLog record structure, field population; integration: test CallLog captures all scenarios from REQ-001 through REQ-014)

### Task 3.9: Implement consumer quotas with sliding-window enforcement

- **spec_ref**: REQ-009
- **files**: openconnector (Quota schema, sliding-window middleware, Redis counter management)
- **acceptance_criteria**:
  - GIVEN a Quota (scope = per_api_key, consumer_id, period = minute, limit = 100) WHEN calls arrive THEN:
    - A Redis sliding-window counter tracks requests in the trailing `period` duration.
    - Each call increments the counter; if counter+1 > limit, the call is rejected with HTTP 429.
    - If counter+1 <= limit, the call is allowed; `quota_remaining` is recorded in CallLog.
  - GIVEN a quota at the limit WHEN the window rolls (e.g., 60 seconds pass for a minute quota) THEN old requests auto-expire from the counter; the next call resets it to 1.
  - GIVEN an HTTP 429 response WHEN returned THEN headers include `Retry-After: <seconds-until-window-rolls>`, `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`.
  - GIVEN multiple scopes (per_adapter, per_source, per_api_key) WHEN applied THEN they are independent; one scope hitting its limit does not affect others.
- [ ] Implement (Quota schema, sliding-window middleware, Redis operations)
- [ ] Test (PHPUnit: sliding-window counter logic, limit enforcement, window rolling; integration: test quota exhaustion and recovery)

### Task 3.10: Implement correlation ID propagation (inbound to outbound)

- **spec_ref**: REQ-011
- **files**: openconnector (header capture middleware, outbound header injection, CallLog population)
- **acceptance_criteria**:
  - GIVEN an inbound request with `X-Correlation-Id: <uuid>` WHEN openconnector receives it THEN:
    - The correlation_id is captured and attached to all downstream callstack context.
    - Every outbound call to an adapter includes the header `X-Correlation-Id: <uuid>`.
    - Every CallLog entry includes `correlation_id = <uuid>`.
  - GIVEN an inbound request with W3C `traceparent: 00-<trace-id>-<span-id>-<trace-flags>` WHEN openconnector receives it THEN the trace_id is used as correlation_id (converted to UUID or passed as-is per implementation choice).
  - GIVEN an inbound request without either header WHEN openconnector receives it THEN a new UUIDv4 is generated and used as correlation_id for all downstream operations.
  - GIVEN an error response WHEN returned THEN it includes `X-Correlation-Id` header and the error body includes `"correlationId": <uuid>` so callers can look up the DLQEntry or CallLog.
- [ ] Implement (correlation ID middleware, header injection, CallLog population)
- [ ] Test (PHPUnit: header parsing, UUID generation, propagation; integration: test correlation ID tracing end-to-end)

### Task 3.11: Implement bulkhead isolation per adapter

- **spec_ref**: REQ-013
- **files**: openconnector (concurrency limit enforcement, in-flight call tracking, BulkheadFull error)
- **acceptance_criteria**:
  - GIVEN a ReliabilityProfile with `concurrency_limit = 50` WHEN calls arrive to that adapter THEN:
    - The middleware tracks in-flight call count per adapter (likely via a concurrent counter or Redis).
    - If in-flight < limit: call is allowed to proceed; in-flight count is incremented.
    - If in-flight >= limit: call is immediately rejected with typed `BulkheadFull` error; CallLog records `bulkhead_rejected = true`; no retry occurs.
  - GIVEN calls to multiple adapters WHEN some are at the bulkhead limit THEN other adapters are unaffected; bulkhead is per-adapter, not global.
  - GIVEN a bulkhead-rejected call and `dlq_enabled = true` WHEN the rejection occurs THEN the call is enqueued to DLQ (no retries, direct enqueue) with error = BulkheadFull.
  - GIVEN a bulkhead-rejected call WHEN the CallLog is queried THEN it includes `error_type = 'BulkheadFull'` so operators know it was a capacity issue, not a transient failure.
- [ ] Implement (concurrency tracking, limit enforcement, BulkheadFull error handling)
- [ ] Test (PHPUnit: limit enforcement, per-adapter isolation; integration: test bulkhead with concurrent load)

### Task 3.12: Implement DLQ provenance chain (replay_of references and UI rendering)

- **spec_ref**: REQ-014
- **files**: openconnector (DLQEntry replay_of reference, chain query API, provenance diff computation)
- **acceptance_criteria**:
  - GIVEN a replayed DLQEntry WHEN a new DLQEntry is created from it (either manually edited or directly replayed) THEN the new entry carries `replay_of = original.id` (FK to the predecessor).
  - GIVEN a chain of DLQEntries (original → edited1 → edited2 → final_success) WHEN the chain is queried via `GET /dlq-entries/{id}/chain` THEN:
    - All entries in the chain are returned with `replay_of` links intact.
    - The chain is acyclic (traversal terminates).
    - The full DAG structure is returned as JSON.
  - GIVEN the UI rendering the chain WHEN displayed THEN:
    - Each entry shows: creation timestamp, operator identity (if edited), error/status, and payload.
    - Diffs are highlighted between consecutive edited payloads.
    - The chain is rendered chronologically (original → edits → replays).
  - GIVEN an export request WHEN triggered THEN the entire chain is serialized to JSON with all metadata and is suitable for compliance archive.
- [ ] Implement (replay_of schema, chain query API, diff computation, UI component)
- [ ] Test (PHPUnit: chain query logic, acyclic validation; browser: chain rendering and diff highlighting; export: JSON format validation)

## 4. Per-adapter implementation pattern (one per adapter)

The following tasks are recorded for per-adapter follow-up cycles; they do not belong to this spec change but are visible here for planning.

### Task 4.1 (per adapter): Add ReliabilityProfile reference to Source configuration

- **spec_ref**: REQ-001, REQ-010, REQ-013
- **files**: per-adapter design.md (rationale for chosen ReliabilityProfile), openconnector Source admin UI (optional ReliabilityProfile selector)
- **acceptance_criteria**:
  - GIVEN a per-adapter source WHEN created THEN an optional `reliability_profile_slug` field is settable (picker or text input).
  - GIVEN a source without `reliability_profile_slug` set WHEN a call is made THEN the global default or adapter-level profile (if defined) is used.
  - GIVEN a per-adapter design.md WHEN written THEN it explains why this adapter's ReliabilityProfile was chosen (e.g., Salesforce bulk API needs 10 retries instead of 5; StUF needs no circuit-breaker because it's already wrapped by network firewalls).
- [ ] Implement (per adapter)
- [ ] Test (integration: Source can be created with a ReliabilityProfile reference; SLA targets / retry counts apply)

### Task 4.2 (per adapter, optional): Ship a seed ReliabilityProfile for dev/test

- **spec_ref**: design.md Seed Data section
- **files**: `lib/Settings/seeds/reliability-profiles/{slug}.json` (new)
- **acceptance_criteria**:
  - GIVEN the seed file WHEN parsed as JSON THEN it conforms to the ReliabilityProfile schema, ships in `lifecycleState: paused`, and carries the `_meta` block per design convention.
  - GIVEN a fresh dev install WHEN the repair step runs THEN the seed appears in the ReliabilityProfile registry; idempotent on re-run.
  - GIVEN the seed profile WHEN reviewed by operators THEN parameters are conservative (high retry count, low error-rate threshold) suitable for dev testing without false positives.
- [ ] Implement (per adapter, optional)
- [ ] Test (PHPUnit: seed load + import + paused-state assertion)

## 5. Cross-cutting follow-ups (separate change candidates)

### Task 5.1 (separate change, optional): Author `add-openconnector-ipaas-first-adapter` as proof-of-concept

- **spec_ref**: proposal.md Success Criteria
- **files**: new change folder (first adapter to use ReliabilityProfile)
- **acceptance_criteria**:
  - GIVEN a proof-of-concept adapter (e.g., StUF, SnapLogic) WHEN implemented THEN:
    - All reliability features (retries, DLQ, circuit-breaker, SLA, quotas) are tested end-to-end.
    - Government tender acceptance test (DLQ replay, SLA compliance monitoring, audit retention) passes.
    - Operator feedback is collected on DLQ UI, replay flow, and SLA dashboard visibility.
  - GIVEN the proof-of-concept WHEN reviewed THEN it validates that the ipaas-reliability spec is operationally complete and implementable.
- [ ] Implement (separate change, high priority)
- [ ] Test (per first-adapter cycle)

### Task 5.2 (separate change, optional): Integrate mydash SLA violation surfacing

- **spec_ref**: REQ-007, design.md Cross-app Integration
- **files**: mydash (SLA violation event subscriber, dashboard widget)
- **acceptance_criteria**:
  - GIVEN a `sla.violation` event WHEN emitted from openconnector THEN mydash receives it and surfaces it on the operations dashboard as a tile (warning/critical/page severity).
  - GIVEN the tile WHEN clicked THEN it links to the openconnector SLA monitoring page showing per-endpoint latency and error-rate trends.
- [ ] Implement (separate change)
- [ ] Test (per mydash integration cycle)

### Task 5.3 (separate change, optional): Integrate pipelinq DLQ failed-task visibility

- **spec_ref**: REQ-003, REQ-004, proposal.md Cross-app Integration
- **files**: pipelinq (DLQ event subscriber, failed-task state surface)
- **acceptance_criteria**:
  - GIVEN a `dlq.entry.created` event WHEN emitted from openconnector THEN pipelinq surfaces it as a failed-task state in the workflow timeline.
  - GIVEN the failed task WHEN clicked THEN it shows the DLQEntry details (error, original payload, replay link).
  - GIVEN a replay WHEN triggered from pipelinq THEN it calls the openconnector DLQ replay API and updates the workflow state.
- [ ] Implement (separate change)
- [ ] Test (per pipelinq integration cycle)

## 6. Verification

- [ ] All Section 1–3 tasks (this spec change's own deliverables) are checked off
- [ ] `openspec validate ipaas-reliability` exits clean
- [ ] Manual peer review by an SRE / integration-engineer persona confirms:
  - The reliability tier is understandable as a configuration contract (operators can read ReliabilityProfile schema and configure it).
  - The DLQ replay flow is operationally safe (no silent double-effects, full provenance chain).
  - Audit trail (CallLog + DLQEntry + correlation IDs) is complete for compliance (auditor can reconstruct any call's full journey).
- [ ] Architecture reviewer confirms ADR-003 + ADR-022 + ADR-031 compliance:
  - CallLog is the single source of truth for call audit (no parallel Sentry/Loki dumps).
  - ReliabilityProfile is declarative configuration, not imperative retry code per adapter.
  - OR abstractions (audit-trail-immutable, event-bus) are consumed, not reimplemented.
- [ ] First adapter implementation (Task 5.1) ships and passes government tender acceptance test.
- [ ] No source code changes outside `openspec/changes/ipaas-reliability/` until implementation phase.

## Tests (company-wide ADR-008)

- [ ] N/A for the spec change itself — no business logic ships
- [ ] PHPUnit unit tests for each component (Tasks 3.1–3.12): idempotency key computation, backoff formula, circuit-breaker state machine, quota sliding-window, DLQ replay chain, SLA calculation, CallLog population, etc. — land with implementation
- [ ] Integration tests: end-to-end retry scenario, DLQ enqueue + replay, circuit-breaker trip + half-open, quota exhaustion, bulkhead rejection — land with first-adapter cycle
- [ ] Browser tests (Playwright): DLQ UI (inspect, edit, replay), provenance chain rendering, SLA dashboard widgets — land with UI implementation
- [ ] Compliance audit test: auditor can query CallLog + DLQEntry + correlation IDs and reconstruct a full 6-month call journey — land with first-adapter cycle
- [ ] All tests pass (`composer test`) — enforced at the implementation PR's CI gate

## Documentation (company-wide ADR-009)

- [ ] N/A for the spec change itself
- [ ] Operational guide: `docs/reliability/overview.md` (explaining ReliabilityProfile, DLQ, circuit-breaker, SLA, quotas for operators)
- [ ] Developer guide: `docs/reliability/implementation-guide.md` (for per-adapter authors: how to reference a ReliabilityProfile, test reliability features)
- [ ] API docs: OpenAPI spec for DLQ inspect/replay endpoints, CallLog query, ReliabilityProfile CRUD
- [ ] Compliance guide: `docs/reliability/audit-trail-compliance.md` (explaining how to export CallLog + DLQEntry chains for audits, retention periods per Archiefwet)
- [ ] Per-adapter docs: each adapter's `docs/integrations/{slug}.md` includes ReliabilityProfile tuning recommendations (e.g., "Salesforce bulk API needs 10 retries; StUF defaults to global profile")

## i18n (company-wide ADR-007)

- [ ] N/A for the spec change itself (no user-facing strings in spec)
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings for implementation:
  - Common terms: `Idempotency`, `Idempotentie`, `Retry`, `Opnieuw proberen`, `Dead-letter queue`, `Dode-letterrij`, `Circuit breaker`, `Snelbeveiliger`, `SLA`, `Quota`, `Quota's`, `Reliability profile`, `Betrouwbaarheidsprofiel`, `Exponential backoff`, `Exponentiële backoff`, `Jitter`, `Bulkhead`, `Isolatie`, `Provenance chain`, `Herkomstenketen`, `Idempotency key`, `Idempotentie-sleutel`, `Sliding window`, `Schuivend venster`.
  - UI strings: `Inspect DLQ entry`, `DLQ-vermelding inspecteren`, `Replay`, `Opnieuw proberen`, `Edit and replay`, `Bewerken en opnieuw proberen`, `Export provenance chain`, `Herkomstenketen exporteren`, etc.
