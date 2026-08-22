# nextcloud-forms-connector Specification

**Status**: planned
**Scope**: integriq
**OpenSpec changes**:
- nextcloud-forms-connector (this change)

## Purpose

Lets Integriq read Nextcloud Forms submissions as a synchronization
source, and map a Forms submission's answers (resolved by question, not raw
numeric position) into an outbound call to an external system on the
already-merged Forms submission trigger (`nextcloud-event-triggers`
REQ-004), through Forms' own public REST API only (never by reaching into
Forms' internal PHP classes), reusing the existing Source →
Synchronization → SynchronizationContract triad, `CallService` transport,
`MappingService` transformation, and `EventService` subscription-dispatch
machinery. Forms is a soft (feature-detected) runtime dependency: absent or
disabled, `nextcloud-form` simply does not exist as a source option, and
`action.kind: 'mapping'` subscriptions fail cleanly rather than attempting
any Forms call.

## ADDED Requirements

### Requirement: Feature detection — Forms app absence hides the type entirely (REQ-001)

The system MUST feature-detect the Forms app via
`IAppManager::isEnabledForUser('forms', ...)` only — never via a direct
reference to any `OCA\Forms\*` class and never via an OCS capabilities
round-trip. When disabled or absent: `nextcloud-form` MUST be omitted from
any editor-facing list of available source types, and a synchronization
already configured with `sourceType: nextcloud-form` MUST fail its run with
a 409-class config error naming the missing dependency rather than
attempting any HTTP call to a Forms endpoint. An `event_subscription` with
`action.kind: 'mapping'` MUST likewise fail its dispatch with a config error
(not a retryable failure — REQ-004) when Forms is disabled, without
attempting any HTTP call to a Forms endpoint.

#### Scenario: Forms app absent hides the source type in the editor

- **GIVEN** the Forms app is not installed on this Nextcloud instance
- **WHEN** the synchronization editor requests the list of available source
  types
- **THEN** `nextcloud-form` is not present in the returned list

#### Scenario: run against nextcloud-form fails cleanly when Forms is disabled

- **GIVEN** a synchronization configured with `sourceType: nextcloud-form`
  AND the Forms app has since been disabled
- **WHEN** the synchronization runs
- **THEN** it fails with a config-error log entry stating the Forms app is
  not enabled
- **AND** no HTTP call is attempted against any Forms endpoint

#### Scenario: an outbound mapping subscription fails cleanly when Forms is disabled

- **GIVEN** an `event_subscription` with `action.kind: 'mapping'` matching
  a `com.nextcloud.forms.submission.created` event, and the Forms app
  disabled
- **WHEN** the matching event is dispatched
- **THEN** the `event_message` is persisted `status='failed'` with a
  config-error naming the missing Forms dependency
- **AND** `retryCount` remains `0` (a config error, not a transient
  failure — mirrors `events-cloudevents` REQ-008's unrecognised-`kind`
  posture)

### Requirement: Nextcloud Form as a synchronization source (REQ-002)

The system SHALL support `sourceType: nextcloud-form`. `sourceId` SHALL
reference a `Source` object (register `openconnector`, schema `source`)
whose `location`/`authentication` are used to reach the target Nextcloud
instance's Forms API; `sourceConfig` SHALL carry `formId` (required,
integer). The system SHALL read a form's submissions page-by-page
(`GET .../forms/{formId}/submissions`, `limit`/`offset`-paginated) and feed
each submission (including its `answers`) into the existing mapping/
transformation pipeline exactly as any other source's fetched objects. The
Forms submission id (`Submission.id`) SHALL be used as the origin id via
the existing `idPosition` default (`id`), with no adapter-specific override
required. Change detection SHALL use the existing order-independent
`hashObject()` primitive against each submission's full fetched shape
(including `answers`). This requirement covers the source (read) direction
only — the system SHALL NOT support `targetType: nextcloud-form` (writing
submissions into Forms is out of scope for this capability).

