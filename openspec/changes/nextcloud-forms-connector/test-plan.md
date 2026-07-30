# Test Plan: nextcloud-forms-connector

## Test Cases

### TC-1: Forms app absent hides the source type in the editor
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-feature-detection--forms-app-absence-hides-the-type-entirely-req-001`
- **type**: functional
- **persona**: Priya (ZZP Developer/Integrator) — configures synchronizations directly
- **preconditions**: Forms app not installed on the dev instance
- **steps**: Open the synchronization editor, open the source-kind selector
- **expected result**: `nextcloud-form` is not present in the list
- **test command**: `/test-functional`

### TC-2: run against nextcloud-form fails cleanly when Forms is disabled
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-feature-detection--forms-app-absence-hides-the-type-entirely-req-001`
- **type**: api
- **preconditions**: A synchronization exists with `sourceType: nextcloud-form`; the Forms app is subsequently disabled
- **steps**: `POST .../synchronizations/{id}/run`
- **expected result**: run fails with a config-error log entry naming the missing Forms dependency; no outbound HTTP call to any Forms endpoint (verify via CallLog — zero new entries)
- **test command**: `/test-api`

### TC-3: an outbound mapping subscription fails cleanly when Forms is disabled
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-feature-detection--forms-app-absence-hides-the-type-entirely-req-001`
- **type**: api
- **preconditions**: `event_subscription` with `action.kind: 'mapping'` matching Forms submission events; Forms disabled
- **steps**: Trigger a matching event (or directly invoke dispatch in a test harness)
- **expected result**: `event_message.status = 'failed'`, `retryCount = 0` (config error, not retried)
- **test command**: `/test-api`

### TC-4: submissions are read and mapped as sync input
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002`
- **type**: api
- **preconditions**: Forms enabled; a form with 30 submissions; a synchronization with `sourceType: nextcloud-form`, `sourceConfig.formId` set
- **steps**: Run the synchronization
- **expected result**: 30 submissions fetched (paginated), each including `answers`; each passed through `MappingService`; 30 `SynchronizationContract`s created
- **test command**: `/test-api`

### TC-5: unchanged submission content produces no downstream write
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002`
- **type**: regression
- **preconditions**: A previously-synced submission, unchanged since last run
- **steps**: Re-run the synchronization
- **expected result**: `hashObject()` matches the contract's `sourceHash`; no target write for that submission
- **test command**: `/test-regression`

### TC-6: nextcloud-form is never selectable as a target type
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-nextcloud-form-as-a-synchronization-source-req-002`
- **type**: functional
- **preconditions**: Forms enabled
- **steps**: Open the synchronization editor's target-kind selector
- **expected result**: `nextcloud-form` is absent regardless of Forms' enabled state
- **test command**: `/test-functional`

### TC-7: resolution by numeric question id
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003`
- **type**: functional (PHPUnit — see tasks.md Task 9 for the unit-level equivalent)
- **preconditions**: A submission with answer `{questionId: 7, text: "Acme BV"}`
- **steps**: Resolve question reference `7`
- **expected result**: returns `"Acme BV"`
- **test command**: `/test-functional`

### TC-8: resolution by unambiguous question text
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003`
- **type**: functional
- **preconditions**: A form whose only question titled "Company name" has `id: 7`, and a matching answer
- **steps**: Resolve question reference `"Company name"`
- **expected result**: returns the answer's `text`
- **test command**: `/test-functional`

### TC-9: ambiguous question text is a hard config error
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003`
- **type**: functional
- **preconditions**: A form with two questions both titled "Comments" (`id: 12`, `id: 19`)
- **steps**: Resolve question reference `"Comments"`
- **expected result**: throws a config error naming both ids; no guess is made
- **test command**: `/test-functional`

### TC-10: a multiple-choice question resolves to an array
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-answer-by-question-resolution-and-type-coercion-req-003`
- **type**: functional
- **preconditions**: A `multiple`-type question `id: 4` with two selected-option answer rows
- **steps**: Resolve question reference `4`
- **expected result**: returns `["Red", "Blue"]`
- **test command**: `/test-functional`

### TC-11: a Forms submission event drives an external call via answer mapping
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004`
- **type**: api
- **preconditions**: `event_subscription` matching `com.nextcloud.forms.submission.created` with `action.kind: 'mapping'`, a `Mapping` referencing "Company name"/"Email", a `Source` pointed at a test HTTP sink
- **steps**: Submit a form (or directly persist a matching event in a test harness) and observe dispatch
- **expected result**: the full submission is fetched, answers resolved, `MappingService::executeMapping()` runs, `CallService::call()` POSTs the mapped result to the sink; message persisted `status='delivered'` on 2xx
- **test command**: `/test-api`

### TC-12: a resolution or mapping failure follows the standard retry/backoff machine
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004`
- **type**: api
- **preconditions**: Same subscription as TC-11, but the `Mapping` references an ambiguous question text
- **steps**: Trigger dispatch
- **expected result**: `status='failed'`, `retryCount` incremented, `nextAttempt` scheduled per backoff
- **test command**: `/test-api`

### TC-13: an unresolvable mappingId or sourceId fails without a Forms call
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-outbound-submission-to-call-mapping-dispatch-req-004`
- **type**: api
- **preconditions**: `action.mappingId` set to a non-existent id
- **steps**: Trigger dispatch
- **expected result**: `status='failed'` naming the unresolved mapping; zero CallLog entries (no Forms client call, no `CallService::call()`)
- **test command**: `/test-api`

### TC-14: form list reflects the configured identity's access
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005`
- **type**: api
- **preconditions**: A `Source` whose credential can see 3 of 8 forms
- **steps**: `GET .../synchronizations/forms-bridge/forms?sourceId=<id>`
- **expected result**: exactly the 3 accessible forms are returned
- **test command**: `/test-api`

