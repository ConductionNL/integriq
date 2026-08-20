# execution-trace Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- `execution-trace-observability` _(in progress)_

## Purpose

Joins one logical OpenConnector execution — an inbound endpoint call, a cron
job run, a CloudEvent delivery, or a manual synchronization run — under a
single minted id, propagated through the rule pipeline, mapping,
synchronization, and outbound `CallService` dispatch, and persists an
ordered per-step timeline as one `execution_trace` OpenRegister object per
execution. Snapshots are redacted via the existing `SensitiveFieldRegistry`
before persistence. Failed executions can be replayed (dry-run by default,
explicit force for a real write) by reusing the dead-letter and test-mode
machinery that already exists in `dead-letter-replay`,
`synchronization-engine` REQ-011, and `job-management` REQ-JOB-002. See
`design.md` Decisions 1-5 for the propagation, schema, redaction, and
replay mechanics.

## ADDED Requirements

### Requirement: Execution id minted at every entry point and propagated through the pipeline (REQ-001)

The system MUST mint a `traceId` (UUIDv4) at each of the four execution
entry points — `EndpointService::handleRequest()`, a cron-triggered job run
(`JobService::executeJob()`), a CloudEvent delivery attempt
(`EventService::attemptDelivery()`), and a manual synchronization run
(`SynchronizationService::synchronize()`) — before any downstream work
begins, and MUST carry it as an `ExecutionTraceContext` value object passed
alongside the existing `FlowToken` (never as a new `FlowToken` constructor
parameter; see `design.md` Decision 1) through the rule pipeline
(`EndpointService::processRules()`), synchronization item processing
(`SynchronizationService::processSynchronizationObject()`), and outbound
dispatch (`CallService::call()`). Every step recorded during one execution
(REQ-002) MUST carry the same `traceId`. When no `ExecutionTraceContext` is
supplied (e.g. `SourcesController::test()`'s ad-hoc outbound call, or any
other call path not originating from one of the four entry points), no
`traceId` is minted and no trace is recorded — this MUST NOT change
existing behaviour for untraced call paths.

@e2e exclude backend correlation-id propagation — covered by PHPUnit

#### Scenario: an endpoint call mints one traceId shared by every downstream step

- **GIVEN** an endpoint with a `mapping` rule (before) and a `save_object`
  rule (before) that dispatches one outbound `CallService` call via a
  `synchronization` rule
- **WHEN** a request reaches `EndpointService::handleRequest()`
- **THEN** a single `traceId` is minted before `processRules()` runs
- **AND** the rule step, the mapping step, and the outbound-call step
  recorded for this request all carry that same `traceId`

#### Scenario: an ad-hoc source test call outside any entry point produces no trace

- **GIVEN** an admin calls `SourcesController::test()` directly
- **WHEN** `CallService::call()` dispatches the test request
- **THEN** no `ExecutionTraceContext` is present
- **AND** no `execution_trace` object is created for that call

#### Notes

- `traceId` is a distinct concept from the pre-existing `correlationId` used
  by the case-handoff intake engine (`OpenFormulierenIntakeService`,
  `DsoIngestService`) — the two are unrelated and MUST NOT be conflated.

### Requirement: Ordered per-execution step timeline (REQ-002)

For each execution carrying an `ExecutionTraceContext`, the system MUST
append one ordered `Step` (`order`, `type` ∈ `rule|mapping|synchronization|
call`, `name`, `timing`, `status`, `durationMs`, `startedAt`, redacted
`input`/`output`) to the context's in-memory buffer for: every rule
evaluated by `processRules()` (including skipped rules, per `rule-pipeline`
REQ-RULE-001's skip semantics — skipped rules MUST still produce a step with
`status: 'skipped'`), every mapping application, every synchronization item
processed, and every outbound `CallService::call()` dispatch. Steps MUST
retain the pipeline's actual execution order (`order` matches the sequence
observed, not the rule's configured `order` field alone, since mapping and
call steps interleave between rule steps).

@e2e exclude backend step assembly — covered by PHPUnit

#### Scenario: a skipped rule still produces a step

- **GIVEN** a rule whose JSON-Logic `conditions` evaluate to false
- **WHEN** the pipeline reaches it during a traced execution
- **THEN** a step with `status: 'skipped'` is appended, matching
  `rule-pipeline` REQ-RULE-001's existing skip behaviour (no data mutation)

#### Scenario: step order reflects real execution sequence

- **GIVEN** a pipeline that runs rule A (order 10), then dispatches an
  outbound call from within rule A, then runs rule B (order 20)
