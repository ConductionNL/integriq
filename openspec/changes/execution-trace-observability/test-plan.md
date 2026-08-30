# Test Plan: execution-trace-observability

## Test Cases

### TC-1: One traceId shared across every step of an endpoint call
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001`
- **type**: api
- **persona**: N/A
- **preconditions**: an endpoint with a `mapping` rule (before) and a `save_object` rule (before) that dispatches one outbound `CallService` call via a `synchronization` rule
- **steps**: call the endpoint via `POST /apps/integriq/api/endpoints/{id}/...`
- **expected result**: the rule step, mapping step, and outbound-call step recorded for the request all carry the same `traceId`; `GET /apps/integriq/api/execution-traces/{traceId}` returns all three
- **test command**: /test-api

### TC-2: Untraced ad-hoc call produces no trace
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001`
- **type**: api
- **preconditions**: a configured Source
- **steps**: `POST /apps/integriq/api/sources/{id}/test`
- **expected result**: no new `execution_trace` object is created for this call
- **test command**: /test-api

### TC-3: Skipped rule still produces a step; step order matches real execution sequence
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002`
- **type**: api
- **preconditions**: an endpoint with rules A (order 10), B (order 20, conditions false), C (order 30, dispatches an outbound call mid-processing)
- **steps**: call the endpoint
- **expected result**: the persisted trace's `steps` array is `[A, B(skipped), call, C]` in that order (interleaved, not grouped by type), with B carrying `status: 'skipped'`
- **test command**: /test-api

### TC-4: Redacted rule-step snapshot never contains a plaintext secret
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-snapshot-redaction-before-any-step-is-buffered-req-003`
- **type**: security
- **preconditions**: an `authentication` rule step whose request carries an `Authorization: Bearer live-secret-token-123` header
- **steps**: call the endpoint, fetch the persisted trace
- **expected result**: the step's `input.headers.authorization` is `***REDACTED***`; the literal secret does not appear anywhere in the trace object
- **test command**: /test-security

### TC-5: Trace call step matches the persisted call_log's redacted data byte-for-byte
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-snapshot-redaction-before-any-step-is-buffered-req-003`
- **type**: api
- **preconditions**: a traced execution dispatching one outbound call to a source configured with a `client_secret` form parameter
- **steps**: call the endpoint, fetch both the persisted `call_log` and the `execution_trace`'s `call` step
- **expected result**: the two redacted `request`/`response` shapes are identical; no divergence, no plaintext secret in either
- **test command**: /test-security

### TC-6: A successful execution persists exactly one trace
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004`
- **type**: api
- **preconditions**: a traced endpoint call that completes successfully
- **steps**: call the endpoint, list `execution_trace` objects filtered by the returned `traceId`
- **expected result**: exactly one object, `status: 'success'`
- **test command**: /test-api

### TC-7: Approval-suspend/resume appends to the same trace, never creates a second one
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004`
- **type**: api
- **preconditions**: an endpoint with a `before`-phase `approval` rule (order 20) followed by an `after`-phase rule
- **steps**: call the endpoint (suspends, HTTP 202), fetch the trace (status `running`), approve via `ApprovalsController`, fetch the trace again
- **expected result**: the same `traceId`/object is updated with the `after`-phase steps appended and a final `status`; no second `execution_trace` exists for this execution
- **test command**: /test-api

### TC-8: Uncaught rule exception still produces a completed, failed trace
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004`
- **type**: api
- **preconditions**: a rule configured to throw
- **steps**: call the endpoint (HTTP 500)
- **expected result**: the trace is persisted with `status: 'failed'` and an `error` object carrying endpoint/rule name/type/message matching the HTTP 500 body
- **test command**: /test-api

### TC-9: Dry-run replay of a failed sync-entryPoint trace makes no writes
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005`
- **type**: api
- **preconditions**: a `failed` `execution_trace` with `entryPoint: 'sync'`
- **steps**: `POST /apps/integriq/api/execution-traces/{id}/replay` with no body
- **expected result**: no `synchronization_contract`/target object created or updated; a new `execution_trace` is persisted with `isReplay: true, dryRun: true, replayOf: '<original id>'`
- **test command**: /test-api

### TC-10: Dry-run replay of a webhook event-entryPoint trace never dispatches
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005`
- **type**: api
- **preconditions**: an `execution_trace` with `entryPoint: 'event'`, `action.kind: 'webhook'`
- **steps**: replay with no `force`
- **expected result**: the response includes the resolved request that would be sent; no outbound HTTP call is observed against the sink (mock/stub asserts zero calls)
- **test command**: /test-api

### TC-11: Dry-run replay of an endpoint-entryPoint trace skips write rules, executes read rules
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005`
- **type**: api
- **preconditions**: an `execution_trace` with `entryPoint: 'endpoint'` whose original run had a `mapping` rule then a `save_object` rule
- **steps**: replay with no `force`
- **expected result**: the new preview trace shows the `mapping` step as real (`status: 'success'`) and the `save_object` step as `status: 'skipped_dry_run'`; no object persisted
- **test command**: /test-api

### TC-12: Forced replay of a failed sync-entryPoint trace writes for real
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-forced-replay-reuses-the-original-entry-points-real-dispatch-path-req-006`
- **type**: api
- **preconditions**: a `failed` `execution_trace` with `entryPoint: 'sync'` whose underlying mapping bug has since been fixed
- **steps**: replay with `{force: true}`
- **expected result**: `synchronization_contract` created/updated as if the item succeeded on first processing; a new `execution_trace` persisted with `isReplay: true, dryRun: false, replayOf: '<original id>'`
- **test command**: /test-api