### TC-15: question list includes type metadata for the mapping helper
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/nextcloud-forms-connector/spec.md#requirement-form-and-question-discovery-for-the-synchronizationrule-editor-req-005`
- **type**: api
- **preconditions**: A form with a `multiple`-type question and a `short`-type question
- **steps**: `GET .../synchronizations/forms-bridge/forms/{formId}/questions?sourceId=<id>`
- **expected result**: each question's `id`/`text`/`name`/`type` returned
- **test command**: `/test-api`

### TC-16: `nextcloud-form` source dispatch reaches the Forms adapter
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/synchronization-engine/spec.md#requirement-nextcloud-form-source-dispatch-req-016`
- **type**: regression
- **preconditions**: A synchronization with `sourceType: nextcloud-form`
- **steps**: Run `getAllObjectsFromSource()` (via a synchronization run)
- **expected result**: the Forms source adapter is invoked; returned submissions become the fetched objects
- **test command**: `/test-regression`

### TC-17: nextcloud-form target type still throws Unsupported target type
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/synchronization-engine/spec.md#requirement-nextcloud-form-source-dispatch-req-016`
- **type**: regression
- **preconditions**: A synchronization with `targetType: nextcloud-form`
- **steps**: Trigger a target write
- **expected result**: throws `Unsupported target type: nextcloud-form`
- **test command**: `/test-regression`

### TC-18: nextcloud-form kind is hidden when Forms is unavailable (editor)
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008`
- **type**: functional
- **preconditions**: Forms disabled
- **steps**: Open the source-kind selector
- **expected result**: `nextcloud-form` not offered
- **test command**: `/test-functional`

### TC-19: picking a Source populates the form list
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/sync-editor-ui/spec.md#requirement-form-picker-for-the-nextcloud-form-source-kind-req-syncui-008`
- **type**: functional
- **preconditions**: Forms enabled; `nextcloud-form` selected as source kind
- **steps**: Pick a `Source`
- **expected result**: the widget fetches and presents forms; choosing one sets `sourceConfig.formId`
- **test command**: `/test-functional`

### TC-20: field reference list shows question text, id, and type; ambiguous text flagged
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/sync-editor-ui/spec.md#requirement-field-mapping-helper-prefilled-from-form-questions-req-syncui-009`
- **type**: accessibility
- **preconditions**: A form with a `short`-type and a `multiple`-type question; a separate form with two questions both titled "Comments"
- **steps**: Select each form in turn and view the field-mapping helper
- **expected result**: questions listed with `text`/`id`/`type`; `multiple`-type visually flagged as array-valued; duplicate-text questions visually flagged as ambiguous with a warning
- **test command**: `/test-accessibility`

### TC-21: action.kind=mapping dispatches to dispatchMappingAction, not deliverMessage
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-may-additionally-support-a-mapping-kind-req-010`
- **type**: regression
- **preconditions**: Subscription with `action.kind: 'mapping'`
- **steps**: Dispatch a matching `event_message`
- **expected result**: `dispatchMappingAction()` invoked; `deliverMessage` not invoked
- **test command**: `/test-regression`

### TC-22: pre-existing action kinds (webhook/synchronization/job) are unaffected
- **spec_ref**: `openspec/changes/nextcloud-forms-connector/specs/events-cloudevents/spec.md#requirement-a-subscriptions-action-dispatch-may-additionally-support-a-mapping-kind-req-010`
- **type**: regression
- **preconditions**: Existing subscriptions covering each of the 3 pre-existing kinds (plus absent `action`)
- **steps**: Run the existing `events-cloudevents` REQ-008 test suite unmodified
- **expected result**: 100% pass, byte-identical behaviour
- **test command**: `/test-regression`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| `nextcloud-forms-connector` REQ-001 (feature detection) | TC-1, TC-2, TC-3 |
| `nextcloud-forms-connector` REQ-002 (sync source) | TC-4, TC-5, TC-6 |
| `nextcloud-forms-connector` REQ-003 (answer resolution/coercion) | TC-7, TC-8, TC-9, TC-10 |
| `nextcloud-forms-connector` REQ-004 (outbound mapping dispatch) | TC-11, TC-12, TC-13 |
| `nextcloud-forms-connector` REQ-005 (discovery endpoints) | TC-14, TC-15 |
| `synchronization-engine` REQ-016 (source dispatch delta) | TC-16, TC-17 |
| `sync-editor-ui` REQ-SYNCUI-008 (form picker) | TC-18, TC-19 |
| `sync-editor-ui` REQ-SYNCUI-009 (field-mapping helper) | TC-20 |
| `events-cloudevents` REQ-010 (mapping action kind) | TC-21, TC-22 |

## Out of Scope

- Live-instance verification of the Forms OCS base path
  (`index.php/apps/forms/api/v3/...` vs the OCS-enveloped
  `ocs/v2.php/...` form) and exact `FormSubmittedEvent` payload shape —
  both are TENTATIVE per design.md/discovery.md pending a Nextcloud
  instance with the `forms` app installed; tracked as a follow-up
  verification task, not a blocking test case here (mirrors the same
  accepted gap for `nextcloud-event-triggers` REQ-003/REQ-004).
- Load/performance testing of the Forms submissions pagination loop —
  `nextcloud-table` (REQ-002's precedent) has no dedicated performance test
  case either; the existing `DEFAULT_MAX_PAGES`-style safety cap is
  considered sufficient risk coverage for this change's scope.
