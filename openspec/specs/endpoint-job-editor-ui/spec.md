---
status: done
---

# endpoint-job-editor-ui Specification

## Purpose
Provides the editing and execution dialogs for endpoints and jobs. Users can edit an endpoint with register- and schema-aware selectors, configure a job's class, synchronization, and typed JSON arguments, and run or test-run a job or test a source directly against the backend with the response surfaced in the modal.

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

The Jobs index create/edit form SHALL expose every authorable job property — name,
description, job class, interval, the four scheduling flags (time sensitive, allow parallel
runs, enabled, single run), schedule-after, user id, log retention and error retention — in a
declared order, with the four flags laid out two-up. It SHALL present the job class as a
select over the Action classes the app ships, conditionally offer a Synchronization picker
that persists to `arguments.synchronizationId`, and serialise/parse JSON argument values.

Field order, labels, help text and the interval/log-retention create defaults SHALL come
from the `job` schema; the class option list, the flag grouping and the error-retention
prefill SHALL come from the page's `fieldOverrides`. The component SHALL NOT hardcode field
names other than for the synchronization conditional, whose target is a nested key no field
descriptor can address.

#### Scenario: Every authorable property is on the form

- WHEN the user opens "+ Add" on the Jobs index
- THEN the form renders name, description, job class, interval, the four scheduling flags,
  schedule-after, user id, log retention and error retention — in that order, taken from the
  schema's `order`, not alphabetically

#### Scenario: Order and grouping are declared, not coded

- WHEN a field's `order` changes in the schema, or its `group` changes in the manifest
- THEN the rendered sequence and the grid layout follow, with no change to the component
- AND fields sharing a `group` are coalesced only while they remain consecutive, so `order`
  stays the single source of truth for sequence

#### Scenario: Selecting a job class

- WHEN the user picks a job class
- THEN the class is recorded, and the Synchronization picker appears directly beneath the
  class field when the chosen class is `SynchronizationAction`

#### Scenario: The class select preserves an off-list value

- WHEN a job holds a job class that is not in the offered list — a seeded
  `OCA\Integriq\BackgroundJob\Example*Job`, or an Action class registered by another app
- THEN the select displays that stored value instead of reading as unset, and saving the job
  does not silently replace it

#### Scenario: Schedule-after round-trips as RFC 3339 with an offset

- WHEN the user picks a date and time for schedule-after
- THEN the value persists as `YYYY-MM-DDTHH:mm:ss±hh:mm` — the offset is required, because
  the backend rejects a bare `YYYY-MM-DDTHH:mm`
- AND reopening the job shows the same local time
- AND clearing the field persists null rather than an empty string

#### Scenario: Create prefills the interval and retention windows

- WHEN the user opens the form to create a job
- THEN interval and log retention prefill to 3600 and error retention to 86400
- AND opening an existing job shows that job's stored values instead

#### Scenario: JSON argument round-trips

- WHEN the user edits a JSON argument field
- THEN the value is serialised and parsed, malformed JSON is rejected without clobbering the
  stored value, and a half-typed string is preserved while editing

#### Scenario: The synchronization picker still writes arguments.synchronizationId

- WHEN the user picks a synchronization
- THEN it is written to `arguments.synchronizationId` — the key
  `SynchronizationAction::run()` reads at execution time — preserving every other argument
  the job carries, without mutating the existing arguments object
- AND clearing the pick removes the key rather than storing null

Notes: `JobFormFields.vue` + `jobDraft.js`; field configuration in
`lib/Settings/register.d/job-form-fields.json` and `pages[Jobs].config` of `src/manifest.json`.
Component-internal behaviour stays unit-level per this capability's `@e2e exclude` rationale
— the helpers are covered by `tests/vitest/jobDraft.spec.js`, which also pins the schema,
manifest and component against each other; the Add-modal surface stays covered by
`job-management` e2e.

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
