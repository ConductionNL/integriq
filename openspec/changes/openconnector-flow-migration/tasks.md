# Tasks: openconnector-flow-migration

> Scope: **Phase 1 only** (orchestration-first). The leaf is additive; no legacy
> path is deleted here. Depends on OpenRegister change
> `openregister-flow-executionmode-and-token` being live on the target instance
> for the `executionMode: sync` parity flow (Task 3) — the async path works
> without it.

## Implementation Tasks

### Task 1: Create the `openconnector.synchronization` flow-node leaf
- **spec_ref**: `openspec/specs/synchronization-flow-node/spec.md#requirement-the-node-shall-adapt-execute-to-synchronizationservicesynchronize-and-return-the-produced-items-req-sfn-004`
- **files**: `lib/Service/Flow/Nodes/SynchronizationNode.php`
- **acceptance_criteria**:
  - GIVEN the node class WHEN inspected THEN it implements `OCA\OpenRegister\Service\Flow\IFlowNode` and `getId()` returns exactly `openconnector.synchronization` (structure copied from OR `SetFieldsNode`).
  - GIVEN metadata methods WHEN called THEN display name/description/icon are non-empty and translatable, and `isAvailableForScope(IManager::SCOPE_ADMIN)` returns true.
  - GIVEN `validateConfig()` WHEN `synchronizationId` is missing/empty THEN it throws `\UnexpectedValueException`; a valid `{synchronizationId, force?}` passes.
  - GIVEN `execute()` WHEN run THEN it resolves `SynchronizationService` lazily via `\OCP\Server::get()`, calls `synchronize()` passing `force`, returns produced items, and lets exceptions propagate uncaught (no swallow).
- [ ] Implement
- [ ] Test

### Task 2: Register the leaf via `RegisterFlowNodesEvent` and wire it in `Application.php`
- **spec_ref**: `openspec/specs/synchronization-flow-node/spec.md#requirement-the-app-shall-register-a-openconnectorsynchronization-flow-node-via-registerflownodesevent-req-sfn-001`
- **files**: `lib/EventListener/RegisterFlowNodesListener.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN OpenRegister dispatches `RegisterFlowNodesEvent` WHEN the listener runs THEN it calls `registerNode()` with the synchronization node and the `FlowNodeRegistry` accepts the non-duplicate id.
  - GIVEN OpenRegister (or `RegisterFlowNodesEvent`) is absent WHEN OpenConnector boots THEN registration is `class_exists()`-guarded, no-ops, and does not throw.
  - GIVEN `Application.php::register()` WHEN inspected THEN the listener is registered against `RegisterFlowNodesEvent` with no constructor injection of OR classes.
- [ ] Implement
- [ ] Test

### Task 3: Author and verify a `schedule`-trigger parity flow that calls the leaf
- **spec_ref**: `openspec/specs/synchronization-flow-node/spec.md#requirement-the-migration-shall-document-how-legacy-triggers-and-chaining-map-onto-flows-under-a-hybrid-execution-model-req-sfn-006`
- **files**: `openspec/changes/openconnector-flow-migration/design.md` (mapping reference; flow authored as an OR object in the shared `flows`/`flow` register — no repo file)
- **acceptance_criteria**:
  - GIVEN a synchronisation previously driven by a Job interval WHEN a `schedule`/cron-trigger flow with a `openconnector.synchronization` node is authored and fired THEN a `FlowRun` executes the synchronisation and returns items (async path).
  - GIVEN the OR dependency is live WHEN an `object.*`-trigger flow is authored with `executionMode: sync` THEN the synchronisation runs inline (save-time parity); when the dependency is absent this parity flow is noted as gated, and the async path still works.
  - GIVEN a `followUp` WHEN modelled THEN it is expressed as an `openregister.sub-flow` edge (hybrid model documented in design.md).
- [ ] Implement
- [ ] Test

### Task 4: Write the ADR updates (ADR-002 amendment + leaf-contract principle)
- **spec_ref**: `openspec/specs/synchronization-flow-node/spec.md#requirement-phase-1-shall-be-additive-and-leave-synchronisation-internals-and-legacy-paths-untouched-req-sfn-005`
- **files**: openconnector `openspec/architecture/adr-002-mapping-rule-engine-stays-app-local.md` (amendment note); a new openconnector ADR (`adr-018-flow-leaves-declare-a-contract.md`) referencing ADR-065
- **acceptance_criteria**:
  - GIVEN ADR-002 ("Mapping and Rule engine stays app-local") WHEN amended THEN the note records that the chaining/ordering concern moves to the OR flow graph (per ADR-065) while the execution engine and connector transforms stay app-local, now expressed as leaves.
  - GIVEN the amendment WHEN reviewed THEN it confirms ADR-005 (contract triad) is preserved and Phase 1 changes no `synchronize()` internals.
  - GIVEN a new ADR WHEN written THEN it establishes that every flow leaf an app contributes MUST declare a leaf contract (node id + config schema + item-list I/O + error/versioning), using this change's `contract.md` as the canonical shape, and is flagged for promotion to the shared hydra/ADR-065 set since it binds all leaf-contributing apps.
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
<!-- Required for all changes. Mark N/A with justification if not applicable. -->
- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — SynchronizationNode metadata/validateConfig/execute and the listener's class_exists guard
- [ ] Newman/Postman tests for new/changed API endpoints — N/A: this change adds no REST endpoints
- [ ] Playwright e2e (`tests/e2e/api-direct/synchronization-node.api.spec.ts`): leaf appears in the flow editor palette (admin scope) AND a schedule-triggered flow runs a synchronisation with a recorded FlowRun (api-direct pattern, per REQ-SFN-002 / REQ-SFN-004)
- [ ] All tests pass (`composer test`)

## Documentation (company-wide ADR-010)
<!-- See `.claude/docs/writing-docs.md` for documentation principles. Required for user-facing features. Mark N/A with justification if not applicable. -->
- [ ] Feature documentation updated in `docs/` — document the synchronization flow node and the trigger/chaining migration mapping
- [ ] Screenshot captured and committed to `docs/images/` — the node in the OR flow editor palette

## i18n (company-wide hydra ADR-007)
<!-- Required when adding user-facing strings. Mark N/A if no new strings. -->
- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added — node display name + description
