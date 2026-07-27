# Test Plan: openconnector-flow-migration

> Phase 1 is backend leaf registration + engine wiring; there is no new UI, so
> coverage is PHPUnit-heavy with one regression case for additive coexistence
> and one review-gated documentation check. Spec scenarios carry `@e2e exclude`
> reasons for the same reason.

## Test Cases

### TC-1: Node registers via RegisterFlowNodesEvent
- **spec_ref**: `openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md#requirement-the-app-shall-register-a-openconnectorsynchronization-flow-node-via-registerflownodesevent-req-sfn-001`
- **type**: functional (PHPUnit)
- **persona**: N/A
- **preconditions**: OpenRegister installed; `RegisterFlowNodesEvent` dispatched
- **steps**: dispatch the event to `RegisterFlowNodesListener`
- **expected result**: `registerNode()` called with a node whose `getId()` is `openconnector.synchronization`; registry accepts the non-duplicate id
- **test command**: `/test-functional` (PHPUnit `tests/Unit/`)

### TC-2: Boot guard when OpenRegister absent
- **spec_ref**: `openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md#requirement-the-app-shall-register-a-openconnectorsynchronization-flow-node-via-registerflownodesevent-req-sfn-001`
- **type**: regression (PHPUnit)
- **persona**: N/A
- **preconditions**: `RegisterFlowNodesEvent` class not available
- **steps**: boot OpenConnector and register listeners
- **expected result**: registration is `class_exists()`-guarded, no-ops, does not throw; node not contributed
- **test command**: `/test-functional`

### TC-3: Metadata + admin scope contract
- **spec_ref**: `openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md#requirement-the-node-shall-expose-or-iflownode-metadata-and-an-admin-scope-req-sfn-002`
- **type**: functional (PHPUnit)
- **persona**: N/A
- **preconditions**: an instance of the synchronization node
- **steps**: call `getId/getDisplayName/getDescription/getIcon`; evaluate `isAvailableForScope(SCOPE_ADMIN)`
- **expected result**: id is exactly `openconnector.synchronization`; name/description/icon non-empty; scope predicate true for admin
- **test command**: `/test-functional`

### TC-4: validateConfig rejects missing synchronizationId
- **spec_ref**: `openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md#requirement-the-node-shall-reject-an-unusable-configuration-at-save-time-req-sfn-003`
- **type**: functional (PHPUnit)
- **persona**: N/A
- **preconditions**: node instance
- **steps**: call `validateConfig([])` then `validateConfig(['synchronizationId' => '00000000-0000-0000-0000-000000000000'])`
- **expected result**: first throws `\UnexpectedValueException`; second does not throw
- **test command**: `/test-functional`

### TC-5: execute() adapts to synchronize() and returns items
- **spec_ref**: `openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md#requirement-the-node-shall-adapt-execute-to-synchronizationservicesynchronize-and-return-the-produced-items-req-sfn-004`
- **type**: functional (PHPUnit)
- **persona**: N/A
- **preconditions**: a synchronisation resolvable by id; `SynchronizationService` resolvable via `\OCP\Server::get()`
- **steps**: call `execute([], ['synchronizationId' => '<id>', 'force' => true], [])`
- **expected result**: `synchronize()` invoked with `force=true`; produced records returned as flow items
- **test command**: `/test-functional`

### TC-6: Synchronisation failure propagates uncaught
- **spec_ref**: `openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md#requirement-the-node-shall-adapt-execute-to-synchronizationservicesynchronize-and-return-the-produced-items-req-sfn-004`
- **type**: functional (PHPUnit)
- **persona**: N/A
- **preconditions**: `synchronize()` stubbed to throw
- **steps**: call `execute()`
- **expected result**: exception propagates out of `execute()` (not swallowed), leaving the engine to apply `onError`
- **test command**: `/test-functional`

### TC-7: Legacy scheduling + lifecycle paths still function
- **spec_ref**: `openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md#requirement-phase-1-shall-be-additive-and-leave-synchronisation-internals-and-legacy-paths-untouched-req-sfn-005`
- **type**: regression
- **persona**: N/A
- **preconditions**: a synchronisation previously driven by a Job interval + object-lifecycle listeners
- **steps**: run the existing `JobTask` path and trigger an object create/update/delete
- **expected result**: synchronisation still triggers via the legacy paths; `synchronize()` internals and the contract triad unchanged
- **test command**: `/test-regression`

### TC-8: schedule-trigger parity flow runs the leaf end-to-end
- **spec_ref**: `openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md#requirement-the-migration-shall-document-how-legacy-triggers-and-chaining-map-onto-flows-under-a-hybrid-execution-model-req-sfn-006`
- **type**: functional
- **persona**: Noor (functional admin) — authors the flow
- **preconditions**: leaf registered; a synchronisation exists; OR `flows`/`flow` register available
- **steps**: author a `schedule`/cron flow with a `openconnector.synchronization` node and fire it
- **expected result**: a `FlowRun` executes the synchronisation and returns items (async path); with the OR dependency live, an `object.*` flow authored `executionMode: sync` runs inline
- **test command**: `/test-persona-noor`

### TC-9: Migration mapping + dependency documented
- **spec_ref**: `openspec/changes/openconnector-flow-migration/specs/synchronization-flow-node/spec.md#requirement-the-migration-shall-document-how-legacy-triggers-and-chaining-map-onto-flows-under-a-hybrid-execution-model-req-sfn-006`
- **type**: functional (review)
- **persona**: N/A
- **preconditions**: design.md exists
- **steps**: review design.md for the trigger-to-flow mapping and the dependency declaration
- **expected result**: Job→schedule, object-lifecycle→`object.*` (`executionMode: sync`), `followUp`→`openregister.sub-flow`; dependency on `openregister-flow-executionmode-and-token` stated
- **test command**: `/test-functional`

## Coverage Summary
- REQ-SFN-001 (register via event): TC-1, TC-2 — covered
- REQ-SFN-002 (metadata + scope): TC-3 — covered
- REQ-SFN-003 (validateConfig): TC-4 — covered
- REQ-SFN-004 (execute adapter + error propagation): TC-5, TC-6 — covered
- REQ-SFN-005 (additive coexistence): TC-7 — covered
- REQ-SFN-006 (migration mapping + hybrid + dependency): TC-8, TC-9 — covered

## Out of Scope
- Phase 2 pipeline decomposition, rule-to-edge mapping, `processSyncRule` real implementation, SoftwareCatalogus-rule removal, and the `FlowToken` XXE fix — deferred to `openconnector-flow-phase2-pipeline`.
- Phase 3 pub-sub / dead-letter migration and `EventAction::run()` — deferred to `openconnector-flow-phase3-pubsub`.
- The OpenRegister engine additions (`executionMode`, run-level flow-token) — tested by `openregister-flow-executionmode-and-token`.
