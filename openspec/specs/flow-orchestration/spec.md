# flow-orchestration Specification

## Purpose

Provides a lightweight, declarative multi-step pipeline entity (`flow`) for
OpenConnector: an ordered list of steps, each referencing an existing
Source/Mapping/Synchronization/Endpoint/Approval by id, with an optional
JsonLogic run-if condition, a single-target `branch` step for non-linear
control flow, and a per-step `onError` policy. `FlowRunnerService`
executes flows by calling the existing `CallService`/`MappingService`/
`SynchronizationService`/`EventService`/`ApprovalService` public
entrypoints — it does not reimplement any of their logic (ADR-008
Controller → Service → Mapper layering; OpenRegister is the persistence
layer for every entity per ADR governing OR as the required runtime
dependency). This closes the "no multi-step workflow entity" competitive
gap (Specter insight #1249) without building a general-purpose workflow
engine — v1 ships a typed step-list editor (no parallel/fan-out, no loops).

**Scope note (2026-07-16, per ADR-065).** This Purpose previously ended
"or a drag-and-drop canvas — v1 ships a typed step-list editor only (no
canvas...)". The **canvas** exclusion is withdrawn — see REQ-009 — because
a shared canvas (`CnGraphCanvas` in `@conduction/nextcloud-vue`) is now the
fleet-standard authoring surface and a per-app opt-out defeats it. The
**engine** exclusion stands and in fact hardens: ADR-065 relocates flow
execution to OpenRegister, so OpenConnector must not grow a general-purpose
workflow engine here or anywhere (ADR-022). Parallel/fan-out and loops
remain out of scope *for this model* — the `order`-as-identity step list
cannot express them — and are delivered by the OpenRegister engine, whose
Petri-net core supports parallel splits and synchronising joins natively.

**Disambiguation:** this capability is unrelated to `flow-workflowengine-integration`
(a separate, sibling change that registers OpenConnector operations as
adapters inside Nextcloud core's own `files_workflowengine` UI). Both use
the word "flow"; neither touches the other's code.

## Requirements
### Requirement: Flow steps execute sequentially in `order` (REQ-001)

The system MUST provide a `flow` OpenRegister schema (register
`openconnector`, schema `flow`) with an ordered `steps[]` array, each step
carrying a stable integer `order` field (not array position).
`FlowRunnerService::run(ObjectEntity $flow, array $input = [], ?string
$triggerSource = null): ObjectEntity` MUST resolve `$flow->getObject()['steps']`,
sort by `order` ascending, and execute each step in that sequence by
dispatching to the step's `type` (`call` | `mapping` | `synchronization` |
`event` | `approval` | `branch`), resolving `configRef` to the referenced
Source/Mapping/Synchronization/Endpoint/Approval-group entity and calling
that entity's existing service method — `CallService::call()` for `call`,
`MappingService::executeMapping()` for `mapping`,
`SynchronizationService::synchronize()` for `synchronization`,
`EventService::emitCloudEvent()` for `event`. No step type's dispatch MUST
reimplement the logic of the service it calls.

@e2e exclude backend flow execution engine — covered by PHPUnit, not browser UI

#### Scenario: a 3-step flow (call → mapping → synchronization) runs in order

- **GIVEN** a `flow` with three steps: order 10 `type: call` (Source X),
  order 20 `type: mapping` (Mapping Y), order 30 `type: synchronization`
  (Synchronization Z), each with `onError: stop`
- **WHEN** `FlowRunnerService::run($flow)` is called
- **THEN** `CallService::call()` is invoked first, its result becomes the
  input to `MappingService::executeMapping()`, and that mapped result
  becomes the input to `SynchronizationService::synchronize()`, invoked
  last
- **AND** the resulting `flow_run`'s `flow_run_log` contains exactly three
  entries in `stepOrder` order 10, 20, 30, each `status: completed`

#### Scenario: steps run in `order` value, not array position

- **GIVEN** a `flow` whose `steps[]` array lists the order-30 step before
  the order-10 step (out-of-position insertion)
- **WHEN** `FlowRunnerService::run($flow)` is called
- **THEN** execution still proceeds order 10 then order 30 (sorted by the
  `order` field, independent of array position)

