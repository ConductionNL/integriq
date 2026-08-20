# Design: nextcloud-forms-connector

## Architecture Overview

Two independent data paths, one shared client:

```
Outbound (submission → external call)
  Forms submission
    -> FormSubmittedEvent (Forms app, unchanged)
    -> NextcloudFormsEventListener (MERGED, unchanged) -> event { data.formId, data.submission:{id,formId,userId,timestamp} }
    -> EventService::processEvent -> matching event_subscription with action.kind='mapping'
    -> EventService::dispatchMappingAction()  [NEW]
         -> FormsClientInterface::getSubmission(source, formId, submissionId)  [fetches the answers the event lacks — discovery.md Finding 3]
         -> FormsClientInterface::getForm(source, formId)  [question id->text index]
         -> FormsAnswerResolver::resolve()  [NEW — answer-by-question, coercion, ambiguity guard]
         -> MappingService::executeMapping()  [EXISTING]
         -> CallService::call()  [EXISTING — external system]

Inbound (nextcloud-form sync source)
  SynchronizationService::getAllObjectsFromSource()
    -> case 'nextcloud-form':  [NEW]
         -> FormsSyncAdapter::fetchAllSubmissions(source, formId)  [NEW]
              -> FormsClientInterface::listSubmissions() (paginated)  [NEW]
much like tables-bridge's `nextcloud-table` branch feeds getAllObjectsFromArray().
```

`FormsClientInterface` is the single seam both paths go through — mirrors
`TablesClientInterface` exactly (design symmetry with the already-shipped
`tables-bridge` change), including taking an `ObjectEntity $source` (register
`openconnector`, schema `source`) on every call, so the Forms endpoint being
reached (local instance today, any NC instance with Forms tomorrow) is
config, not code.

## Goals / Non-Goals

**Goals:** answer-by-question resolution (id or text) with type-aware
coercion and a hard-fail on ambiguous text references; a `nextcloud-form`
sync source; feature detection that hides/fails cleanly when `forms` is
absent; an editor field-mapping helper prefilled from a form's questions.

**Non-Goals:** writing to Forms (out of scope per proposal.md); a generic
non-Forms `action.kind='mapping'` target resolver (this change proves the
mechanism with Forms only); changing `NextcloudFormsEventListener` or
`nextcloud-event-triggers` REQ-004's event shape (see Decision 4).

## Decisions

### Decision 1: One `FormsClientInterface`, `ObjectEntity $source`-scoped, for both directions

**Choice:** `FormsClientInterface` (`lib/Service/Forms/`) declares
`getForm(ObjectEntity $source, int $formId): array`,
`getSubmission(ObjectEntity $source, int $formId, int $submissionId): array`,
`listSubmissions(ObjectEntity $source, int $formId, int $page, int $pageSize): array`.
The concrete `FormsOcsClient` speaks the `index.php/apps/forms/api/v3/*`
REST surface over `CallService::call()` — same transport, same
`CallLog`/rate-limit/credential-broker inheritance `TablesOcsClient` gets
"for free" (its own docblock's phrase), same TENTATIVE-base-path caveat
(discovery.md Finding 5).

**Alternatives considered:**
- *Two separate clients (one per direction).* Rejected — both directions
  need `getForm()`'s question list (outbound: text resolution + ambiguity
  check; inbound: none directly, but a future column-aware mapping helper
  would). One client avoids two independent TENTATIVE-base-path
  assumptions drifting apart.
- *In-process call to `OCA\Forms\Controller\ApiController` via the DI
  container* (skip the HTTP round-trip since Forms is typically the same
  instance). Rejected — violates the constraint carried over from
  `tables-bridge` Decision 3 and `HealthController`'s precedent: **never a
  direct `OCA\Forms\*` reference**, not even a feature-detected one. A
  compile-time `use OCA\Forms\Controller\ApiController` would tie this
  class's autoloadability to Forms being present, and (unlike the already-
  merged `NextcloudFormsEventListener`, which only references
  `OCA\Forms\Events\FormSubmittedEvent` inside a class that is itself
  registered only when Forms is enabled) `FormsClientInterface`
  implementations are eagerly bound in the DI container at every request,
  so this would break autoloading on every instance without Forms
  installed. HTTP-over-`CallService` has no such coupling.

