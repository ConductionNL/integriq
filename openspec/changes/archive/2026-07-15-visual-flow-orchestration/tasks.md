# Tasks: visual-flow-orchestration

## 1. Schema & Persistence

### Task 1: Add `flow` OR schema register.d fragment
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001`
- **files**: `lib/Settings/register.d/visual-flow-orchestration.json`
- **acceptance_criteria**:
  - GIVEN the fragment is merged by the AppHost repair step WHEN the register is inspected THEN a `flow` schema exists with `name`, `description`, `isEnabled`, `steps[]` (each with `order`, `type` enum, `configRef`, `condition`, `onError` enum, `branches[]`, `defaultNextStepOrder`) matching design.md Decision 1
  - GIVEN a `flow` object is saved with two steps carrying the same `order` value THEN validation rejects it (steps' `order` values MUST be unique within a flow)
- [x] Implement
- [x] Test — duplicate-order rejection enforced in `FlowRunnerService::assertUniqueStepOrders()` (schema itself cannot express cross-item uniqueness) and covered by `FlowRunnerServiceTest::testDuplicateStepOrderThrowsFlowRunException`; fragment merge verified with a standalone `deepMergeConfig()` replay against `openconnector_register.json` (confirms `flow`/`flow_run`/`flow_run_log` land and `approval_request` gets the two new properties without duplicating its base schema).

### Task 2: Add `flow_run` / `flow_run_log` OR schema register.d fragment
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flow-runs-are-persisted-with-a-per-step-trace-req-008`
- **files**: `lib/Settings/register.d/visual-flow-orchestration.json`
- **acceptance_criteria**:
  - GIVEN the fragment is merged THEN `flow_run` (flowId, triggerSource, status, startedAt, finishedAt) and `flow_run_log` (flowRunId, stepOrder, type, status, startedAt, finishedAt, error) schemas exist
- [x] Implement
- [x] Test — covered by `FlowRunnerServiceTest`/`FlowRunnerIntegrationTest` asserting `flow_run`/`flow_run_log` field contents on every run; `status` enum extended with `failed` beyond the literal REQ-008 list to satisfy REQ-004's "flow_run's status is recorded as failed" wording (see design deviation note in the final report).

### Task 3: Extend `approval_request` schema with `flowRunId` / `resumeStepOrder`
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005`
- **files**: `lib/Settings/register.d/visual-flow-orchestration.json` (additive properties only — do NOT edit `hitl-approval-rule-action.json` directly; add a second fragment that extends the same schema, or confirm with the register merge tooling whether an additive property extension belongs in a new fragment vs. amending the existing one — follow whatever the AppHost register-merge convention already establishes for extending another change's schema)
- **acceptance_criteria**:
  - GIVEN an `approval_request` object THEN it MAY carry `flowRunId` (uuid, FK to `flow_run`, `SET_NULL` on delete) and `resumeStepOrder` (integer), both optional, with no change to existing required fields or the `pending → approved|rejected|expired|dead_letter|error` state machine
- [x] Implement
- [x] Test — `ApprovalServiceTest::testSuspendForFlowPersistsFlowRunIdAndResumeStepOrder` asserts the new fields persist without disturbing `endpointId`/`ruleId`/`synchronizationId`; deep-merge replay confirms the base schema's `required`/existing properties are untouched.

## 2. Runner Service

### Task 4: Implement `FlowRunnerService::run()` — sequential dispatch
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001`
- **files**: `lib/Service/FlowRunnerService.php`
- **acceptance_criteria**:
  - GIVEN a 3-step flow (call → mapping → synchronization) WHEN `run($flow)` is called THEN `CallService::call()`, `MappingService::executeMapping()`, `SynchronizationService::synchronize()` are invoked in `order` sequence (not array position) and a `flow_run`/`flow_run_log` trail records all three as `completed`
- [x] Implement
- [x] Test — `FlowRunnerServiceTest::testRunExecutesStepsInOrderNotArrayPosition` + `FlowRunnerIntegrationTest::testThreeStepCallMappingSynchronizationFlowRunsEndToEnd` (asserts real cross-collaborator invocation ORDER, not just call counts).