- **WHEN** the trace is assembled
- **THEN** the steps appear in the sequence [rule A, call, rule B], not
  grouped by type

### Requirement: Snapshot redaction before any step is buffered (REQ-003)

Every step's `input`/`output` snapshot MUST be redacted via
`SensitiveFieldRegistry::redactArray()` (never a new or duplicated
redaction implementation) before it is appended to the `ExecutionTraceContext`
buffer. For the `call` step type specifically, the system MUST reuse the
already-redacted `request`/`response` array `CallService::buildResponseData()`
produces for `call_log` persistence (per `http-call-engine` REQ-006) rather
than deriving a second, independent redaction of the same data — see
`design.md` Decision 3.

@e2e exclude backend redaction — covered by PHPUnit; integration scenario
below is the cross-layer contract test

#### Scenario: a redacted rule-step snapshot never contains a plaintext secret

- **GIVEN** an `authentication` rule step whose amended `FlowToken` request
  slot carries an `Authorization` header
- **WHEN** the step is appended to the trace buffer
- **THEN** the persisted step's `input.headers.authorization` value is
  `***REDACTED***`

#### Scenario: the call step's snapshot matches the call_log's redacted request/response byte-for-byte

- **GIVEN** a traced execution whose rule pipeline dispatches one outbound
  `CallService::call()` to a source configured with a `client_secret`
  form parameter
- **WHEN** the execution completes and both the `call_log` and the
  `execution_trace` are persisted
- **THEN** the trace's `call` step `output` equals the `call_log.request`/
  `call_log.response` redacted shape exactly — no divergence, no duplicate
  redaction pass

### Requirement: Trace persistence as one execution_trace object per execution (REQ-004)

The system MUST persist the assembled `ExecutionTraceContext` as exactly one
`execution_trace` OpenRegister object (register/schema `openconnector` /
`execution_trace`, register.d fragment per `design.md` Decision 2) when the
execution completes — on success, on pipeline short-circuit (e.g. an `error`
rule or approval suspension), or on an uncaught exception (`rule-pipeline`
REQ-RULE-001's HTTP 500 path) — using the minted `traceId` as the object's
own id. Persistence MUST be a single create for every entry point EXCEPT the
approval-suspend/resume continuation (`EndpointService::resumeFromApproval()`),
where the system MUST update the SAME `execution_trace` object (matched by
`traceId`, carried in the rehydrated `ApprovalService::rehydrateFlowToken()`
context) to append the `after`-phase steps rather than create a second,
disconnected trace for the same logical execution.

@e2e exclude backend persistence orchestration — covered by PHPUnit

#### Scenario: a successful execution persists exactly one trace

- **GIVEN** a traced endpoint call that completes successfully
- **WHEN** the response is returned
- **THEN** exactly one `execution_trace` object exists with `status:
  'success'` and every step recorded during the request

#### Scenario: an approval-suspended execution's resume appends to the same trace

- **GIVEN** a `before`-phase `approval` rule suspends a traced execution
  (`approval-workflow` REQ-001), producing a trace with `status: 'running'`
  and the `before`-phase steps
- **WHEN** an approver later approves and `EndpointService::resumeFromApproval()`
  runs the remaining `after`-phase rules
- **THEN** the SAME `execution_trace` object (same `traceId`) is updated
  with the `after`-phase steps appended and `status` set to its final value
- **AND** no second `execution_trace` object is created for this execution

#### Scenario: an uncaught rule exception still produces a completed trace

- **GIVEN** a rule that throws during a traced execution
- **WHEN** the pipeline surfaces the HTTP 500 (`rule-pipeline` REQ-RULE-001)
- **THEN** the `execution_trace` is persisted with `status: 'failed'` and an
  `error` object carrying the endpoint name, rule name, rule type, and error
  message — the same fields the HTTP 500 body already carries

### Requirement: Dry-run replay performs no writes (REQ-005)

`POST /api/execution-traces/{id}/replay` MUST default to dry-run
(`force` absent or `false`) and MUST NOT perform any write with an external
or persisted side-effect for the replayed execution: for a `sync`-entryPoint
trace it MUST invoke `SynchronizationService::replaySynchronizationItem()`
with `isTest: true` (reusing `synchronization-engine` REQ-011's existing
no-write guarantee); for a `job`-entryPoint trace it MUST invoke
`JobService::executeJob()`'s existing test mode (`job-management`
REQ-JOB-002); for an `event`-entryPoint trace of `action.kind: webhook` it
MUST resolve and return the request that would be dispatched WITHOUT
invoking the network call; for an `endpoint`-entryPoint trace it MUST run
`processRules()` with `dryRun: true` (`rule-pipeline` REQ-RULE-011),
suppressing every write-shaped rule's side effect. Every dry-run replay MUST
create a NEW `execution_trace` with `isReplay: true`, `dryRun: true`, and
`replayOf` set to the original trace's id — it MUST NOT mutate the original
trace.

