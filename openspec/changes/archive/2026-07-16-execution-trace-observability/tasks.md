# Tasks: execution-trace-observability

## Implementation Tasks

### Task 1: Add the `execution_trace` register.d fragment schema
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004`
- **files**: `lib/Settings/register.d/execution-trace-observability.json`
- **acceptance_criteria**:
  - GIVEN the fragment file exists WHEN `InitializeRegister`'s repair step runs (`occ maintenance:repair`) THEN the `execution_trace` schema is merged into the `openconnector` register with fields `traceId, entryPoint, entryPointId, status, startedAt, finishedAt, durationMs, steps, error, replayOf, isReplay, dryRun, triggeredBy`, `appendOnly: false`, `immutable: false`, and the `x-openregister-archival` retention rules from design.md Decision 2 (default `P30D`, `P7D` for successful non-replay traces, `P1D` for dry-run previews)
  - Follow the `lib/Settings/register.d/hitl-approval-rule-action.json` fragment shape exactly (`$comment` referencing this change's design.md, `components.registers.openconnector.schemas` append, `components.schemas.execution_trace`)
- [x] Implement
- [ ] Test — NOT DONE: the acceptance criterion is the `occ maintenance:repair` fragment-merge on a live instance, which this build had no instance for. Static validation passed (`npm run check:register`, `check:json-strict`), and the fragment mirrors `hitl-approval-rule-action.json`'s shape, but the actual merge is unverified.

### Task 2: Add `ExecutionTraceContext` value object
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001`
- **files**: `lib/Service/Helper/ExecutionTraceContext.php`
- **acceptance_criteria**:
  - GIVEN `new ExecutionTraceContext(entryPoint: 'endpoint', entryPointId: $id)` WHEN constructed THEN `traceId` is a fresh UUIDv4 (via `Symfony\Component\Uid\Uuid`, same import already used in `EndpointService.php`), `startedAt` is set, and the step buffer is empty
  - GIVEN a context WHEN `addStep(type, name, timing, status, input, output)` is called THEN the step is appended with the next sequential `order` and `durationMs` computed from the step's own start/end
- [x] Implement
- [x] Test — `tests/Unit/Service/Helper/ExecutionTraceContextTest.php` (9 tests: UUIDv4 mint, distinct ids, explicit-id reuse, sequential order, durationMs from own start/end, no-redaction contract, priorSteps continuation, replay flags).