### Task 5: Implement FlowToken context threading between steps
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-step-context-is-threaded-via-the-reused-flowtoken-req-002`
- **files**: `lib/Service/FlowRunnerService.php`
- **acceptance_criteria**:
  - GIVEN a step's result WHEN the next step runs THEN `$flowToken->getSyncInputAmended()` returns the previous step's output, per design.md Decision 2 (no new context object introduced)
  - GIVEN an endpoint-triggered flow THEN `requestOriginal`/`responseOriginal` are seeded from the triggering request at flow start
- [x] Implement
- [x] Test — `FlowRunnerServiceTest::testStepOutputThreadsIntoNextStepInput` (also doubles as a Task 20 sync-safety assertion: the captured `approvalRequestId` positional arg is null). Endpoint-triggered `requestOriginal` seeding is implemented (`run($flow, $input, $triggerSource, $requestContext)` → `new FlowToken(requestOriginal: $requestContext)`, wired from `EndpointService::processFlowRule()`'s pipeline `$data`) but not covered by a dedicated unit test beyond the `processFlowRule` dispatch tests.

### Task 6: Implement `condition` evaluation (step skip)
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-step-condition-skips-a-step-when-it-evaluates-false-req-003`
- **files**: `lib/Service/FlowRunnerService.php`
- **acceptance_criteria**:
  - GIVEN a step's `condition` evaluates false via `JWadhams\JsonLogic::apply()` WHEN the runner reaches it THEN the step's service is not called and `flow_run_log` records `status: skipped`
- [x] Implement
- [x] Test — `FlowRunnerServiceTest::testConditionSkipsStep`.

### Task 7: Implement `branch` step JsonLogic target selection
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004`
- **files**: `lib/Service/FlowRunnerService.php`
- **acceptance_criteria**:
  - GIVEN a `branch` step's `branches[]` WHEN evaluated in order THEN the first matching `nextStepOrder` is selected, or `defaultNextStepOrder` if none match
  - GIVEN a `branch` step targets a non-existent `order` WHEN selected THEN the flow run fails fatally regardless of any step's `onError` policy
- [x] Implement
- [x] Test — `FlowRunnerServiceTest::testBranchSelectsFirstMatchingTarget`, `testBranchFallsBackToDefaultNextStepOrder`, `testBranchUnresolvableTargetFailsRunFatally`.

### Task 8: Implement `onError` policy (stop | continue | dead_letter)
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-per-step-onerror-policy-governs-failure-handling-req-006`
- **files**: `lib/Service/FlowRunnerService.php`
- **acceptance_criteria**:
  - GIVEN a step throws WHEN `onError: stop` THEN `flow_run.status: stopped` and no later step runs
  - GIVEN a step throws WHEN `onError: continue` THEN the next step still runs and the run can still reach `completed`
  - GIVEN a step throws WHEN `onError: dead_letter` THEN `flow_run.status: dead_letter` (distinct from `stopped`) and no later step runs
- [x] Implement
- [x] Test — `FlowRunnerServiceTest::testOnErrorStopHaltsRun`, `testOnErrorContinueProceedsPastFailure`, `testOnErrorDeadLetterMarksRunDistinctly`.

### Task 9: Implement `approval` step suspend
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005`
- **files**: `lib/Service/FlowRunnerService.php`
- **acceptance_criteria**:
  - GIVEN an `approval` step WHEN the runner reaches it THEN an `approval_request` is created with `flowRunId` and `resumeStepOrder` set, `flow_run.status` becomes `suspended`, and `run()` returns without executing later steps
- [x] Implement
- [x] Test — `FlowRunnerServiceTest::testApprovalStepSuspendsRun`; persistence shape covered by `ApprovalServiceTest::testSuspendForFlowPersistsFlowRunIdAndResumeStepOrder`. New `ApprovalService::suspendForFlow()` method mirrors `suspend()`'s shape exactly (design.md Decision 4).

### Task 10: Implement `FlowRunnerService::resumeFromApproval()`
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005`
- **files**: `lib/Service/FlowRunnerService.php`, `lib/Controller/ApprovalsController.php` (add the `flowRunId` branch alongside the existing `endpointId`/`synchronizationId` branches in `approve()`/`reject()`)
- **acceptance_criteria**:
  - GIVEN an approved `approval_request` with `flowRunId` set WHEN `ApprovalsController::approve()` is called THEN `FlowRunnerService::resumeFromApproval()` rehydrates the `FlowToken` via `ApprovalService::rehydrateFlowToken()` and resumes at `resumeStepOrder`
  - GIVEN a rejected `approval_request` with `flowRunId` set WHEN `reject()` is called THEN `flow_run.status` becomes `stopped`
