# Tasks: visual-flow-orchestration

## 1. Schema & Persistence

### Task 1: Add `flow` OR schema register.d fragment
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001`
- **files**: `lib/Settings/register.d/visual-flow-orchestration.json`
- **acceptance_criteria**:
  - GIVEN the fragment is merged by the AppHost repair step WHEN the register is inspected THEN a `flow` schema exists with `name`, `description`, `isEnabled`, `steps[]` (each with `order`, `type` enum, `configRef`, `condition`, `onError` enum, `branches[]`, `defaultNextStepOrder`) matching design.md Decision 1
  - GIVEN a `flow` object is saved with two steps carrying the same `order` value THEN validation rejects it (steps' `order` values MUST be unique within a flow)
- [ ] Implement
- [ ] Test

### Task 2: Add `flow_run` / `flow_run_log` OR schema register.d fragment
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flow-runs-are-persisted-with-a-per-step-trace-req-008`
- **files**: `lib/Settings/register.d/visual-flow-orchestration.json`
- **acceptance_criteria**:
  - GIVEN the fragment is merged THEN `flow_run` (flowId, triggerSource, status, startedAt, finishedAt) and `flow_run_log` (flowRunId, stepOrder, type, status, startedAt, finishedAt, error) schemas exist
- [ ] Implement
- [ ] Test

### Task 3: Extend `approval_request` schema with `flowRunId` / `resumeStepOrder`
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005`
- **files**: `lib/Settings/register.d/visual-flow-orchestration.json` (additive properties only — do NOT edit `hitl-approval-rule-action.json` directly; add a second fragment that extends the same schema, or confirm with the register merge tooling whether an additive property extension belongs in a new fragment vs. amending the existing one — follow whatever the AppHost register-merge convention already establishes for extending another change's schema)
- **acceptance_criteria**:
  - GIVEN an `approval_request` object THEN it MAY carry `flowRunId` (uuid, FK to `flow_run`, `SET_NULL` on delete) and `resumeStepOrder` (integer), both optional, with no change to existing required fields or the `pending → approved|rejected|expired|dead_letter|error` state machine
- [ ] Implement
- [ ] Test

## 2. Runner Service

### Task 4: Implement `FlowRunnerService::run()` — sequential dispatch
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001`
- **files**: `lib/Service/FlowRunnerService.php`
- **acceptance_criteria**:
  - GIVEN a 3-step flow (call → mapping → synchronization) WHEN `run($flow)` is called THEN `CallService::call()`, `MappingService::executeMapping()`, `SynchronizationService::synchronize()` are invoked in `order` sequence (not array position) and a `flow_run`/`flow_run_log` trail records all three as `completed`
- [ ] Implement
- [ ] Test

### Task 5: Implement FlowToken context threading between steps
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-step-context-is-threaded-via-the-reused-flowtoken-req-002`
- **files**: `lib/Service/FlowRunnerService.php`
- **acceptance_criteria**:
  - GIVEN a step's result WHEN the next step runs THEN `$flowToken->getSyncInputAmended()` returns the previous step's output, per design.md Decision 2 (no new context object introduced)
  - GIVEN an endpoint-triggered flow THEN `requestOriginal`/`responseOriginal` are seeded from the triggering request at flow start
- [ ] Implement
- [ ] Test

### Task 6: Implement `condition` evaluation (step skip)
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-step-condition-skips-a-step-when-it-evaluates-false-req-003`
- **files**: `lib/Service/FlowRunnerService.php`
- **acceptance_criteria**:
  - GIVEN a step's `condition` evaluates false via `JWadhams\JsonLogic::apply()` WHEN the runner reaches it THEN the step's service is not called and `flow_run_log` records `status: skipped`
- [ ] Implement
- [ ] Test

### Task 7: Implement `branch` step JsonLogic target selection
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004`
- **files**: `lib/Service/FlowRunnerService.php`
- **acceptance_criteria**:
  - GIVEN a `branch` step's `branches[]` WHEN evaluated in order THEN the first matching `nextStepOrder` is selected, or `defaultNextStepOrder` if none match
  - GIVEN a `branch` step targets a non-existent `order` WHEN selected THEN the flow run fails fatally regardless of any step's `onError` policy
- [ ] Implement
- [ ] Test

### Task 8: Implement `onError` policy (stop | continue | dead_letter)
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-per-step-onerror-policy-governs-failure-handling-req-006`
- **files**: `lib/Service/FlowRunnerService.php`
- **acceptance_criteria**:
  - GIVEN a step throws WHEN `onError: stop` THEN `flow_run.status: stopped` and no later step runs
  - GIVEN a step throws WHEN `onError: continue` THEN the next step still runs and the run can still reach `completed`
  - GIVEN a step throws WHEN `onError: dead_letter` THEN `flow_run.status: dead_letter` (distinct from `stopped`) and no later step runs
