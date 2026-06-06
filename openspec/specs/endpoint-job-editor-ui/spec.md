# endpoint-job-editor-ui Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-25-endpoint-job-editor-ui. Update Purpose after archive.

@e2e exclude Vue component-internal method/computed behaviour (editEndpoint/setSchemaOptions derived selectors, draft init, modal close/re-derive) reverse-engineered from the endpoint/job edit-modal .vue components — unit-level (vitest), not browser-observable; the endpoint/job detail-page renders + Add modal surfaces are covered by manifest-pages e2e under endpoint-runtime and job-management

## Requirements
### Requirement: Endpoint edit modal (REQ-EPJOBUI-001)

The endpoint edit modal SHALL initialise an endpoint draft, fetch the available
registers/schemas/configurations, derive schema options for the selected register,
and persist the endpoint through the store. It supports closing and re-deriving
options on update.

#### Scenario: Editing an endpoint
- WHEN the user fills the modal and saves
- THEN `editEndpoint` persists the endpoint and the modal closes

#### Scenario: Register selection derives schema options
- WHEN a register is selected
- THEN `setSchemaOptions` populates the schema selector for that register

Notes: `EditEndpoint.vue` (9 methods/lifecycle).

### Requirement: Job form fields (REQ-EPJOBUI-002)

The job form SHALL present job-class options, conditionally show an arguments field,
let the user pick a synchronization, render typed argument fields, and serialise/parse
JSON argument values. It loads synchronizations on demand and relays picks to the parent.

#### Scenario: Selecting a job class
- WHEN the user picks a job class via `onJobClassPick`
- THEN the selected class is recorded and `hasArgumentsField` decides whether the args field shows

#### Scenario: JSON argument round-trips
- WHEN the user edits a JSON argument field
- THEN `jsonStringFor`/`onJsonInput` serialise and parse the value, rejecting malformed JSON

Notes: `JobFormFields.vue` (11).

### Requirement: Run a job (REQ-EPJOBUI-003)

The run-job modal SHALL trigger a job run against the backend, surface success/error,
and close.

#### Scenario: Running a job
- WHEN the user confirms the run-job modal
- THEN `runJob` invokes the backend and the outcome is surfaced

Notes: `RunJob.vue` (2).

### Requirement: Test a job (REQ-EPJOBUI-004)

The test-job modal SHALL trigger a job dry-run against the backend, surface the
result, and close.

#### Scenario: Testing a job
- WHEN the user confirms the test-job modal
- THEN `testJob` invokes the backend dry-run and the result is surfaced

Notes: `TestJob.vue` (2).

### Requirement: Test a source (REQ-EPJOBUI-005)

The test-source modal SHALL issue a test call against the configured source, prettify
the JSON response for display, surface errors, and close.

#### Scenario: Testing a source
- WHEN the user confirms the test-source modal
- THEN `testSource` invokes the backend test call and `prettifyJson` formats the response

Notes: `TestSource.vue` (3).

