# Test Plan: visual-flow-orchestration

## Test Cases

### TC-1: 3-step flow runs in order (call → mapping → synchronization)
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001`
- **type**: functional (unit + integration, PHPUnit)
- **persona**: N/A (backend)
- **preconditions**: a `flow` with steps order 10 (`call`), order 20 (`mapping`), order 30 (`synchronization`), all `onError: stop`
- **steps**: call `FlowRunnerService::run($flow)`
- **expected result**: `CallService::call()`, `MappingService::executeMapping()`, `SynchronizationService::synchronize()` invoked in that order; `flow_run_log` has 3 `completed` entries in order 10/20/30
- **test command**: PHPUnit (`tests/Unit/Service/FlowRunnerServiceTest.php`, `tests/Integration/FlowRunnerIntegrationTest.php`)

### TC-2: steps execute by `order` field, not array position
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flow-steps-execute-sequentially-in-order-req-001`
- **type**: functional
- **preconditions**: a flow whose `steps[]` array lists order 30 before order 10
- **steps**: call `run($flow)`
- **expected result**: execution order is still 10 then 30
- **test command**: PHPUnit

### TC-3: step output threads into next step's input via FlowToken
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-step-context-is-threaded-via-the-reused-flowtoken-req-002`
- **type**: functional
- **preconditions**: order-10 `mapping` step returns `{"id":"abc"}`
- **steps**: runner proceeds to order-20 `synchronization` step
- **expected result**: `SynchronizationService::synchronize()` called with `data: {"id":"abc"}`
- **test command**: PHPUnit

### TC-4: false step condition skips the step
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-step-condition-skips-a-step-when-it-evaluates-false-req-003`
- **type**: functional
- **preconditions**: order-20 step's `condition` evaluates false against current context
- **steps**: runner reaches order-20 step
- **expected result**: step's service NOT called; `flow_run_log` records `status: skipped`; execution continues to next step
- **test command**: PHPUnit

### TC-5: true step condition runs the step normally
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-step-condition-skips-a-step-when-it-evaluates-false-req-003`
- **type**: functional
- **preconditions**: same step, condition now evaluates true
- **steps**: runner reaches the step
- **expected result**: service is called; `flow_run_log` records `completed` (or `failed`)
- **test command**: PHPUnit

### TC-6: branch step selects the first matching target
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004`
- **type**: functional
- **preconditions**: `branch` step at order 20 with two `branches[]` entries; context matches the second
- **steps**: runner reaches order-20 branch step
- **expected result**: execution jumps to the matched `nextStepOrder`; the skipped intermediate step does not appear in `flow_run_log`
- **test command**: PHPUnit

### TC-7: branch falls back to defaultNextStepOrder when no branch matches
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004`
- **type**: functional
- **preconditions**: no `branches[].condition` matches; `defaultNextStepOrder` set
- **steps**: runner reaches the branch step
- **expected result**: execution proceeds to `defaultNextStepOrder`
- **test command**: PHPUnit

### TC-8: unresolvable branch target fails the run fatally
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004`
- **type**: functional
- **preconditions**: matched `nextStepOrder` does not correspond to any existing step
- **steps**: runner selects that branch
- **expected result**: flow run fails with `flow_run.status: failed`, regardless of any step's `onError` policy
- **test command**: PHPUnit

### TC-9: approval step suspends the run
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005`
- **type**: functional
- **preconditions**: flow with an `approval` step at order 20
- **steps**: runner reaches order-20 step
- **expected result**: `approval_request` created with `flowRunId`/`resumeStepOrder: 30`; `run()` returns with `flow_run.status: suspended`; no later step executes
- **test command**: PHPUnit

### TC-10: approving resumes the flow from the next step
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005`
- **type**: api
- **preconditions**: the suspended run from TC-9
- **steps**: authorized approver calls `POST /api/approvals/{id}/approve`
- **expected result**: `FlowRunnerService::resumeFromApproval()` invoked; execution resumes at order 30; run reaches `completed`
- **test command**: `/test-api` (Newman) + PHPUnit

