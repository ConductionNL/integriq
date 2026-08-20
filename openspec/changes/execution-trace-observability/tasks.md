# Tasks: execution-trace-observability

## Implementation Tasks

### Task 1: Add the `execution_trace` register.d fragment schema
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004`
- **files**: `lib/Settings/register.d/execution-trace-observability.json`
- **acceptance_criteria**:
  - GIVEN the fragment file exists WHEN `InitializeRegister`'s repair step runs (`occ maintenance:repair`) THEN the `execution_trace` schema is merged into the `openconnector` register with fields `traceId, entryPoint, entryPointId, status, startedAt, finishedAt, durationMs, steps, error, replayOf, isReplay, dryRun, triggeredBy`, `appendOnly: false`, `immutable: false`, and the `x-openregister-archival` retention rules from design.md Decision 2 (default `P30D`, `P7D` for successful non-replay traces, `P1D` for dry-run previews)
  - Follow the `lib/Settings/register.d/hitl-approval-rule-action.json` fragment shape exactly (`$comment` referencing this change's design.md, `components.registers.openconnector.schemas` append, `components.schemas.execution_trace`)
- [ ] Implement
- [ ] Test

### Task 2: Add `ExecutionTraceContext` value object
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001`
- **files**: `lib/Service/Helper/ExecutionTraceContext.php`
- **acceptance_criteria**:
  - GIVEN `new ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: $id)` WHEN constructed THEN `traceId` is a fresh UUIDv4 (via `Symfony\Component\Uid\Uuid`, same import already used in `EndpointService.php`), `startedAt` is set, and the step buffer is empty
  - GIVEN a context WHEN `addStep(type, name, timing, status, input, output)` is called THEN the step is appended with the next sequential `order` and `durationMs` computed from the step's own start/end
- [ ] Implement
- [ ] Test

