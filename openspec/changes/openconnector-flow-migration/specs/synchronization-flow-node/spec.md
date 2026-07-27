# synchronization-flow-node Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- openconnector-flow-migration (Phase 1 — this change)

## Purpose
OpenConnector contributes a coarse flow node — the leaf `openconnector.synchronization` — to OpenRegister's flow engine (ADR-065), so that a synchronisation can be triggered and chained from a declarative flow graph rather than from OpenConnector's bespoke `JobTask`, object-lifecycle listeners, and `followUps` code. The node is a thin adapter over the existing `SynchronizationService::synchronize()`; it changes no synchronisation internals (ADR-002 amended for chaining only, ADR-005 preserved). This is Phase 1 of a three-phase migration; the node is additive and the legacy paths remain until parity is proven.

Every `#### Scenario:` in this capability MUST carry either an `@e2e` reference to a browser test, or a reason-bearing `@e2e exclude <reason>` line, so gate-19 can trace coverage. Two user-observable scenarios — the node appearing in the flow editor palette, and a schedule-triggered flow actually running a synchronisation — carry real Playwright references (`tests/e2e/api-direct/synchronization-node.api.spec.ts`). The remaining scenarios are pure backend registration/validation invariants and are `@e2e exclude`d with concrete reasons (covered by PHPUnit).

## ADDED Requirements

### Requirement: The app SHALL register a `openconnector.synchronization` flow node via `RegisterFlowNodesEvent` (REQ-SFN-001)

OpenConnector MUST register a flow node whose id is `openconnector.synchronization` by listening for OpenRegister's `RegisterFlowNodesEvent` and calling `registerNode()` on it, wired in `lib/AppInfo/Application.php`. The listener MUST resolve every OpenRegister flow type lazily and guard resolution with `class_exists()` so that OpenConnector boots normally when OpenRegister is absent or too old, in which case the listener MUST no-op rather than throw.

#### Scenario: Node is registered when OpenRegister dispatches the event
- GIVEN OpenRegister is installed and its flow engine dispatches `RegisterFlowNodesEvent`
- WHEN OpenConnector's `RegisterFlowNodesListener` handles the event
- THEN it MUST call `registerNode()` with a node whose `getId()` returns `openconnector.synchronization`
- AND the OpenRegister `FlowNodeRegistry` MUST accept it as a new, non-duplicate id
- @e2e exclude backend flow-node registration via event listener — covered by PHPUnit, not browser UI

#### Scenario: OpenConnector boots when OpenRegister is absent
- GIVEN OpenRegister (or its `RegisterFlowNodesEvent` class) is not available
- WHEN OpenConnector boots and registers its listeners
- THEN registration MUST be guarded by `class_exists()` and MUST NOT throw
- AND the node MUST simply not be contributed
- @e2e exclude backend boot-guard behaviour — covered by PHPUnit, not browser UI

### Requirement: The node SHALL expose OR `IFlowNode` metadata and an admin scope (REQ-SFN-002)

The node MUST implement `OCA\OpenRegister\Service\Flow\IFlowNode` and return a stable id (`openconnector.synchronization`), a human-readable display name, a one-sentence description, and a palette icon URL. Because running a synchronisation is an administrative action, `isAvailableForScope()` MUST offer the node in `IManager::SCOPE_ADMIN`.

#### Scenario: Metadata methods return stable, non-empty values
- GIVEN an instance of the synchronization node
- WHEN its `getId()`, `getDisplayName()`, `getDescription()`, and `getIcon()` are called
- THEN `getId()` MUST return exactly `openconnector.synchronization`
- AND the display name, description, and icon URL MUST each be non-empty
- @e2e exclude backend node-metadata contract — covered by PHPUnit, not browser UI

#### Scenario: Node appears in the flow editor palette (admin scope)
- GIVEN an admin opens the OpenRegister flow editor palette (`IManager::SCOPE_ADMIN`)
- WHEN the palette is built
- THEN the `openconnector.synchronization` node MUST be offered (its display name visible)
- AND `isAvailableForScope(IManager::SCOPE_ADMIN)` MUST return true
- @e2e tests/e2e/api-direct/synchronization-node.api.spec.ts

### Requirement: The node SHALL reject an unusable configuration at save time (REQ-SFN-003)

The node's `validateConfig()` MUST throw `\UnexpectedValueException` when the authored configuration lacks a non-empty `synchronizationId`, so that a broken step is caught in the flow editor when the flow is saved rather than at run time. A `force` value, when present, MUST be treated as a boolean and MUST default to false when omitted.

#### Scenario: Missing synchronizationId is refused
- GIVEN a flow-node configuration with no `synchronizationId` (or an empty one)
- WHEN the flow is saved and `validateConfig()` runs
- THEN it MUST throw `\UnexpectedValueException`
- AND the flow MUST NOT be persisted with the invalid step
- @e2e exclude backend config validation at save — covered by PHPUnit, not browser UI

#### Scenario: Valid configuration passes validation
- GIVEN a configuration `{ "synchronizationId": "00000000-0000-0000-0000-000000000000", "force": false }`
- WHEN `validateConfig()` runs
- THEN it MUST NOT throw
- @e2e exclude backend config validation happy path — covered by PHPUnit, not browser UI

### Requirement: The node SHALL adapt `execute()` to `SynchronizationService::synchronize()` and return the produced items (REQ-SFN-004)