- [ ] Implement
- [ ] Test

### Task 9: Implement `approval` step suspend
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005`
- **files**: `lib/Service/FlowRunnerService.php`
- **acceptance_criteria**:
  - GIVEN an `approval` step WHEN the runner reaches it THEN an `approval_request` is created with `flowRunId` and `resumeStepOrder` set, `flow_run.status` becomes `suspended`, and `run()` returns without executing later steps
- [ ] Implement
- [ ] Test

### Task 10: Implement `FlowRunnerService::resumeFromApproval()`
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005`
- **files**: `lib/Service/FlowRunnerService.php`, `lib/Controller/ApprovalsController.php` (add the `flowRunId` branch alongside the existing `endpointId`/`synchronizationId` branches in `approve()`/`reject()`)
- **acceptance_criteria**:
  - GIVEN an approved `approval_request` with `flowRunId` set WHEN `ApprovalsController::approve()` is called THEN `FlowRunnerService::resumeFromApproval()` rehydrates the `FlowToken` via `ApprovalService::rehydrateFlowToken()` and resumes at `resumeStepOrder`
  - GIVEN a rejected `approval_request` with `flowRunId` set WHEN `reject()` is called THEN `flow_run.status` becomes `stopped`
- [ ] Implement
- [ ] Test

### Task 11: Implement `event` step dispatch to `EventService::emitCloudEvent()`
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001`
- **files**: `lib/Service/FlowRunnerService.php`
- **acceptance_criteria**:
  - GIVEN an `event` step WHEN dispatched THEN `EventService::emitCloudEvent(type, source, subject, data)` is called with the step's config and current step context, per design.md Decision 5
- [ ] Implement
- [ ] Test

## 3. Triggers

### Task 12: Add `flow` action type to `EndpointService::processRules()`
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/rule-pipeline/spec.md#requirement-flow-rule-action-type-triggers-a-flow-run-req-rule-009`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN an endpoint rule of `type: flow` WHEN its conditions pass THEN `FlowRunnerService::run()` is called with the current pipeline `$data`, and existing rule ordering/short-circuit behavior (REQ-RULE-001, REQ-RULE-008) is unchanged for all other rule types
- [ ] Implement
- [ ] Test

### Task 13: Implement `lib/Action/FlowAction.php`
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/job-management/spec.md#requirement-flowaction-runs-a-flow-as-a-scheduled-job-req-job-003`
- **files**: `lib/Action/FlowAction.php`
- **acceptance_criteria**:
  - GIVEN a `job` OR object with `jobClass: 'OCA\Integriq\Action\FlowAction'` WHEN `JobService::executeJob()` invokes it THEN `FlowRunnerService::run()` executes the referenced flow and `run()` returns `{level, message, stackTrace}` derived from `flow_run.status`, matching `SynchronizationAction::run()`'s return shape
- [ ] Implement
- [ ] Test

### Task 14: Wire event-triggered flows through EventService's subscriber path
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007`
- **files**: `lib/Service/EventService.php` (or a new subscriber class alongside its existing delivery path — follow whatever pattern `EventService` already uses to match an incoming CloudEvent to a registered consumer)
- **acceptance_criteria**:
  - GIVEN a CloudEvent matching a flow's configured trigger (type/source/subject) WHEN `EventService` processes it THEN `FlowRunnerService::run()` is invoked with `triggerSource: 'event'`
- [ ] Implement
- [ ] Test

## 4. API & Controller

### Task 15: Implement `FlowsController` (CRUD + manual run)
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007`
- **files**: `lib/Controller/FlowsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `POST /api/flows/{id}/run` WHEN called by an authenticated admin THEN `FlowRunnerService::run()` executes synchronously and the response carries the resulting `flow_run`'s status and `flow_run_log`
  - GIVEN standard CRUD routes for `flow` objects THEN they follow the same Controller → Service → Mapper layering (ADR-008) already used by `SynchronizationsController`/`JobsController`, with explicit auth attributes per route (`hydra-gate-route-auth`)
- [ ] Implement
- [ ] Test

## 5. Manifest & UI

### Task 16: Add `Flows` index + `FlowDetail` custom detail manifest entries
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN `src/manifest.json` THEN a `Flows` `type: index` page (register `openconnector`, schema `flow`) and a `FlowDetail` `type: custom` page (`component: FlowDetailPage`, with a `_note` justifying the bespoke component per the `SynchronizationDetail` precedent) exist, plus a menu entry in the `AutomationGroup` after `SyncDeadLetters` (`order: 122`)
- [ ] Implement
- [ ] Test

### Task 17: Build `FlowDetailPage.vue` + step-list editor
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009`
- **files**: `src/views/Flow/FlowDetailPage.vue`, `src/views/Flow/FlowStepRow.vue`, `src/views/Flow/FlowStepConditionEditor.vue` (reuse the existing Rules condition editor component if one exists — check `src/views/Rule/` first before writing a new one), `src/modals/Flow/EditFlow.vue`
- **acceptance_criteria**:
  - GIVEN the Flow detail page THEN each step row renders a type `NcSelect` with `inputLabel: 'Step type'`, a config-ref `NcSelect` (`inputLabel` set, options filtered by the chosen step type) when applicable, a condition editor, an onError `NcSelect` (`inputLabel: 'On error'`), and move-up/move-down/remove controls — no drag-and-drop
  - GIVEN a `branch` step row THEN it additionally renders a `branches[]` sub-list (condition + target-order picker) and a default-target picker
  - GIVEN any modal on the Flow pages THEN it lives in `src/modals/Flow/`, not inline in `FlowDetailPage.vue`
