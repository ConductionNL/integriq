# Design: openconnector-flow-migration

## Context
OpenConnector's orchestration is bespoke and spread across five concerns:

1. **Scheduling** — Job objects (OR register `openconnector`, schema `job`: `jobClass` + `arguments` + `interval` + `nextRun`) executed by `JobTask` (5-minute background job) → `JobService` → `SynchronizationAction::run()`.
2. **Object-lifecycle triggering** — `lib/EventListener/ObjectCreatedEventListener.php`, `ObjectUpdatedEventListener.php`, `ObjectDeletedEventListener.php` (wired in `lib/AppInfo/Application.php`) → `SynchronizationService::handleObjectEventSynchronization()` (`lib/Service/SynchronizationService.php:1080`), which runs **synchronously inside the save**.
3. **Chaining** — `followUps` (`lib/Service/SynchronizationService.php:1896`) walk synchronisation → synchronisation.
4. **Rules** — `processRules()` (`lib/Service/SynchronizationService.php:5861`): ids in `synchronization['actions']`, sorted by `order`, filtered by `timing` + JsonLogic `conditions`, dispatched by `type`.
5. **Pub-sub** — `EventService` (process → subscription match → `event_message` → deliver) + `EventRetryJob` (dead-letter redelivery, exponential backoff).

OpenRegister's flow engine (`apps-extra/openregister/lib/Service/Flow/`) already provides all five as first-class, observable, resumable primitives. ADR-065 (OR flow-parity programme) designates it as the fleet's one engine and every app flow as a **consumer**. This change is **Phase 1** of migrating OpenConnector onto it.

### The three-phase arc
- **Phase 1 (this change) — orchestration-first.** One coarse leaf `openconnector.synchronization` wrapping the whole `synchronize()` call; the outer chaining (jobs, object triggers, follow-ups) becomes flow configuration. Sync internals untouched. Additive: legacy paths stay.
- **Phase 2 (`openconnector-flow-phase2-pipeline`).** Decompose the 6-stage extern→intern pipeline into leaves: `openconnector.source-fetch` (HTTP + pagination), `openconnector.write-target` (owns `SynchronizationContract` hashing/idempotency per ADR-005), `openconnector.save-object`, `openconnector.fetch-file`, `openconnector.write-file`, `openconnector.extra-data`. The rules pipeline maps onto edges: `order` → sequence, `timing` (before/after) → graph position, JsonLogic `conditions` → edge `condition` (same `jwadhams/json-logic-php` lib). Convert the `processSyncRule` stub (`lib/Service/SynchronizationService.php:6939`) into a real sub-flow invoke. Drop the SoftwareCatalogus-specific rules (`processSoftwareCatalogusRule` + its `RuleService` branch). **Close the pre-existing XXE hole** in `FlowToken::parseContent`/`looksLikeXml` (`lib/Service/Helper/FlowToken.php:187,211` — no `LIBXML_NONET`) — Phase 2 must not carry it forward.
- **Phase 3 (`openconnector-flow-phase3-pubsub`).** Migrate `EventService`/`EventRetryJob` pub-sub + dead-letter onto flows: `object.*` trigger → `openconnector.deliver` leaf with `onError: dead_letter`; `FlowRun` retry/resume replaces the bespoke backoff. Convert the `EventAction::run()` stub into a real event-emit leaf.

### Mixed-spec rationale (`kind: code`)
This change is classified `kind: code`. It is PHP: a leaf class, a listener, and an edit to `Application.php`. The only config-shaped surface it relies on — the `executionMode` prop on the `flow` schema — is **not authored here**; it ships in the `depends_on` OpenRegister change. Phase 1 therefore introduces no schema JSON of its own, so no config/code split is warranted. (If a reviewer judges the config-glue non-trivial, see Open Questions — flagged as a deferred split question.)

## Architecture Overview
```
  Trigger (schedule cron | object.* | manual)
        │
        ▼
  OpenRegister FlowTriggerService.fire()
        │   executionMode: async (default) → queue → FlowRunWorker
        │   executionMode: sync            → inline FlowRunService.execute()
        ▼
  FlowRun (marking + items + context + flow-token; suspend/resume/queue)
        │  walks edges: type=node id, config, condition (JsonLogic), onError
        ▼
  Node: openconnector.synchronization   ← THIS CHANGE (leaf)
        │  execute(items, config{synchronizationId, force?}, context)
        │  \OCP\Server::get(SynchronizationService::class)  (lazy, class_exists-guarded)
        ▼
  OCA\OpenConnector\Service\SynchronizationService::synchronize()   ← UNCHANGED
        │  returns produced items
        ▼
  Flow item list  →  next edge  →  openregister.sub-flow edge = a followUp
```

