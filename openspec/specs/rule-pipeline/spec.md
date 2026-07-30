---
status: done
retrofit: true
---

# Endpoint Rule Pipeline

## Purpose

OpenConnector endpoints can carry an ordered list of rules that run before and
after the request reaches its target (a register/schema or an external source).
Rules implement endpoint-level business logic — authentication enforcement,
data mapping, object persistence, file handling, synchronisation triggers,
audit-trail reads, locking, custom transforms, and configured error responses.
This capability describes the **observed behaviour** of the rule-processing
engine (`EndpointService` rule methods + `RuleService` custom rules) as it
exists today. It is a retrofit spec: the code already exists, and these REQs
document it rather than prescribe new work. Per ADR-002 the rule engine is an
openconnector-local concept with no OpenRegister equivalent.

**OpenSpec changes**
- [`vng-klantinteracties-adapter`](../../changes/archive/2026-07-12-vng-klantinteracties-adapter/) _(archived 2026-07-12)_ — added a composite transactional fan-out Rule type (REQ-RULE-006, used for VNG's composite `maak-klantcontact`) and a `referentienummer` generation Rule (REQ-RULE-007). Both are dialect-agnostic gateway mechanics (ADR-031 external-integration exception).
## Requirements

### REQ-RULE-UI-001: Rule Management UI

OpenConnector MUST provide a Rules section in its SPA where administrators can
browse, create, edit, and configure rule objects that are referenced by endpoints.

#### Scenario: rules list page mounts and shows content

- GIVEN an authenticated admin visits the openconnector app
- WHEN they navigate to the Rules section via the sidebar or direct URL
- THEN the Rules index page renders inside the app-content area with content visible

#### Scenario: Add Rule button opens the creation modal

- GIVEN the Rules index page is loaded
- WHEN the user clicks the "Add Rule" button
- THEN a modal/dialog opens containing the rule creation form

#### Scenario: Rule detail page renders for an existing rule

- GIVEN at least one rule exists in the system
- WHEN the user clicks on a rule row or card in the Rules list
- THEN the rule detail view renders without error

### Requirement: Ordered Rule Pipeline Execution (REQ-RULE-001)

The system MUST resolve an endpoint's configured rules into rule entities,
sort them by their numeric `order` field (ascending, default 0), and process
each rule in turn for the requested timing phase. For each rule the system MUST
first evaluate the rule's JSON-Logic `conditions` and its `timing` ("before"
or "after"); a rule whose conditions do not pass OR whose timing does not match
the current phase MUST be skipped (logged at info level) without mutating the
data envelope. When a rule's processing returns a `JSONResponse` or
`DataDownloadResponse`, pipeline execution MUST short-circuit and that response
MUST be returned immediately. Any exception thrown while applying a rule MUST be
caught and surfaced as an HTTP 500 `JSONResponse` whose body carries the
endpoint name, rule name, rule type, and error message.

@e2e exclude backend rule pipeline execution — covered by PHPUnit, not browser UI

#### Scenario: rules execute in ascending order

- **GIVEN** an endpoint with three rules of `order` 30, 10, 20
- **WHEN** the pipeline runs
- **THEN** the rules execute in the sequence 10, 20, 30.

#### Scenario: failing conditions skip the rule

- **GIVEN** a rule whose JSON-Logic `conditions` evaluate to false
- **WHEN** the pipeline reaches it
- **THEN** the rule is skipped, an info log records the skip, and the data
  envelope is unchanged.

#### Scenario: timing mismatch skips the rule

- **GIVEN** a rule with `timing` "after"
- **WHEN** the pipeline runs the "before" phase
- **THEN** that rule is skipped during the before phase.

#### Scenario: rule exception surfaces as HTTP 500

- **GIVEN** a rule whose processing throws an exception
- **WHEN** the pipeline applies it
- **THEN** the response is HTTP 500 with a body containing the endpoint name,
  rule name, rule type, and the exception message, and no further rules run.

