# Tasks: retire-integriq-flow-schema

Before starting: `integriq-flow-nodes` must be landed, not merely proposed.
This change consumes `openconnector.source-call` and
`openconnector.synchronization-run`; without them the migrated flows have no
node for their two most common steps and every migrated flow is dead on
arrival.

## Pre-implementation Gate

- [ ] PO sign-off on the editor change: users lose Integriq's step-list editor and gain OpenRegister's visual flow builder. This is a UX decision, and taking it after the migration is taking it too late.
- [x] Confirm whether OpenRegister has an event-EMIT node. The `event` step type has no confirmed counterpart; if none exists this change grows by one contributed node.
  > Confirmed 2026-09-02 against openregister origin/development `lib/Service/Flow/Nodes/`: no CloudEvent emitter exists (the send-* nodes address people — email, notification, Talk — not systems). The change therefore grew by `openconnector.event-emit`, contributed below.
- [x] Count the live `flow` objects per instance. A migration whose blast radius is unmeasured is a migration whose rollback is unplanned.
  > Measured 2026-09-02 on the dev instance (postgres, `oc_openregister_objects` + `oc_openregister_table_1_1`): ONE schema row carries slug `flow` (id 1) and it holds ZERO live objects. The migration is a no-op there; other instances measure themselves with the dry run `occ integriq:flow:steps-to-graph`, which reports one row per flow before anything is written.

## Implementation Tasks

### 1. The missing node

- [x] Contribute `openconnector.approval-request` via `RegisterFlowNodesEvent`, implementing `IFlowNode`
- [x] Pause/resume rides on `AwaitSignalNode`; the node emits the signal name the approval resolves to
- [x] Unit tests: an approved request resumes the flow, a rejected one takes the reject edge, an expired one fails closed
- [x] Contribute the event-emit node if the gate above found none

### 2. The step-to-graph migration

- [x] A pure, tested translator: `steps[]` to `nodes`/`edges`. Step `order` becomes the node id, so `branch` targets stay valid without renumbering
- [x] Property tests: a flow with duplicate `order` values is rejected exactly as `FlowRunnerService::run()` rejects it today, rather than silently producing a graph with a lost node
- [x] A repair step that rewrites live `flow` objects in place, idempotent, refusing rather than overwriting when an object already carries `nodes`
  > Delivered 2026-09-02 as `FlowStepsToGraphTranslator` (pure) + `FlowGraphMigrationService` (live objects) + repair step `MigrateFlowStepsToGraph` + occ command `integriq:flow:steps-to-graph` (dry run by default, `--apply` writes, `--rollback --apply` removes the graph again; `steps` is kept through both directions as the rollback shape). The engine-side wiring also landed: `ApprovalService::suspendForEngineRun()`, `EngineSignalService` (guarded `FlowRunSignalService::signalAs` delivery) and the `engineRunUuid`/`signalNodeId`/`question` approval_request schema extension. Tasks 3-5 below stay open on purpose: retiring the runner and the schema only starts once migrated flows have run in anger on the engine.

### 3. Retire the runner

- [ ] `FlowsController` reads through the openregister register rather than `integriq`
- [ ] `FlowRunnerService` deleted; its callers moved to OpenRegister's `FlowRunService`
- [ ] `RuleToFlowGenerator` emits `nodes`/`edges`; its `TYPE_FLOW` constant follows
- [ ] `FlowRunnerService::SCHEMA_FLOW`, `SCHEMA_FLOW_RUN` and `SCHEMA_FLOW_RUN_LOG` removed with it

### 4. Retire the schemas

- [ ] Remove `flow` from `register.d/visual-flow-orchestration.json` and from the register's schema list
- [ ] Keep `flow_run` and `flow_run_log`, retired but present, so run history stays readable. Record that decision in the fragment's `$comment`
- [ ] Bump `info.version`, or the descriptor change never reaches an existing install
- [ ] `occ openregister:schemas:prune-retired --app integriq --slug flow --apply` on each instance, because the import unions schema ids and never removes one
- [ ] Regenerate `contracts/fleet-schema-slugs.json` in hydra-gates so gate-106's baseline stops recording `flow` as shared

### 5. Prove it

- [ ] E2E: a flow that calls a Source, maps the response and writes an object runs green end to end on OpenRegister's engine
- [ ] E2E: an approval step pauses the run and resumes on approve
- [ ] The migration repair step runs twice with the same result
- [ ] `occ openregister:schemas:prune-retired --app integriq --slug flow` reports not-found afterwards, which is the proof the row is gone rather than the descriptor merely edited