### Decision 2: The outbound path fetches the submission independently — it does not trust the event payload

Per discovery.md Finding 3, `event.data.submission` (as persisted by the
already-merged `NextcloudFormsEventListener`) is `{id, formId, userId,
timestamp}` — no answers. `EventService::dispatchMappingAction()` MUST call
`FormsClientInterface::getSubmission($source, $formId, $submissionId)` using
the `formId`/`submission.id` already in the event payload to obtain
`answers`. This is a live HTTP call inside the delivery-dispatch path
(synchronous on first attempt, or from the retry sweep — same execution
contexts `dispatchSynchronizationAction()`/`dispatchJobAction()` already run
in, so this is not a new concurrency/session concern).

**Alternative considered:** extend `NextcloudFormsEventListener` to also
persist `answers`/`form.questions` into the event payload (both are
available in-process at listener time via `$event->getWebhookSerializable()`
— `FormSubmittedEvent` overrides `getWebhookSerializable()` to include
`'form' => $this->form->read()`, and `Form::read()` includes `questions`,
though `$this->submission->read()`'s answer-less shape would still need a
separate `AnswerMapper` lookup inside the listener to add `answers`).
Rejected for this change: it would modify an already-merged, independently-
specified requirement (`nextcloud-event-triggers` REQ-004) whose event-class
verification is itself still flagged TENTATIVE — bundling a payload-shape
change into a different capability's already-shipped spec is exactly the
kind of fork the proposal commits not to do. Routing around it via an
independent fetch keeps this change's blast radius to its own new files.

### Decision 3: Answer-by-question resolution — id first, text second, ambiguity is a hard error

`FormsAnswerResolver::resolve(array $form, array $submission, string
$questionRef): array|string|null`:

1. If `$questionRef` is numeric (or explicitly typed as an id in the
   mapping config), match `answers[].questionId === (int) $questionRef`
   directly — always unambiguous (`questionId` is the Forms DB primary key).
2. Otherwise, treat `$questionRef` as question **text**: build an index of
   `form.questions[].text -> [ids...]` from the fetched form. Exactly one
   id -> resolve via step 1's logic against that id. Zero ids -> `null`
   (question not found; the mapping's own null-handling applies, matching
   Mapping's existing behaviour for absent source fields). **Two or more
   ids sharing that text -> throw a config error naming the ambiguous text
   and the matching question ids** — never guess, mirroring `tables-bridge`
   REQ-001's ambiguous-column-title precedent exactly (same failure
   posture, same rationale: a config error here is a data-integrity bug the
   admin needs to fix, not a runtime condition to paper over).
3. Coercion: a `multiple`/`multiple_unique`-type question (Constants,
   discovery.md Finding 2) resolves to an **array** of every matching
   answer row's `text` (zero, one, or many rows can share a `questionId`);
   every other question type resolves to a **single scalar** — the sole
   matching row's `text`, or `null` if unanswered. `date`/`datetime`/`time`
   types are passed through as Forms' own ISO-ish string representation
   unchanged (no further coercion — downstream `MappingService`/Twig
   already has established idioms for date reformatting; duplicating that
   here would be exactly the kind of utility-duplication ADR-011 forbids).

**Alternative considered:** silently take the first matching answer /
first matching question-text id, like a naive `array_search`. Rejected —
this is precisely the "guess instead of fail" anti-pattern already
identified and rejected in `tables-bridge` REQ-001; consistency across the
two connectors matters more than convenience here, and both are hit rarely
enough (a genuinely misconfigured mapping) that a hard, loud failure is the
right default.

### Decision 4: Outbound reuse is `EventService`'s action-dispatch switch, not the endpoint rule pipeline

