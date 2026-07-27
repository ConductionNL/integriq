---
kind: code
depends_on:
  - openregister-flow-executionmode-and-token
follow_ups:
  - openconnector-flow-phase2-pipeline
  - openconnector-flow-phase3-pubsub
---

# Proposal: openconnector-flow-migration

## Summary
OpenConnector today owns its own orchestration: Job objects on a 5-minute `JobTask`, object-lifecycle listeners that synchronise inside the save, and a `followUps` chain that walks synchronisation to synchronisation — all bespoke code. The OR flow-parity programme (ADR-065) designates OpenRegister's `symfony/workflow`-based flow engine as the fleet's single orchestration engine and every app's "flow" as a consumer of it. This change is **Phase 1** of a three-phase migration that moves OpenConnector's synchronisation onto that engine. Phase 1 is orchestration-first: it adds one coarse flow node — the leaf `openconnector.synchronization`, a thin adapter over the existing `SynchronizationService::synchronize()` — plus the listener that registers it, and it documents how the outer chaining (interval jobs, object-lifecycle triggers, follow-ups) becomes flow configuration. Sync internals stay 100% untouched, and the existing JobTask/listener paths remain in place alongside the new flow path so nothing is retired before parity is proven.

## Motivation
The fleet is converging on one flow engine (ADR-065): OpenRegister's. OpenConnector currently reinvents scheduling, event-triggering, chaining, retry and dead-lettering — five orchestration concerns duplicated in app code that the OR engine already provides as first-class, observable, resumable `FlowRun`s. Duplicated orchestration means duplicated bugs, no shared run history, and no shared editor. Migrating orchestration to the engine lets connector authors branch, join, loop, condition and chain synchronisations in a graph they can see, while OpenConnector keeps owning the connector-specific work. Starting orchestration-first (one coarse leaf) proves the seam end-to-end with minimal blast radius before the pipeline is decomposed (Phase 2) and pub-sub is migrated (Phase 3).

## Affected Projects
- [x] Project: `openconnector` — adds the `openconnector.synchronization` flow leaf (adapter over `SynchronizationService::synchronize()`), one `RegisterFlowNodesEvent` listener, and its wiring in `lib/AppInfo/Application.php`; documents the trigger/chaining migration. No sync internals change; no existing path is deleted.
- [ ] Project: `openregister` — NOT modified by this change. The required engine additions (`executionMode`, run-level flow-token) ship as the separate change `openregister-flow-executionmode-and-token` (see Cross-Project Dependencies).

## Scope

### In Scope
- A coarse flow node leaf `openconnector.synchronization` implementing OR's `IFlowNode`, config `{synchronizationId, force?}`: resolve the synchronisation object, call the existing `SynchronizationService::synchronize()`, return the resulting flow item list.
- Lazy resolution of OR flow types via `\OCP\Server::get()` guarded by `class_exists()` (no constructor injection of cross-app classes).
- One `IEventListener<RegisterFlowNodesEvent>` that registers the leaf, wired in `lib/AppInfo/Application.php`.
- Documentation (in design.md) of how interval Jobs become `schedule`-trigger (cron) flows, how object-lifecycle syncs become `object.*`-trigger flows, and how `followUps` become `openregister.sub-flow` edges.
- An amendment note to ADR-002 (see design.md) covering the orchestration/chaining move.

### Out of Scope (deferred to follow-on changes)
- **Phase 2 (`openconnector-flow-phase2-pipeline`)**: decomposing the 6-stage sync into per-stage leaves (`source-fetch`, `write-target`, `save-object`, `fetch-file`, `write-file`, `extra-data`); mapping `actions`/`order`/`timing`/`conditions` rules onto flow edges; converting the `processSyncRule` stub to a real sub-flow invoke; dropping the SoftwareCatalogus-specific rule branch; and closing the `FlowToken` XXE hole.
- **Phase 3 (`openconnector-flow-phase3-pubsub`)**: migrating `EventService`/`EventRetryJob` pub-sub + dead-letter onto flows; converting the `EventAction::run()` stub to a real event-emit leaf.
- **Retirement** of the existing `JobTask`, `SynchronizationAction`, object-lifecycle listeners and `followUps` code — deferred to Phase 2/3, only after flow-path parity is proven.
- Any OpenRegister engine code — owned by the `depends_on` change.