#### Scenario: returned response short-circuits the pipeline

- **GIVEN** a rule that returns a `JSONResponse` (e.g. an error or override
  rule)
- **WHEN** the pipeline applies it
- **THEN** that response is returned immediately and subsequent rules are not
  processed.

**Notes:**
- `getRuleById()` resolves rules via OpenRegister `find(register: 'openconnector', schema: 'rule')` and returns `null` on lookup failure (logged), so an unresolvable rule id is silently dropped from the chain rather than failing the request. Documented as observed behaviour; flagged for future tightening.
- Rule entities are re-fetched and re-sorted on every request; there is no caching of the resolved rule chain.

### Requirement: Data-Mutation Rules (REQ-RULE-002)

The system MUST provide rules that mutate the request/response data envelope
against an OpenRegister object or schema. `save_object` MUST persist
`data['body']` to a configured register/schema (optionally pre-mapping it) via
OpenRegister `ObjectService::saveObject`. `mapping` MUST transform the body
through a configured mapping object — iterating per-result when the body is a
GET result set with `mapResults` enabled, otherwise mapping the whole body.
`extend_input` MUST resolve UUID-valued request parameters to full OpenRegister
objects and attach them under `extendedParameters` / `body._extendedInput`.
`override` (timing "after" only) MUST re-persist a previously written object
with the flow-token body. `locking` MUST lock or unlock the target object via
OpenRegister `lockObject` / `unlockObject`.

@e2e exclude backend data-mutation rule internals — covered by PHPUnit, not browser UI

#### Scenario: save_object persists and replaces the body

- **GIVEN** a `save_object` rule with a configured register, schema, and
  optional mapping
- **WHEN** the rule runs
- **THEN** the body is (optionally mapped and) persisted via OpenRegister and
  the saved object replaces `data['body']`.

#### Scenario: mapping iterates over a GET result set

- **GIVEN** a `mapping` rule and a GET body containing a `results` array with
  `mapResults` not disabled
- **WHEN** the rule runs
- **THEN** the mapping is applied to each result element individually.

#### Scenario: extend_input resolves a UUID parameter

- **GIVEN** an `extend_input` rule whose configured parameter holds a valid UUID
- **WHEN** the rule runs
- **THEN** the referenced OpenRegister object is fetched and merged into
  `extendedParameters`; a non-UUID or not-found value is skipped without error.

#### Scenario: locking locks the target object

- **GIVEN** a `locking` rule with action "lock" and a target object id
- **WHEN** the rule runs
- **THEN** the object is locked for the configured duration (default 3600s) and
  the locked object replaces the body; an unknown locking action leaves the data
  untouched.

**Notes:**
- `processExtendInputRule` and `RuleService::extendExternalUrl` both write `body._extendedInput`; the pipeline `unset()`s `body._extendedInput` after the rule loop completes.

### Requirement: Authentication and Error Rules (REQ-RULE-003)

The system MUST provide an `authentication` rule that enforces per-endpoint
credential checks and an `error` rule that returns a configured error response.
The authentication rule MUST read the credential from the `Authorization`
header (or a configured header name, case/underscore-insensitive), return HTTP
403 when the header is absent, and dispatch to the configured authentication
type — `apikey`, `jwt`/`jwt-zgw`, `basic`, or `oauth` — returning HTTP 401 on a
caught `AuthenticationException` and HTTP 501 for an unsupported type; on
success it returns the data unchanged. The error rule MUST return a
`JSONResponse` built from the configured `name`, `message`, and `code`,
optionally including the JSON-Logic result as an `errors` array.

@e2e exclude backend authentication and error rule internals — covered by PHPUnit, not browser UI

#### Scenario: missing Authorization header returns 403

- **GIVEN** an `authentication` rule and a request with no Authorization header
- **WHEN** the rule runs
- **THEN** the response is HTTP 403 with a "forbidden" error and the pipeline
  short-circuits.

#### Scenario: invalid apikey returns 401

