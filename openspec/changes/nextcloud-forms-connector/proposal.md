# Proposal: nextcloud-forms-connector

## Summary

Nextcloud Forms submissions are a common automation trigger, and the merged
`nextcloud-event-hub` change already normalizes a Forms submission into a
CloudEvent (`nextcloud-event-triggers` REQ-004), feature-detected via
`IAppManager`. That trigger's event payload does not carry the submission's
answers (verified against HEAD — see design.md). This change adds a
first-class Forms connector: (1) an outbound path that, on a Forms
submission event, resolves the full submission's answers by question and
maps them to an external system call via the existing `MappingService` +
`CallService`, and (2) an inbound `nextcloud-form` synchronization source
type that reads a form's submissions into a register/table on a schedule,
following the `tables-bridge` soft-dependency pattern exactly (an interface
+ OCS client behind `IAppManager` feature detection, never a direct
`OCA\Forms\*` reference).

## Motivation

Today, acting on a Forms submission requires a hand-rolled webhook consumer
on the receiving end that already knows the Forms answer shape (numeric
`questionId`-keyed, no field names) and reassembles it. Every other
NC-native connector in this app (Tables) gets a first-class sync
source/target with column-aware mapping; Forms has neither. Municipal and
MKB users file intake forms (contact requests, complaint forms, quote
requests) that should become cases/leads/rows in an external or OR-backed
system without a custom integration per form. This change closes that gap
using entirely existing machinery (event subscriptions, Mapping, CallService,
the synchronization engine) — no new external dependency.

## Affected Projects

- [x] Project: `integriq` — new `FormsClientInterface` + OCS client,
  `nextcloud-form` synchronization source type, Forms-answer mapping/resolution
  helper, a new `event_subscription.action.kind = 'mapping'` outbound dispatch
  path, and sync-editor-ui field-mapping helper prefilled from a form's
  questions.

## Scope

### In Scope

1. **Outbound (submission → external call):** a `FormsAnswerResolver` that,
   given a form's `questionId`→`text` index and a submission's raw answers,
   resolves answers by question id or question text (case-sensitive exact
   match), coerces each answer's value per the question's declared type, and
   fails hard (never guesses) on an ambiguous question-text reference. A new
   `event_subscription.action.kind = 'mapping'` dispatch path in
   `EventService` that — reusing the existing trigger/subscription/retry
   machinery unchanged — fetches the full submission (with answers) via
   `FormsClientInterface`, resolves answers, runs `MappingService::executeMapping()`,
   and performs the external call via `CallService::call()`.
2. **Inbound (`nextcloud-form` sync source):** `sourceType: nextcloud-form`
   reads a form's submissions page-by-page via `FormsClientInterface` and
   feeds each into the existing mapping/transformation pipeline exactly like
   `nextcloud-table` (`tables-bridge` REQ-002) does today. No
   `targetType: nextcloud-form` — see Out of Scope.
3. **Feature detection:** `FormsClientInterface`/adapter feature-detect the
   `forms` app via `IAppManager` only; `nextcloud-form` is omitted from the
   editor's available source-type list and a configured synchronization
   fails cleanly (config error, no HTTP attempt) when Forms is absent.
4. **Editor UI:** a field-mapping helper in the sync/rule editor, prefilled
   from a form's questions (id, text, type), mirroring the existing
   `tables-bridge` column-mapping helper.
5. Unit tests for answer-by-question resolution (id/text, coercion,
   ambiguity) and submission→call mapping; integration tests behind a mocked
   `FormsClientInterface`.

### Out of Scope

- Writing submissions into Forms from external data. The Forms OCS API does
  expose `POST .../submissions`, but per the original brief this direction
  is explicitly excluded, and there is no user story motivating it in this
  change — deferred to a follow-up if a concrete need emerges.
- Building/editing forms in Integriq.
- Changing `NextcloudFormsEventListener` or `nextcloud-event-triggers`
  REQ-004's event shape — see design.md for why the outbound path fetches
  the submission independently rather than relying on the event payload.
- A generic reusable `action.kind = 'mapping'` for non-Forms sources — this
  change introduces the dispatch path and proves it with Forms; broadening
  it to an arbitrary source is a future generalization, not required here.