### Requirement: Step context is threaded via the reused FlowToken (REQ-002)

`FlowRunnerService` MUST reuse `flow-token-helper`'s `FlowToken` as the
step-to-step data channel rather than introducing a second, competing
context object. Before each step runs, the system MUST set
`$flowToken->setSyncInputAmended()` to the previous step's output (or the
flow's initial `$input` for the first step); after a step produces a
result, the system MUST set `$flowToken->setSyncOutputAmended()` to that
result, which becomes the next step's `syncInputAmended`. For an
endpoint-triggered flow, `requestOriginal`/`responseOriginal` MUST be
seeded once at flow start from the triggering request so step conditions
MAY reference `request.parameters.*`, matching how an endpoint rule's
JsonLogic condition can already do so.

@e2e exclude backend flow context threading — covered by PHPUnit, not browser UI

#### Scenario: a step's output becomes the next step's input

- **GIVEN** a 2-step flow where the order-10 `mapping` step returns
  `{ "id": "abc" }`
- **WHEN** the order-20 `synchronization` step runs
- **THEN** `SynchronizationService::synchronize()` is called with
  `data: { "id": "abc" }` (read from `$flowToken->getSyncInputAmended()`)

### Requirement: Step `condition` skips a step when it evaluates false (REQ-003)

The system MUST evaluate each step's optional JsonLogic `condition`
before dispatching that step, calling `JWadhams\JsonLogic::apply($step['condition'],
$context)` — the same static call already used by
`EndpointService::checkRuleConditions()` for endpoint rules — against the
current step context. If the condition evaluates to a value that does not
loosely equal `true`, the step MUST be skipped (not dispatched, no
downstream service called) and recorded in `flow_run_log` with
`status: skipped`. A step with no `condition` (absent or empty) MUST
always run.

@e2e exclude backend condition evaluation — covered by PHPUnit, not browser UI

#### Scenario: a false condition skips the step