- **GIVEN** an `authentication` rule of type `apikey` and an invalid key
- **WHEN** the rule runs
- **THEN** the response is HTTP 401 carrying the exception message and details.

#### Scenario: unsupported auth type returns 501

- **GIVEN** an `authentication` rule with an unsupported type
- **WHEN** the rule runs
- **THEN** the response is HTTP 501 Not Implemented.

#### Scenario: error rule includes the JSON-Logic result

- **GIVEN** an `error` rule with `includeJsonLogicResult` true
- **WHEN** the rule runs after a condition that produced a logic result
- **THEN** the response body includes that result under `errors` and uses the
  configured status code.

**Notes:**
- The authentication rule returns the data envelope **unchanged** on success rather than recording the authenticated principal; downstream rules and the target dispatch run with no record of which credential passed. Documented as observed; flagged as a follow-up for principal propagation.

### Requirement: File, Synchronisation, and Download Rules (REQ-RULE-004)

The system MUST provide rules that handle file persistence, mid-request
synchronisation, and file downloads. `write_file` MUST base64-decode payload(s)
at a configured `filePath` and persist them via OpenRegister `FileService`
against the target object. `fileparts_create` / `filepart_upload` MUST
orchestrate TUS-style chunked uploads via the storage service. `synchronization`
MUST trigger a configured synchronisation through `SynchronizationService`
(honouring test/force flags, optional pre/post delays, and result-merge
configuration). `download` MUST resolve a file for the target object and return
it as a `DataDownloadResponse`.

@e2e exclude backend file/sync/download rule internals — covered by PHPUnit, not browser UI

#### Scenario: write_file decodes and persists files

- **GIVEN** a `write_file` rule and a body carrying base64 file content at the
  configured path
- **WHEN** the rule runs
- **THEN** each file is decoded and written via OpenRegister FileService and the
  path(s) replace the content in the envelope.

#### Scenario: synchronization triggers the sync service

- **GIVEN** a `synchronization` rule referencing a synchronisation by id or
  reference
- **WHEN** the rule runs
- **THEN** `SynchronizationService::synchronize` is invoked with the resolved
  test/force/mutationType flags and the body is merged or overwritten per the
  rule's result configuration.

#### Scenario: download returns a DataDownloadResponse

- **GIVEN** a `download` rule and a target object with a resolvable file
- **WHEN** the rule runs
- **THEN** a `DataDownloadResponse` carrying the file content, name, and mime
  type is returned and the pipeline short-circuits.

#### Scenario: fileparts_create before object exists raises an error

- **GIVEN** a `fileparts_create` rule applied before the object exists (null
  objectId)
