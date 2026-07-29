# Design: visual-flow-orchestration

## Architecture Overview

```
Trigger (cron job | endpoint rule | CloudEvent | manual "Run")
   │
   ▼
FlowRunnerService::run(ObjectEntity $flow, array $input, ?string $triggerSource)
   │  loads $flow->getObject()['steps'] (sorted by 'order')
   │  creates a FlowToken (flow-token-helper) as the shared step context
   │  creates a `flow_run` OR object (status: running)
   │
   ├─ for each step (or the branch-selected next step):
   │     ├─ evaluate step['condition'] via JWadhams\JsonLogic::apply() against
   │     │  the current FlowToken-derived data — same call EndpointService
   │     │  ::checkRuleConditions() already makes; skip step (log 'skipped')
   │     │  if false
   │     ├─ dispatch by step['type']:
   │     │     call            -> CallService::call()
   │     │     mapping         -> MappingService::executeMapping()
   │     │     synchronization -> SynchronizationService::synchronize(..., flowToken: $token)
   │     │     event           -> EventService::emitCloudEvent()
   │     │     approval        -> ApprovalService::suspend()-equivalent, then
   │     │                        FlowRunnerService::run() RETURNS (run status: suspended)
   │     │     branch          -> evaluate branches[].condition in order,
   │     │                        pick nextStepOrder (or defaultNextStepOrder)
   │     ├─ write a flow_run_log row (stepOrder, type, status, timing, error?)
   │     └─ on error: apply onError (stop | continue | dead_letter)
   │
   └─ mark flow_run 'completed' | 'stopped' | 'dead_letter' | 'suspended'

Resume (approval granted):
ApprovalsController::approve() -> ApprovalService rehydrates -> for a
flow-sourced approval_request, dispatches to
FlowRunnerService::resumeFromApproval($flowRun, $approvalRequest)
instead of EndpointService::resumeFromApproval() — same pattern, new
sibling entrypoint, not a shared generic one (none exists today).
```

The runner is pure orchestration: every unit of actual work (calling an
HTTP source, running a mapping, executing a sync, emitting an event,
gating on approval) is delegated to the existing service that already
does that job. `FlowRunnerService` owns exactly three things a flow adds
that nothing else does: step ordering, condition/branch evaluation, and
per-step error-policy + trace recording.

## Goals / Non-Goals

**Goals:** sequential, declarative, referencing-not-reimplementing
multi-step execution; branch (single-target jump) control flow; approval
suspension mid-flow; per-step trace; cron/endpoint/event/manual triggers;
a typed step-list editor.

**Non-Goals:** drag-and-drop canvas, parallel/fan-out, loops/iteration,
a new generic cross-service context object, a new generic
"resume anything" mechanism, retrying individual steps (a failed step
either stops, continues, or dead-letters the whole run — no per-step
retry in v1).

## Decisions

### Decision 1: `flow` OR schema shape

```jsonc
{
  "slug": "flow",
  "properties": {
    "uuid": { "type": "string", "title": "UUID" },
    "name": { "type": "string" },
    "description": { "type": "string" },
    "isEnabled": { "type": "boolean", "default": true },
    "steps": {
      "type": "array",
      "title": "Steps",
      "description": "Ordered list of flow steps. Order is the array index's `order` field, not array position, so branch targets (nextStepOrder) remain stable across insert/delete/reorder edits.",
      "items": {
        "type": "object",
        "required": ["order", "type", "onError"],
        "properties": {
          "order": { "type": "integer", "description": "Stable step identifier and default execution sequence (ascending). Branch nextStepOrder/defaultNextStepOrder reference this, not array position." },
          "type": { "type": "string", "enum": ["call", "mapping", "synchronization", "event", "approval", "branch"] },
          "configRef": { "type": "string", "format": "uuid", "description": "Id of the existing Source (call)/Mapping (mapping)/Synchronization (synchronization)/event-target/Endpoint entity this step invokes. Not applicable to branch steps." },
          "condition": { "type": "object", "description": "Optional JsonLogic rule. Step runs only if apply(condition, context) == true. Absent/empty = always run." },
          "onError": { "type": "string", "enum": ["stop", "continue", "dead_letter"], "default": "stop" },
          "branches": {
            "type": "array",
            "description": "branch steps only. Evaluated in array order, first match wins.",
            "items": {
              "type": "object",
              "properties": {
                "condition": { "type": "object" },
                "nextStepOrder": { "type": "integer" }
              }
            }
          },
          "defaultNextStepOrder": { "type": "integer", "description": "branch steps only. Used if no branches[].condition matches." }
        }
      }
    }
  }
}
```