#### Scenario: submissions are read and mapped as sync input

- **GIVEN** a synchronization with `sourceType: nextcloud-form` pointed at
  a form with 30 submissions and `sourceConfig.formId` set
- **WHEN** the synchronization runs
- **THEN** all 30 submissions are fetched (paginated as needed), each
  including its `answers` array
- **AND** each submission is passed through `MappingService`, exactly as
  an `api`-sourced object would be

#### Scenario: unchanged submission content produces no downstream write

- **GIVEN** a previously-synced submission whose content is unchanged since
  the last run
- **WHEN** the synchronization runs again
- **THEN** `hashObject()` on the submission's fetched shape matches the
  contract's `sourceHash`, and no downstream target write occurs for that
  submission

#### Scenario: nextcloud-form is never selectable as a target type

- **GIVEN** the synchronization editor's target-type selector
- **WHEN** it renders, regardless of whether the Forms app is enabled
- **THEN** `nextcloud-form` is not offered as a target-type option

### Requirement: Answer-by-question resolution and type coercion (REQ-003)

The system MUST resolve a question reference — supplied as either a
numeric question id or a question-text string — to that question's answer
value(s), given a form's fetched `questions` (each `{id, text, name, type}`)
and a submission's fetched `answers` (each `{id, questionId, text}`, zero or
more rows sharing a `questionId` for `multiple`/`multiple_unique`-type
questions):

- A numeric reference MUST match directly against `answers[].questionId`
  (always unambiguous).
- A text reference MUST be resolved via the form's `questions[].text`
  index. Exactly one question matching that text resolves via the id path
  above. Zero questions matching resolves to `null`. **Two or more
  questions sharing that exact text MUST fail with a config error naming
  the ambiguous text and the matching question ids — the system MUST NOT
  guess by picking the first match.**
- A `multiple`/`multiple_unique`-type question MUST resolve to an array of
  every matching answer row's `text` (zero, one, or many entries). Every
  other question type MUST resolve to a single scalar: the sole matching
  row's `text`, or `null` when the question was unanswered.

#### Scenario: resolution by numeric question id

- **GIVEN** a submission with an answer `{questionId: 7, text: "Acme BV"}`
- **WHEN** the resolver is asked to resolve question reference `7`
- **THEN** it returns `"Acme BV"`

#### Scenario: resolution by unambiguous question text

- **GIVEN** a form whose only question with `text: "Company name"` has
  `id: 7`, and a submission with an answer `{questionId: 7, text: "Acme BV"}`
- **WHEN** the resolver is asked to resolve question reference `"Company name"`
- **THEN** it returns `"Acme BV"`

#### Scenario: ambiguous question text is a hard config error, never a guess

- **GIVEN** a form with two questions both having `text: "Comments"`
  (`id: 12` and `id: 19`)
- **WHEN** the resolver is asked to resolve question reference `"Comments"`
- **THEN** the system SHALL fail with a config-error naming the ambiguous
  text and both matching question ids (`12`, `19`)
- **AND** SHALL NOT guess by picking either question's answer

#### Scenario: a multiple-choice question resolves to an array

- **GIVEN** a `multiple`-type question `id: 4` with two selected-option
  answer rows: `{questionId: 4, text: "Red"}` and `{questionId: 4, text: "Blue"}`
- **WHEN** the resolver resolves question reference `4`
- **THEN** it returns `["Red", "Blue"]`

#### Scenario: an unanswered optional question resolves to null

- **GIVEN** a form question `id: 9` with no matching answer row in the
  submission
- **WHEN** the resolver resolves question reference `9`
- **THEN** it returns `null`

### Requirement: Outbound submission-to-call mapping dispatch (REQ-004)