- **WHEN** the rule runs
- **THEN** an exception is thrown ("Filepart rules can only be applied after the
  object has been created").

**Notes:**
- **Silent failure (flagged):** `processWriteFileRule` wraps each
  `FileService::addFile` call in a `try/catch (Exception)` whose body is empty —
  per-file write failures are swallowed with no log and no error surfaced to the
  caller. The rule reports success even when some/all files failed to write.
  Documented as observed behaviour; recommended for follow-up hardening.
- **Stub (flagged):** `processJavaScriptRule` (rule type `javascript`) is a
  no-op — it returns the data unchanged with a `@todo` for the unimplemented JS
  engine. The rule type is dispatchable but inert.
- `processSyncRule` honours `synchronization.preDelay` / `postDelay` via
  blocking `sleep()` calls inside the request thread.

### Requirement: Custom Software-Catalogus Rules (REQ-RULE-005)

The system MUST provide a `custom` rule type that dispatches on a configured
custom-rule `type`. `softwareCatalogus` MUST build a GEMMA/ArchiMate export
model — validating and back-filling property definitions, resolving Voorziening
and VoorzieningGebruik objects from OpenRegister, creating ArchiMate elements,
relations, nodes, and connections, and assembling them into the export
structure. `connectRelations` MUST extend the catalogue model for a UUID model
id taken from the request path. An external-extension rule
(`extend_external_input`) MUST fetch referenced external objects over HTTP
(reusing or creating a Source row by location), optionally validate them against
a schema, and merge them into `extendedParameters`. An unsupported custom-rule
type MUST throw.

@e2e exclude backend custom-rule engine internals — covered by PHPUnit, not browser UI

#### Scenario: softwareCatalogus builds the export model

- **GIVEN** a `custom` rule of type `softwareCatalogus` and a register/schema
  configuration
- **WHEN** the rule runs
- **THEN** the export body is populated with property definitions, elements,
  relationships, views, and organisation folders for the resolved
  Voorzieningen.

#### Scenario: connectRelations extends the catalogue model

- **GIVEN** a `custom` rule of type `connectRelations` and a path ending in a
  valid UUID
- **WHEN** the rule runs
- **THEN** the catalogue model is extended for that id and an HTTP 200
  "Connected views succesfully" response is returned; a missing/invalid id
  returns HTTP 200 "model id was not provided".

#### Scenario: extend_external_input fetches and merges an external object

- **GIVEN** an `extend_external_input` rule referencing an external URL
- **WHEN** the rule runs
- **THEN** the object is fetched (creating a Source by location if none exists),
  optionally validated, and merged into `extendedParameters`; a validation
  failure returns HTTP 400 with field-level errors.

#### Scenario: unrecognised custom type throws

- **GIVEN** a `custom` rule of an unrecognised type
- **WHEN** the rule runs
- **THEN** an exception "Unsupported custom rule type" is thrown (surfaced as
  HTTP 500 by the pipeline).

**Notes:**
- The software-catalogus rule carries hard-coded constants — fixed node and
  relation UUID pools (`NODE_IDS`, `RELATION_IDS`), property-definition ids, and
  the model name "Turfburg (test VNG Realisatie)". `@TODO` comments mark these
  for replacement with stored identifiers. Documented as observed; this is
  test/demo-shaped data baked into production code.
- `extendExternalUrl` auto-creates a `Source` register object for any unseen
  external URL with `isEnabled: true`, then calls it via `CallService`. This is
  an implicit write side-effect of a read-extension rule; flagged for review.

### Requirement: Composite transactional fan-out Rule type (REQ-RULE-006)

The rule pipeline MUST support a composite fan-out Rule type that, from a single
request body, performs multiple related object writes as one logical
transaction, rolling back all writes if any child write fails and returning a
single error. The Rule MUST run in the before-timing slot and MUST compose with
the ordered pipeline (REQ-RULE-001) without breaking the existing before/after
ordering.

@e2e exclude backend rule engine — covered by PHPUnit/Newman, no browser UI

#### Scenario: Composite fan-out creates all children atomically
- GIVEN a composite fan-out Rule configured for `maak-klantcontact` and a request body with a parent and two child objects
- WHEN the Rule runs
- THEN the parent and both children are created and the response is the created parent resource

#### Scenario: Any child failure rolls the whole composite back
- GIVEN a composite fan-out Rule and a request where the second child write fails
- WHEN the Rule runs
- THEN neither the parent nor the first child remains persisted and a single error is returned

#### Scenario: Composite Rule respects pipeline ordering
- GIVEN a composite fan-out Rule ordered alongside an AVG BSN policy Rule
- WHEN both are configured on the endpoint
- THEN the AVG policy Rule runs before the fan-out writes, per the ordered pipeline

**Implementation:** `lib/Rule/CompositeFanoutRule.php`, dispatched from
`EndpointService::processRules()`'s `composite_fanout` case. Rollback deletes
every object created so far (most-recently-created first) via
`ObjectService::deleteObject()`. See the archived change's tasks.md
Deviations for the before-rule/dispatch-ordering caveat on the packaged
`vng-maak-klantcontact` endpoint.

### Requirement: `referentienummer` generation Rule (REQ-RULE-007)

The rule pipeline MUST support a Rule that generates and stamps a unique
`referentienummer` (message reference) on emitted resources/responses. The
default MUST be a UUIDv4; a configured numbering scheme MAY override it. The
generated reference MUST be stable for the response in which it is issued.

@e2e exclude backend rule engine — covered by PHPUnit, no browser UI

#### Scenario: Response carries a unique referentienummer
- GIVEN an endpoint with the `referentienummer` Rule enabled
- WHEN a resource is emitted
- THEN the response carries a unique `referentienummer` (UUIDv4 by default)

#### Scenario: Configured numbering scheme overrides the default
- GIVEN the `referentienummer` Rule configured with a municipality numbering scheme
- WHEN a resource is emitted
- THEN the reference follows the configured scheme instead of a UUIDv4

**Implementation:** `lib/Rule/ReferentienummerRule.php`, dispatched from
`EndpointService::processRules()`'s `referentienummer` case. Scheme tokens:
`{uuid}`, `{year}`.

### Requirement: `approval` rule action type suspends the pipeline (REQ-RULE-008)

The system MUST provide an `approval` rule type in
`EndpointService::processRules()`'s type dispatch, valid only for
`timing: before`. When a `before`-phase `approval` rule's conditions pass,
processing MUST NOT continue to later rules in the same run; instead the
system MUST delegate to `ApprovalService::suspend()` (see
`approval-workflow` REQ-001) and return the resulting `JSONResponse(202)`
through the pipeline's existing short-circuit contract (the same contract
`error` and other terminal rule types already use — no new Response type).
An `approval` rule configured with `timing: after` MUST be treated as
invalid configuration and MUST NOT be dispatched.