**Rationale:** `order` is a stable integer field on each step object
(not array position) precisely so `branch` steps can jump to a specific
step even after the author reorders/inserts/deletes other steps in the
editor — mirrors how `rule.order` already works for endpoint rules
(rules are matched/sorted by an `order` field, not array index, per
`rule-pipeline` REQ-RULE-001). **Alternative considered:** array-index
addressing (branch target = index) — rejected because it makes the
step-list editor's insert/delete/reorder operations silently corrupt
branch targets.

### Decision 2: FlowRunnerService execution model — reusing FlowToken as-is

`flow-token-helper`'s `FlowToken` is **not** a generic named-slot store
— it has exactly 8 fixed properties (`requestOriginal`/`Amended`,
`responseOriginal`/`Amended`, `syncInputOriginal`/`Amended`,
`syncOutputOriginal`/`Amended`). The context-brief's "reuse the slot
mechanism" is honored literally, not reinvented, by treating the flow's
step-to-step data channel as `syncInputAmended`/`syncOutputAmended`:

- Before each step runs, `FlowRunnerService` sets
  `$flowToken->setSyncInputAmended($previousStepOutput ?? $initialInput)`.
- After a step produces a result, `FlowRunnerService` sets
  `$flowToken->setSyncOutputAmended($stepResult)`, which becomes the next
  step's `syncInputAmended`.
- `requestOriginal`/`responseOriginal` are seeded once at flow start from
  the trigger context (the endpoint request, for an endpoint-triggered
  flow; empty array for cron/event/manual triggers) and left largely
  untouched — they exist so a `call`/`mapping` step's JsonLogic condition
  can still reference `request.parameters.x` if the flow was
  endpoint-triggered, exactly as an endpoint rule's condition can today.
- No `__unserialize()` exists on `FlowToken` (confirmed gap, shared with
  `ApprovalService::rehydrateFlowToken()`); `FlowRunnerService` rehydrates
  the same way `ApprovalService` does — construct empty, then call the 8
  setters from a stored snapshot array.

