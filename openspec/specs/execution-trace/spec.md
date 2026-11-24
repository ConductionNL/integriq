# execution-trace Specification

## Purpose
Every execution integriq runs, whether an endpoint call, a job, a synchronization or a rule, gets one trace id at its entry point and leaves one execution_trace record with its steps, timing and outcome. An administrator can replay a trace, and can send traces to an OpenTelemetry collector as spans that carry names, timing and status but never message content.

## Requirements

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
`{register: 'integriq', schema: 'execution_trace'}`) listing traces
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
- **THEN** the `NcSelect` carries `:input-label="t('integriq',
- @e2e exclude the Traces page renders and is mounted by `manifest-pages.spec.ts`, but nothing asserts that its entryPoint filter is a LABELED NcSelect. A real, testable gap on a real surface, not an infrastructure blocker; gate-nc-input-labels holds the same rule statically
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
- **WHEN** `GET /apps/integriq/api/metrics` is called by an admin
- **THEN** the output includes `integriq_traces_total{status="success"}
  7`, `integriq_traces_total{status="failed"} 2`, and
  `integriq_traces_total{status="running"} 1`

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

### Requirement: A persisted trace is exported as OpenTelemetry spans (REQ-OTEL-001)

When export is enabled and a trace is sampled, integriq MUST send the trace to
the configured collector at `{endpoint}/v1/traces` as OTLP JSON, with one root
span for the execution and one child span per retained step. The trace id MUST
equal the execution's `traceId` written as 32 hexadecimal characters. Each span
MUST carry its start time, end time and status. The root span MUST carry the
number of steps that were counted but not retained.

#### Scenario: a synchronization run appears in the collector
- GIVEN export enabled with a collector at `https://otel.example.org:4318` and sampling at 100%
- WHEN a synchronization run completes with a mapping step and two call steps
- THEN the collector receives one trace with a root span `integriq.sync` and three child spans, and its trace id equals the run's trace id without dashes
- @e2e exclude an outbound export to a collector; covered by PHPUnit on SpanMapper and OtlpTraceExporter against a recorded request

#### Scenario: a failed step is a failed span
- GIVEN a trace whose call step failed with status 503
- WHEN it is exported
- THEN that span has status error and carries `http.response.status_code` 503
- @e2e exclude a span attribute; covered by PHPUnit on SpanMapper

### Requirement: Export never delays the traced work (REQ-OTEL-002)

Integriq MUST NOT send spans on the request or run that produced the trace. It
MUST queue the trace and send it from a background job, and a collector that
is down MUST NOT change the response time or outcome of the traced work. A
failed batch MUST be retried at most three times, each retry later than the
one before, and then dropped with one log line. After five failed sends in a
row, sends MUST pause for five minutes and no new trace is queued meanwhile,
so a collector outage cannot tie up cron.

#### Scenario: the collector is down
- GIVEN export enabled and a collector that refuses connections
- WHEN a consumer calls an endpoint
- THEN the endpoint answers as it would with export off, and the trace is queued for the background job
- @e2e exclude a timing guarantee; covered by PHPUnit asserting no HTTP call during persist()

#### Scenario: a long collector outage pauses sends
- GIVEN export enabled and a collector that has refused five sends in a row
- WHEN the next trace is due for export
- THEN integriq sends nothing for five minutes, queues no new trace, and retries the waiting trace later
- @e2e exclude a background-job timing claim; covered by PHPUnit in `OtelExportTest`

### Requirement: Spans carry no message content (REQ-OTEL-003)

A span MUST NOT carry a step's `input` or `output`. Span attributes MUST be
limited to the step type and name, the status, the duration, the HTTP method,
the HTTP status code, the URL without its query string, fragment and
credentials and with every identifier-like path segment masked, the entry
point and the ids of the trace and the entry point object.

#### Scenario: a BSN never reaches the collector
- GIVEN a mapping step whose input contains a BSN
- WHEN the trace is exported
- THEN no attribute or event in the exported payload contains the BSN
- @e2e exclude a payload absence claim; covered by PHPUnit on SpanMapper