### TC-11: rejecting stops the flow
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-approval-step-suspends-and-resumes-the-flow-run-req-005`
- **type**: api
- **preconditions**: the suspended run
- **steps**: authorized approver calls `POST /api/approvals/{id}/reject` with a mandatory comment
- **expected result**: no further steps run; `flow_run.status: stopped`
- **test command**: `/test-api` (Newman) + PHPUnit

### TC-12: onError stop halts the run on the failing step
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-per-step-onerror-policy-governs-failure-handling-req-006`
- **type**: functional
- **preconditions**: order-20 step has `onError: stop`, throws
- **steps**: runner processes the step
- **expected result**: `flow_run_log` records `failed`; `flow_run.status: stopped`; no later step runs
- **test command**: PHPUnit

### TC-13: onError continue proceeds past the failing step
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-per-step-onerror-policy-governs-failure-handling-req-006`
- **type**: functional
- **preconditions**: order-20 step has `onError: continue`, throws
- **steps**: runner processes the step
- **expected result**: order-30 step still executes; run can reach `completed`
- **test command**: PHPUnit

### TC-14: onError dead_letter marks the run distinctly from stop
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-per-step-onerror-policy-governs-failure-handling-req-006`
- **type**: functional
- **preconditions**: order-20 step has `onError: dead_letter`, throws
- **steps**: runner processes the step
- **expected result**: `flow_run.status: dead_letter` (not `stopped`); no later step runs
- **test command**: PHPUnit

### TC-15: a cron job triggers a flow via FlowAction
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/job-management/spec.md#requirement-flowaction-runs-a-flow-as-a-scheduled-job-req-job-003`
- **type**: functional
- **preconditions**: enabled `job` with `jobClass: FlowAction`, `arguments: {flowId}`, due `nextRun`
- **steps**: `JobService::run()` sweeps due jobs
- **expected result**: `FlowAction::run()` invoked; flow executes with `triggerSource: 'cron'`; `job_log` written
- **test command**: PHPUnit

### TC-16: an endpoint rule triggers a flow
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/rule-pipeline/spec.md#requirement-flow-rule-action-type-triggers-a-flow-run-req-rule-009`
- **type**: api
- **preconditions**: endpoint with a `flow`-type rule, `configRef` pointing at an enabled flow, conditions pass
- **steps**: call the endpoint
- **expected result**: `FlowRunnerService::run()` invoked with pipeline data as initial input; other rules run in existing order
- **test command**: `/test-api` (Newman)

### TC-17: manual run triggers a flow synchronously
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-a-flow-runs-via-cron-endpoint-rule-event-or-manual-trigger-req-007`
- **type**: functional
- **persona**: Priya (ZZP developer / integrator) — configures and manually tests a flow
- **preconditions**: admin viewing the Flow detail page for an enabled flow
- **steps**: click "Run"
- **expected result**: `POST /api/flows/{id}/run` called; response carries the resulting `flow_run`'s status and log
- **test command**: `/test-functional`

### TC-18: completed run's log reflects every step outcome
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flow-runs-are-persisted-with-a-per-step-trace-req-008`
- **type**: functional
- **preconditions**: 3-step flow where the middle step's condition is false
- **steps**: run the flow
- **expected result**: `flow_run_log` has 3 entries (`completed`, `skipped`, `completed`); `flow_run.status: completed`, `finishedAt` set
- **test command**: PHPUnit

### TC-19: Flows index page mounts and lists flows
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin) — reviews configured automation
- **preconditions**: authenticated admin
- **steps**: navigate to `/apps/integriq/flows` via sidebar nav
- **expected result**: Flows index page renders with content; lists name, enabled state, last-run status
- **test command**: `/test-functional`

