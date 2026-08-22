# Proposal: visual-flow-orchestration

## Summary

Integriq has no multi-step workflow entity: a `synchronization` is one
source → one target → one mapping, and chaining steps together today only
happens indirectly through endpoint rules or events. This change adds a
`flow` OpenRegister entity — an ordered list of steps, each referencing an
existing Source/Mapping/Synchronization/Endpoint/Approval by id, with an
optional JsonLogic `condition` and an `onError` policy — plus a
`FlowRunnerService` that executes those steps in order by calling the
existing `CallService`/`MappingService`/`SynchronizationService`/
`EventService`/`ApprovalService` APIs. This closes the multi-step
orchestration gap identified against n8n/Windmill/Make/NiFi (Specter
insight #1249) without building a new execution engine or a drag-and-drop
canvas.

**Disambiguation:** this is NOT the same as the sibling change
`flow-workflowengine-integration`, which registers Integriq operations
as adapters inside Nextcloud core's own `files_workflowengine` "Flow" UI.
This change introduces Integriq's own `flow` entity and runner; the two
share the word "flow" but touch unrelated code paths and have no scope
overlap.

## Motivation

Competitors model multi-step pipelines as first-class entities with
per-step logs and branching. Today, an Integriq admin who wants
"call an external API, map the result, then synchronize it into a
register" has to either write three separate endpoint rules glued together
through the `synchronization`/`mapping`/`extend_external_input` rule types,
or accept that each step is triggered and observed independently with no
shared run identity. This is workable for two-step chains but breaks down
past that, and there is no way to express "run step 3 only if step 2's
result satisfies a condition" or "branch to step 4a or 4b depending on the
payload" without hand-rolling it inside a single rule's JsonLogic
condition. A lightweight, declarative, backend-first `flow` entity closes
this gap while deliberately NOT attempting to match a general-purpose
workflow engine's feature set.

## Affected Projects

- [x] Project: `integriq` — new `flow` OR entity + `FlowRunnerService`
  + `flow` rule-pipeline action type + `flow` job Action + Flows index/detail
  UI.

## Scope

### In Scope

- A `flow` OpenRegister schema: `name`, `description`, `isEnabled`, and an
  ordered `steps[]` array. Each step has `order`, `type` (`call` |
  `mapping` | `synchronization` | `event` | `approval` | `branch`),
  `configRef` (the id of the existing Source/Mapping/Synchronization/
  Endpoint/Approval-group entity the step invokes), an optional JsonLogic
  `condition` (run-if, evaluated against the shared step context), an
  `onError` policy (`stop` | `continue` | `dead_letter`), and, for `branch`
  steps only, a `branches[]` list of `{ condition, nextStepOrder }` pairs
  evaluated in order (first match wins) plus a `defaultNextStepOrder`.
- `FlowRunnerService::run(ObjectEntity $flow, array $input = [],
  ?string $triggerSource = null): ObjectEntity` — executes a flow's steps
  in `order`, threading a `FlowToken` (reused as-is from
  `flow-token-helper` — see design.md for exactly how its 8 fixed slots
  map onto step input/output, since it is not a generic named-slot store)
  as the shared context between steps, calling the existing
  `CallService::call()`, `MappingService::executeMapping()`,
  `SynchronizationService::synchronize()`, `EventService::emitCloudEvent()`,
  and `ApprovalService::suspend()`/resume path for the matching step types.
  No step type reimplements the logic of the entity it calls.
- A `branch` step type: evaluates each `branches[].condition`
  (JsonLogic, via the same `JWadhams\JsonLogic::apply()` call already used
  by `EndpointService::checkRuleConditions()`) in order against the
  current context and jumps to the first matching `nextStepOrder`, or
  `defaultNextStepOrder` if none match. This is the only non-linear
  control flow in v1 — no fan-out, no loops.
- An `approval` step type that suspends the flow run mid-execution by
  delegating to `ApprovalService::suspend()`-equivalent persistence, and
  resumes via a new `FlowRunnerService::resumeFromApproval()` entrypoint
  analogous to `EndpointService::resumeFromApproval()` (see design.md —
  nothing generic exists in `ApprovalService` for "resume any suspended
  thing"; each caller writes its own resume path).
- Per-step execution trace: a `flow_run` OR object (one per run) with an
  embedded/related `flow_run_log` array of per-step entries
  (`stepOrder`, `type`, `status`, `startedAt`, `finishedAt`, `error?`).
  If `execution-trace-observability` lands first, `flow_run_log` SHOULD
  compose with its `execution_trace` schema instead of duplicating it —
  noted as a sequencing dependency, not a hard blocker (see design.md).
- Triggers: a flow runs via (a) a new `flow` job Action (`lib/Action/
  FlowAction.php`, `jobClass` on a `job` OR object — cron), (b) a new
  `flow` rule-pipeline action type in `EndpointService::processRules()`
  (endpoint rule), (c) a new `EventService`/event-subscriber hook that
  triggers a flow on a CloudEvent (event), and (d) a manual "Run" button
  on the Flow detail page calling `FlowRunnerService::run()` directly
  (manual). All four reuse existing trigger surfaces — no new scheduler.
- A Flows index page (`type: index`, generic list, matching the
  `Synchronizations` pattern) and a Flow detail page
  (`type: custom`, component `FlowDetailPage`, matching the
  `SynchronizationDetail` pattern) with a typed step-list editor: an
  ordered list of step rows, each with a type dropdown (NcSelect with
  `inputLabel`, following `EditEndpoint.vue`'s pattern — NOT
  `EditSynchronization.vue`'s non-conformant one), a config-ref picker
  scoped to the chosen type's entity list, a condition editor (reusing
  whatever condition-editing UI the rule pipeline already has), an
  onError select, and up/down/remove step controls. This is a list, not
  a canvas — no drag-and-drop, no node graph.
- Unit tests for `FlowRunnerService`: step ordering, condition skip,
  branch step next-step selection, `onError: stop|continue|dead_letter`,
  approval-step suspend/resume. Integration test: a 3-step flow
  (`call` → `mapping` → `synchronization`) runs end-to-end and produces
  a correctly-ordered `flow_run_log`.

### Out of Scope

- **Visual drag-and-drop canvas UI.** V1 ships a typed step-list editor
  only. A canvas is a natural v2 follow-up once the declarative model is
  proven; file a follow-up issue at archive time.
- **Parallel/fan-out steps.** V1 is strictly sequential-with-branch; a
  step has exactly one active predecessor at runtime (branch selects ONE
  next step, it does not spawn concurrent branches). Fan-out/join
  semantics are deferred.
- **Loops/iteration steps.** No `for-each` or `while` step type in v1. A
  step that needs to process N items does so by delegating to a
  `synchronization` step (which already has its own internal batching),
  not by the flow runner looping.
- Reimplementing Source/Mapping/Synchronization/Endpoint/Approval logic
  inside the runner — steps are thin references, not copies.
- A generic "resume any suspended thing" mechanism in `ApprovalService`
  — the flow approval step gets its own resume entrypoint, matching the
  existing precedent that `EndpointService` and `SynchronizationService`
  each have their own resume path.

## Approach

Add a `flow` OpenRegister schema (register.d fragment, following the
`hitl-approval-rule-action.json` precedent) plus `flow_run`/`flow_run_log`
schemas for execution history. Add `FlowRunnerService` in `lib/Service/`
that resolves a flow's `steps[]`, iterates them in `order`, evaluates
`condition`/`branches` via the existing static `JWadhams\JsonLogic::apply()`
call, and dispatches each step's `type` to the corresponding existing
service's public entrypoint — no new execution logic beyond sequencing,
context-threading, and error-policy handling. Add one new `match` arm
(`'flow' => ...`) to `EndpointService::processRules()`'s existing 22-way
type dispatch, following the exact precedent set by the `approval` action
type (REQ-RULE-008). Add `lib/Action/FlowAction.php` implementing the
same duck-typed `run(array $arguments): array` contract as
`SynchronizationAction`/`PingAction`, resolved via `jobClass` on a `job` OR
object — no new Action interface needed. Add `Flows`/`FlowDetail` manifest
entries following the `Synchronizations`/`SynchronizationDetail`
generic-index + custom-detail split already used for comparably complex
entities.

## New Dependencies

None. Reuses `jwadhams/json-logic-php` (already a dependency, used for
rule conditions) and all existing Integriq services.

## Impact

- `lib/Settings/register.d/` — new fragment(s) defining `flow`, `flow_run`,
  `flow_run_log` schemas.
- `lib/Service/EndpointService.php` — one new `match` arm in
  `processRules()`.
- `lib/Service/FlowRunnerService.php` — new file.
- `lib/Action/FlowAction.php` — new file.
- `lib/Controller/FlowsController.php` (or equivalent) — new file, CRUD +
  manual-run endpoint, following existing controller conventions.
- `src/manifest.json` — new `Flows`/`FlowDetail` page entries + menu entry.
- `src/views/Flow/` — new `FlowDetailPage.vue` + step-list editor
  sub-components; `src/modals/Flow/` — new modal(s).
- `openspec/specs/flow-orchestration/spec.md` — new capability spec.
- `openspec/specs/rule-pipeline/spec.md` — delta (`flow` action type,
  REQ-RULE-009).
- `openspec/specs/job-management/spec.md` — delta (flow job action,
  REQ-JOB-003).

## Cross-Project Dependencies

None outside `integriq`. Within `integriq`, this change
references (but does not modify) `flow-token-helper`, `hitl-approval`
(`ApprovalService`), `rule-pipeline`, `job-management`/`job-scheduling`,
`synchronization-engine`, and `openconnector-app-manifest`. It SHOULD
sequence after `execution-trace-observability` if that change lands first
(see design.md); if it lands first, `flow_run_log` ships as its own
minimal schema and a follow-up change converges the two.

## Risks

### Risk 1: FlowToken is not a generic slot store

**Severity:** Medium — **Mitigation:** `flow-token-helper`'s `FlowToken`
has 8 fixed, hardcoded slots (request/response/syncInput/syncOutput ×
original/amended), not an extensible key-value context. The brief assumed
a generic slot mechanism; it does not exist. Design.md defines exactly how
the runner maps step input/output onto the existing 8 slots (treating
`syncInputAmended`/`syncOutputAmended` as the primary step-to-step
data channel) rather than inventing a second, competing context object.

### Risk 2: Approval resume has no generic mechanism to reuse

**Severity:** Medium — **Mitigation:** `ApprovalService` provides
suspend/rehydrate/complete primitives but each caller (`EndpointService`,
`SynchronizationService`) writes its own bespoke resume path. The flow
approval step follows the same pattern with its own
`FlowRunnerService::resumeFromApproval()`, persisting the suspending
step's `order` as `resumeOrder` (mirroring the endpoint-rule case
exactly) rather than trying to generalize `ApprovalService` itself, which
is out of scope for this change.

### Risk 3: execution-trace-observability may not land first

**Severity:** Low — **Mitigation:** ship a minimal, self-contained
`flow_run`/`flow_run_log` schema now; if/when `execution-trace-observability`
lands, converge in a small follow-up change rather than blocking this one
on another in-flight, spec-only change.

### Risk 4: naming collision with flow-workflowengine-integration

**Severity:** Low — **Mitigation:** explicit disambiguation note in this
proposal and in the new capability spec's Purpose section; no code-level
conflict exists (verified — that change touches `files_workflowengine`
adapters, not any file this change touches).

## Rollback Strategy

The `flow` entity, `FlowRunnerService`, `FlowAction`, and Flows UI are
wholly additive and isolated behind their own routes/schemas/action type.
Rollback = revert the PR(s); the new `flow` match arm in
`EndpointService::processRules()` is a single additive `case`, and no
existing dispatch, schema, or service method is modified. Any `flow`
OR objects and `flow_run`/`flow_run_log` history left behind after a
revert are inert (no other code reads them) and can be cleaned up via a
standard OR object deletion pass; no destructive migration is introduced
in either direction.

## Open Questions

- Should the `event` step type target `EventService::emitCloudEvent()`
  (the app's own CloudEvents subscriber pipeline) or raw
  `OCP\EventDispatcher\IEventDispatcher` (internal NC events), or should
  the step config let the author pick? Deferred to design.md Decision 5 —
  default is CloudEvents-only in v1, since that's the mechanism a flow
  step config can name declaratively (a source/subject/type), while raw
  NC events are typically fired from code, not configuration.
- Exact retention policy for `flow_run`/`flow_run_log` (mirrors
  `job_log`'s per-record `expires` + cleanup task, or a fixed retention?)
  — resolved in design.md, follows the `job_log` precedent.
