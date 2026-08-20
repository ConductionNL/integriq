# Test Plan: flow-workflowengine-integration

## Test Cases

### TC-1: WorkflowEngine operations register when the app is enabled
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001`
- **type**: functional
- **persona**: Noor (Municipal CISO / Functional Admin) — configures Flow rules
- **preconditions**: OpenConnector and NC core `workflowengine` app both enabled
- **steps**: Open Settings > Flow as an admin and start creating a new rule
- **expected result**: "Run synchronization", "Call endpoint", and "Fire CloudEvent" appear in the operation
  dropdown for File-entity rules
- **test command**: `/test-functional`

### TC-2: WorkflowEngine operations are absent, with no error, when the app is disabled
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-workflowengine-operation-registration-must-be-feature-detected-on-the-workflowengine-app-req-001`
- **type**: regression
- **preconditions**: `workflowengine` app disabled via `occ app:disable workflowengine`
- **steps**: Restart/re-request OpenConnector's app boot (any authenticated request); check `nextcloud.log`
- **expected result**: No `RegisterOperationsListener` registration occurs; no error-level log entry is
  written; OpenConnector boots normally (all other capabilities unaffected)
- **test command**: `/test-regression`

### TC-3: "Run synchronization" dispatches to `SynchronizationService` on a matching file event
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-the-run-synchronization-operations-onevent-must-dispatch-to-synchronizationservice-req-002`
- **type**: functional
- **persona**: Noor
- **preconditions**: An admin-configured Flow rule using "Run synchronization" (settings `{"synchronizationId":
  "<real id>"}`), scoped to files tagged `push-to-erp`; the target synchronization is configured against a
  reachable test source
- **steps**: Tag a file with `push-to-erp` in the Files app
- **expected result**: The configured synchronization runs (verify via its job/run log showing a new run
  triggered by the Flow event, not by cron)
- **test command**: `/test-functional`

### TC-4: `RunSynchronizationOperation::onEvent()` unit-level dispatch and failure isolation
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-the-run-synchronization-operations-onevent-must-dispatch-to-synchronizationservice-req-002`
- **type**: api
- **preconditions**: `SynchronizationService` mocked; `IRuleMatcher::getFlows(false)` returns two matched
  flows, one with a valid `synchronizationId`, one with a deleted/unresolvable `synchronizationId`
- **steps**: Invoke `onEvent()` directly (PHPUnit)
- **expected result**: `synchronize()` is called exactly once, for the valid flow; the invalid flow's
  `DoesNotExistException` is caught and logged; `onEvent()` returns without throwing
- **test command**: `/test-api` (PHPUnit, run via composer test)

### TC-5: "Call endpoint" dispatches via `EndpointService::triggerFromFlow()`
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-the-call-endpoint-operations-onevent-must-dispatch-to-endpointservicetriggerfromflow-req-003`
- **type**: functional
- **persona**: Noor
- **preconditions**: A Flow rule using "Call endpoint" targeting a test endpoint that logs/echoes incoming
  triggers
- **steps**: Trigger the rule's configured file event
- **expected result**: The target endpoint's configured action runs (verify via the endpoint's own
  logs/statistics showing an invocation), using the SAME `handleRequest()` code path a real inbound API call
  uses — no separate/duplicated routing behavior
- **test command**: `/test-functional`

### TC-6: `EndpointService::triggerFromFlow()` synthesizes a request and delegates without duplicating logic
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-the-call-endpoint-operations-onevent-must-dispatch-to-endpointservicetriggerfromflow-req-003`
- **type**: api
- **preconditions**: `EndpointService` under test, `handleRequest()` spied/mocked
- **steps**: Call `triggerFromFlow($endpoint, ['foo' => 'bar'])` (PHPUnit)
- **expected result**: `handleRequest()` is called exactly once with an `IRequest` whose `getParam('foo')`
  returns `'bar'` and method `GET`; a synthetic-request construction failure is caught, logged, and does not
  throw
- **test command**: `/test-api`