### TC-20: step-list editor's config-ref picker is scoped to the selected type
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009`
- **type**: functional
- **persona**: Priya (ZZP developer / integrator)
- **preconditions**: admin on the Flow detail page
- **steps**: click "Add step", select `type: mapping`, open the config-ref picker
- **expected result**: picker options are Mappings only (not Sources/Synchronizations/Endpoints)
- **test command**: `/test-functional`

### TC-21: reordering uses move controls, not drag-and-drop
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-flows-index-and-detail-ui-provide-a-typed-step-list-editor-req-009`
- **type**: functional
- **preconditions**: flow with 3 steps
- **steps**: click "Move up" on the second step
- **expected result**: second and first step's `order` values swap; no drag-and-drop present
- **test command**: `/test-functional`

### TC-22: NcSelect controls carry inputLabel (WCAG 1.3.1 / 4.1.2)
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#non-functional-requirements`
- **type**: accessibility
- **preconditions**: Flow detail page's step-list editor rendered
- **steps**: audit every `NcSelect` on the page
- **expected result**: every select carries a non-empty `inputLabel` (or `ariaLabelCombobox`); zero `hydra-gate-nc-input-labels` violations
- **test command**: `/test-accessibility`

### TC-23: branch-target validation blocks save on a dangling reference
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/flow-orchestration/spec.md#requirement-branch-step-selects-the-next-step-via-jsonlogic-req-004`
- **type**: functional
- **preconditions**: a `branch` step's `nextStepOrder` does not match any step's `order`
- **steps**: admin attempts to save the flow
- **expected result**: save is blocked with an inline validation error
- **test command**: `/test-functional`

### TC-24: synchronization step does not bypass sync-safety approval gate
- **spec_ref**: `openspec/changes/visual-flow-orchestration/design.md#decision-3-dispatch--thin-adapter-methods-not-reimplementation`
- **type**: regression
- **preconditions**: a `synchronization` step targets a Synchronization with `sourceConfig.requiresApproval` set
- **steps**: run the flow step
- **expected result**: the same batch-approval gate fires as a directly-triggered sync; no bypass flag is set by the flow step
- **test command**: PHPUnit (regression against `synchronization-engine` REQ-015 behaviour)

### TC-25: FlowAction's returned level maps job_log correctly across outcomes
- **spec_ref**: `openspec/changes/visual-flow-orchestration/specs/job-management/spec.md#requirement-flowaction-runs-a-flow-as-a-scheduled-job-req-job-003`
- **type**: functional
- **preconditions**: three job-backed flow runs ending `completed`, `dead_letter`, `failed` respectively
- **steps**: execute each job
- **expected result**: `job_log.level` is `SUCCESS`, `WARNING`, `ERROR` respectively
- **test command**: PHPUnit

## Coverage Summary

| Requirement | Covered by | Status |
|---|---|---|
| flow-orchestration REQ-001 (sequential execution) | TC-1, TC-2 | Covered |
| flow-orchestration REQ-002 (context threading) | TC-3 | Covered |
| flow-orchestration REQ-003 (condition skip) | TC-4, TC-5 | Covered |
| flow-orchestration REQ-004 (branch) | TC-6, TC-7, TC-8, TC-23 | Covered |
| flow-orchestration REQ-005 (approval suspend/resume) | TC-9, TC-10, TC-11 | Covered |
| flow-orchestration REQ-006 (onError) | TC-12, TC-13, TC-14 | Covered |
| flow-orchestration REQ-007 (triggers) | TC-15, TC-16, TC-17 | Covered |
| flow-orchestration REQ-008 (trace/log) | TC-18 | Covered |
| flow-orchestration REQ-009 (UI) | TC-19, TC-20, TC-21, TC-22 | Covered |
| rule-pipeline REQ-RULE-009 (flow rule action) | TC-16 | Covered |
| job-management REQ-JOB-003 (FlowAction) | TC-15, TC-25 | Covered |
| sync-safety composition (design.md Decision 3) | TC-24 | Covered |

## Out of Scope

- No test cases for a drag-and-drop canvas, parallel/fan-out execution, or
  loop/iteration steps — these are explicitly out of scope for v1 per
  proposal.md; test coverage is deferred to whichever future change
  implements them.
- No load/performance test case for flow execution latency under
  concurrent triggers — the NFR documents sequential-latency as a known
  v1 ceiling, not a target to benchmark against in this change.