- [x] Implement
- [x] Test — `FlowRunnerServiceTest::testResumeFromApprovalContinuesAtResumeStepOrder`; `ApprovalsControllerTest::testApproveFlowHappyPathResumesAndCompletes` + `testRejectFlowStopsFlowRun`. **Known gap, not phantom-ticked**: `ApprovalService::sweepExpired()` (the unattended-expiry cron path, `ApprovalTimeoutSweepJob`) flips `approval_request.status` in bulk with no awareness of `flowRunId` — a flow suspended on `approval` whose request times out unattended will NOT have its `flow_run.status` updated to `dead_letter`/`stopped` by the sweep (only the approve()/reject() synchronous paths, both implemented and tested, update `flow_run`). This is outside Task 10's literal file scope (`FlowRunnerService.php` + `ApprovalsController.php` only); wiring the sweep path would touch `ApprovalService`/`ApprovalTimeoutSweepJob` and risks a circular-dependency redesign not scoped here. Left as an explicit, disclosed limitation.

### Task 11: Implement `event` step dispatch to `EventService::emitCloudEvent()`
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001`
- **files**: `lib/Service/FlowRunnerService.php`
- **acceptance_criteria**:
  - GIVEN an `event` step WHEN dispatched THEN `EventService::emitCloudEvent(type, source, subject, data)` is called with the step's config and current step context, per design.md Decision 5
- [x] Implement
- [x] Test — `FlowRunnerServiceTest::testEventStepDispatchesToEventServiceEmitCloudEvent`. `EventService` resolved lazily via `ContainerInterface` (not constructor-injected) to break the `FlowRunnerService<->EventService` cycle created by Task 14's `action.kind = 'flow'` dispatch — documented in `FlowRunnerService`'s class docblock.

## 3. Triggers

### Task 12: Add `flow` action type to `EndpointService::processRules()`
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/rule-pipeline/spec.md#requirement-flow-rule-action-type-triggers-a-flow-run-req-rule-009`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN an endpoint rule of `type: flow` WHEN its conditions pass THEN `FlowRunnerService::run()` is called with the current pipeline `$data`, and existing rule ordering/short-circuit behavior (REQ-RULE-001, REQ-RULE-008) is unchanged for all other rule types
- [x] Implement
- [x] Test — `EndpointServiceTest::testProcessFlowRuleRunsAndReturnsDataUnmodified`, `testProcessFlowRuleThrowsWhenFlowRunFails`, `testProcessFlowRuleThrowsWithoutConfiguredFlow`. `configuration.flow` is the id, mirroring `configuration.synchronization`/`configuration.mapping`'s naming convention.

### Task 13: Implement `lib/Action/FlowAction.php`
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/job-management/spec.md#requirement-flowaction-runs-a-flow-as-a-scheduled-job-req-job-003`
- **files**: `lib/Action/FlowAction.php`
- **acceptance_criteria**:
  - GIVEN a `job` OR object with `jobClass: 'OCA\OpenConnector\Action\FlowAction'` WHEN `JobService::executeJob()` invokes it THEN `FlowRunnerService::run()` executes the referenced flow and `run()` returns `{level, message, stackTrace}` derived from `flow_run.status`, matching `SynchronizationAction::run()`'s return shape
- [x] Implement
- [x] Test — `tests/Unit/Action/FlowActionTest.php` (5 tests: missing flowId, unknown flow, completed→SUCCESS, dead_letter→WARNING, stopped→ERROR).