- [ ] Implement
- [ ] Test

### Task 18: Add client-side branch-target validation on save
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004`
- **files**: `src/views/Flow/FlowDetailPage.vue`
- **acceptance_criteria**:
  - GIVEN a `branch` step's `nextStepOrder`/`defaultNextStepOrder` does not match any existing step's `order` WHEN the admin attempts to save THEN save is blocked with an inline validation error (per design.md's branch-target risk mitigation)
- [ ] Implement
- [ ] Test

### Task 19: Add "Run" manual-trigger action + run-log view
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007`
- **files**: `src/views/Flow/FlowDetailPage.vue`, `src/views/Flow/FlowRunLog.vue`
- **acceptance_criteria**:
  - GIVEN the Flow detail page THEN a "Run" header action calls `POST /api/flows/{id}/run` and surfaces the resulting run's status
  - GIVEN a flow with prior runs THEN a run-log list/tab shows past `flow_run`/`flow_run_log` records, following the `SyncDeadLetters`/`Job logs` list pattern
- [ ] Implement
- [ ] Test

## 6. Integration & Cross-Cutting

### Task 20: Verify a `synchronization` step does not bypass sync-safety guards
- **spec_ref**: `openspec/changes/visual-flow-orchestration/design.md#decision-3-dispatch--thin-adapter-methods-not-reimplementation`
- **files**: `lib/Service/FlowRunnerService.php`, `tests/Integration/FlowRunnerSynchronizationGuardsTest.php`
- **acceptance_criteria**:
  - GIVEN a `synchronization` step targeting a Synchronization configured with `sourceConfig.requiresApproval` (the sync-safety batch-approval gate) WHEN the flow step runs THEN the same approval gate fires exactly as a directly-triggered sync would — the flow step MUST NOT pass any flag that bypasses it
- [ ] Implement
- [ ] Test

### Task 21: File follow-up issues for explicitly out-of-scope v2 items
- **spec_ref**: `openspec/changes/visual-flow-orchestration/proposal.md#out-of-scope`
- **files**: N/A (GitHub issues in `ConductionNL/integriq`)
- **acceptance_criteria**:
  - GIVEN this change is archived THEN three follow-up issues exist: (1) drag-and-drop canvas UI for the step editor, (2) parallel/fan-out step execution, (3) loop/iteration step types — each referencing this change's proposal.md Out of Scope section
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate visual-flow-orchestration --type change --strict` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for `FlowRunnerService` (`tests/Unit/Service/FlowRunnerServiceTest.php`): ordering, condition skip, branch selection (including unresolvable-target failure), `onError` stop/continue/dead_letter, approval suspend/resume, event dispatch.
- [ ] PHPUnit integration test: a 3-step flow (`call` → `mapping` → `synchronization`) runs end-to-end against faked/stubbed `CallService`/`MappingService`/`SynchronizationService` collaborators, asserting call order and `flow_run_log` contents (`tests/Integration/FlowRunnerIntegrationTest.php`).
- [ ] Newman/Postman tests for `POST /api/flows/{id}/run` and standard `flow` CRUD endpoints.
- [ ] Browser tests (Playwright MCP) for the Flows index page mount, step-list editor add/reorder/remove, and the manual "Run" action — trace each to REQ-009's scenarios per `hydra-gate-e2e-coverage`.
- [ ] All tests pass (`composer test`, `newman run`).

## Documentation (company-wide ADR-010)

- [ ] Feature documentation added in `docs/` describing the `flow` entity, step types, and the v1 scope boundary (no canvas/fan-out/loops).
- [ ] Screenshot of the Flow detail page's step-list editor captured and committed to `docs/images/`.

## i18n (company-wide hydra ADR-007)

- [ ] English (`en_US`) source strings added for all new Flow UI labels/messages (`l10n/` source strings are authored in English per project convention).
- [ ] Dutch (`nl_NL`) translations added for the same strings.