@e2e exclude backend replay orchestration — covered by PHPUnit

#### Scenario: a dry-run replay of a failed sync-entryPoint trace makes no writes

- **GIVEN** a `failed` `execution_trace` with `entryPoint: 'sync'`
- **WHEN** an admin calls replay with no `force` flag
- **THEN** `SynchronizationService::replaySynchronizationItem()` is invoked
  with `isTest: true`
- **AND** no `synchronization_contract` or target object is created or
  updated
- **AND** a new `execution_trace` is persisted with `isReplay: true,
  dryRun: true, replayOf: '<original id>'`

#### Scenario: a dry-run replay of a webhook event-entryPoint trace never dispatches

- **GIVEN** an `execution_trace` with `entryPoint: 'event'` whose
  subscription resolves to `action.kind: 'webhook'`
- **WHEN** an admin calls replay with no `force` flag
- **THEN** the resolved outbound request (URL, method, headers) is returned
  in the response
- **AND** no HTTP request is dispatched to the sink

#### Scenario: a dry-run replay of an endpoint-entryPoint trace skips write rules

- **GIVEN** an `execution_trace` with `entryPoint: 'endpoint'` whose original
  execution ran a `mapping` rule then a `save_object` rule
- **WHEN** an admin calls replay with no `force` flag
- **THEN** the `mapping` rule executes for real and produces a real step
- **AND** the `save_object` rule does NOT persist an object; its step is
  recorded with `status: 'skipped_dry_run'`

### Requirement: Forced replay reuses the original entry point's real dispatch path (REQ-006)

`POST /api/execution-traces/{id}/replay` with `force: true` MUST perform a
real write using the SAME dispatch mechanism the original execution would
have used, never a bespoke re-implementation: `sync`-entryPoint traces
dispatch via `SynchronizationService::replaySynchronizationItem(isTest:
false)`; `job`-entryPoint traces dispatch via `JobService::executeJob()`
with test mode off; `event`-entryPoint traces dispatch via the existing
`EventService::attemptDelivery()` / `dead-letter-replay` REQ-DLR-003 path
unchanged; `endpoint`-entryPoint traces dispatch via `processRules()` with
`dryRun: false` (ordinary execution). A forced replay MUST NEVER read
outbound-call credentials from the stored (redacted) trace snapshot —
Source-level authentication MUST be re-resolved live by `CallService` from
the Source object exactly as in the original execution, matching the
existing `sync_item_dead_letter.payload` pattern where the stored payload is
business data, never a credential. Every forced replay MUST create a new
`execution_trace` with `isReplay: true`, `dryRun: false`, `replayOf` set to
the original trace's id.

@e2e exclude backend replay orchestration — covered by PHPUnit

#### Scenario: a forced replay of a failed sync-entryPoint trace writes for real

- **GIVEN** a `failed` `execution_trace` with `entryPoint: 'sync'` whose
  original mapping bug has since been corrected
- **WHEN** an admin calls replay with `force: true`
- **THEN** `SynchronizationService::replaySynchronizationItem()` is invoked
  with `isTest: false`
- **AND** the corresponding `synchronization_contract` is created/updated as
  if the item had succeeded on first processing
- **AND** a new `execution_trace` is persisted with `isReplay: true,
  dryRun: false, replayOf: '<original id>'`

#### Scenario: forced replay resolves live credentials, never the redacted snapshot

- **GIVEN** an `execution_trace` whose `call` step snapshot carries
  `***REDACTED***` in place of the original Source's `Authorization` header
- **WHEN** an admin calls replay with `force: true`
- **THEN** the replayed outbound call carries the Source's current live
  credential (resolved fresh by `CallService`), never the literal string
  `***REDACTED***`

### Requirement: Traces UI — typed list and detail timeline (REQ-007)

The app's manifest MUST expose a `Traces` page (`"type": "logs"`, following
the `SourceLogs`/`EndpointLogs`/`CloudEventLogs` precedent, config
`{register: 'openconnector', schema: 'execution_trace'}`) listing traces
with filters for `entryPoint`, `status`, and time range, and a `TraceDetail`
view rendering the ordered step timeline (type, duration, status per step,
with redacted input/output expandable per step) plus a "Replay" action
(dry-run by default, an explicit confirmation step required before a forced
replay). Every `NcSelect` filter control MUST carry an `inputLabel` prop
(never a bare `<label>` + `@keydown.enter`, per the recurring
`ncvue-schema-editor-related-object-and-enum-wiring` gotcha).