The proposal's brief framed this as "reuse... the rule pipeline"; the
verified reuse target is `EventService::attemptDelivery()`'s existing
three-way `action.kind` switch (`events-cloudevents` REQ-008 —
`webhook`/`synchronization`/`job`), not `EndpointService::processRules()`
(`rule-pipeline` capability). The endpoint rule pipeline runs **inside an
inbound HTTP request hitting an `Endpoint`** — there is no inbound request
here (a Forms submission fires a CloudEvent, not an Endpoint call), so that
pipeline has no entry point to hook. `attemptDelivery()`'s switch is the
actual mechanism the merged Forms trigger already flows through today (as
`kind='webhook'`, the default), and it already reuses `MappingService`
(indirectly, since `dispatchSynchronizationAction` eventually would) and
`CallService` (via `deliverMessage`'s webhook path) elsewhere in the same
switch — adding a fourth `kind='mapping'` branch there is the minimal,
consistent extension. This is a deliberate, documented deviation from the
brief's literal wording, per this change's remit to follow verified code
over the brief where they differ.

`dispatchMappingAction(ObjectEntity $message, array $subscriptionData,
array $action): bool` (`action = {kind: 'mapping', mappingId, sourceId,
endpoint, method?}`) resolves the `Mapping` (OR object, register
`openconnector`, schema `mapping`) and target `Source` by id (same
not-found -> `recordFailure('...not found')` retryable-failure posture as
`dispatchSynchronizationAction`/`dispatchJobAction`), fetches the
submission/form via `FormsClientInterface`, resolves answers
(`FormsAnswerResolver`), runs `MappingService::executeMapping($mapping,
$resolvedAnswers)`, and calls `CallService::call($source, $action['endpoint'],
$action['method'] ?? 'POST', ['json' => $mappedResult])`. Success/failure
bookkeeping (`recordDeliverySuccess`/`recordFailure`) is identical to the
sibling `synchronization`/`job` branches, so retry/backoff/dead-letter/
replay (`dead-letter-replay`) all apply unchanged — REQ-008's existing
"an unrecognised `action.kind` fails once without entering the retry loop"
behaviour is preserved for any `kind` still outside `{webhook,
synchronization, job, mapping}`.

**Constraint:** `dispatchMappingAction` is only meaningful for
Forms-sourced events in this change (it calls `FormsClientInterface`
unconditionally) — a subscription with `action.kind='mapping'` on a
non-Forms event type (e.g. `com.nextcloud.files.node.created`) will fail
with a config error (`event.data.formId`/`submission.id` absent). This is
accepted per the proposal's explicit non-goal (a generic mapping dispatcher
is future work); the failure mode is a clean, retryable-vs-config-error
distinction consistent with the rest of REQ-008, not a crash.

### Decision 5: `nextcloud-form` sync source only — no target branch, no deletion-guard interaction

Mirrors `tables-bridge` REQ-002 exactly for the read side:
`sourceConfig.formId` (required, int). `FormsSyncAdapter::fetchAllSubmissions()`
pages via `FormsClientInterface::listSubmissions()` (`limit`/`offset`,
same best-effort-pagination caveat `TablesOcsClient::listRows()` carries,
since the Forms `submissions` endpoint's pagination contract is not
schema-guaranteed either — discovery.md), feeding the existing
`getOriginId()`/`hashObject()`/mapping pipeline unchanged. Submission `id`
is the origin id via the existing default `idPosition` (`id`) — same free
ride `tables-bridge` REQ-002 documented for Tables row ids.

No `targetType: nextcloud-form` branch is added (proposal.md Out of
Scope), so `nextcloud-form` never participates in `updateTarget()` or
`deleteInvalidObjects()`'s target-side dispatch — only the source-fetch
`switch` in `getAllObjectsFromSource()` gains a case, identical in shape to
`synchronization-engine` REQ-014's `nextcloud-table` **source** branch but
with no accompanying target/deletion branch.