#### Scenario: a BSN in the URL path never reaches the collector
- GIVEN a call step to `https://svc:s3cret@brp.example.org/ingeschrevenpersonen/999993653`
- WHEN the trace is exported
- THEN its `url.full` is `https://brp.example.org/ingeschrevenpersonen/{id}` and the payload contains neither the BSN nor the credentials
- @e2e exclude a payload absence claim; covered by PHPUnit on SpanMapper

### Requirement: Trace context travels in and out as W3C traceparent (REQ-OTEL-004)

An endpoint request carrying a valid W3C `traceparent` MUST produce a trace
whose exported W3C trace id is the header's trace id and whose root span's
parent is the header's span id. The `execution_trace` record MUST keep an id
of integriq's own and hold the header's trace id apart, in `otelTraceId`, so a
caller can never choose which record its execution writes. An invalid header
MUST be ignored. Every outbound call made during a trace MUST carry a
`traceparent` naming the trace's W3C trace id and the span id of the call
step.

#### Scenario: a caller's trace continues into integriq
- GIVEN a consumer sending `traceparent: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01`
- WHEN it calls an integriq endpoint
- THEN the resulting execution trace has `otelTraceId` `4bf92f35-77b3-4da6-a3ce-929d0e0e4736`, its exported spans carry that trace id, and the exported root span has parent `00f067aa0ba902b7`
- @e2e exclude a header contract; covered by PHPUnit in `TraceContextPropagationTest`

#### Scenario: a known trace id cannot overwrite a record
- GIVEN two requests carrying the same valid `traceparent`
- WHEN both call an integriq endpoint
- THEN two `execution_trace` records exist, each with its own id, neither id being the header's trace id
- @e2e exclude a persistence-id claim; covered by PHPUnit in `TraceContextPropagationTest`

#### Scenario: a partner sees integriq's trace
- GIVEN an endpoint that proxies to a source during a trace
- WHEN integriq calls the source
- THEN the request carries a `traceparent` with the trace's id and the call step's span id
- @e2e exclude an outbound header; covered by PHPUnit on CallService

### Requirement: Export is configured by an administrator (REQ-OTEL-005)

The admin page MUST let an administrator enable export and set the collector
endpoint, the service name, the sampling ratio and collector headers given as a
credential reference. A failed or replayed trace MUST always be sampled.

#### Scenario: an administrator switches export on
- GIVEN an administrator on the integriq admin page
- WHEN they enable export, enter a collector endpoint and a sampling ratio of 0.1, and save
- THEN the settings are stored and the next sampled trace is queued for export
- e2e: tests/e2e/opentelemetry-export.spec.ts

### Requirement: Errors from the apps an administrator selects go to an error sink (REQ-OTEL-006)

When the error sink is enabled, integriq SHALL forward every log message whose
level is at or above the configured minimum (default `3`, error) and whose app
is in the configured list (default `integriq`, `openregister`, `opencatalogi`)
to the configured sink. The sink kind SHALL be `sentry` (a Sentry compatible
envelope posted to the endpoint derived from a DSN) or `otlp-logs` (OTLP JSON
posted to `{endpoint}/v1/logs`). Integriq SHALL read the messages from
Nextcloud's `OCP\Log\BeforeMessageLoggedEvent` (`getApp(): string`,
`getLevel(): int`, `getMessage(): array`), so no app needs code of its own.
Forwarding SHALL be queued and sent by a background job, as REQ-OTEL-002
requires for spans. A forwarded event SHALL carry only the app, the level,
the time, the exception class, the file, the line, the request id and the
message text after `BodyRedactor` has run. Integriq SHALL NOT log from inside
the listener, so a failing sink cannot raise an error about itself.

#### Scenario: an error in openregister reaches the organisation's Sentry
- GIVEN the error sink enabled with kind `sentry`, a DSN for `https://sentry.example.org`, and `openregister` in the app list
- WHEN openregister logs an exception at level error
- THEN one event is queued, and the background job posts it to the Sentry envelope endpoint with the exception class and the app `openregister`
- @e2e exclude an outbound post to an error service; covered by PHPUnit `ErrorSinkListenerTest::testAnOpenRegisterErrorIsQueuedForTheSink` and `ErrorSinkExporterTest::testASentryEnvelopeIsPostedToTheDsnEndpoint`