The node's `execute(array $items, array $config, array $context)` MUST lazily resolve `OCA\OpenConnector\Service\SynchronizationService` via `\OCP\Server::get()`, resolve the synchronisation identified by `config['synchronizationId']`, invoke `synchronize()` passing `config['force']` through to the service's `$force` argument, and return the produced records as the flow item list (`{json, binary, pairedItem}`). The node MUST NOT catch and swallow exceptions from `synchronize()`: a thrown error MUST propagate so the engine can apply the step's `onError` policy.

#### Scenario: Executing the node runs the referenced synchronisation
- GIVEN a registered synchronisation object with id `00000000-0000-0000-0000-000000000000`
- AND a flow whose step is `openconnector.synchronization` configured with that id
- WHEN the engine executes the node
- THEN `SynchronizationService::synchronize()` MUST be invoked for that synchronisation
- AND the node MUST return the produced records as flow items
- AND a `FlowRun` MUST be recorded for the run (verifiable via the flow run history API)
- @e2e tests/e2e/api-direct/synchronization-node.api.spec.ts

#### Scenario: A synchronisation failure propagates to the engine
- GIVEN the referenced synchronisation raises an exception during `synchronize()`
- WHEN the node executes
- THEN the exception MUST propagate out of `execute()` uncaught
- AND the engine MUST decide stop/continue/dead-letter from the step's `onError` policy
- @e2e exclude backend error-propagation contract — covered by PHPUnit, not browser UI

### Requirement: Phase 1 SHALL be additive and leave synchronisation internals and legacy paths untouched (REQ-SFN-005)

This change MUST NOT modify `SynchronizationService::synchronize()`, `processRules()`, `followUps`, the `SynchronizationContract` triad (ADR-005), or the `FlowToken` helper. It MUST NOT delete or disable the existing `JobTask` / `SynchronizationAction` scheduling path or the object-lifecycle listeners. The new flow node MUST exist alongside those paths so parity can be proven before any retirement (retirement is deferred to Phase 2/3).

#### Scenario: Legacy scheduling and lifecycle paths still function
- GIVEN a synchronisation previously driven by a Job interval and by object-lifecycle listeners
- WHEN this change is applied
- THEN the `JobTask` path and the object-lifecycle listeners MUST continue to trigger it unchanged
- AND no change MUST be made to `synchronize()` internals or the contract triad
- @e2e exclude backend additive-coexistence invariant — covered by PHPUnit + regression, not browser UI

### Requirement: The migration SHALL document how legacy triggers and chaining map onto flows under a hybrid execution model (REQ-SFN-006)

The design MUST document how each legacy orchestration mechanism becomes flow configuration in OpenRegister's shared `flows`/`flow` register: an interval Job becomes a `schedule`/cron-trigger flow, an object-lifecycle synchronisation becomes an `object.*`-trigger flow authored with `executionMode: sync` for save-time parity, and each `followUp` becomes an `openregister.sub-flow` edge. The hybrid model — async queue default, per-flow `executionMode: sync` opt-in — and its dependency on the OpenRegister change `openregister-flow-executionmode-and-token` MUST be stated.

#### Scenario: Trigger-to-flow mapping is specified
- GIVEN the design document for this change
- WHEN a reviewer looks for the migration mapping
- THEN it MUST map Job→schedule flow, object-lifecycle→`object.*` flow (`executionMode: sync`), and `followUp`→`openregister.sub-flow` edge
- AND it MUST declare the dependency on `openregister-flow-executionmode-and-token`
- @e2e exclude documentation requirement — verified by review of design.md, not a runnable flow

## Non-Functional Requirements

- **Performance:** the leaf MUST add no measurable overhead beyond one lazy container lookup and one object resolution before delegating to the unchanged `synchronize()`; it MUST NOT duplicate any synchronisation work.
- **Accessibility:** no new UI in Phase 1; the OpenRegister flow editor renders the node from its `IFlowNode` metadata, so accessibility is inherited from the OR editor (no regression introduced).
- **Internationalization:** the node's display name and description MUST be translatable; Dutch (`nl_NL`) and English (`en_US`) MUST be supported (hydra ADR-007).

## Acceptance Criteria

- [ ] A node with id `openconnector.synchronization` is registered via `RegisterFlowNodesEvent` and accepted by the OR `FlowNodeRegistry`.
- [ ] `validateConfig()` throws on a missing `synchronizationId` and passes on a valid config.
- [ ] `execute()` resolves the service lazily, calls `synchronize()` with `force`, and returns the produced items; exceptions propagate uncaught.
- [ ] OpenConnector boots without OpenRegister present (listener no-ops via `class_exists`).
- [ ] Legacy `JobTask` / listener paths remain functional and unchanged.
- [ ] design.md documents the hybrid trigger/chaining mapping and the OR engine dependency.

## Notes

- Node contract: `OCA\OpenRegister\Service\Flow\IFlowNode` (`apps-extra/openregister/lib/Service/Flow/IFlowNode.php`). Reference implementation: `SetFieldsNode` (id `openregister.set-fields`).
- Registration event: `OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent`.
- Adapter target: `OCA\OpenConnector\Service\SynchronizationService::synchronize()`.
- Dependency: OpenRegister change `openregister-flow-executionmode-and-token` (per-flow `executionMode`, run-level flow-token).
- ADRs: ADR-065 (flow-parity, sanctions this), ADR-002 (amended for the chaining concern), ADR-005 (contract triad preserved), ADR-031 (declarative chaining / imperative leaf exception).