#### Scenario: operator inspects a trace's step timeline

- **GIVEN** an admin on the Traces list with one `failed` trace
- **WHEN** they open the trace's detail view
- **THEN** the ordered step timeline renders with each step's type,
  duration, and status, and expanding a step reveals its redacted
  input/output

#### Scenario: replay defaults to dry-run with a confirmation step for force

- **GIVEN** an admin viewing a `failed` trace's detail
- **WHEN** they click "Replay"
- **THEN** a dry-run preview runs and is shown before any write occurs
- **AND** a separate, explicitly confirmed action is required to force a
  real replay

#### Scenario: the entryPoint filter uses a labeled NcSelect

- **GIVEN** the Traces list page
- **WHEN** the entryPoint filter renders
- **THEN** the `NcSelect` carries `:input-label="t('openconnector',
  'Entry point')"`, matching the pattern already used in
  `EventDeliveriesPage.vue`

### Requirement: `traces_total` Prometheus counter via the AppHost observability engine (REQ-008)

The app's `src/manifest.json` `observability.metrics` array MUST declare a
`traces_total` counter descriptor with `source.kind: 'tableCount'` pointed
at the `execution_trace` register's backing table, grouped by `status`,
following the exact shape of the existing `calls_total`/
`synchronization_runs_total` descriptors (`apphost-adoption` capability) —
no bespoke `MetricsController` code.

@e2e exclude API-only endpoint — covered by the OR AppHost Newman contract collection

#### Scenario: traces_total is emitted per status

- **GIVEN** 10 `execution_trace` objects with `status` values 7 `success`,
  2 `failed`, 1 `running`
- **WHEN** `GET /apps/openconnector/api/metrics` is called by an admin
- **THEN** the output includes `openconnector_traces_total{status="success"}
  7`, `openconnector_traces_total{status="failed"} 2`, and
  `openconnector_traces_total{status="running"} 1`

### Requirement: Retention-bounded trace persistence (REQ-009)

The `execution_trace` schema's `x-openregister-archival.retention` MUST
default to `P30D`, with a `P7D` rule for `status == 'success' AND dryRun ==
false` and a `P1D` rule for `dryRun == true`, mirroring the
condition-branched retention pattern already used by `call_log`
(`http-call-engine` REQ-002's sibling schema annotation). The schema MUST
be declared `appendOnly: false, immutable: false` (a deliberate deviation
from `call_log`/`synchronization_log`'s append-only/immutable pattern — see
`design.md` Decision 2 for why the approval-resume continuation requires
mutability).

@e2e exclude backend retention annotation — covered by the register.d
fragment's own schema validation, not a runtime test

#### Scenario: a successful non-replay trace expires after 7 days

- **GIVEN** an `execution_trace` with `status: 'success', dryRun: false`
- **WHEN** the retention-rebase job evaluates its `expires`
- **THEN** `expires` is 7 days after `created`

#### Scenario: a dry-run preview trace expires after 1 day

- **GIVEN** an `execution_trace` with `dryRun: true`
- **WHEN** the retention-rebase job evaluates its `expires`
- **THEN** `expires` is 1 day after `created`

## Non-Functional Requirements

- **Performance:** trace-step buffering MUST NOT add a synchronous OpenRegister write per step — only the final `persist()` call writes (REQ-004), so per-execution overhead is bounded to one extra `saveObject` call plus in-memory array appends.
- **Internationalization:** Traces UI strings (REQ-007) MUST be translatable Dutch + English (hydra ADR-007).

## Acceptance Criteria

- [ ] An endpoint call produces one `execution_trace` spanning its rule, mapping, and outbound-call steps, with every snapshot redacted.
- [ ] A dry-run replay of a failed sync/job/event/endpoint trace performs zero writes and produces a new linked preview trace.
- [ ] A forced replay reuses the original entry point's real dispatch path and resolves credentials live, never from the stored snapshot.
- [ ] The Traces list/detail UI renders with a labeled `NcSelect` filter and a `traces_total` counter is scraped from `GET /apps/openconnector/api/metrics`.

## Notes

- Distributed tracing (OpenTelemetry export) is explicitly out of scope — see proposal.md.
- `call_log.sessionId` is repurposed to carry `traceId` (see the `http-call-engine` delta in this change, REQ-011) rather than adding a new column — see `design.md` Decision 5.