The **data channel** is the item list `{json, binary, pairedItem}` (OR's `FlowItems`). `context` is run-level metadata (NOT the data channel) — this is where the run-level flow-token lives.

## API Design
Not applicable. This change introduces no REST endpoints. Its "interface" is OR's existing `IFlowNode` contract (`apps-extra/openregister/lib/Service/Flow/IFlowNode.php`): `getId / getDisplayName / getDescription / getIcon / isAvailableForScope / validateConfig / execute(array $items, array $config, array $context): array`. The leaf's authored config is the only new surface:

```json
{ "synchronizationId": "00000000-0000-0000-0000-000000000000", "force": false }
```
`synchronizationId` (required, string UUID) identifies the OR `synchronization` object; `force` (optional, bool, default false) maps to `synchronize()`'s `$force` argument.

## Database Changes
None. This change adds no tables, columns, OR registers or OR schemas. Flows are authored as objects in OpenRegister's **existing** `flows`/`flow` register (register 2487 / `flow` schema on 8080), resolved by `OpenRegisterFlowResolver`. The `executionMode` prop extends the existing `flow` schema and is added by the `depends_on` change, not here.

## Nextcloud Integration
- **Controllers:** none.
- **Services:** new `OCA\OpenConnector\Service\Flow\Nodes\SynchronizationNode` implementing `OCA\OpenRegister\Service\Flow\IFlowNode`; resolves `OCA\OpenConnector\Service\SynchronizationService` lazily via `\OCP\Server::get()`.
- **Mappers/Entities:** none — all OpenConnector config entities are OR objects (chain-C cutover; no `lib/Db` for them).
- **Events/Hooks:** new `IEventListener<OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent>` (`RegisterFlowNodesListener`) that calls `$event->registerNode(...)`; registered in `lib/AppInfo/Application.php::register()`.

## Declarative-vs-imperative decision (ADR-031)
ADR-031 favours declarative configuration over imperative app code for orchestration and behaviour. Flow **graphs**, **triggers**, **edge conditions** and **chaining** are exactly that: they move here from OpenConnector code into declarative OR flow objects — the ADR-031-aligned direction.

The **flow node itself** is the sanctioned exception. A node is call-shaped imperative code by nature: OR's `IFlowNode::execute(array $items, array $config, array $context): array` is a callable step, and OR's own reference node `SetFieldsNode` (id `openregister.set-fields`) is likewise a PHP class. There is no HTTP node and no synchronisation node in the built-in palette (`set-fields, filter, switch, route, merge, loop, wait, stop, sub-flow`) precisely because those are the leaves each consuming app contributes as code. `openconnector.synchronization` is one such leaf. So: **the chaining becomes declarative (ADR-031-aligned); the leaf stays imperative (the sanctioned node exception).**

## ADR reconciliation

### ADR-002 (OpenConnector, Accepted): "Mapping and Rule engine stays app-local" — AMENDED
ADR-002's core concern is that connector-specific transform/rule **logic** stays in OpenConnector, not scattered across the fleet. This change is sanctioned by ADR-065, which makes OpenConnector's flow a **consumer** of OR's engine. Reconciliation: connector-specific transform/rule logic **stays in OpenConnector code**, now encapsulated **inside** the flow leaves (Phase 2's `source-fetch` / `write-target` / `save-object` / `fetch-file` / `write-file`) — honouring ADR-002's core concern — while only the **chaining/ordering** moves to the flow graph.

This change therefore **amends ADR-002**: it supersedes ADR-002's "orchestration/chaining stays app-local" clause for the chaining concern, and keeps "the execution engine + connector transforms stay app-local, now expressed as leaves". A task in tasks.md writes this amendment (a note appended to ADR-002 or a superseding ADR).

### ADR-005 (contract triad Source → Synchronization → SynchronizationContract) — PRESERVED
The Source→Synchronization→SynchronizationContract triad is untouched by Phase 1. Contract hashing/idempotency is owned by Phase 2's `openconnector.write-target` leaf; Phase 1's coarse leaf calls `synchronize()`, which already runs the existing contract logic internally. No change to the triad here.

## Dependency: `openregister-flow-executionmode-and-token`
Phase 1 relies on two additions shipped by the separate OpenRegister change:

1. **Per-flow `executionMode: sync | async`.** `async` (default) keeps the queue → `FlowRunWorker` path. `sync` runs the flow inline within `FlowTriggerService::fire()` via `FlowRunService::execute()`. It adds the `executionMode` prop to the `flow` schema. **Why Phase 1 needs it:** OpenConnector's object-lifecycle syncs run synchronously inside the save today (`handleObjectEventSynchronization`). The hybrid-trigger decision (below) preserves that behaviour by authoring those flows with `executionMode: sync`; without it, an `object.*`-triggered flow would only ever run async, changing observable timing.
2. **Run-level flow-token.** A first-class token on the `FlowRun` that (a) propagates **into** followUps/sub-flows, (b) lets a sub-flow **return values** to its parent (extending wait-mode `SubFlowNode`, which today only returns items), and (c) **survives pause/continue** (persisted on the `FlowRun` alongside marking, so suspend→resume keeps the token). **Why Phase 1 needs it:** a `followUp` modelled as an `openregister.sub-flow` edge must carry synchronisation context across the boundary the way the in-process `FlowToken` does today; the run-level token is the engine-side equivalent that survives the queue and pause/resume.

If this dependency is not yet live on the target instance, the leaf still registers and runs under the async engine — only the `sync`-parity flows and cross-sub-flow token return are unavailable.

## Trigger-timing decision: HYBRID (locked)
Async queue → `FlowRunWorker` is the **default**. A per-flow **`executionMode: sync` opt-in** preserves sync-on-save parity for object-lifecycle-triggered synchronisations. This is a locked decision, not open for re-litigation. Mapping of legacy triggers to flows:

| Legacy mechanism | Flow equivalent |
|---|---|
| Job (`interval`/`nextRun`) via `JobTask` | `schedule`-trigger flow with `cron`, node `openconnector.synchronization` (`FlowScheduleService` + `FlowScheduleWorker`, 5-min) |
| Object-lifecycle listener (sync-in-save) | `object.*`-trigger flow with `executionMode: sync`, node `openconnector.synchronization` |
| `followUps` chain | `openregister.sub-flow` edge (wait-mode = run + return items/token; recursion guard `context.flowStack` depth 16) |

## Security Considerations
- **Auth/scope:** the leaf implements `isAvailableForScope()`; a synchronisation run is an administrative action, so the node is offered in `IManager::SCOPE_ADMIN` (matching how sync configuration is administered). No new endpoint, no new CSRF surface.
- **Input validation:** `validateConfig()` rejects a missing/empty `synchronizationId` at flow-save time (mirrors `SetFieldsNode`), so a broken step fails in the editor, not at 3am in a scheduled run.
- **No secrets in config:** the leaf config carries only an id and a boolean; source credentials remain on the `source` object resolved inside `synchronize()`. Placeholder id used in examples is the nil UUID `00000000-0000-0000-0000-000000000000`.
- **Pre-existing XXE (noted, not fixed here):** `FlowToken::parseContent`/`looksLikeXml` parse request XML without `LIBXML_NONET`. Phase 1 does not touch `FlowToken`; Phase 2 MUST close this and MUST NOT carry it forward. Recorded here so it is not lost.

## Seed Data
No new registers or schemas — this change extends the existing `flows`/`flow` register only (and the `executionMode` prop is added by the dependency change). No seed task is needed. Example/parity flows are documented as configuration, not shipped as seed objects in Phase 1.

## File Structure
```
lib/
  Service/
    Flow/
      Nodes/
        SynchronizationNode.php      (new — implements OR IFlowNode; copy of SetFieldsNode shape)
  EventListener/
    RegisterFlowNodesListener.php    (new — IEventListener<RegisterFlowNodesEvent>)
  AppInfo/
    Application.php                  (edited — register the listener)
```

## Trade-offs
- **Coarse leaf vs. decomposed pipeline first.** A single coarse `synchronization` node proves the seam with the smallest blast radius and zero change to sync internals; decomposition is deferred to Phase 2 where the value (per-stage graphs, edge conditions) lives. Alternative rejected: decomposing immediately would couple the risky internals refactor to the orchestration cutover.
- **Lazy `\OCP\Server::get()` vs. constructor DI.** Constructor injection of OR classes would hard-couple OpenConnector's boot to OpenRegister's presence and version. Lazy, `class_exists`-guarded resolution lets OpenConnector boot cleanly when OR is absent/older; the listener simply no-ops. This mirrors the fleet rule against phantom cross-app DI.
- **Additive vs. cutover.** Keeping the legacy JobTask/listener paths alongside the flow path means transient duplication (a sync could be triggered by both a Job and a flow if an operator configures both). Accepted for Phase 1 because it lets parity be proven before deletion; retirement is Phase 2/3.

## Open Questions
- Whether the leaf's cross-project config contract warrants a formal `contract.md`. Provisional decision: no — it implements OR's existing `IFlowNode` contract rather than exposing a new API for other apps to call, and only OpenConnector is modified. Flagged as a deferred question.
- Whether the ADR-002 amendment should be a note on the existing ADR or a new superseding ADR. Provisional decision: an amendment note on ADR-002 referencing ADR-065, to avoid ADR sprawl. Flagged as a deferred question.