The system MUST, for `action.kind: 'mapping'` in `EventService`'s subscription action-dispatch switch (`events-cloudevents` REQ-008/REQ-010), resolve
`action.mappingId` to a `Mapping` object and `action.sourceId` to a `Source`
object (same not-found handling — persist the message `status='failed'`
with a retryable error — as the existing `synchronization`/`job` branches).
When resolved, the system MUST: fetch the full submission (with `answers`)
via the Forms client using `event.data.formId` and
`event.data.submission.id` (the merged trigger's event payload alone does
not carry `answers` — this requirement's dispatch MUST NOT assume it does),
fetch the form's `questions`, resolve every answer reference named in the
`Mapping` via REQ-003, run `MappingService::executeMapping()` against the
resolved answers, and call `CallService::call()` against the resolved
`Source` and `action.endpoint` (`action.method`, default `POST`) with the
mapped result as the request body. A thrown exception from any step MUST be
treated as a REQ-002 (`events-cloudevents`)-style failure-path attempt
(`status='failed'`, retry/backoff applies); a successful call MUST be
treated as a success-path attempt (`status='delivered'`). This dispatch
kind MUST NOT invoke `deliverMessage` (no direct webhook HTTP request is
made) and MUST NOT apply `webhook-signing`.

#### Scenario: a Forms submission event drives an external call via answer mapping

- **GIVEN** an `event_subscription` matching
  `com.nextcloud.forms.submission.created` with
  `action = {kind: 'mapping', mappingId: '<uuid>', sourceId: '<uuid>',
  endpoint: '/leads'}`, and a `Mapping` that references question text
  `"Company name"` and `"Email"`
- **WHEN** a form submission fires the trigger and `EventService` dispatches
  the resulting `event_message`
- **THEN** the full submission is fetched via the Forms client, the
  referenced answers are resolved (REQ-003), `MappingService::executeMapping()`
  transforms them into the target shape, and `CallService::call()` POSTs
  the result to the resolved `Source`'s `/leads` endpoint
- **AND** on a 2xx response the message is persisted `status='delivered'`

#### Scenario: a resolution or mapping failure follows the standard retry/backoff machine

- **GIVEN** the same subscription, but the referenced `Mapping` names an
  ambiguous question text (REQ-003)
- **WHEN** the dispatch runs
- **THEN** the message is persisted `status='failed'`, `retryCount`
  incremented, and `nextAttempt` scheduled per the standard backoff — a
  data-shape problem in a specific submission does not permanently
  misconfigure the subscription, so it remains retryable exactly like a
  webhook delivery failure

#### Scenario: an unresolvable mappingId or sourceId fails without a Forms call

- **GIVEN** a subscription with `action = {kind: 'mapping', mappingId:
  'does-not-exist', sourceId: '<uuid>', endpoint: '/leads'}`
- **WHEN** the dispatch runs
- **THEN** the message is persisted `status='failed'` with an error naming
  the unresolved mapping
- **AND** no Forms client call and no `CallService::call()` is attempted

### Requirement: Form and question discovery for the synchronization/rule editor (REQ-005)

The system SHALL expose read-only endpoints that, given a `Source` id, list
the forms and — given a form id — the questions (id, text, name, type)
accessible to that Source's configured identity, gated by the same
feature-detection guard as REQ-001, so the synchronization editor can build
a form picker and a field-mapping helper without the frontend talking to
Forms directly.

#### Scenario: form list reflects the configured identity's access

- **GIVEN** a `Source` whose credential can see 3 of the 8 forms that
  otherwise exist on the target instance
- **WHEN** the editor calls the form-list endpoint with that Source's id
- **THEN** exactly the 3 accessible forms are returned

#### Scenario: question list includes type metadata for the mapping helper

- **GIVEN** a form with a `multiple`-type question and a `short`-type
  question
- **WHEN** the editor calls the question-list endpoint for that form
- **THEN** each question's `id`/`text`/`name`/`type` is returned, sufficient
  for the mapping helper to indicate array-vs-scalar resolution (REQ-003)
