---
status: in-progress
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
- `vng-klantinteracties-adapter` (active) — adds a composite transactional fan-out Rule type (REQ-RULE-006, used for VNG's composite `maak-klantcontact`) and a `referentienummer` generation Rule (REQ-RULE-007). Both are dialect-agnostic gateway mechanics (ADR-031 external-integration exception). Normative requirements live in the change's delta spec and merge here on archive.

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