#### Scenario: a BSN in an error message never leaves
- GIVEN an error message that contains the BSN `999993653`
- WHEN it is forwarded
- THEN the posted payload does not contain `999993653`
- @e2e exclude a payload absence claim; covered by PHPUnit `ErrorSinkExporterTest::testTheRedactorRunsBeforeTheEventIsQueued`

#### Scenario: a message below the level or from an unlisted app stays home
- GIVEN the minimum level error and an app list without `dossiq`
- WHEN dossiq logs an error and openregister logs a warning
- THEN nothing is queued
- @e2e exclude a filter on a log event; covered by PHPUnit `ErrorSinkListenerTest::testUnlistedAppsAndLowLevelsAreNotQueued`

#### Scenario: a sink that is down changes nothing for the request
- GIVEN the error sink enabled and an endpoint that refuses connections
- WHEN openregister logs an error during a request
- THEN the request answers as it would with the sink off, no HTTP call is made during the request, and the job retries at most three times
- @e2e exclude a timing guarantee; covered by PHPUnit `ErrorSinkListenerTest::testTheListenerMakesNoHttpCall`

### Requirement: Another app hands integriq its spans through a typed command (REQ-OTEL-007)

Integriq SHALL offer `OCA\Integriq\Event\SpanExportRequestedEvent`, constructed
as `new SpanExportRequestedEvent(string $appId, array $spans)`. Each span SHALL
be an array with the keys `traceId` (32 lowercase hex), `spanId` (16 lowercase
hex), `parentSpanId` (16 hex or absent), `name` (string), `startTimeUnixNano`
(int), `endTimeUnixNano` (int), `status` (`ok` or `error`) and `attributes`
(a map of string keys to scalar values). The listener SHALL validate every
span, queue the valid ones for the same background job and sampler as
integriq's own traces, and answer through `getResult(): array` with the keys
`queued` (int), `rejected` (int) and `reasons` (list of strings). A span with
an attribute key outside the REQ-OTEL-003 allowlist SHALL have that attribute
dropped, not the span. When export is disabled the result SHALL be
`queued: 0` with the reason `export-disabled`. An event no listener handled
SHALL answer `queued: 0` with the reason `no-listener`. The event SHALL be
handled without a Nextcloud session.

#### Scenario: an opencatalogi request is traced in the same collector
- GIVEN export enabled, sampling at 100%, and a collector at `https://otel.example.org:4318`
- WHEN opencatalogi dispatches `SpanExportRequestedEvent` with one span `GET /api/publications` and trace id `4bf92f3577b34da6a3ce929d0e0e4736`
- THEN `getResult()` answers `queued: 1`, and the collector receives a span with that trace id and the service name `opencatalogi`
- @e2e exclude a backend command; covered by PHPUnit `SpanExportRequestedListenerTest::testAValidSpanIsQueuedAndAnsweredQueued` with the real event class

#### Scenario: a malformed span is refused with a reason
- GIVEN a span whose `traceId` is 30 characters
- WHEN it is dispatched
- THEN `getResult()` answers `queued: 0`, `rejected: 1`, and a reason naming `traceId`
- @e2e exclude a validation answer; covered by PHPUnit `SpanExportRequestedListenerTest::testAMalformedSpanIsRejectedWithItsField`

### Requirement: The error sink is configured on the admin page (REQ-OTEL-008)

The integriq admin page SHALL let an administrator enable the error sink and
set its kind, its endpoint or DSN, its headers as a credential reference
(ADR-064), the minimum level and the list of apps. The endpoint SHALL be
validated as `https`, or as a host the administrator marked internal, as
REQ-OTEL-005 does for the collector. Saving with the sink enabled and no
endpoint SHALL be refused.

#### Scenario: an administrator points errors at Sentry
- GIVEN an administrator on the integriq admin page
- WHEN they enable the error sink, choose `sentry`, paste a DSN, keep the default apps and save
- THEN the settings are stored, and the next openregister error is queued for that DSN
- e2e: tests/e2e/opentelemetry-export.spec.ts

#### Scenario: an enabled sink without an endpoint is refused
- GIVEN the error sink switched on with an empty endpoint
- WHEN the administrator saves
- THEN the save is refused with a message naming the endpoint field
- @e2e exclude a server-side validation; covered by PHPUnit `SettingsServiceTest::testAnEnabledErrorSinkNeedsAnEndpoint`