**Alternative considered:** wrap `FlowToken` in a new
`FlowExecutionContext` with a generic `array $slots` map keyed by step
`order`, giving every step's full output history, not just the
immediately-preceding step's. Rejected for v1: it duplicates
`FlowToken` rather than reusing it (violating the brief's explicit "do
NOT duplicate" constraint), and no v1 step type needs anything but its
immediate predecessor's output — `synchronization`/`mapping`/`call` are
single-hop transforms. Noted as a v2 follow-up if a real use case for
"read step 1's output from step 4" emerges.

### Decision 3: dispatch — thin adapter methods, not reimplementation

`FlowRunnerService` gets one `private function runStep(array $step,
FlowToken &$flowToken, ObjectEntity $flowRun): array` with a `match` on
`$step['type']`, each arm resolving `configRef` to the target entity via
`OrObjectService::find()` and calling exactly one existing public method:

| step type | resolves `configRef` as | calls |
|---|---|---|
| `call` | Source id | `CallService::call($source, ...)` |
| `mapping` | Mapping id | `MappingService::executeMapping($mapping, $flowToken->getSyncInputAmended())` |
| `synchronization` | Synchronization id | `SynchronizationService::synchronize($synchronization, data: $flowToken->getSyncInputAmended(), flowToken: $flowToken)` — passes `$flowToken` through by reference so `sync-safety`'s guards (batch-approval gate, dedup, etc.) run exactly as they do for a directly-triggered sync; a flow step MUST NOT set any flag that bypasses those guards |
| `event` | n/a (config carries source/subject/type template) | `EventService::emitCloudEvent(type, source, subject, $flowToken->getSyncInputAmended())` |
| `approval` | n/a (config carries approverGroup/onReject/onTimeout) | writes an `approval_request` OR object with `flowRunId`/`resumeStepOrder` set (new fields, alongside the existing `endpointId`/`synchronizationId` — see migration note) and returns a `suspended` run status |
| `branch` | n/a | evaluates `branches[]` conditions via `JsonLogic::apply()`, returns the selected `nextStepOrder` to the runner's loop control (no service call) |

This table **is** the reuse contract: no step arm contains
source-calling, mapping-transform, or sync-batching logic — it only
resolves an id and forwards to the existing method signature (verified
against HEAD in the accompanying research: `CallService::call()`,
`MappingService::executeMapping()`, `SynchronizationService::synchronize()`
line 2055, `EventService::emitCloudEvent()`).

### Decision 4: approval-step suspend/resume

No generic "suspend/resume anything" primitive exists in
`ApprovalService` — `EndpointService::resumeFromApproval()` and
`SynchronizationService`'s gate-resume path are both bespoke. The flow
approval step follows the same house style:

- **Suspend:** `FlowRunnerService` calls
  `ApprovalService::suspend()`-equivalent persistence: create an
  `approval_request` OR object with `flowRunId` (new field) and
  `resumeStepOrder` = the approval step's `order` + 1's-worth of context
  (the step immediately after the approval step to resume from),
  `approverGroup`/`onReject`/`onTimeout` from the step's config, `snapshot`
  = `$flowToken->__serialize()` (sensitive-header-stripped, matching the
  existing `suspend()` precedent). The `flow_run` object's status is set
  to `suspended`.
- **Resume:** `ApprovalsController::approve()`'s existing branch-on-record-shape
  logic (today: `endpointId` vs `synchronizationId`) gets a third branch:
  `flowRunId` set → `FlowRunnerService::resumeFromApproval(ObjectEntity
  $approvalRequest): ObjectEntity` — rehydrates `FlowToken` via
  `ApprovalService::rehydrateFlowToken()` (reused as-is, no fork), loads
  the `flow_run`/`flow`, and re-enters the step loop starting at
  `resumeStepOrder`, exactly mirroring how `EndpointService
  ::resumeFromApproval()` re-enters `processRules()` after
  `resumeAfterOrder`.
- **Reject/timeout:** `ApprovalService::reject()`/`sweepExpired()` are
  reused unmodified; the flow-specific piece is only in
  `ApprovalsController`'s branch and `FlowRunnerService`'s new resume
  method — the flow_run's status is set to `stopped` (rejected) or
  `dead_letter` (expired, per the approval step's `onTimeout` config,
  matching the endpoint-rule case's `onTimeout` semantics).

**Migration note:** `approval_request` gains two new optional properties
(`flowRunId`, `resumeStepOrder`) — additive, no existing property
changes; see migration section below on why this is a register.d fragment
addition, not a NC schema migration.

### Decision 5: `event` step targets EventService's CloudEvents pipeline, not raw IEventDispatcher

Two event mechanisms exist in this codebase: `EventService
::emitCloudEvent()` (the app's own CloudEvents/subscription delivery
pipeline, itself OR-persisted and inspectable) and raw
`OCP\EventDispatcher\IEventDispatcher::dispatchTyped()` (fired
imperatively from code, e.g. `SynchronizationDeletionGuardedEvent`). A
flow step's config is declarative (source/subject/type strings an admin
fills in), which only `emitCloudEvent()` supports — raw NC events are
typed PHP event objects with no declarative constructor shape. **Decision:
v1 `event` steps call `EventService::emitCloudEvent()` only.** Firing an
internal NC event from a flow step is out of scope; noted as a v1
limitation, not a follow-up promise (no known use case yet).

### Decision 6: per-step trace — own minimal schema now, converge later

`execution-trace-observability` (sibling change, context-brief only, no
landed spec) proposes riding `FlowToken`'s 8-slot snapshots for a
cross-cutting `execution_trace` schema. Since it has not landed, this
change ships its own minimal `flow_run`/`flow_run_log` schemas now
(status, timing, error per step) rather than blocking on another
in-flight, spec-only change. If `execution-trace-observability` lands
first, a small follow-up change converges `flow_run_log` onto
`execution_trace` (or documents why they stay separate) — flagged
explicitly in tasks.md as a deferred item, not silently dropped.

### Decision 7: step-list editor UI — typed pages, no canvas

`FlowDetailPage.vue` (custom component, following the
`SynchronizationDetailPage` precedent — `type: "custom"` in
`src/manifest.json` with a `_note` justifying the bespoke component,
since the generic `detail` widget-grid page cannot express an ordered,
reorderable step list) renders:

- Flow metadata (name, description, isEnabled) via the standard
  generic-detail-equivalent form fields.
- A step list: each row = type `NcSelect` (`inputLabel: 'Step type'`,
  following `EditEndpoint.vue`'s conformant pattern, NOT
  `EditSynchronization.vue`'s), a config-ref picker (`NcSelect`,
  `inputLabel` set, options filtered to the entity list matching the
  chosen type — Sources for `call`, Mappings for `mapping`, etc.), a
  condition editor field (JSON/JsonLogic textarea — reuse whatever
  editor the Rules page already uses for rule conditions, do not build a
  new one), an onError `NcSelect` (`inputLabel: 'On error'`), and — for
  `branch` rows only — a nested branches sub-list (condition + target
  step-order picker) + a default-target picker.
- Row controls: move up / move down / remove (simple array reorder, not
  drag-and-drop — no `NcListItem` drag handles, no SortableJS
  dependency).
- A "Run" header action (manual trigger) that calls
  `FlowRunnerService::run()` via a new `POST
  /api/flows/{id}/run` endpoint and surfaces the resulting `flow_run`'s
  status/log, following the same pattern as `JobsController::run()`.
- A `FlowRunLog` sub-view (or a tab on the detail page) listing past
  `flow_run`/`flow_run_log` records, mirroring the `Job logs` /
  `SyncDeadLetters` list pattern already in the manifest.

New modal(s) live at `src/modals/Flow/` (e.g. `EditFlow.vue` for the
name/description/isEnabled fields, matching the `src/modals/<Entity>/`
convention) — no inline modal markup in `FlowDetailPage.vue` itself.

**Follow-up (explicitly deferred, not silently dropped):** a
drag-and-drop node-graph canvas for step editing/branch visualization is
the natural v2 evolution once the declarative model has real usage data;
file a GitHub issue at archive time rather than scope-creeping v1.

## Risks / Trade-offs

- [Risk] `FlowToken`'s single-predecessor data channel (Decision 2)
  can't express "step 4 needs step 1's raw output, not step 3's" →
  [Mitigation] documented v1 limitation; a step needing earlier data
  should have its `mapping` step assemble what later steps need, or this
  becomes a v2 `FlowExecutionContext` follow-up once a concrete case
  exists.
- [Risk] `branch` steps reference `nextStepOrder` by integer — a step
  deleted from the editor without updating branch targets silently
  dead-ends a flow (the runner would fail to find that `order` and must
  treat it as a `stop`-policy error) → [Mitigation] the step-list editor
  MUST validate on save that every `nextStepOrder`/`defaultNextStepOrder`
  referenced by a `branch` step resolves to an existing step `order`
  (client-side validation task in tasks.md); the runner MUST also
  defensively treat an unresolvable `nextStepOrder` as a fatal run error
  (not a silent skip) so a bad edit fails loudly, not silently.
- [Risk] Sequential-only execution (no fan-out) means a flow with N
  independent `call` steps runs them one at a time, N× the latency of
  doing them concurrently → [Mitigation] explicitly out of scope per the
  brief; documented as a known v1 performance ceiling, not a bug.
- [Risk] `approval` step's suspend/resume duplicates
  `EndpointService::resumeFromApproval()`'s shape as a third bespoke
  resume path rather than factoring out a shared helper →
  [Mitigation] accepted: the existing two paths (endpoint, synchronization)
  are already not shared, so a third following the identical pattern is
  consistent with house style, not a new inconsistency; refactoring
  all three into one generic resume mechanism is out of scope for this
  change.

## Migration Plan

No Nextcloud schema migration (`lib/Migration/`) is needed — see
`migration.md` (skipped, reason recorded there): this app persists
domain entities as OpenRegister objects defined via `lib/Settings/
register.d/*.json` fragments (confirmed pattern — `hitl-approval-rule-action.json`
is the direct precedent), not Doctrine tables. Deploying this change
means: (1) merge the new `flow`/`flow_run`/`flow_run_log`
register.d fragment(s) + the additive `approval_request` field extension
into the OR register via the existing AppHost repair-step mechanism (no
manual SQL); (2) ship the new `EndpointService::processRules()` match
arm, `FlowAction`, `FlowRunnerService`, controller, and manifest entries
in the same PR — all additive, no existing behavior changed. Rollback =
revert the PR; orphaned `flow`/`flow_run` OR objects are inert (see
proposal.md Rollback Strategy).

## Seed Data

### Schema: `flow`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `onboarding-partner-sync` | `invoice-approval-flow` | `catalog-refresh-branch` |
| name | Onboarding partner sync | Invoice approval flow | Catalog refresh with branch |
| description | Calls the partner API, maps the response, and syncs it into the register | Maps an inbound invoice, requires finance approval, then synchronizes | Branches to a full or incremental sync depending on a condition |
| isEnabled | true | true | true |
| steps | `call`(order 10)→`mapping`(order 20)→`synchronization`(order 30), each `onError: stop` | `mapping`(order 10, `onError: stop`)→`approval`(order 20, `onError: stop`)→`synchronization`(order 30, `onError: dead_letter`) | `mapping`(order 10)→`branch`(order 20, branches to order 30 or 40)→`synchronization`(order 30, full)→`synchronization`(order 40, incremental) |

**Related items per object:** none (flows reference existing seeded
Source/Mapping/Synchronization objects by id — no separate files/notes/
tasks/contacts). Seed `configRef` values MUST resolve to already-seeded
Source/Mapping/Synchronization objects in the same install (wire up
against whichever seed Sources/Mappings/Synchronizations the base
`openconnector` seed already provides — do not invent new placeholder
entities just for flow steps).

## Trade-offs

Considered building `FlowRunnerService` as a new interpreter for
JsonLogic-described DAGs (general graph, arbitrary edges) instead of an
ordered array with a single `branch` escape hatch. Rejected: the brief
is explicit that v1 is "declarative pipeline, NOT drag canvas," and a
general DAG model pulls in exactly the fan-out/loop complexity that is
explicitly out of scope — the ordered-array-plus-branch model is the
minimum structure that supports "3-step linear" and "branch to A or B"
without opening the door to unbounded graph complexity prematurely.