@e2e exclude backend rule pipeline execution — covered by PHPUnit, not browser UI

#### Scenario: approval rule short-circuits the before-phase pipeline

- **GIVEN** an endpoint with rules at order 10 (`authentication`), 20
  (`approval`), and 30 (`save_object`)
- **WHEN** the `before`-phase pipeline runs and the order-20 rule's
  conditions pass
- **THEN** the order-10 rule runs normally, the order-20 rule suspends the
  pipeline via `ApprovalService::suspend()`, the pipeline returns HTTP 202,
  and the order-30 rule does NOT run in this request

#### Scenario: an approval rule configured for the after phase never dispatches

- **GIVEN** an `approval` rule configured with `timing: after`
- **WHEN** the pipeline evaluates rules for either phase
- **THEN** the rule is never matched to the `approval` dispatch case (timing
  mismatch is invalid configuration, not a runtime skip)

#### Notes

- This requirement only adds a new entry to the existing `match` dispatch
  in `processRules()` (alongside `save_object`, `authentication`, `error`,
  etc.) and does not change REQ-RULE-001's ordering/condition/short-circuit
  contract, which the `approval` type reuses as-is.
- Resume (the counterpart to this suspension) is specified in
  `approval-workflow` REQ-003, not here — resuming calls back into
  `processRules()` for the remaining rules in the same phase, so no
  separate "resume" rule type exists.

### Requirement: Trace-step emission during rule pipeline execution (REQ-RULE-010)

The system MUST append one ordered `Step` to the active
`ExecutionTraceContext`'s buffer (per `execution-trace` REQ-001) for every
rule `processRules()` evaluates, when a non-null `ExecutionTraceContext` is
supplied. This MUST include rules skipped by REQ-RULE-001's condition/timing
checks (`status: 'skipped'`), rules that mutate the data envelope
(`status: 'success'`, redacted input/output per `execution-trace` REQ-003),
and rules whose processing throws (`status: 'error'`, the same
endpoint/rule name/type/message the HTTP 500 body carries). When no
`ExecutionTraceContext` is supplied, `processRules()` MUST behave
identically to its current, untraced behaviour — no step buffering, no
additional OpenRegister writes, no change to REQ-RULE-001's
ordering/condition/short-circuit contract.