### TC-7: "Fire CloudEvent" dispatches to `EventService::emitCloudEvent()`
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-the-fire-cloudevent-operations-onevent-must-dispatch-to-eventserviceemitcloudevent-req-004`
- **type**: functional
- **persona**: Priya (ZZP Developer / Integrator) — consumes CloudEvents via a webhook subscription
- **preconditions**: A Flow rule using "Fire CloudEvent" (`type = "nl.conduction.flow.file-tagged"`, `source
  = "/openconnector/flow"`); an active `event_subscription` matching that `type` with a webhook target
- **steps**: Trigger the rule's configured file event
- **expected result**: The subscribed webhook receives a delivered `event_message` with the configured
  `type`/`source` and `data.eventName` populated
- **test command**: `/test-functional`

### TC-8: Static `data` literal merges into the emitted CloudEvent
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-the-fire-cloudevent-operations-onevent-must-dispatch-to-eventserviceemitcloudevent-req-004`
- **type**: api
- **preconditions**: `EventService` mocked
- **steps**: Invoke `onEvent()` with a flow whose settings include `data: {"reason": "tagged for export"}`
  (PHPUnit)
- **expected result**: `emitCloudEvent()` is called with `data` containing both `reason` and `eventName`
- **test command**: `/test-api`

### TC-9: All three operations are admin-scoped and File-entity-scoped only
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-all-three-operations-must-be-admin-scoped-and-file-entity-scoped-only-req-005`
- **type**: security
- **preconditions**: An NC instance with user-scope Flow enabled for a non-admin
- **steps**: As the non-admin, open Files > Automation (personal Flow editor)
- **expected result**: None of the three operations appear in the available operations list (verify each
  `isAvailableForScope(IManager::SCOPE_USER)` returns `false` at the unit level, and confirm the UI omission
  end-to-end)
- **test command**: `/test-security`

### TC-10: `validateOperation()` rejects malformed/unresolvable settings before a rule can be saved
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-validateoperation-must-reject-unresolvable-or-malformed-target-settings-before-a-rule-can-be-saved-req-006`
- **type**: functional
- **persona**: Noor
- **preconditions**: Admin creating a new Flow rule
- **steps**: (a) Configure "Run synchronization" with a `synchronizationId` that does not exist and attempt
  to save; (b) configure "Fire CloudEvent" with an empty `type` and attempt to save
- **expected result**: Both save attempts are rejected by NC's Flow editor (surfacing the
  `\UnexpectedValueException` message); neither rule is persisted
- **test command**: `/test-functional`

### TC-11: A valid Flow rule using each operation saves successfully
- **spec_ref**: `openspec/changes/flow-workflowengine-integration/specs/flow-workflowengine-operations/spec.md#requirement-validateoperation-must-reject-unresolvable-or-malformed-target-settings-before-a-rule-can-be-saved-req-006`
- **type**: regression
- **preconditions**: Admin creating a new Flow rule with valid settings for each of the three operations
- **steps**: Save each rule
- **expected result**: All three save without error and appear in the Flow rule list
- **test command**: `/test-regression`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| REQ-001 (feature-detected registration) | TC-1, TC-2 |
| REQ-002 (Run synchronization dispatch) | TC-3, TC-4 |
| REQ-003 (Call endpoint dispatch + `triggerFromFlow()`) | TC-5, TC-6 |
| REQ-004 (Fire CloudEvent dispatch) | TC-7, TC-8 |
| REQ-005 (admin-scoped, File-entity-scoped) | TC-9 |
| REQ-006 (`validateOperation()` rejection) | TC-10, TC-11 |

All six requirements have at least one functional/regression-level test case and, where the requirement is
primarily about internal dispatch logic (REQ-002/003/004), a unit-level (`api`/PHPUnit) test case asserting
the exact delegation and failure-isolation behavior.

## Out of Scope
- Load/performance testing of the synchronous `onEvent()` dispatch path (design.md Risk 1) — deferred; no
  SLA is defined for Flow-triggered synchronization latency in this change.
- Testing against every NC 28-34 point release — the interface stability finding (discovery.md) is based on
  `@since` annotations in the local NC 33.0.0 checkout; this plan validates behavior against that checkout
  only, consistent with how `nextcloud-event-triggers`' own test coverage is scoped.