### TC-13: Forced replay resolves live credentials, never the redacted snapshot
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-forced-replay-reuses-the-original-entry-points-real-dispatch-path-req-006`
- **type**: security
- **preconditions**: an `execution_trace` whose `call` step carries `***REDACTED***` for the Source's `Authorization` header
- **steps**: replay with `{force: true}`
- **expected result**: the replayed outbound call carries the Source's current live credential; the literal string `***REDACTED***` is never sent upstream
- **test command**: /test-security

### TC-14: Operator inspects a trace's step timeline in the UI
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin) — the primary Traces-UI consumer, debugging integration failures
- **preconditions**: an admin on the Traces list with one `failed` trace
- **steps**: open the trace detail view, expand a step
- **expected result**: the ordered step timeline renders with type/duration/status per step; expanding a step reveals its redacted input/output
- **test command**: /test-persona-noor

### TC-15: Replay defaults to dry-run with a confirmation step for force
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007`
- **type**: functional
- **preconditions**: an admin viewing a `failed` trace's detail
- **steps**: click "Replay"
- **expected result**: a dry-run preview runs and displays before any write occurs; forcing a real replay requires a separate, explicit confirmation
- **test command**: /test-functional

### TC-16: entryPoint filter uses a labeled NcSelect
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007`
- **type**: accessibility
- **preconditions**: Traces list page loaded
- **steps**: inspect the entryPoint filter control
- **expected result**: the `NcSelect` carries `:input-label="t('integriq', 'Entry point')"`, matching `EventDeliveriesPage.vue:28-31`; no bare `<label>` + `@keydown.enter` pattern
- **test command**: /test-accessibility

### TC-17: traces_total is scraped per status
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-traces_total-prometheus-counter-via-the-apphost-observability-engine-req-008`
- **type**: api
- **preconditions**: 10 `execution_trace` objects: 7 `success`, 2 `failed`, 1 `running`
- **steps**: `GET /apps/integriq/api/metrics` as admin
- **expected result**: output includes `integriq_traces_total{status="success"} 7`, `{status="failed"} 2`, `{status="running"} 1`
- **test command**: /test-api

### TC-18: Retention rules match the design's condition-branched schedule
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-retention-bounded-trace-persistence-req-009`
- **type**: regression
- **preconditions**: three `execution_trace` rows — default (running/failed), `status: success, dryRun: false`, `dryRun: true`
- **steps**: run the retention-rebase job (`SettingsService::rebase()`-equivalent pass over `execution_trace`)
- **expected result**: `expires` is `created + P30D` (default), `created + P7D` (successful non-replay), `created + P1D` (dry-run preview) respectively
- **test command**: /test-regression

### TC-19: sessionId populated for a call inside a traced execution; unset otherwise
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/http-call-engine/spec.md#requirement-trace-scoped-call-correlation-via-call_logsessionid-req-011`
- **type**: api
- **preconditions**: (a) a call dispatched from within a traced endpoint execution, (b) a `SourcesController::test()` ad-hoc call
- **steps**: dispatch both, fetch both `call_log` rows
- **expected result**: (a) `call_log.sessionId` equals the execution's `traceId`; (b) `call_log.sessionId` is absent
- **test command**: /test-api

### TC-20: Trace-step emission does not change untraced rule-pipeline behaviour
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/rule-pipeline/spec.md#requirement-trace-step-emission-during-rule-pipeline-execution-req-rule-009`
- **type**: regression
- **preconditions**: existing `rule-pipeline` REQ-RULE-001..008 PHPUnit scenarios, run with no `ExecutionTraceContext` supplied
- **steps**: run the existing rule-pipeline PHPUnit suite unmodified
- **expected result**: all pre-existing scenarios pass unchanged — no step buffering, no new writes
- **test command**: /test-regression

### TC-21: dryRun suppresses write-shaped rules but not read-shaped ones
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/rule-pipeline/spec.md#requirement-dry-run-mode-suppresses-write-shaped-rule-dispatch-req-rule-010`
- **type**: api
- **preconditions**: a pipeline with a `mapping` rule then a `save_object` rule, `dryRun: true`
- **steps**: run `processRules(..., dryRun: true)`
- **expected result**: mapping executes for real; `save_object` is skipped (`status: 'skipped_dry_run'`), no object persisted
- **test command**: /test-api

## Coverage Summary

| Requirement | Covered by |
|---|---|
| execution-trace REQ-001 | TC-1, TC-2 |
| execution-trace REQ-002 | TC-3 |
| execution-trace REQ-003 | TC-4, TC-5 |
| execution-trace REQ-004 | TC-6, TC-7, TC-8 |
| execution-trace REQ-005 | TC-9, TC-10, TC-11 |
| execution-trace REQ-006 | TC-12, TC-13 |
| execution-trace REQ-007 | TC-14, TC-15, TC-16 |
| execution-trace REQ-008 | TC-17 |
| execution-trace REQ-009 | TC-18 |
| http-call-engine REQ-011 | TC-5, TC-19 |
| rule-pipeline REQ-RULE-009 | TC-3, TC-20 |
| rule-pipeline REQ-RULE-010 | TC-11, TC-21 |

All ADDED requirements across the three delta specs have at least one mapped test case.

## Out of Scope

- Distributed tracing / OpenTelemetry export — no test cases, per proposal.md Out of Scope.
- Load/volume testing of trace-buffer memory growth on very large mapped result sets (proposal.md Risk 3) — flagged as a follow-up, not gated by this test plan.