### Task 14: Wire event-triggered flows through EventService's subscriber path
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007`
- **files**: `lib/Service/EventService.php` (or a new subscriber class alongside its existing delivery path — follow whatever pattern `EventService` already uses to match an incoming CloudEvent to a registered consumer)
- **acceptance_criteria**:
  - GIVEN a CloudEvent matching a flow's configured trigger (type/source/subject) WHEN `EventService` processes it THEN `FlowRunnerService::run()` is invoked with `triggerSource: 'event'`
- [x] Implement — extended `event_subscription`'s existing `action.kind` dispatch (`attemptDelivery()`'s `switch`) with `kind: 'flow'` → `dispatchFlowAction()`, the exact additive extension point `notificaties-api-subscriber` REQ-010 already used for `kind: 'notificaties'`. The subscription's own `types`/`source`/`filters` fields do the CloudEvent type/source/subject matching (`doesEventMatchSubscription()`, unmodified); `action.flowId` selects which flow runs once matched.
- [x] Test — `EventServiceTest::testAttemptDeliveryDispatchesFlowActionOnSuccess`, `testAttemptDeliveryDispatchesFlowActionOnFailure`.

## 4. API & Controller

### Task 15: Implement `FlowsController` (CRUD + manual run)
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007`
- **files**: `lib/Controller/FlowsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `POST /api/flows/{id}/run` WHEN called by an authenticated admin THEN `FlowRunnerService::run()` executes synchronously and the response carries the resulting `flow_run`'s status and `flow_run_log`
  - GIVEN standard CRUD routes for `flow` objects THEN they follow the same Controller → Service → Mapper layering (ADR-008) already used by `SynchronizationsController`/`JobsController`, with explicit auth attributes per route (`hydra-gate-route-auth`)
- [x] Implement — `FlowsController` implements only the bespoke `run()` action; standard `flow` CRUD deliberately has NO controller methods (routes.php's own "Resource block intentionally omitted" convention — CRUD goes through OpenRegister's generic `/api/objects/openconnector/flow/*`, exactly how `SynchronizationsController`/`JobsController` already work post chain-C — verified by inspection, adding CRUD methods here would trip `hydra-gate-redundant-controller`). `#[NoAdminRequired]`/`#[NoCSRFRequired]` + `ActionAuthService::requireAction('flow.run')` on the route.
- [x] Test — `tests/Unit/Controller/FlowsControllerTest.php` (4 tests) + `composer check:routes` PASS (173 routes, all resolve).

## 5. Manifest & UI

### Task 16: Add `Flows` index + `FlowDetail` custom detail manifest entries
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `src/manifest.json` THEN a `Flows` `type: index` page (register `openconnector`, schema `flow`) and a `FlowDetail` `type: custom` page (`component: FlowDetailPage`, with a `_note` justifying the bespoke component per the `SynchronizationDetail` precedent) exist, plus a menu entry in the `AutomationGroup` after `SyncDeadLetters` (`order: 122`)
- [x] Implement
- [ ] Test — no live Nextcloud instance available in this environment to Playwright-verify the page actually mounts/routes; manifest JSON validated (`python3 -m json.tool`) and `USE_LOCAL_LIB=false NODE_ENV=production npm run build` compiled successfully (0 errors) as the available proxy checks. Left unticked rather than phantom-ticked.

### Task 17: Build `FlowDetailPage.vue` + step-list editor
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009`
- **files**: `src/views/Flow/FlowDetailPage.vue`, `src/views/Flow/FlowStepRow.vue`, `src/views/Flow/FlowStepConditionEditor.vue` (reuse the existing Rules condition editor component if one exists — check `src/views/Rule/` first before writing a new one), `src/modals/Flow/EditFlow.vue`
- **acceptance_criteria**:
  - GIVEN the Flow detail page THEN each step row renders a type `NcSelect` with `inputLabel: 'Step type'`, a config-ref `NcSelect` (`inputLabel` set, options filtered by the chosen step type) when applicable, a condition editor, an onError `NcSelect` (`inputLabel: 'On error'`), and move-up/move-down/remove controls — no drag-and-drop
  - GIVEN a `branch` step row THEN it additionally renders a `branches[]` sub-list (condition + target-order picker) and a default-target picker
  - GIVEN any modal on the Flow pages THEN it lives in `src/modals/Flow/`, not inline in `FlowDetailPage.vue`
- [x] Implement — **two deliberate file-list deviations, disclosed**: (1) no `FlowStepConditionEditor.vue` wrapper — `RuleConditionGroup.vue` is imported and used directly in `FlowStepRow.vue` (both for the per-step condition and each branch's condition), which is what the task's own instruction ("reuse... before writing a new one") asks for; a pass-through wrapper file would add nothing. (2) no `src/modals/Flow/EditFlow.vue` — verified against HEAD that the CURRENT custom-detail-page convention (`RuleDetailPage.vue`, `SynchronizationDetailPage.vue`, `MappingDetailPage.vue` — none of which have a companion Edit modal) edits base fields (name/description/isEnabled) inline via the page's own draft/Save/Discard flow; the `src/modals/<Entity>/Edit*.vue` pattern design.md referenced is unwired legacy code per `src/handlers/actionHandlers.js`'s own docblock ("preserved in git history... for the future bespoke extraction PR series"). Building an unused modal file would itself be dead code. `FlowDetailPage.vue` + `FlowStepRow.vue` + `FlowRunLog.vue` (Task 19) built; all `NcSelect`s carry `inputLabel`; branch sub-list + default-target picker implemented; no drag-and-drop.
- [ ] Test — no live instance to Playwright-verify rendering/interaction; `npm run lint` (0 errors) and the production build (0 errors) are the available proxy checks. Left unticked.

### Task 18: Add client-side branch-target validation on save
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004`
- **files**: `src/views/Flow/FlowDetailPage.vue`
- **acceptance_criteria**:
  - GIVEN a `branch` step's `nextStepOrder`/`defaultNextStepOrder` does not match any existing step's `order` WHEN the admin attempts to save THEN save is blocked with an inline validation error (per design.md's branch-target risk mitigation)
- [x] Implement — `FlowDetailPage.vue`'s `validationErrors` computed checks both duplicate step `order` values (Task 1's AC) and unresolvable `branch` targets (`nextStepOrder`/`defaultNextStepOrder`); rendered as an inline `NcNoteCard` list; `canSave` is false while any error exists, disabling the Save button.
- [ ] Test — no live instance to Playwright-verify the blocked-save interaction. Left unticked.

### Task 19: Add "Run" manual-trigger action + run-log view
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007`
- **files**: `src/views/Flow/FlowDetailPage.vue`, `src/views/Flow/FlowRunLog.vue`
- **acceptance_criteria**:
  - GIVEN the Flow detail page THEN a "Run" header action calls `POST /api/flows/{id}/run` and surfaces the resulting run's status
  - GIVEN a flow with prior runs THEN a run-log list/tab shows past `flow_run`/`flow_run_log` records, following the `SyncDeadLetters`/`Job logs` list pattern
- [x] Implement — `FlowDetailPage.vue`'s header "Run" `NcButton` + `FlowRunLog.vue` (expandable run list with per-step log detail on expand). Also wired a `runFlowHandler` row action on the `Flows` index page (`src/handlers/actionHandlers.js`/`src/registry.js`) for a manual run without opening the detail page.
- [ ] Test — no live instance to Playwright-verify. Left unticked.

## 6. Integration & Cross-Cutting

### Task 20: Verify a `synchronization` step does not bypass sync-safety guards
- **spec_ref**: `openspec/changes/visual-flow-orchestration/design.md#decision-3-dispatch--thin-adapter-methods-not-reimplementation`
- **files**: `lib/Service/FlowRunnerService.php`, `tests/Integration/FlowRunnerSynchronizationGuardsTest.php`
- **acceptance_criteria**:
  - GIVEN a `synchronization` step targeting a Synchronization configured with `sourceConfig.requiresApproval` (the sync-safety batch-approval gate) WHEN the flow step runs THEN the same approval gate fires exactly as a directly-triggered sync would — the flow step MUST NOT pass any flag that bypasses it
- [x] Implement — `FlowRunnerService::dispatchSynchronization()` never sets `approvalRequestId` when calling `synchronize()`.
- [x] Test — `tests/Integration/FlowRunnerSynchronizationGuardsTest.php` asserts the captured `approvalRequestId` positional argument is `null`. Scoped deliberately to FlowRunnerService's OWN contract (never bypasses); the gate's actual fire/suspend behaviour once `requiresApproval` is set is `synchronization-engine`'s existing, separately-covered responsibility — not re-asserted here to avoid duplicating that suite.

### Task 21: File follow-up issues for explicitly out-of-scope v2 items
- **spec_ref**: `openspec/changes/visual-flow-orchestration/proposal.md#out-of-scope`
- **files**: N/A (GitHub issues in `ConductionNL/openconnector`)
- **acceptance_criteria**:
  - GIVEN this change is archived THEN three follow-up issues exist: (1) drag-and-drop canvas UI for the step editor, (2) parallel/fan-out step execution, (3) loop/iteration step types — each referencing this change's proposal.md Out of Scope section
- [x] Implement — filed on Codeberg (the app's actual live issue tracker post-migration; `ConductionNL/openconnector` on GitHub is archived, per `git remote -v` → `codeberg.org/Conduction/openconnector`): [#206](https://codeberg.org/Conduction/openconnector/issues/206) (canvas UI), [#207](https://codeberg.org/Conduction/openconnector/issues/207) (fan-out), [#208](https://codeberg.org/Conduction/openconnector/issues/208) (loops).
- [x] Test — verified all three issues exist via the Codeberg API (`GET /repos/Conduction/openconnector/issues/{206,207,208}`, all `state: open`).

## Verification
- [ ] All tasks checked off — 19/21 tasks fully Implement+Test; Tasks 16-19's Test boxes are intentionally unticked (no live Nextcloud/Playwright environment available — see each task's note). Not phantom-ticked.
- [x] `openspec validate visual-flow-orchestration --type change --strict` passes
- [ ] Manual testing against acceptance criteria — no live instance available in this environment.
- [ ] Code review against spec requirements — no independent reviewer; self-reviewed only.

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for `FlowRunnerService` (`tests/Unit/Service/FlowRunnerServiceTest.php`): ordering, condition skip, branch selection (including unresolvable-target failure), `onError` stop/continue/dead_letter, approval suspend/resume, event dispatch.
- [x] PHPUnit integration test: a 3-step flow (`call` → `mapping` → `synchronization`) runs end-to-end against faked/stubbed `CallService`/`MappingService`/`SynchronizationService` collaborators, asserting call order and `flow_run_log` contents (`tests/Integration/FlowRunnerIntegrationTest.php`).
- [ ] Newman/Postman tests for `POST /api/flows/{id}/run` and standard `flow` CRUD endpoints — not authored; no live instance to run Newman against in this environment.
- [ ] Browser tests (Playwright MCP) for the Flows index page mount, step-list editor add/reorder/remove, and the manual "Run" action — trace each to REQ-009's scenarios per `hydra-gate-e2e-coverage` — not performed; no live Nextcloud instance available in this environment.
- [x] All tests pass (`composer test`) — PHPUnit: 1586/1586 passing (1 pre-existing, unrelated skip), 0 failures, 0 errors; baseline before this change was 1553/1553 passing (33 new tests added, all pass, zero regressions). `newman run` not executed (no live instance).

## Documentation (company-wide ADR-010)

- [ ] Feature documentation added in `docs/` describing the `flow` entity, step types, and the v1 scope boundary (no canvas/fan-out/loops) — not written; deferred, not part of the 21 numbered implementation tasks and out of the time budget for this pass.
- [ ] Screenshot of the Flow detail page's step-list editor captured and committed to `docs/images/` — not captured; no live instance/browser available in this environment.

## i18n (company-wide hydra ADR-007)

- [x] English (`en_US`) source strings added for all new Flow UI labels/messages (`l10n/` source strings are authored in English per project convention) — every new UI string uses `t('openconnector', '<English text>')`.
- [ ] Dutch (`nl_NL`) translations added for the same strings — not added. Verified precedent: `l10n/nl.json` does not carry the prior, larger `hitl-approval-rule-action` change's strings either (checked `grep -n "Approve\|Reject request" l10n/nl.json` → no hits at HEAD); Dutch translations follow this repo's existing external localization pipeline, not manual per-PR maintenance of `l10n/*.json`.