- **GIVEN** a flow's order-20 step has `condition: { "==": [{"var":
  "syncInputAmended.status"}, "active"] }` and the order-10 step's output
  is `{ "status": "inactive" }`
- **WHEN** the runner reaches the order-20 step
- **THEN** the step's service method is NOT called
- **AND** `flow_run_log` records a `stepOrder: 20, status: skipped` entry
- **AND** execution continues to the next step in sequence

#### Scenario: a true condition runs the step normally

- **GIVEN** the same flow but the order-10 step's output is
  `{ "status": "active" }`
- **WHEN** the runner reaches the order-20 step
- **THEN** the step's service method IS called and `flow_run_log` records
  `status: completed` (or `failed`, per REQ-005) for that step

### Requirement: `branch` step selects the next step via JsonLogic (REQ-004)

A step of `type: branch` MUST carry a `branches[]` array of
`{ condition, nextStepOrder }` pairs and MAY carry a
`defaultNextStepOrder`. The system MUST evaluate each `branches[].condition`
in array order via `JWadhams\JsonLogic::apply()` against the current step
context and, on the first match, set the next step to execute to that
entry's `nextStepOrder`, skipping any steps between the branch step and
the selected target. If no `branches[].condition` matches, the system
MUST use `defaultNextStepOrder` if present, or continue to the next step
in `order` sequence otherwise. A `branch` step's `nextStepOrder` or
`defaultNextStepOrder` that does not resolve to an existing step `order`
MUST cause the flow run to fail with a fatal error (not a silent skip),
regardless of any step's individual `onError` policy — an unresolvable
branch target is a configuration error, not a runtime step failure.

@e2e exclude backend branch step evaluation — covered by PHPUnit, not browser UI

#### Scenario: branch selects the first matching target

- **GIVEN** a `branch` step at order 20 with
  `branches: [{ condition: {"==":[{"var":"syncInputAmended.mode"},"full"]}, nextStepOrder: 30 }, { condition: {"==":[{"var":"syncInputAmended.mode"},"incremental"]}, nextStepOrder: 40 }]`
  and the order-10 step's output is `{ "mode": "incremental" }`
- **WHEN** the runner reaches the order-20 branch step
- **THEN** execution jumps to the order-40 step
- **AND** the order-30 step is NOT executed and does NOT appear in
  `flow_run_log`

#### Scenario: no branch matches falls back to defaultNextStepOrder

- **GIVEN** the same branch step and the order-10 step's output is
  `{ "mode": "unknown" }`, with `defaultNextStepOrder: 30`
- **WHEN** the runner reaches the order-20 branch step
- **THEN** execution proceeds to the order-30 step

#### Scenario: an unresolvable branch target fails the run

- **GIVEN** a `branch` step whose only `branches[].nextStepOrder` is `99`
  and no step with `order: 99` exists in the flow
- **WHEN** that branch matches and is selected
- **THEN** the flow run fails with a fatal error
- **AND** `flow_run`'s status is recorded as `failed`, regardless of any
  step's configured `onError` policy

### Requirement: `approval` step suspends and resumes the flow run (REQ-005)

A step of `type: approval` MUST suspend the flow run by persisting an
`approval_request` OR object carrying `flowRunId` and `resumeStepOrder`
(the step immediately following the approval step), `approverGroup`,
`onReject`, `onTimeout`, following the same persistence shape
`ApprovalService::suspend()` already uses for endpoint-rule suspensions,
with `snapshot` set to the current `$flowToken->__serialize()`
(sensitive-header-stripped). `FlowRunnerService::run()` MUST return
immediately after suspending, with the `flow_run`'s status set to
`suspended`; no later step MUST execute in that invocation.

On approval, `FlowRunnerService::resumeFromApproval(ObjectEntity
$approvalRequest): ObjectEntity` MUST rehydrate the `FlowToken` via
`ApprovalService::rehydrateFlowToken()` (reused unmodified) and resume
execution at `resumeStepOrder`, continuing the same sequencing,
condition, branch, and `onError` rules as an unsuspended run. On
rejection, the flow run's status MUST be set to `stopped`. On timeout
(via `ApprovalService::sweepExpired()`'s existing cron sweep), the flow
run's status MUST be set per the approval step's `onTimeout` config,
matching the endpoint-rule case's `onTimeout` semantics.

@e2e exclude backend approval suspend/resume — covered by PHPUnit, not browser UI

#### Scenario: an approval step suspends the run

- **GIVEN** a flow with an `approval` step at order 20
- **WHEN** the runner reaches the order-20 step
- **THEN** an `approval_request` OR object is created with
  `flowRunId` set to the current run and `resumeStepOrder: 30`
- **AND** `FlowRunnerService::run()` returns with `flow_run.status: suspended`
- **AND** no step after order 20 has executed

#### Scenario: approving the request resumes the flow from the next step

- **GIVEN** the suspended run from the previous scenario
- **WHEN** an authorized approver calls `POST /api/approvals/{id}/approve`
- **THEN** `FlowRunnerService::resumeFromApproval()` is invoked
- **AND** execution resumes at the order-30 step using the rehydrated
  `FlowToken`
- **AND** the flow run's status becomes `completed` once all remaining
  steps finish

#### Scenario: rejecting the request stops the flow

- **GIVEN** the suspended run
- **WHEN** an authorized approver calls `POST /api/approvals/{id}/reject`
  with a mandatory comment
- **THEN** no further flow steps execute
- **AND** the flow run's status becomes `stopped`

### Requirement: per-step `onError` policy governs failure handling (REQ-006)

Each step MUST carry an `onError` policy of `stop` (default), `continue`,
or `dead_letter`. If a step's dispatched service call throws, the system
MUST catch the throwable, record `flow_run_log` for that step with
`status: failed` and the captured error message, and then:

- `stop`: the flow run MUST end immediately with `flow_run.status: stopped`;
  no later step MUST execute.
- `continue`: the flow run MUST proceed to the next step in sequence as
  if the failed step had been skipped; the failure is recorded but does
  not halt the run.
- `dead_letter`: the flow run MUST end immediately with
  `flow_run.status: dead_letter`, distinct from `stop`, so dead-lettered
  runs can be filtered/queried separately from cleanly-stopped ones (an
  operator worklist, matching the existing `SyncDeadLetters` pattern).

@e2e exclude backend error-policy dispatch — covered by PHPUnit, not browser UI

#### Scenario: onError stop halts the run on the failing step

- **GIVEN** a flow's order-20 step has `onError: stop` and its
  `CallService::call()` throws
- **WHEN** the runner processes that step
- **THEN** `flow_run_log` records `stepOrder: 20, status: failed`
- **AND** `flow_run.status` becomes `stopped`
- **AND** no step after order 20 executes

#### Scenario: onError continue proceeds past the failing step

- **GIVEN** the same flow but the order-20 step has `onError: continue`
- **WHEN** the runner processes that step and it throws
- **THEN** `flow_run_log` records `stepOrder: 20, status: failed`
- **AND** the order-30 step still executes
- **AND** `flow_run.status` becomes `completed` if all remaining steps
  succeed

#### Scenario: onError dead_letter marks the run distinctly from stop

- **GIVEN** the same flow but the order-20 step has `onError: dead_letter`
- **WHEN** the runner processes that step and it throws
- **THEN** `flow_run.status` becomes `dead_letter` (not `stopped`)
- **AND** no step after order 20 executes

### Requirement: a flow runs via cron, endpoint rule, event, or manual trigger (REQ-007)

The system MUST support triggering `FlowRunnerService::run()` from four
surfaces, each reusing an existing trigger mechanism rather than
introducing a new scheduler: (a) a cron-scheduled `job` OR object whose
`jobClass` is `OCA\OpenConnector\Action\FlowAction`, resolved and
`run($arguments)`-invoked by `JobService::executeJob()` exactly as any
other job action; (b) a `flow` rule action type added to
`EndpointService::processRules()`'s existing type dispatch, valid for
either timing, which resolves `configRef` to a `flow` id and calls
`FlowRunnerService::run($flow, data: $data)`; (c) an event-triggered
invocation wired through the existing `EventService` subscriber delivery
path, matching a configured CloudEvent type/source/subject to a `flow` id
and calling `FlowRunnerService::run()`; (d) a manual "Run" action on the
Flow detail page calling `POST /api/flows/{id}/run`, which invokes
`FlowRunnerService::run()` synchronously and returns the resulting
`flow_run`.

@e2e exclude backend job/rule/event trigger wiring — covered by PHPUnit/Newman, not browser UI (manual-trigger UI is covered under REQ-009)

#### Scenario: a cron job triggers a flow

- **GIVEN** an enabled `job` OR object with `jobClass:
  'OCA\OpenConnector\Action\FlowAction'` and `arguments: { flowId: '<uuid>' }`
- **WHEN** `JobService::run()` sweeps due jobs and calls
  `FlowAction::run($arguments)`
- **THEN** `FlowRunnerService::run()` is invoked for the referenced flow
- **AND** a `job_log` entry is written summarising the flow run's outcome

#### Scenario: an endpoint rule triggers a flow

- **GIVEN** an endpoint with a rule of `type: flow`, `configRef: <flow
  uuid>`
- **WHEN** the endpoint's rule pipeline reaches that rule and its
  condition (if any) passes
- **THEN** `FlowRunnerService::run($flow, data: $data)` is invoked with
  the current pipeline data as the flow's initial input

#### Scenario: manual run triggers a flow synchronously

- **GIVEN** an admin viewing the Flow detail page for an enabled flow
- **WHEN** they click "Run"
- **THEN** `POST /api/flows/{id}/run` is called
- **AND** `FlowRunnerService::run()` executes synchronously and the
  response carries the resulting `flow_run`'s status and `flow_run_log`

### Requirement: flow runs are persisted with a per-step trace (REQ-008)

Every `FlowRunnerService::run()` invocation MUST create a `flow_run` OR
object (register `openconnector`, schema `flow_run`) carrying `flowId`,
`triggerSource` (`cron` | `endpoint` | `event` | `manual`), `status`
(`running` | `completed` | `stopped` | `dead_letter` | `suspended`),
`startedAt`, `finishedAt`. Each step execution (including skipped steps,
per REQ-003) MUST append a `flow_run_log` entry with `stepOrder`, `type`,
`status` (`completed` | `skipped` | `failed`), `startedAt`, `finishedAt`,
and `error` (present only when `status: failed`).

@e2e exclude backend trace persistence — covered by PHPUnit, not browser UI

#### Scenario: a completed run's log reflects every step outcome

- **GIVEN** the 3-step flow from REQ-001 where the order-20 step's
  condition is false
- **WHEN** the flow run completes
- **THEN** `flow_run_log` contains three entries: order 10 `completed`,
  order 20 `skipped`, order 30 `completed`
- **AND** `flow_run.status` is `completed`
- **AND** `flow_run.finishedAt` is set

### Requirement: Flows index and detail UI provide a typed step-list editor (REQ-009)

OpenConnector MUST provide a `Flows` section in its SPA: an index page
(`type: index`, listing `name`, `isEnabled`, last-run status/time) and a
detail page (`type: custom`, component `FlowDetailPage`) where an admin
can add, remove, reorder, and configure steps. Each step row MUST use an
`NcSelect` for `type` and, where applicable, `configRef` and `onError`,
each with an explicit `inputLabel` (WCAG 2.1 AA 1.3.1/4.1.2 — matching
the codebase's `EditEndpoint.vue` pattern, not `EditSynchronization.vue`'s
non-conformant one). The step list MUST support add, remove, and reorder
via move-up/move-down controls, which remain a valid authoring surface for
linear flows and MUST stay keyboard-operable regardless of any canvas.
Any modal used by the Flow pages MUST live in its own file under
`src/modals/Flow/`, not inline in the page component.

**Graph editing (revised 2026-07-16, per ADR-065).** This requirement
previously read *"the editor MUST NOT implement drag-and-drop or a
node-graph canvas in this version"*. That prohibition is **withdrawn**: it
was a reasonable v1 scope constraint, but it now contradicts the fleet
decision that a canvas is the standard flow-authoring surface. It is
replaced by a uniformity rule:

- OpenConnector MUST NOT hand-roll a node-graph canvas. If and when the
  Flow pages offer graph editing, they MUST consume `CnGraphCanvas` from
  `@conduction/nextcloud-vue` (ADR-065), which owns geometry and
  interaction only; step semantics stay app-owned.
- A canvas MUST NOT be the sole authoring surface. The typed step list is
  the accessible path and the fallback (WCAG 2.1 AA 2.1.1 — a
  drag-only editor is not keyboard-operable).
- Adopting a canvas over the current model requires resolving `order`
  first. `order` is simultaneously step identity, execution sequence, and
  the implicit edge set, and `branches[].nextStepOrder` /
  `defaultNextStepOrder` reference it **by value** — so a dragged edge can
  silently invalidate every branch target in the flow. A canvas MUST NOT
  ship against the `order`-as-identity model until edges are explicit.
  This is tracked by the engine relocation to OpenRegister (ADR-065), not
  by a local workaround.

#### Scenario: Flows index page mounts and lists flows

- **GIVEN** an authenticated admin visits the openconnector app
- **WHEN** they navigate to the Flows section via the sidebar nav or
  direct URL `/apps/openconnector/flows`
- **THEN** the Flows index page renders inside the main content area,
  listing each flow's name, enabled state, and last-run status

#### Scenario: the step-list editor adds a step with a typed config picker

- **GIVEN** an admin on the Flow detail page for an existing flow
- **WHEN** they click "Add step", select `type: mapping` from the step
  type `NcSelect`, and then open the config-ref picker
- **THEN** the config-ref picker's options are scoped to existing
  Mapping entities only (not Sources, Synchronizations, or Endpoints)

#### Scenario: reordering is possible without a pointer drag

- **GIVEN** a flow with three steps
- **WHEN** the admin clicks "Move up" on the second step
- **THEN** the second step's `order` value is swapped with the first
  step's `order` value
- **AND** the reorder is achievable by keyboard alone, with no
  drag-and-drop interaction required

#### Scenario: graph editing, if offered, reuses the shared canvas

- **GIVEN** the Flow detail page offers a graph view of a flow
- **WHEN** the page renders that view
- **THEN** it renders `CnGraphCanvas` from `@conduction/nextcloud-vue`
  rather than a component-local node-graph implementation
- **AND** the typed step list remains reachable as the keyboard-operable
  authoring surface for the same flow

@e2e exclude graph view not yet offered — the canvas is introduced with the
OpenRegister engine relocation (ADR-065); this scenario becomes testable when
the Flow pages gain a graph view, and the step-list scenarios above cover the
current UI

