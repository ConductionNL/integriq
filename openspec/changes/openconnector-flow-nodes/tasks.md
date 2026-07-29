# Tasks: openconnector-flow-nodes

Before starting: the working copy is `0.2.19` while the installed app reports
`0.3.3`. Re-verify `CallService::call()`'s named arguments, the Source
register/schema pair, and OpenRegister's `IFlowNode` method set against the
deployed tree before wiring anything. See `design.md`.

## Pre-implementation Gate

- [ ] `contract.md` reviewed and accepted by hermiq (change `hydra-console-agent-leaves`) before Task 1 starts — it is the named first consumer, and the contract is the node interface its triage agentflow's terminal label-write step depends on

## Implementation Tasks

### Task 1: Flow-node scaffolding, guarded registration, shared helpers

Create `lib/Flow/` with the listener, the fail-closed owner resolver and the
item templater, and register the listener in `Application.php` behind a
`class_exists` guard so OpenConnector still boots without OpenRegister's flow
engine. Add both palette icons. Follow `hermiq/lib/Flow/HermiqFlowNodeListener.php`
for registration shape only.

- **spec_ref**: `openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md#requirement-openconnector-contributes-flow-nodes-to-openregisters-flow-engine`
- **files**: `lib/Flow/FlowNodeListener.php`, `lib/Flow/FlowOwner.php`, `lib/Flow/FlowTemplate.php`, `lib/AppInfo/Application.php`, `img/flow-source-call.svg`, `img/flow-synchronization-run.svg`
- **acceptance_criteria**:
  - GIVEN both apps enabled WHEN OpenRegister dispatches `RegisterFlowNodesEvent` THEN both node ids appear in the palette with non-empty name, description and icon
  - GIVEN an instance without `RegisterFlowNodesEvent` WHEN OpenConnector boots THEN it boots cleanly and registers no listener
  - GIVEN a colliding node id WHEN registration runs THEN it fails loudly rather than displacing a node
  - GIVEN a run context with no `triggeredBy` WHEN `FlowOwner` resolves THEN it raises; no admin, creator or anonymous fallback exists
  - GIVEN an item `{"issue":{"number":42}}` WHEN `FlowTemplate` renders `/issues/{{issue.number}}` THEN it yields `/issues/42`; a missing path resolves deterministically and never leaves literal `{{...}}`
- [ ] Implement
- [ ] Test

### Task 2: SourceCallNode — Source targeting, per-item execution, response mapping

Implement `openconnector.source-call`: resolve `config.source` as an
OpenRegister object (register `openconnector`, schema `source`), render the
endpoint/query/body/headers against the item, call
`CallService::call(source:, endpoint:, method:, config:)` once per item, and map
the response onto author-named keys. No URL field, no find-or-create, no direct
HTTP client.

- **spec_ref**: `openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md#requirement-the-source-call-node-targets-a-configured-source-never-a-raw-url`
- **files**: `lib/Flow/SourceCallNode.php`, `lib/Flow/FlowNodeListener.php`
- **acceptance_criteria**:
  - GIVEN a step naming an enabled Source WHEN it executes THEN `CallService::call()` receives the resolved Source object, endpoint and method, and no HTTP client is invoked directly
  - GIVEN an unknown `source` WHEN it executes THEN it raises naming the reference, creates no Source and makes no request
  - GIVEN `endpoint` is absolute or scheme-relative WHEN validated THEN it is rejected; GIVEN a rendered endpoint containing `../` WHEN executing THEN it is rejected before any request
  - GIVEN three input items WHEN it executes THEN three calls are made and three items returned, each with correct `pairedItem`
  - GIVEN a response containing `pairedItem` WHEN mapped THEN the item's provenance and other reserved `FlowItems` keys are unchanged
  - GIVEN an empty input list WHEN it executes THEN no call is made and an empty list is returned
  - GIVEN any flow-originated call WHEN it completes THEN a CallLog is written
- [ ] Implement
- [ ] Test

### Task 3: Explicit failure, fail-closed attribution, validation and scope

Wire the security and error half of the node: raise on failure so `onError`
decides, carry structurally distinct error state under `continue`, treat non-2xx
and transport failures as failures unless opted in via `acceptStatuses`, refuse
to execute without a resolvable owner, reject credential-bearing and owner
fields, and implement `validateConfig()` + `isAvailableForScope()`. Explicitly
do NOT reproduce `HermiqAgentNode`'s `catch (Throwable) { $answer = ''; }` or its
`config.owner` fallback.