## Approach
Copy OR's reference node `SetFieldsNode` as the structural template for a new `openconnector.synchronization` node. The node's `execute(array $items, array $config, array $context)` resolves `\OCP\Server::get(SynchronizationService::class)` lazily (guarded by `class_exists`), reads `synchronizationId`/`force` from `$config`, calls `synchronize()`, and returns the produced items as the flow data channel. Register it by listening for OR's `RegisterFlowNodesEvent` and calling `registerNode()`. The outer chaining moves to flow configuration in the shared OR `flows`/`flow` register: an interval Job becomes a cron/`schedule`-trigger flow, object-lifecycle syncs become `object.*`-trigger flows, and each `followUp` becomes an `openregister.sub-flow` edge. Trigger timing is **hybrid** (see design.md): the engine's async queue is default, with a per-flow `executionMode: sync` opt-in — supplied by the dependency change — to preserve today's synchronous-in-the-save behaviour where a connector needs it.

## New Dependencies
None. `jwadhams/json-logic-php` (used for edge conditions in later phases) is already an OpenConnector dependency. Phase 1 adds no new packages; it consumes OR's already-shipped `IFlowNode`/`RegisterFlowNodesEvent` contract plus the two engine additions from the `depends_on` change.

## Impact
- **Code**: adds `lib/Service/Flow/Nodes/SynchronizationNode.php` (or equivalent), `lib/EventListener/RegisterFlowNodesListener.php`, and edits `lib/AppInfo/Application.php`. Touches no existing sync logic.
- **Data/config**: no new OR registers or schemas from this change. Flows are authored as objects in OR's existing `flows`/`flow` register. The `executionMode` flow-schema prop is added by the dependency change, not here.
- **Runtime**: introduces a second, additive path to trigger a synchronisation (via a FlowRun) alongside the existing JobTask/listener path. Both coexist during Phase 1.

## Cross-Project Dependencies
Depends on OpenRegister change **`openregister-flow-executionmode-and-token`**, which adds to the OR engine: (a) a per-flow `executionMode: sync|async` (async keeps queue→FlowRunWorker; `sync` runs inline in `FlowTriggerService::fire()` via `FlowRunService::execute()`; adds the `executionMode` prop to the `flow` schema); and (b) a first-class run-level flow-token that propagates into followUps/sub-flows, lets a sub-flow return values to its parent (extending wait-mode `SubFlowNode`, which today only returns items), and survives pause/continue (persisted on the `FlowRun` alongside marking). Phase 1's leaf and the hybrid-trigger decision both rely on these. This change must not be applied before that engine change is merged and available on the target instance.

## Risks

### Risk 1: Dependency change not yet merged
**Severity:** High — **Mitigation:** Phase 1's leaf runs against the async engine that already exists; the `sync` opt-in and flow-token only matter for parity flows. Gate `apply` on the `depends_on` change being live on the target instance (register 2487 / `flow` schema on 8080). If it is not, the leaf still registers and runs async — the sync-on-save parity flows are simply not authorable yet.

### Risk 2: Cross-app class resolution at registration/run time
**Severity:** Medium — **Mitigation:** resolve `SynchronizationService` and all OR flow types lazily via `\OCP\Server::get()` guarded by `class_exists()`, never via constructor injection. If OpenRegister is absent or too old, the listener no-ops and OpenConnector boots normally.

### Risk 3: Behavioural drift between the flow path and the legacy path
**Severity:** Medium — **Mitigation:** Phase 1 is purely additive and changes no sync internals; the legacy JobTask/listener paths stay authoritative until parity is proven. The leaf is a thin pass-through to the same `synchronize()` call the legacy paths use, so both share one engine.

### Risk 4: Duplicate node-id registration
**Severity:** Low — **Mitigation:** the id `openconnector.synchronization` is app-namespaced; OR's `FlowNodeRegistry` refuses duplicate ids at registration rather than resolving by load order, so a collision fails loud.

## Rollback Strategy
The change is additive and isolated to three files. To revert: remove the `RegisterFlowNodesListener` registration from `lib/AppInfo/Application.php` (the leaf then never registers and any authored flow node becomes an unknown type), and delete the node + listener classes. No data migration, no schema change, and no existing code path is touched, so rollback cannot affect running JobTask/listener synchronisations.

## Open Questions
- Whether the cross-project leaf config contract (`{synchronizationId, force?}`) warrants a formal `contract.md`. Provisionally skipped — the leaf implements OR's existing `IFlowNode` contract rather than defining a new API for other apps to call. Flagged as a deferred question.