@e2e exclude backend rule pipeline execution — covered by PHPUnit, not browser UI

#### Scenario: a traced pipeline records a step per evaluated rule

- **GIVEN** an endpoint with three rules of `order` 10, 20, 30 and an active
  `ExecutionTraceContext`
- **WHEN** the pipeline runs and the order-20 rule's conditions fail
- **THEN** three steps are appended in order 10, 20, 30
- **AND** the order-20 step carries `status: 'skipped'`

#### Scenario: an untraced pipeline is unaffected

- **GIVEN** an endpoint call with no `ExecutionTraceContext` supplied
  (`traceId` not minted — e.g. a code path that predates this change)
- **WHEN** `processRules()` runs
- **THEN** behaviour is byte-for-byte identical to REQ-RULE-001's existing
  scenarios — no step is buffered, no `execution_trace` write occurs

#### Notes

- This requirement only adds an optional-parameter hook to the existing
  `processRules()`/`dispatchAfterBeforeRules()` signatures (default `null`);
  it does not change REQ-RULE-001's ordering, condition evaluation, or
  short-circuit contract.

### Requirement: Dry-run mode suppresses write-shaped rule dispatch (REQ-RULE-011)

`processRules()` MUST accept an optional `dryRun` parameter (default
`false`, preserving existing behaviour exactly). When `dryRun === true`,
rule types with an external or persisted side-effect — `save_object`,
`override`, `locking`, `write_file`, `fileparts_create`, `filepart_upload`,
`composite_fanout` (per `rule-pipeline` REQ-RULE-006) — MUST NOT perform
their write; the pipeline MUST instead record a step with `status:
'skipped_dry_run'` and continue evaluating downstream rules against the
pre-rule data envelope. Rule types with no external side-effect —
`mapping`, `extend_input`, `authentication`, `error` — MUST execute
normally under `dryRun: true`. A `synchronization` rule under `dryRun: true`
MUST forward `isTest: true` to `SynchronizationService::synchronize()`
(reusing `synchronization-engine` REQ-011's existing no-write guarantee)
rather than being unconditionally skipped, since the target synchronization
already knows how to no-op safely.

@e2e exclude backend rule pipeline execution — covered by PHPUnit, not browser UI

#### Scenario: dryRun suppresses a save_object rule's write

- **GIVEN** a `save_object` rule and `dryRun: true`
- **WHEN** the pipeline reaches it
- **THEN** no OpenRegister object is persisted
- **AND** the recorded step carries `status: 'skipped_dry_run'`

#### Scenario: dryRun does not suppress a mapping rule

- **GIVEN** a `mapping` rule and `dryRun: true`
- **WHEN** the pipeline reaches it
- **THEN** the mapping is applied for real and the step carries a normal
  `status: 'success'`

#### Scenario: dryRun forwards isTest to a synchronization rule

- **GIVEN** a `synchronization` rule and `dryRun: true`
- **WHEN** the pipeline reaches it
- **THEN** `SynchronizationService::synchronize()` is invoked with
  `isTest: true`, and no target write occurs

#### Notes

- `dryRun` defaults to `false`; every pre-existing REQ-RULE-* requirement in
  this capability is exercised with the default and is unaffected by this
  requirement's existence.
- This requirement exists to support `execution-trace` REQ-005/REQ-006's
  endpoint-entryPoint replay preview; it has no caller outside that replay
  path in this change's scope.
- **Integration follow-up (not in this change's scope):** this change was
  authored against a base that predates the `flow` rule action type
  (REQ-RULE-009, added independently). `flow` triggers a flow run — a
  write-shaped side effect — but is NOT in this requirement's suppression
  set (`EndpointService::DRY_RUN_SUPPRESSED_RULE_TYPES`), because it does
  not exist in this change's base `processRules()` type dispatch. Whoever
  integrates the two MUST decide whether `flow` belongs in the suppression
  set (likely yes, or a forwarded dry-run flag mirroring the
  `synchronization` partial exception above); until then a dry-run replay of
  an endpoint carrying a `flow` rule WOULD trigger a real flow run.
### Requirement: `flow` rule action type triggers a flow run (REQ-RULE-009)

The system MUST provide a `flow` rule type in
`EndpointService::processRules()`'s type dispatch (the existing 22-way
`match` on `$ruleData['type']`, alongside `save_object`, `approval`,
etc.), valid for either `timing: before` or `timing: after`. When a
`flow` rule's conditions pass (per the existing `checkRuleConditions()`
contract, REQ-RULE-001), the system MUST resolve the rule's `configRef`
to a `flow` OR object and call `FlowRunnerService::run($flow, data:
$data)` (see `flow-orchestration` REQ-001/REQ-007). The flow runs
synchronously within the same request; its result MUST NOT alter the
pipeline's existing before/after ordering or short-circuit contract for
other rules (matching REQ-RULE-008's precedent for the `approval` type —
this requirement only adds one new dispatch entry, it does not change
REQ-RULE-001's ordering/condition/short-circuit contract).

If the referenced flow's run ends with `flow_run.status: failed`,
`stopped`, or `dead_letter`, the rule pipeline MUST treat this the same
way it treats any other rule-level failure today (surfaced as an error
through the pipeline's existing error contract) — a flow rule does not
introduce a new pipeline-level failure mode beyond what `error`/`approval`
rule types already establish.

@e2e exclude backend rule pipeline dispatch — covered by PHPUnit, not browser UI

#### Scenario: a `flow` rule triggers a flow run mid-pipeline

- **GIVEN** an endpoint with rules at order 10 (`authentication`), order
  20 (`flow`, `configRef` pointing at an enabled flow), and order 30
  (`save_object`)
- **WHEN** the pipeline evaluates the `before`-phase rules and the
  order-20 rule's conditions pass
- **THEN** `FlowRunnerService::run()` is called for the referenced flow
- **AND** the order-10 and order-30 rules still run in their existing
  order, unaffected by the flow rule's dispatch

#### Scenario: a flow rule's conditions gate whether the flow runs

- **GIVEN** a `flow` rule with a condition that evaluates false for the
  current request
- **WHEN** the pipeline reaches that rule
- **THEN** `FlowRunnerService::run()` is NOT called
- **AND** the pipeline proceeds to the next rule as normal

#### Notes

- This requirement only adds a new entry to the existing `match` dispatch
  in `processRules()` (alongside `save_object`, `authentication`,
  `approval`, etc.) — the exact same integration pattern REQ-RULE-008
  already used for the `approval` type. It does not change
  REQ-RULE-001's ordering/condition/short-circuit contract, which the
  `flow` type reuses as-is.
- Unlike the `approval` type (REQ-RULE-008, `timing: before` only), a
  `flow` rule is valid at either timing — a flow can be a pre-write
  side-effect (`before`) or a post-write follow-up action (`after`),
  matching how `synchronization`/`mapping` rule types are already valid
  at either timing.
- A `flow` rule referencing a flow that itself contains an `approval`
  step will suspend that flow run (per `flow-orchestration` REQ-005) —
  from the endpoint rule pipeline's perspective this is treated
  identically to any other rule dispatch that completes without altering
  the pipeline's own response; the pipeline does NOT wait on or surface
  the flow's suspension state synchronously. This is a deliberate v1
  simplification: chaining a suspending flow off an endpoint rule is
  supported for triggering, but the endpoint response is not itself
  gated on that flow's eventual approval outcome (only a direct
  `approval` rule type, per REQ-RULE-008, gates the endpoint response
  itself).

## Non-Functional Requirements

- **Performance:** the composite Rule MUST bound its writes to the resources named in the request (no unbounded cascade).
- **Internationalization:** rule error messages MUST be localisable (Dutch + English, hydra ADR-007) — REQ-RULE-006/007 error messages are not yet localised; flagged as a follow-up in the archived change's tasks.md.