- **spec_ref**: `openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md#requirement-a-failed-call-is-explicit-and-never-a-silent-empty-success`
- **files**: `lib/Flow/SourceCallNode.php`, `lib/Flow/FlowOwner.php`
- **acceptance_criteria**:
  - GIVEN a 500 response and the default policy WHEN it executes THEN the node raises, the run stops, and status, message, Source and endpoint are logged
  - GIVEN `onError: continue` and one failing of two items WHEN it executes THEN the run continues and the failed item carries error state structurally distinct from a success
  - GIVEN a regression test WHEN a call fails THEN the output is never a success-shaped empty result
  - GIVEN `acceptStatuses: [200,404]` and a 404 WHEN it executes THEN it does not raise and the status is available downstream
  - GIVEN a timeout or TLS failure WHEN it executes THEN a transport failure is reported, not an empty body
  - GIVEN no `triggeredBy`, or a `triggeredBy` naming a deleted user WHEN it executes THEN it raises and makes no request
  - GIVEN config carrying an owner, token, password or API-key field WHEN validated THEN it is rejected with a translated message
  - GIVEN a Source with `credentialRef` WHEN it executes THEN the broker authenticates the call and no secret appears in config, logs or item; an unresolvable credential never becomes an anonymous call
  - GIVEN missing `source`, missing `endpoint`, unsupported method or malformed `acceptStatuses` WHEN the flow is saved THEN `UnexpectedValueException` names the field and the flow is not persisted
  - GIVEN `isAvailableForScope()` WHEN asked THEN it answers with `IManager::SCOPE_ADMIN`/`SCOPE_USER` and returns false for other values
- [ ] Implement
- [ ] Test

### Task 4: SynchronizationRunNode with bounded fan-out, seed data, and a live end-to-end run

Implement `openconnector.synchronization-run` reusing Task 1/3 helpers, emitting
**one item per synchronised object** (PO decision 2026-07-27) bounded by
`config.maxItems` (default 1000, raise-never-truncate); seed the three demo
Sources and the demo flow from `design.md` (nil-UUID placeholders,
`demo-forge-api` seeded disabled, no secrets); then run the demo flow live and
confirm a real response lands on the item.

- **spec_ref**: `openspec/changes/openconnector-flow-nodes/specs/flow-nodes/spec.md#requirement-the-synchronization-run-node-emits-one-item-per-synchronised-object`
- **files**: `lib/Flow/SynchronizationRunNode.php`, `lib/Flow/FlowNodeListener.php`, `lib/sources.seed.json`, `tests/Unit/Flow/SynchronizationRunNodeTest.php`
- **acceptance_criteria**:
  - GIVEN a configured Synchronization and a resolvable owner WHEN the node executes THEN it runs through the existing synchronization service
  - GIVEN a synchronization processing three objects WHEN the node executes THEN three items are emitted, each carrying its object payload and per-object outcome under the output key, the run counts under `<output>.summary`, and a `pairedItem` referring to the input item
  - GIVEN a synchronization processing zero objects WHEN the node executes THEN exactly one summary-only item is emitted with zero counts, not an empty list
  - GIVEN 10000 processed objects and the default `maxItems` of 1000 WHEN the node executes THEN it raises naming count, ceiling, step id and synchronization, returns no truncated or sampled subset, and states the objects were nevertheless synchronised
  - GIVEN `maxItems: 20000` and 10000 processed objects WHEN it executes THEN 10000 items are emitted and a warning naming count, step id and synchronization was logged at the 250-item threshold
  - GIVEN an inline synchronization definition WHEN validated THEN it is rejected; the node never creates a Synchronization
  - GIVEN `onError: dead_letter` and a failing synchronization WHEN it executes THEN the run is dead-lettered, not reported successful
  - GIVEN no resolvable owner WHEN it executes THEN it raises and starts no synchronization
  - GIVEN a fresh install WHEN the register is imported (forced) THEN the three demo Sources and the demo flow exist, `demo-forge-api` is disabled, and no seed value is a real token or host
  - GIVEN the seeded demo flow WHEN run manually on the dev instance THEN it completes and the item carries the echo response
  - GIVEN the seed flow's built-in step type WHEN verified against `FlowNodeRegistry` THEN the recorded type string matches the deployed registration
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)
- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/Flow/`)
- [ ] Newman/Postman tests — N/A: this change adds no HTTP API endpoint, only flow node types
- [ ] Browser tests (Playwright MCP) — N/A for OpenConnector: no UI added; palette rendering belongs to OpenRegister's flow editor. Replaced by a live flow run against the seeded demo Source (Task 4)
- [ ] All tests pass (`composer test`, `composer check:strict`)

## Documentation (company-wide ADR-010)
- [ ] Feature documentation added in `docs/` — how to call an API from a flow, why there is no raw-URL node, and why an unattributed run fails closed
- [ ] Screenshot of both nodes in OpenRegister's flow palette captured and committed to `docs/images/`

## i18n (company-wide hydra ADR-007)
- [ ] Dutch (`nl_NL`) and English (`en_US`) strings added for both nodes' display names, descriptions and all validation messages