### Task 3: Mint and propagate `traceId` through `EndpointService`'s rule pipeline
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/rule-pipeline/spec.md#requirement-trace-step-emission-during-rule-pipeline-execution-req-rule-009`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN a request reaches `handleRequest()`/`doHandleRequest()` WHEN it constructs the `FlowToken` (line ~298) THEN it also constructs an `ExecutionTraceContext` and threads it as an additional `?ExecutionTraceContext $trace = null` parameter into `processRules()` (line 1665) and `dispatchAfterBeforeRules()` (line 406)
  - GIVEN a traced execution WHEN `processRules()` evaluates each rule (matching REQ-RULE-009's scenarios) THEN one step is appended per rule, including skipped rules
  - GIVEN no trace context is supplied WHEN `processRules()` runs THEN behaviour is unchanged from the pre-existing REQ-RULE-001 scenarios
- [ ] Implement
- [ ] Test

### Task 4: Add `dryRun` write-suppression to `processRules()`
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/rule-pipeline/spec.md#requirement-dry-run-mode-suppresses-write-shaped-rule-dispatch-req-rule-010`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN `processRules(..., dryRun: true)` WHEN a `save_object`/`override`/`locking`/`write_file`/`fileparts_create`/`filepart_upload`/`composite_fanout` rule is reached THEN its write is skipped and a `status: 'skipped_dry_run'` step is recorded
  - GIVEN `dryRun: true` WHEN a `mapping`/`extend_input`/`authentication`/`error` rule is reached THEN it executes normally
  - GIVEN `dryRun: true` WHEN a `synchronization` rule is reached THEN `SynchronizationService::synchronize()` is called with `isTest: true`
  - GIVEN `dryRun` is omitted (default `false`) WHEN any existing REQ-RULE-001..008 scenario runs THEN behaviour is unchanged
- [ ] Implement
- [ ] Test

### Task 5: Propagate `traceId` through `SynchronizationService`; add `isTest` to `replaySynchronizationItem()`
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a manual synchronization run (`synchronize()`) WHEN it starts THEN a fresh `ExecutionTraceContext(entryPoint: 'sync')` is minted and threaded into `processSynchronizationObject()`, appending one step per synchronized item
  - GIVEN `replaySynchronizationItem(synchronization, payload, isTest: true)` (new optional param, default `false`) WHEN invoked THEN it forwards `isTest: true` into `processSynchronizationObject()` and no target write occurs
  - GIVEN existing `dead-letter-replay` REQ-DLR-009 callers (which never pass `isTest`) WHEN they invoke `replaySynchronizationItem()` THEN behaviour is unchanged (`isTest: false` default preserved)
- [ ] Implement
- [ ] Test

### Task 6: Propagate `traceId` through `CallService`; populate `call_log.sessionId`; emit the call step
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/http-call-engine/spec.md#requirement-trace-scoped-call-correlation-via-call_logsessionid-req-011`
- **files**: `lib/Service/CallService.php`
- **acceptance_criteria**:
  - GIVEN `call()` is invoked with an active `ExecutionTraceContext` WHEN `buildAndPersistCallLog()` persists the `call_log` THEN `sessionId` is set to `traceId`
  - GIVEN no active trace context WHEN a call completes THEN `sessionId` stays unset, unchanged from current behaviour
  - GIVEN an active trace context WHEN `buildResponseData()` produces its already-redacted request/response array THEN that exact array (not a re-derived one) is appended to the trace as the `call` step's output
  - Existing REQ-001..REQ-010/REQ-SBC-001..004 scenarios in `http-call-engine` MUST remain unaffected — verify via the existing PHPUnit suite for `CallServiceTest`
- [ ] Implement
- [ ] Test

### Task 7: Mint `traceId` at the job-run entry point
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001`
- **files**: `lib/Service/JobService.php`
- **acceptance_criteria**:
  - GIVEN `executeJob()` runs (cron-triggered or manual) WHEN it starts THEN an `ExecutionTraceContext(entryPoint: 'job', entryPointId: <jobId>)` is minted and threaded into whatever downstream synchronization/endpoint dispatch the job performs
  - GIVEN `executeJob()`'s existing test mode (`job-management` REQ-JOB-002) is active WHEN a traced replay dry-run reaches this entry point THEN test mode is used unchanged, per `execution-trace` REQ-005
- [ ] Implement
- [ ] Test

### Task 8: Mint `traceId` at the event-delivery entry point; add webhook dry-run preview
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005`
- **files**: `lib/Service/EventService.php`
- **acceptance_criteria**:
  - GIVEN `attemptDelivery()` runs WHEN it starts THEN an `ExecutionTraceContext(entryPoint: 'event', entryPointId: <messageId>)` is minted and threaded through the `action.kind` dispatch (`deliverMessage`/`dispatchSynchronizationAction`/`dispatchJobAction`)
  - GIVEN a dry-run replay of a `webhook`-kind trace WHEN `ExecutionTraceService::replay()` calls into this entry point with dry-run THEN the resolved outbound request (URL/method/headers) is returned WITHOUT invoking `deliverMessage()`/`CallService::call()`
  - Existing `dead-letter-replay` REQ-DLR-003/REQ-DLR-006 forced-replay behaviour MUST remain unchanged
- [ ] Implement
- [ ] Test

### Task 9: `ExecutionTraceService` — assembly, persistence, retrieval, replay orchestration
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004`
- **files**: `lib/Service/ExecutionTraceService.php`
- **acceptance_criteria**:
  - GIVEN an `ExecutionTraceContext` at the end of a traced execution WHEN `persist()` is called THEN exactly one `execution_trace` object is created (or, for the approval-resume continuation only, updated) via OpenRegister `saveObject(register: 'openconnector', schema: 'execution_trace', uuid: $context->traceId)`
  - GIVEN `replay(traceId, actorUid, force)` WHEN `force` is omitted or `false` THEN it dispatches per `execution-trace` REQ-005's per-entryPoint dry-run branching and creates a new linked preview trace
  - GIVEN `replay(traceId, actorUid, force: true)` WHEN invoked THEN it dispatches per REQ-006's real-write branching, resolving credentials live (never from the stored redacted snapshot) and creates a new linked trace
  - GIVEN a missing `traceId` WHEN `replay()` is called THEN it returns 404
- [ ] Implement
- [ ] Test

### Task 10: `ExecutionTracesController` REST surface
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007`
- **files**: `lib/Controller/ExecutionTracesController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `GET /apps/openconnector/api/execution-traces` WHEN called THEN it lists `execution_trace` objects with `entryPoint`/`status`/time-range filters and pagination, matching the `LogsController::index` pattern (`logs-and-statistics` REQ-001)
  - GIVEN `GET /apps/openconnector/api/execution-traces/{id}` WHEN called THEN it returns the full trace including the ordered `steps` array
  - GIVEN `POST /apps/openconnector/api/execution-traces/{id}/replay` WHEN called with no body or `{force: false}` THEN it performs a dry-run replay (REQ-005); WHEN called with `{force: true}` THEN it performs a forced replay (REQ-006)
  - All three endpoints carry `@NoAdminRequired` + `@NoCSRFRequired`, consistent with the existing `LogsController`/`SourcesController` posture in this codebase (documented as observed convention, not re-litigated by this change)
- [ ] Implement
- [ ] Test

### Task 11: Approval-resume trace continuation
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004`
- **files**: `lib/Service/EndpointService.php`, `lib/Service/ApprovalService.php`
- **acceptance_criteria**:
  - GIVEN an `approval` rule suspends a traced execution WHEN the trace is persisted at suspension THEN `status: 'running'` and the `before`-phase steps are saved, and the `traceId` is carried in the persisted `approval_request.snapshot` alongside the `FlowToken` serialization
  - GIVEN `ApprovalService::rehydrateFlowToken()` runs on resume WHEN it reconstructs the `FlowToken` THEN it also reconstructs an `ExecutionTraceContext` pre-loaded with the original `traceId` and prior steps, so `resumeFromApproval()` appends (never recreates) the trace
- [ ] Implement
- [ ] Test

### Task 12: Traces manifest v2 pages (list + detail timeline)
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007`
- **files**: `src/manifest.json`, `src/views/ExecutionTrace/TracesPage.vue`, `src/views/ExecutionTrace/TraceDetailPage.vue`, `src/views/ExecutionTrace/TraceTimelineWidget.vue`
- **acceptance_criteria**:
  - GIVEN `src/manifest.json` WHEN a `Traces` page entry is added THEN it follows the `SourceLogs`/`EndpointLogs`/`CloudEventLogs` `"type": "logs"` precedent with `config: {register: 'openconnector', schema: 'execution_trace'}`
  - GIVEN the detail view WHEN a trace is opened THEN the ordered step timeline renders (type/duration/status per step, expandable redacted input/output) via a body-slot widget registered per the existing kind-agnostic slot resolver (ADR-036) — confirm the exact current slot-registration key against `src/manifest.d/` at implementation time (not fully pinned by this task; see design.md's UI note)
  - GIVEN the entryPoint/status filters WHEN rendered THEN every `NcSelect` carries `:input-label`, matching `EventDeliveriesPage.vue:28-31`
  - GIVEN the detail view WHEN "Replay" is clicked THEN a dry-run preview is shown first, with a separate confirmation step required before a forced replay request is sent
- [ ] Implement
- [ ] Test

### Task 13: `traces_total` AppHost `tableCount` observability descriptor
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-traces_total-prometheus-counter-via-the-apphost-observability-engine-req-008`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `observability.metrics` WHEN a `traces_total` descriptor is added THEN it follows the exact `source.kind: 'tableCount'`/`groupBy`/`labelMap`/`labelDefaults` shape of the existing `calls_total`/`synchronization_runs_total` descriptors (lines 40-59/69-86), grouped by `status`
  - GIVEN a seeded instance with mixed-status `execution_trace` rows WHEN `GET /apps/openconnector/api/metrics` is called THEN `openconnector_traces_total{status="..."}` values match direct table counts
- [ ] Implement
- [ ] Test

### Task 14: Unit tests — traceId propagation and redaction-in-snapshot
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-snapshot-redaction-before-any-step-is-buffered-req-003`
- **files**: `tests/Unit/Service/Helper/ExecutionTraceContextTest.php`, `tests/Unit/Service/ExecutionTraceServiceTest.php`
- **acceptance_criteria**:
  - Covers `execution-trace` REQ-001 (traceId minted once, shared across steps; untraced calls produce no trace), REQ-003 (redacted snapshot scenarios), and `rule-pipeline` REQ-RULE-009/REQ-RULE-010's scenarios
- [ ] Implement
- [ ] Test

### Task 15: Integration test — one endpoint call spans rule → mapping → call
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002`
- **files**: `tests/Integration/ExecutionTraceIntegrationTest.php`, `tests/postman/openconnector.postman_collection.json` (new "Execution traces" folder)
- **acceptance_criteria**:
  - GIVEN an endpoint configured with a `mapping` rule and a `save_object` rule that triggers one outbound `CallService` call WHEN the endpoint is called THEN exactly one `execution_trace` is persisted with steps for the rule, the mapping, and the call, all sharing one `traceId`, and the call step's snapshot matches the persisted `call_log`'s redacted data (per `http-call-engine` REQ-011)
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- [ ] Newman/Postman tests for new/changed API endpoints (`ExecutionTracesController`)
- [ ] Browser tests (Playwright MCP) for the Traces list/detail UI and replay confirmation flow
- [ ] All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` (new "Execution traces" page under `docs/features/`)
- [ ] Screenshot captured and committed to `docs/images/` (Traces list + detail timeline)

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added for the Traces UI (list filters, detail timeline labels, replay confirmation dialog)