### Task 3: Mint and propagate `traceId` through `EndpointService`'s rule pipeline
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/rule-pipeline/spec.md#requirement-trace-step-emission-during-rule-pipeline-execution-req-rule-010`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN a request reaches `handleRequest()`/`doHandleRequest()` WHEN it constructs the `FlowToken` (line ~298) THEN it also constructs an `ExecutionTraceContext` and threads it as an additional `?ExecutionTraceContext $trace = null` parameter into `processRules()` (line 1665) and `dispatchAfterBeforeRules()` (line 406)
  - GIVEN a traced execution WHEN `processRules()` evaluates each rule (matching REQ-RULE-010's scenarios) THEN one step is appended per rule, including skipped rules
  - GIVEN no trace context is supplied WHEN `processRules()` runs THEN behaviour is unchanged from the pre-existing REQ-RULE-001 scenarios
- [x] Implement
- [x] Test — `EndpointServiceTest::testProcessRulesEmitsOrderedStepsIncludingSkipped` (ordered steps, skipped rule still emits a step) + `testProcessRulesWithoutTraceRunsUnaffected` (null trace ⇒ untraced behaviour).

### Task 4: Add `dryRun` write-suppression to `processRules()`
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/rule-pipeline/spec.md#requirement-dry-run-mode-suppresses-write-shaped-rule-dispatch-req-rule-011`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN `processRules(..., dryRun: true)` WHEN a `save_object`/`override`/`locking`/`write_file`/`fileparts_create`/`filepart_upload`/`composite_fanout` rule is reached THEN its write is skipped and a `status: 'skipped_dry_run'` step is recorded
  - GIVEN `dryRun: true` WHEN a `mapping`/`extend_input`/`authentication`/`error` rule is reached THEN it executes normally
  - GIVEN `dryRun: true` WHEN a `synchronization` rule is reached THEN `SynchronizationService::synchronize()` is called with `isTest: true`
  - GIVEN `dryRun` is omitted (default `false`) WHEN any existing REQ-RULE-001..008 scenario runs THEN behaviour is unchanged
- [x] Implement
- [x] Test — all three REQ-RULE-011 scenarios covered: `testProcessRulesDryRunSuppressesSaveObjectWrite` (write suppressed, `skipped_dry_run`, container never resolved), `testProcessRulesDryRunDoesNotSuppressMappingRule` (mapping executes for real), `testProcessRulesDryRunForwardsIsTestToSynchronizationRule` (`isTest: true` forwarded, not blanket-skipped). Default-`false` no-change is covered by the pre-existing suite staying green (1553→1578, 0 failures).

### Task 5: Propagate `traceId` through `SynchronizationService`; add `isTest` to `replaySynchronizationItem()`
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005`
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a manual synchronization run (`synchronize()`) WHEN it starts THEN a fresh `ExecutionTraceContext(entryPoint: 'sync')` is minted and threaded into `processSynchronizationObject()`, appending one step per synchronized item
  - GIVEN `replaySynchronizationItem(synchronization, payload, isTest: true)` (new optional param, default `false`) WHEN invoked THEN it forwards `isTest: true` into `processSynchronizationObject()` and no target write occurs
  - GIVEN existing `dead-letter-replay` REQ-DLR-009 callers (which never pass `isTest`) WHEN they invoke `replaySynchronizationItem()` THEN behaviour is unchanged (`isTest: false` default preserved)
- [x] Implement
- [x] Test — `isTest` forwarding covered both ways by `ExecutionTraceServiceTest::testDryRunReplayOfSyncTraceForwardsIsTestTrue` / `testForcedReplayOfSyncTraceForwardsIsTestFalse`; the `isTest: false` default for existing REQ-DLR-009 callers is covered by the pre-existing dead-letter suite staying green. NOTE (partial): `synchronize()`'s own self-minted `sync`-entryPoint context + per-item step emission has no dedicated unit test — `SynchronizationService` is a 7.5k-line service whose `synchronize()` needs a deep collaborator graph; the mint/persist path is exercised indirectly via the replay tests. Flagged rather than claimed.

### Task 6: Propagate `traceId` through `CallService`; populate `call_log.sessionId`; emit the call step
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/http-call-engine/spec.md#requirement-trace-scoped-call-correlation-via-call_logsessionid-req-011`
- **files**: `lib/Service/CallService.php`
- **acceptance_criteria**:
  - GIVEN `call()` is invoked with an active `ExecutionTraceContext` WHEN `buildAndPersistCallLog()` persists the `call_log` THEN `sessionId` is set to `traceId`
  - GIVEN no active trace context WHEN a call completes THEN `sessionId` stays unset, unchanged from current behaviour
  - GIVEN an active trace context WHEN `buildResponseData()` produces its already-redacted request/response array THEN that exact array (not a re-derived one) is appended to the trace as the `call` step's output
  - Existing REQ-001..REQ-010/REQ-SBC-001..004 scenarios in `http-call-engine` MUST remain unaffected — verify via the existing PHPUnit suite for `CallServiceTest`
- [x] Implement
- [x] Test — `tests/Integration/ExecutionTraceIntegrationTest.php` exercises the REAL `CallService` end-to-end (only OR persistence + Guzzle transport doubled): `testTracedCallStampsCallLogSessionId`, `testUntracedCallLeavesSessionIdUnset`, `testTraceCallStepReusesCallLogsRedactedDataByteForByte` (asserts the step's snapshot IS the same redacted array persisted to `call_log`, and that no plaintext `client_secret` survives in either). Existing `CallServiceTest` stays green.

### Task 7: Mint `traceId` at the job-run entry point
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-execution-id-minted-at-every-entry-point-and-propagated-through-the-pipeline-req-001`
- **files**: `lib/Service/JobService.php`
- **acceptance_criteria**:
  - GIVEN `executeJob()` runs (cron-triggered or manual) WHEN it starts THEN an `ExecutionTraceContext(entryPoint: 'job', entryPointId: <jobId>)` is minted and threaded into whatever downstream synchronization/endpoint dispatch the job performs
  - GIVEN `executeJob()`'s existing test mode (`job-management` REQ-JOB-002) is active WHEN a traced replay dry-run reaches this entry point THEN test mode is used unchanged, per `execution-trace` REQ-005
- [x] Implement — `executeJob()` mints a `job`-entryPoint context (after the enabled/due gate, so an idle cron tick makes no trace), threads it to job actions via the internal `_executionTrace` argument (consumed by `SynchronizationAction`), and persists once on completion.
- [ ] Test — NOT DONE: no dedicated unit test for the job-entryPoint mint/persist. `JobServiceTest` exists and stays green, but it does not cover the new path. **The second acceptance criterion is also not satisfiable as written** — see the disclosed deviation in `ExecutionTraceService::replayJob()`'s docblock: `executeJob()`'s `$forceRun` only bypasses the enabled/schedule gate, it is NOT a no-write test mode (`JobsController::test()` already passes `forceRun: true` for a real run), so there is no existing job-level no-write guarantee to reuse. A job-entryPoint replay therefore always runs for real regardless of `force`. Flagged for follow-up rather than silently claimed.

### Task 8: Mint `traceId` at the event-delivery entry point; add webhook dry-run preview
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-dry-run-replay-performs-no-writes-req-005`
- **files**: `lib/Service/EventService.php`
- **acceptance_criteria**:
  - GIVEN `attemptDelivery()` runs WHEN it starts THEN an `ExecutionTraceContext(entryPoint: 'event', entryPointId: <messageId>)` is minted and threaded through the `action.kind` dispatch (`deliverMessage`/`dispatchSynchronizationAction`/`dispatchJobAction`)
  - GIVEN a dry-run replay of a `webhook`-kind trace WHEN `ExecutionTraceService::replay()` calls into this entry point with dry-run THEN the resolved outbound request (URL/method/headers) is returned WITHOUT invoking `deliverMessage()`/`CallService::call()`
  - Existing `dead-letter-replay` REQ-DLR-003/REQ-DLR-006 forced-replay behaviour MUST remain unchanged
- [x] Implement — `attemptDelivery()` mints the `event`-entryPoint context and persists it (dispatch switch extracted to `attemptDeliveryDispatch()` so the mint/persist wrapper is one choke point); `previewWebhookDelivery()` resolves the would-be request without dispatching; `deliverMessage()` redacts and records its own `call` step (it uses `IClientService` directly, bypassing CallService).
- [x] Test — `ExecutionTraceServiceTest::testDryRunReplayOfEventTraceNeverDispatches` (asserts `replayMessage` is NEVER called and `previewWebhookDelivery` IS) + `testForcedReplayOfEventTraceDelegatesToReplayMessage` (asserts the existing REQ-DLR-003 path is used unchanged and the preview is NOT). NOTE (partial): `attemptDelivery()`'s own mint/persist has no dedicated test; the pre-existing `EventService` suites stay green.

### Task 9: `ExecutionTraceService` — assembly, persistence, retrieval, replay orchestration
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004`
- **files**: `lib/Service/ExecutionTraceService.php`
- **acceptance_criteria**:
  - GIVEN an `ExecutionTraceContext` at the end of a traced execution WHEN `persist()` is called THEN exactly one `execution_trace` object is created (or, for the approval-resume continuation only, updated) via OpenRegister `saveObject(register: 'openconnector', schema: 'execution_trace', uuid: $context->traceId)`
  - GIVEN `replay(traceId, actorUid, force)` WHEN `force` is omitted or `false` THEN it dispatches per `execution-trace` REQ-005's per-entryPoint dry-run branching and creates a new linked preview trace
  - GIVEN `replay(traceId, actorUid, force: true)` WHEN invoked THEN it dispatches per REQ-006's real-write branching, resolving credentials live (never from the stored redacted snapshot) and creates a new linked trace
  - GIVEN a missing `traceId` WHEN `replay()` is called THEN it returns 404
- [x] Implement — resolves `EndpointService`/`SynchronizationService`/`JobService`/`EventService` lazily via `ContainerInterface` inside `replay()` only, so those four can constructor-inject this service without a circular DI graph.
- [x] Test — `tests/Unit/Service/ExecutionTraceServiceTest.php` (6 tests): persist-uses-traceId-as-uuid, `find()` returns null when absent, `replay()` throws `DoesNotExistException` (controller maps to 404), sync dry-run/forced `isTest` branching, event dry-run/forced branching. Credentials-live (REQ-006) is structural — replay passes only the business payload and never reads the redacted snapshot for auth.

### Task 10: `ExecutionTracesController` REST surface
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007`
- **files**: `lib/Controller/ExecutionTracesController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `GET /apps/openconnector/api/execution-traces` WHEN called THEN it lists `execution_trace` objects with `entryPoint`/`status`/time-range filters and pagination, matching the `LogsController::index` pattern (`logs-and-statistics` REQ-001)
  - GIVEN `GET /apps/openconnector/api/execution-traces/{id}` WHEN called THEN it returns the full trace including the ordered `steps` array
  - GIVEN `POST /apps/openconnector/api/execution-traces/{id}/replay` WHEN called with no body or `{force: false}` THEN it performs a dry-run replay (REQ-005); WHEN called with `{force: true}` THEN it performs a forced replay (REQ-006)
  - All three endpoints carry `@NoAdminRequired` + `@NoCSRFRequired`, consistent with the existing `LogsController`/`SourcesController` posture in this codebase (documented as observed convention, not re-litigated by this change)
- [x] Implement — 3 routes registered (static before `{id}` wildcard, matching the `events#`/`syncDeadLetter#` ordering); `composer check:routes` PASS (175 routes, all resolve).
- [ ] Test — NOT DONE: no PHPUnit controller test, and the Newman folder added in Task 15 was not executed (needs a live instance + seeded traces). Route reachability is statically verified by `check:routes`; the service layer beneath is unit-tested (Task 9).

### Task 11: Approval-resume trace continuation
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-trace-persistence-as-one-execution_trace-object-per-execution-req-004`
- **files**: `lib/Service/EndpointService.php`, `lib/Service/ApprovalService.php`
- **acceptance_criteria**:
  - GIVEN an `approval` rule suspends a traced execution WHEN the trace is persisted at suspension THEN `status: 'running'` and the `before`-phase steps are saved, and the `traceId` is carried in the persisted `approval_request.snapshot` alongside the `FlowToken` serialization
  - GIVEN `ApprovalService::rehydrateFlowToken()` runs on resume WHEN it reconstructs the `FlowToken` THEN it also reconstructs an `ExecutionTraceContext` pre-loaded with the original `traceId` and prior steps, so `resumeFromApproval()` appends (never recreates) the trace
- [x] Implement — `suspend()` stamps `traceId`/`traceSteps` into the snapshot and persists the trace `status: 'running'`; `rehydrateTraceContext()` (a sibling of `rehydrateFlowToken()`, returning null for untraced/pre-existing requests) rebuilds the context; `ApprovalsController` passes it to `resumeFromApproval()`, which finalizes with `resume: true`. Order continuation is guaranteed by `ExecutionTraceContext`'s `priorSteps` seeding (unit-tested in Task 2's `testPriorStepsSeedOrderContinuation`).
- [ ] Test — NOT DONE: no dedicated test for the suspend→resume round trip. It spans `EndpointService` + `ApprovalService` + `ApprovalsController` and needs a suspended-approval fixture; the `priorSteps` continuation primitive it relies on IS unit-tested, but the wiring itself is unverified. Pre-existing `ApprovalServiceTest` stays green.

### Task 12: Traces manifest v2 pages (list + detail timeline)
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-traces-ui--typed-list-and-detail-timeline-req-007`
- **files**: `src/manifest.json`, `src/views/ExecutionTrace/TracesPage.vue`, `src/views/ExecutionTrace/TraceDetailPage.vue`, `src/views/ExecutionTrace/TraceTimelineWidget.vue`
- **acceptance_criteria**:
  - GIVEN `src/manifest.json` WHEN a `Traces` page entry is added THEN it follows the `SourceLogs`/`EndpointLogs`/`CloudEventLogs` `"type": "logs"` precedent with `config: {register: 'openconnector', schema: 'execution_trace'}`
  - GIVEN the detail view WHEN a trace is opened THEN the ordered step timeline renders (type/duration/status per step, expandable redacted input/output) via a body-slot widget registered per the existing kind-agnostic slot resolver (ADR-036) — confirm the exact current slot-registration key against `src/manifest.d/` at implementation time (not fully pinned by this task; see design.md's UI note)
  - GIVEN the entryPoint/status filters WHEN rendered THEN every `NcSelect` carries `:input-label`, matching `EventDeliveriesPage.vue:28-31`
  - GIVEN the detail view WHEN "Replay" is clicked THEN a dry-run preview is shown first, with a separate confirmation step required before a forced replay request is sent
- [x] Implement — with two disclosed deviations from this task's `files` list:
  (a) **No `TracesPage.vue`.** REQ-007 mandates the `"type": "logs"` precedent, which resolves to nc-vue's generic `CnLogsPage`; writing a bespoke list component would have contradicted the requirement. The `Traces` manifest page is therefore declarative-only (`type: logs`, `config.register/schema/detailRoute`), matching `SourceLogs`/`EndpointLogs`/`CloudEventLogs`.
  (b) **Consequence for the `NcSelect :input-label` criterion:** because the list is `CnLogsPage`, this change contributes no `NcSelect` for entryPoint/status — those filters are nc-vue's to render, so the criterion is satisfied by delegation, not by code here. My own new `.vue` files contain no `NcSelect` at all (`hydra-gate-nc-input-labels` is vacuously satisfied). `TraceDetailPage.vue` + `TraceTimelineWidget.vue` were built as specified, registered in `src/registry.js`, and the Replay flow is dry-run-first with a separate explicit confirmation before any forced request.
- [ ] Test — NOT DONE: Playwright/browser verification requires a live instance with seeded traces; none was available in this build. Static verification only: `npm run check:manifest` PASS (Ajv, 33 pages), `npm run lint` 0 errors, `USE_LOCAL_LIB=false NODE_ENV=production npm run build` exit 0. The UI has never been rendered.

### Task 13: `traces_total` AppHost `tableCount` observability descriptor
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-traces_total-prometheus-counter-via-the-apphost-observability-engine-req-008`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `observability.metrics` WHEN a `traces_total` descriptor is added THEN it follows the exact `source.kind: 'tableCount'`/`groupBy`/`labelMap`/`labelDefaults` shape of the existing `calls_total`/`synchronization_runs_total` descriptors (lines 40-59/69-86), grouped by `status`
  - GIVEN a seeded instance with mixed-status `execution_trace` rows WHEN `GET /apps/openconnector/api/metrics` is called THEN `openconnector_traces_total{status="..."}` values match direct table counts
- [x] Implement — **with a disclosed deviation: `source.kind` is `objectCount`, not `tableCount`.** The task assumed `tableCount`, but every existing `tableCount` descriptor targets a fixed-name chain-C legacy table (`openconnector_call_logs` etc.), whereas `execution_trace` is a register.d fragment schema (ADR-037) whose backing magic table is named from runtime-assigned numeric register/schema ids (`MagicMapper::TABLE_PREFIX.$registerId.'_'.$schemaId`) — so no static `table` string can address it, and `TableMetricSource` would emit zero samples forever (its `tableExists()` guard fails silently). `objectCount` is the AppHost engine's OR-portable aggregation source (`ObjectMetricSource`), which resolves the `register`/`schema` SLUGS at collection time — the same mechanism the app's other register.d schemas will need. Grouped by `status` as specified. `npm run check:manifest` PASS.
- [ ] Test — NOT DONE: verifying emitted values against direct counts needs a live instance with seeded mixed-status rows. The descriptor is schema-validated only; the metric has never been scraped.

### Task 14: Unit tests — traceId propagation and redaction-in-snapshot
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-snapshot-redaction-before-any-step-is-buffered-req-003`
- **files**: `tests/Unit/Service/Helper/ExecutionTraceContextTest.php`, `tests/Unit/Service/ExecutionTraceServiceTest.php`
- **acceptance_criteria**:
  - Covers `execution-trace` REQ-001 (traceId minted once, shared across steps; untraced calls produce no trace), REQ-003 (redacted snapshot scenarios), and `rule-pipeline` REQ-RULE-010/REQ-RULE-011's scenarios
- [x] Implement — `ExecutionTraceContextTest` (9) + `ExecutionTraceServiceTest` (6); the REQ-RULE-010/011 scenarios live with their subject in `EndpointServiceTest` (5 new tests) and the REQ-003 cross-layer redaction contract in `ExecutionTraceIntegrationTest`.
- [x] Test — all pass; suite 1553 → 1578 (+25), 0 failures.

### Task 15: Integration test — one endpoint call spans rule → mapping → call
- **spec_ref**: `openspec/changes/execution-trace-observability/specs/execution-trace/spec.md#requirement-ordered-per-execution-step-timeline-req-002`
- **files**: `tests/Integration/ExecutionTraceIntegrationTest.php`, `tests/postman/openconnector.postman_collection.json` (new "Execution traces" folder)
- **acceptance_criteria**:
  - GIVEN an endpoint configured with a `mapping` rule and a `save_object` rule that triggers one outbound `CallService` call WHEN the endpoint is called THEN exactly one `execution_trace` is persisted with steps for the rule, the mapping, and the call, all sharing one `traceId`, and the call step's snapshot matches the persisted `call_log`'s redacted data (per `http-call-engine` REQ-011)
- [x] Implement — `tests/Integration/ExecutionTraceIntegrationTest.php` (5 tests) + a new "14 — Execution traces" Postman folder (list/detail/dry-run-replay).
- [x] Test — passes, **with one honest scope note on how the criterion is met.** `testOneTraceSpansRuleMappingAndCallUnderOneTraceId` drives the REAL `EndpointService::processRules()` and the REAL `CallService::call()` through ONE shared context and asserts: 2 steps in real execution order (`rule` then `call`, not grouped by type), sequential `order`, `call_log.sessionId == traceId`, and no plaintext secret in the assembled trace. The redaction-equivalence half of the criterion (call step snapshot IS the array persisted to `call_log`) is asserted by `testTraceCallStepReusesCallLogsRedactedDataByteForByte`. What is NOT covered: the criterion says "WHEN the endpoint is called" — driving `handleRequest()` itself, and the resulting single `execution_trace` PERSIST, needs a live instance (`handleRequest()` mints its trace internally and hands it straight to `persist()`, leaving no seam to observe). Persistence is unit-tested separately in `ExecutionTraceServiceTest`. The Postman folder was authored but NOT executed (no instance).
- [ ] Test

## Verification
- [ ] All tasks checked off — 15/15 Implement ticked; 8/15 Test ticked. The 7 unticked Test boxes each carry a written reason above (live instance / Playwright / Newman execution).
- [x] `openspec validate` passes — scoped strict validation run per touched spec at archive time.
- [ ] Manual testing against acceptance criteria — NOT DONE: no live instance in this build. Nothing in this change has been exercised against a running Nextcloud.
- [ ] Code review against spec requirements — not self-certifiable; left for the reviewer.

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — 20 new tests across `ExecutionTraceContextTest` (9), `ExecutionTraceServiceTest` (6), `EndpointServiceTest` (5).
- [ ] Newman/Postman tests for new/changed API endpoints (`ExecutionTracesController`) — AUTHORED but NOT EXECUTED: the "14 — Execution traces" folder exists in `tests/postman/openconnector.postman_collection.json`; running it needs a live instance with seeded traces.
- [ ] Browser tests (Playwright MCP) for the Traces list/detail UI and replay confirmation flow — NOT DONE: requires a live instance. The Traces UI has never been rendered.
- [ ] All tests pass (`composer test`, `newman run`) — PHPUnit passes (1578 tests / 4414 assertions, 0 failures, 1 pre-existing skip; baseline on this change's merge-base was 1553/4338). `newman run` NOT executed (no instance).

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` (new "Execution traces" page under `docs/features/`) — NOT DONE, deferred.
- [ ] Screenshot captured and committed to `docs/images/` (Traces list + detail timeline) — NOT DONE: a screenshot requires rendering the UI on a live instance.

## i18n (company-wide hydra ADR-007)

- [x] Dutch (`nl_NL`) and English (`en_US`) translation strings added for the Traces UI (list filters, detail timeline labels, replay confirmation dialog) — all 26 `t()` literals in `src/views/ExecutionTrace/` are covered: `l10n/en.json` via the repo's own sanctioned extractor (`npm run test:l10n:write`, which also cleared ~24 keys of pre-existing `NotificatiesAbonnement` drift), and `l10n/nl.json` hand-translated (21 added; 5 already present). Scope note: the "list filters" clause is moot — the list is nc-vue's generic `CnLogsPage` (Task 12(b)), so this change ships no filter strings. `l10n/nl.js` deliberately untouched: it is a lagging Transifex-generated artifact (124 keys it has that `nl.json` lacks, 595 the reverse) and the last three merged features all updated `nl.json` only.
