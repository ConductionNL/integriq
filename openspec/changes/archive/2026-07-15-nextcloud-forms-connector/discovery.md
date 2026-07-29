# Discovery: nextcloud-forms-connector

## Question

Does the Nextcloud Forms app expose an API surface sufficient to (a) read a
form's questions and submissions for a sync source, and (b) resolve a
specific submission's answers by question for the outbound mapping path —
and does the already-merged `nextcloud-event-hub` Forms trigger
(`NextcloudFormsEventListener`, `nextcloud-event-triggers` REQ-004) already
carry the answer data the outbound path needs, or must this change fetch it
independently?

Neither this repo's nor the reachable dev server's checkout has the `forms`
app installed (same constraint the merged `nextcloud-event-hub` change
recorded for its own REQ-003/REQ-004), so this discovery verifies against
the public `nextcloud/forms` upstream source (`main` branch) rather than a
live instance, mirroring that change's own precedent.

## Approach Taken

- Read `lib/Controller/ApiController.php` on `nextcloud/forms` (`main`) for
  the public OCS route surface (methods, verbs, URL patterns, response
  types).
- Read `lib/ResponseDefinitions.php` for the exact `FormsForm`,
  `FormsQuestion`, `FormsAnswer`, `FormsSubmission`, `FormsSubmissions`
  shapes.
- Read `lib/Constants.php` for the question-type vocabulary.
- Traced the Forms submission event's actual payload: read
  `lib/Db/Submission.php::read()`, `lib/Service/FormsService.php::notifyNewSubmission()`,
  and `lib/Controller/ApiController.php::newSubmission()` to see exactly
  what object is dispatched with `FormSubmittedEvent` and what
  `getWebhookSerializable()` returns from it — cross-checked against
  `lib/Events/FormSubmittedEvent.php` and `lib/Events/AbstractFormEvent.php`.
- Cross-referenced this repo's HEAD `NextcloudFormsEventListener` (merged
  `nextcloud-event-hub`) and `nextcloud-event-triggers` REQ-004 to compare
  the spec's claimed event payload against the verified upstream behaviour.
- Read this repo's `tables-bridge` implementation (`TablesClientInterface`,
  `TablesOcsClient`, `TablesSyncAdapter`, `TablesBridgeController`) as the
  precedent pattern for a soft-dependency Nextcloud-native connector.

## Findings

1. **The Forms OCS API is sufficient for both directions.** `ApiController`
   (an `OCSController`) exposes `GET /api/v3/forms` (list), `GET
   /api/v3/forms/{formId}` (single form incl. `questions: list<FormsQuestion>`),
   `GET /api/v3/forms/{formId}/submissions` (paginated, `query`/`limit`/`offset`)
   and `GET /api/v3/forms/{formId}/submissions/{submissionId}` (single
   submission incl. `answers: list<FormsAnswer>`). `FormsAnswer` is
   `{id, submissionId, fileId, questionId, questionName?, text}` — answers
   reference questions by numeric `questionId`; `questionName` is an
   *optional* convenience field whose population was not confirmed (see
   Finding 3) — the resolver cannot rely on it being present.

2. **Question type vocabulary** (`Constants::ANSWER_TYPE_*`): `short`,
   `long` (free text), `dropdown`, `multiple`, `multiple_unique` (single/
   multi-select — `multiple`-type questions produce **one `FormsAnswer` row
   per selected option, all sharing the same `questionId`**), `date`,
   `datetime`, `time`, `color`, `linearscale`, `ranking`, `grid`, `file`.
   This directly informs the answer-coercion design: a `multiple`-type
   question's resolved value MUST be an array (zero or more rows), every
   other type resolves to a single scalar (zero or one row).