### Decision 6: Feature detection — `IAppManager::isEnabledForUser('forms', ...)` only

`FormsSyncAdapter::isEnabled()`/`assertEnabled()` mirror
`TablesSyncAdapter`'s methods exactly (same method names, same
`IAppManager` call shape, same `FormsFeatureDisabledException` ->
409-class-config-error mapping in the new `FormsBridgeController`). Editor
type lists omit `nextcloud-form` when disabled; a synchronization already
configured with `sourceType: nextcloud-form` fails its run with a
config-error log entry naming the missing dependency, never attempting an
HTTP call — identical posture to `tables-bridge` REQ-004.

### Decision 7: New `FormsBridgeController` discovery endpoints, admin-only by default (unseeded action)

`GET .../synchronizations/forms-bridge/status` (`{enabled: bool}`),
`GET .../synchronizations/forms-bridge/forms?sourceId=` (list forms
accessible to the Source's identity), `GET
.../synchronizations/forms-bridge/forms/{formId}/questions?sourceId=`
(question id/text/type list for the mapping helper) — same three-endpoint
shape as `TablesBridgeController`. Discovery endpoints (`forms`,
`questions`) call `ActionAuthService::requireAction($user,
'synchronization.formsBridge.discover')`, matching
`TablesBridgeController`'s `synchronization.tablesBridge.discover` call.
Neither action is seeded into `lib/actions.seed.json` (verified:
`tablesBridge.discover` isn't seeded either) — both fall back to the
matrix's documented `["admin"]` default for any unseeded action
(`ActionAuthService::getAllowedGroups`), so this is intentionally
consistent with the existing Tables discovery endpoints' posture, not an
oversight.

### Decision 8: `sync-editor-ui` field-mapping helper — same shape as the column-mapping helper, one property difference

`SyncConfigWidget.vue` gains a `nextcloud-form` kind (hidden unless
`forms-bridge/status` reports `enabled: true`), a form picker (`GET
.../forms-bridge/forms`) storing `formId` into `sourceConfig.formId`, and
a field-mapping helper (`GET .../forms-bridge/forms/{formId}/questions`)
listing each question's `text`/`type`, letting the user pick a mapping
output field per question — mirrors `sync-editor-ui` REQ-SYNCUI-007's
column-mapping helper with `column.title`/`column.type` replaced by
`question.text`/`question.type`. Because there is no `nextcloud-form`
*target* (Decision 5), this helper is read-only labelling to help the user
write mapping expressions by hand (the mapping picker itself,
REQ-SYNCUI-003, already exists) — it does not itself write `columnMapping`-
style config the way the Tables helper does, since there is no per-column
write payload to key.

## Nextcloud Integration

- **Controllers:** `FormsBridgeController` (new) — `#[NoAdminRequired]
  #[NoCSRFRequired]`, mirrors `TablesBridgeController` method-for-method.
- **Services:** `FormsClientInterface` / `FormsOcsClient` / `FormsSyncAdapter`
  / `FormsAnswerResolver` (new, `lib/Service/Forms/`); `SynchronizationService`
  (new `nextcloud-form` source-dispatch case); `EventService` (new
  `dispatchMappingAction()` branch in `attemptDelivery()`'s switch).
- **DI wiring:** `Application.php::register()` binds `FormsClientInterface`
  -> `FormsOcsClient`, identical shape to the existing
  `TablesClientInterface` -> `TablesOcsClient` binding (line ~358 at HEAD).
  No conditional/feature-detected binding — the client class itself has no
  `OCA\Forms\*` reference (Decision 1), so it is always safely constructible;
  only *usage* is feature-detected (`FormsSyncAdapter::assertEnabled()`).
- **Events/Hooks:** none new — `NextcloudFormsEventListener` (merged) is
  unchanged (Decision 2/4); this change only adds a new branch to
  `EventService`'s existing dispatch switch, not a new listener.
- **Routes:** `appinfo/routes.php` gains `formsBridge#status`,
  `formsBridge#forms`, `formsBridge#questions` — same three-line shape as
  the existing `tablesBridge#*` entries.

## Security Considerations

- `FormsBridgeController`'s discovery endpoints follow
  `TablesBridgeController`'s exact posture: session-authenticated,
  action-matrix-gated (admin-only by default, broadenable per-instance),
  no secrets in responses (the `Source`'s `authentication` block is never
  echoed back).
- `EventService::dispatchMappingAction()` resolves `mappingId`/`sourceId`
  by caller-supplied-at-subscription-creation-time ids, not per-request
  input — the same trust boundary `dispatchSynchronizationAction`/
  `dispatchJobAction` already operate under (an `event_subscription` is
  itself an admin/authorized-user-created OR object, gated by
  `events-cloudevents` REQ-005's `event.subscribe`/`event.update-subscription`
  actions). No new IDOR surface: this change does not let an unauthenticated
  or arbitrary caller choose `mappingId`/`sourceId`/`endpoint` at delivery
  time.
- `FormsOcsClient` transports exclusively through `CallService::call()` —
  no new HTTP client, no new secret storage, no direct handling of Forms
  credentials outside the existing `Source.authentication` /
  credential-broker path.
- The outbound `CallService::call()` target endpoint (`action.endpoint`) is
  admin-configured on the subscription, not derived from submission
  content — no SSRF surface introduced by submission data (unlike the
  pre-existing, separately-flagged `fetchFile()` endpoint-templating
  concern noted in `synchronization-engine` REQ-004's Notes, which this
  change does not touch).

## File Structure

```
lib/
  Controller/
    FormsBridgeController.php                 (new)
  Service/
    EventService.php                          (modified — new dispatchMappingAction() branch)
    SynchronizationService.php                 (modified — new nextcloud-form source-fetch case)
    Forms/
      FormsClientInterface.php                 (new)
      FormsOcsClient.php                       (new)
      FormsSyncAdapter.php                     (new)
      FormsAnswerResolver.php                  (new)
  Exception/
    FormsFeatureDisabledException.php          (new)
    FormsNotFoundException.php                 (new)
    FormsPermissionDeniedException.php         (new)
    FormsUpstreamException.php                 (new)
    FormsConfigException.php                   (new — ambiguous question text, missing formId, etc.)
  AppInfo/
    Application.php                            (modified — DI binding)
appinfo/
  routes.php                                   (modified — 3 new routes)
src/
  components/synchronizations/                 (modified — SyncConfigWidget.vue: nextcloud-form kind + field-mapping helper)
tests/
  Unit/Service/Forms/                          (new)
  Unit/EventListener/ (no change — event listener untouched)
  Integration/Forms/                           (new)
  vitest/formsBridge.spec.js                   (new)
  stubs/OCA/Forms/                             (new — event/entity stubs for unit tests, mirroring tests/stubs/OCA/Tables)
```

## Trade-offs

- **Live HTTP round-trip per outbound dispatch** (Decision 2) vs. a richer
  event payload: chosen to avoid forking an already-merged, independently-
  owned spec (`nextcloud-event-triggers` REQ-004). Cost: one extra
  synchronous HTTP call (to what is, in the common case, the same
  instance) per matched submission event — acceptable, matches the
  existing `dispatchSynchronizationAction`/`dispatchJobAction` branches'
  own "resolve then call a service" shape (those also do at least one
  extra lookup before their real work).
- **No `targetType: nextcloud-form`** (Decision 5) despite the verified
  Forms API supporting submission writes: deliberate scope cut per
  proposal.md, not a technical limitation — flagged as explicit future
  work rather than silently precluded (the `FormsClientInterface` seam
  does not prevent adding `createSubmission()` later).
- **TENTATIVE base path** (Decision 1/discovery.md Finding 5): accepted
  the same way `tables-bridge` and `nextcloud-event-triggers` REQ-003/004
  already accepted it for their own Nextcloud-native integrations — a
  live-instance verification step is called out in tasks.md rather than
  blocking spec/design on it.