## Approach

Mirror the `tables-bridge` change end to end: a narrow `FormsClientInterface`
domain seam, one concrete OCS-backed client, feature detection via
`IAppManager` only, and dispatch branches added to `SynchronizationService`
for the read side. For the outbound side, add one new `action.kind` value
to the existing event-subscription action-dispatch switch
(`events-cloudevents` REQ-008 sibling), so a Forms submission subscription
can declare `action: {kind: 'mapping', mappingId, sourceId, endpoint}` instead
of `webhook`/`synchronization`/`job`. Details in design.md.

## New Dependencies

None. Reuses `CallService`, `MappingService`, `EventService`, the existing
`Source` (register `openconnector`, schema `source`) object for the Forms
OCS endpoint's credentials, and the ADR-005 Source→Synchronization→Contract
triad.

## Impact

- `lib/Service/Forms/` (new): `FormsClientInterface`, `FormsOcsClient`,
  `FormsAnswerResolver`, `FormsSyncAdapter`.
- `lib/Service/SynchronizationService.php`: new `nextcloud-form` source
  dispatch branches (source fetch only — no target branch).
- `lib/Service/EventService.php`: new `dispatchMappingAction()` branch in
  the existing action-dispatch switch (`attemptDelivery()`).
- `lib/Controller/` (new): `FormsBridgeController` (status + form/question
  discovery endpoints for the editor, mirroring `TablesBridgeController`).
- `src/` (sync-editor-ui): a Forms field-mapping helper component consuming
  the new discovery endpoints.
- No changes to `NextcloudFormsEventListener` or `nextcloud-event-triggers`
  REQ-004.

## Cross-Project Dependencies

None outside `integriq`. Depends on the `forms` app being installed
for the feature to activate (soft dependency, degrades to hidden/disabled
otherwise) — same posture as `tables-bridge`'s `tables` dependency.

## Risks

### Risk 1: Forms' OCS submission-answer shape was verified against public upstream source, not a live instance

**Severity:** Medium — **Mitigation:** Verified against `nextcloud/forms`
`main` branch source (`ApiController`, `ResponseDefinitions`,
`Db/Submission.php`, `FormsService::notifyNewSubmission()`) during this
change (design.md Decision 1/2). The exact route base path
(`/ocs/v2.php/apps/forms/api/v3/...` vs `/index.php/apps/forms/api/v3/...`)
and OCS envelope unwrapping are flagged TENTATIVE pending a live instance
with the `forms` app installed, mirroring the same caveat already accepted
for `nextcloud-event-triggers` REQ-004's event-class verification. Feature
detection means an incorrect assumption fails closed (config error), not
silently.

### Risk 2: Forms question text is not guaranteed unique within a form

**Severity:** Low — **Mitigation:** This is treated as a hard config error
at resolution time (never a guess), matching the precedent already shipped
in `tables-bridge` REQ-001 for ambiguous column titles.

## Rollback Strategy

Revert the `FormsClientInterface`/adapter/controller files, the
`nextcloud-form` dispatch branches in `SynchronizationService`, and the
`action.kind = 'mapping'` branch in `EventService`. Because dispatch is
feature-detected and additive (an unrecognised `action.kind` was already a
handled configuration-error path before this change), reverting leaves no
dangling state: existing `nextcloud-table`/webhook/synchronization/job
synchronizations and subscriptions are untouched. Any `synchronization`
object left configured with `sourceType: nextcloud-form`, or any
`event_subscription` left with `action.kind: 'mapping'`, will fail cleanly
post-revert exactly as an unrecognised type does today (REQ-001/REQ-008
default branches).

## Open Questions

- Should `action.kind = 'mapping'` also support a plain register/schema
  write (via `ObjectService`) as an alternative to `CallService::call()`,
  for the "create a lead in OpenRegister rather than an external API" case?
  Deferred — the brief's concrete example is an external API call;
  answering "yes" would fold in `SynchronizationService`'s OR-target write
  path and is a larger surface than this change's scope. Flagged for a
  follow-up change if requested.