3. **Critical finding — the merged trigger's event payload does NOT carry
   answers, contrary to its own spec text.** `ApiController::newSubmission()`
   builds a bare `Submission` entity (`setFormId`/`setTimestamp`/`setUserId`
   only) and passes it to `FormsService::notifyNewSubmission($form,
   $submission)`, which dispatches
   `new FormSubmittedEvent($form, $submission)` using that SAME bare
   entity — answers are stored separately via
   `storeAnswersForQuestion()` and never attached back onto `$submission`.
   `Db\Submission::read()` returns exactly `{id, formId, userId, timestamp}`
   — no `answers` key. Therefore
   `FormSubmittedEvent::getWebhookSerializable()['submission']` (what
   `NextcloudFormsEventListener` persists into `event.data.submission` at
   HEAD) is `{id, formId, userId, timestamp}` only.
   `nextcloud-event-triggers` REQ-004's prose ("`data` carrying `formId` and
   the submitted answers") does not match this verified upstream behaviour
   — the field exists and is non-null, but it is answer-less. This is a
   pre-existing spec/code gap in the *already-merged* trigger, not
   something this change is free to silently "fix" by rewriting REQ-004
   (out of scope, and would require re-verifying against a live instance
   per that change's own TENTATIVE flag) — but it is a hard blocker for
   this change's stated outbound design if the outbound resolver were to
   rely solely on the event payload.

4. **Consequence for design:** the outbound (submission → external call)
   path in this change MUST independently fetch the full submission (with
   `answers`) via `GET .../forms/{formId}/submissions/{submissionId}` using
   the `formId` + `submission.id` already present in the event payload,
   rather than trusting the event to carry answers. This is a live HTTP
   call at dispatch time, not a database read — it goes through the same
   `FormsClientInterface` seam the read-side sync source uses, keeping one
   client for both directions (see design.md Decision 1).

5. **Base path / OCS envelope is the one remaining TENTATIVE item.**
   `ApiController extends OCSController`, so responses are reachable at
   both `ocs/v2.php/apps/forms/api/v3/...` (wrapped in the standard
   `{ocs:{meta,data}}` envelope, requiring the `OCS-APIRequest: true`
   header) and, per Nextcloud's dual-routing convention for OCS
   controllers, `index.php/apps/forms/api/v3/...` (unwrapped JSON) — this
   repo's own `tables-bridge` precedent chose the unwrapped `index.php`
   form for `Tables` (`TablesOcsClient::BASE_PATH`) despite Tables' client
   also extending `OCSController`, specifically because it avoids
   envelope-unwrapping in `CallService`-based clients. This change follows
   the same convention for consistency, flagged TENTATIVE pending a live
   `forms`-enabled instance (same caveat class as `nextcloud-event-triggers`
   REQ-003/REQ-004 already carry for their event class names).

## Recommendation

Proceed with the full `tables-bridge`-mirrored design:
`FormsClientInterface` + `FormsOcsClient` (unwrapped `index.php` REST
surface, TENTATIVE base path), `IAppManager`-only feature detection, a
`FormsAnswerResolver` that fetches the full submission independently
(Finding 3/4) rather than trusting the event payload, and a
`multiple`-type-aware coercion rule (Finding 2). Do not modify
`NextcloudFormsEventListener` or `nextcloud-event-triggers` REQ-004 — the
event payload gap is real but this change routes around it rather than
patching a different, already-merged capability's spec.

## Risks Uncovered

- If a live `forms`-enabled instance later shows the base path is actually
  the OCS-enveloped `ocs/v2.php/...` form (not `index.php/...`), only
  `FormsOcsClient`'s `BASE_PATH` constant and envelope-unwrapping need to
  change — `FormsClientInterface`'s contract is transport-agnostic by
  design (mirrors `TablesClientInterface`'s own stated rationale).
- `questionName` on `FormsAnswer` is documented as optional and its
  population was not confirmed either way — the resolver's question-text
  path always resolves via the form's fetched `questions` list, never via
  `FormsAnswer.questionName`, so this is a non-issue for correctness (only
  a potential missed optimization).

## Next Steps

Proceed to design.md and specs.
