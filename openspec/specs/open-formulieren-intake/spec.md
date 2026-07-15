# open-formulieren-intake Specification

## Purpose
TBD - created by archiving change open-formulieren-intake. Update Purpose after archive.
## Requirements
### Requirement: Signed inbound submission webhook (REQ-001)

OpenConnector MUST expose `POST /api/open-formulieren/submissions` as a
`#[PublicPage]` endpoint (no NC session — mirrors
`PeppolController::inbound()` / `NotifyNlController::inbound()`) gated by
HMAC verification via the existing `WebhookSignatureService`, reading the
shared secret from the active `open-formulieren` source's
`configuration.webhookSignature` (plaintext at rest, matching the verified
convention of every other inbound webhook in this app — never ICrypto,
which is reserved for asymmetric API keys/private keys). A missing or
invalid signature MUST return 401 with an undifferentiated error body before
any state change.

#### Scenario: Valid signature is accepted

- GIVEN an active `open-formulieren` source with a configured
  `webhookSignature.secret`
- WHEN a submission POST arrives with a valid HMAC signature over the exact
  raw body bytes
- THEN the request is accepted and processing proceeds
- @e2e exclude backend HMAC verification — covered by PHPUnit

#### Scenario: Invalid signature is rejected

- GIVEN an active source with a configured secret
- WHEN a submission POST arrives with a signature computed against a
  different secret
- THEN the endpoint returns HTTP 401 with no state change and no submission
  record is created
- @e2e exclude backend HMAC verification — covered by PHPUnit

#### Scenario: Missing signature is rejected

- GIVEN an active source with a configured secret
- WHEN a submission POST arrives with no signature header at all
- THEN the endpoint returns HTTP 401 with no state change
- @e2e exclude backend HMAC verification — covered by PHPUnit

#### Scenario: No active source configured fails closed

- GIVEN no `open-formulieren` source is configured (or none is enabled)
- WHEN a submission POST arrives
- THEN the endpoint returns HTTP 401 (no secret to verify against — fail
  closed, never fail open)
- @e2e exclude backend HMAC verification — covered by PHPUnit

### Requirement: Per-form mapping onto `ns#Case` contract fields (REQ-002)

OpenConnector MUST persist per-form mapping configuration as
`openformulieren_form_mapping` OR records (keyed by `formSlug`) and provide
a single testable `FormFieldMapper` class resolving a submission's raw
`values` onto the mandatory `ns#Case` contract fields `title`/`summary`/
`channel` and the optional `priority`, via three expression kinds (`from`,
`const`, `template`). A mapping config missing a mandatory contract field key
MUST be rejected before use. A declared `from`/`template` entry whose
referenced key is absent from the submitted values at runtime MUST raise a
typed `MappingResolutionException` — it MUST NOT return the literal
path/template string as if it were resolved data (the known
`oc-mapping-literal-leak` bug class this deliberately avoids).

#### Scenario: Full mapping resolves every declared field

- GIVEN a form mapping declaring `title`, `summary`, `channel`, and
  `priority`, each resolvable against the submitted values
- WHEN `FormFieldMapper` runs
- THEN all four normalised fields are populated with no error
- @e2e exclude backend mapping logic — covered by PHPUnit

#### Scenario: Partial mapping omits an undeclared optional field

- GIVEN a form mapping declaring only the three mandatory fields (no
  `priority` key)
- WHEN `FormFieldMapper` runs
- THEN `mappedPriority` is omitted (null) with no error
- @e2e exclude backend mapping logic — covered by PHPUnit

#### Scenario: Mapping config missing a mandatory field is rejected

- GIVEN a form mapping whose `fieldMapping` has no `channel` key
- WHEN the mapping config is validated
- THEN it is rejected before any submission is processed against it
- @e2e exclude backend mapping config validation — covered by PHPUnit

#### Scenario: Unresolvable declared field errors, never leaks the literal

- GIVEN a form mapping declaring `summary` as
  `{"type": "from", "value": "toelichting"}`
- WHEN a submission arrives whose `values` has no `toelichting` key
- THEN `FormFieldMapper` throws `MappingResolutionException` naming the
  field and the missing key — it never returns the literal string
  `"toelichting"` as the resolved summary
- @e2e exclude backend mapping logic — covered by PHPUnit

#### Scenario: Unresolvable template placeholder errors, never leaks the template

- GIVEN a form mapping declaring `title` as
  `{"type": "template", "value": "Aanvraag: {{aanvraagType}}"}`
- WHEN a submission arrives whose `values` has no `aanvraagType` key
- THEN `FormFieldMapper` throws `MappingResolutionException` — it never
  returns the unexpanded `"Aanvraag: {{aanvraagType}}"` string as the
  resolved title
- @e2e exclude backend mapping logic — covered by PHPUnit

### Requirement: `openformulieren_submission` lifecycle with per-submission isolation (REQ-003)

OpenConnector MUST persist one `openformulieren_submission` OR record per
inbound webhook call, tracking `status` through
`received → mapped → handed_off | failed`. A mapping or attachment failure
for one submission MUST NOT affect any other submission's processing or
state (each webhook POST is independently isolated).

#### Scenario: Successful ingest reaches `mapped`

- GIVEN a valid signed submission for a form with a complete, resolvable
  mapping
- WHEN the webhook is processed
- THEN a submission record is created with `status=mapped` and the
  normalised fields populated
- @e2e exclude backend ingest pipeline — covered by PHPUnit

#### Scenario: Mandatory field resolution failure isolates to one submission

- GIVEN two submissions for the same form arrive in sequence, the first with
  a value the mapping cannot resolve and the second with a fully resolvable
  payload
- WHEN both are processed
- THEN the first submission's record is `status=failed` with `errorDetail`
  set, and the second submission's record independently reaches
  `status=mapped`
- @e2e exclude backend ingest pipeline — covered by PHPUnit

#### Scenario: Unknown form slug fails closed

- GIVEN a submission for a `formSlug` with no `openformulieren_form_mapping`
  record
- WHEN the webhook is processed
- THEN the submission record is `status=failed` with a machine-readable
  `errorDetail`, and no `ns#Case` handoff is declared as executable
- @e2e exclude backend ingest pipeline — covered by PHPUnit

### Requirement: Declared `ns#Case` handoff, executed by a real authenticated actor (REQ-004)

The `openformulieren_submission` schema MUST declare an
`x-openregister-handoff` entry (`id: submission-to-case`, targetSemanticType
`https://openregister.app/ns#Case`, `trigger: manual`,
`whenUnavailable: queue`) whose mapping reads only the already-normalised
`mappedTitle`/`mappedSummary`/`mappedChannel`/`mappedPriority` properties,
plus the engine-filled `source` (`{"provenance": true}`) — the `requester`
contract field is deliberately not mapped (no OR-managed party register to
resolve a BSN/KvK auth context against; anonymous requester is the supported
path). OpenConnector MUST expose an authenticated
`POST /api/open-formulieren/submissions/{id}/handoff` endpoint that calls
OpenRegister's `HandoffService::execute()` under the calling user's own
session/RBAC — never a system-account/impersonation shortcut, per
`HandoffService`'s documented v1 constraint (no lifecycle/webhook-triggered
system-user lane).

#### Scenario: Authenticated handoff succeeds

- GIVEN a `mapped` submission and an authenticated user with write access to
  it and create access on the resolved `ns#Case` provider schema
- WHEN `POST .../{id}/handoff` is called
- THEN the engine creates the target Case, the submission's `status` becomes
  `handed_off` (via the declared `onSuccess.set`), and the response carries
  the created object's identifiers
- @e2e exclude backend handoff execution — covered by PHPUnit; the engine
  itself is covered by OpenRegister's own HandoffServiceTest

#### Scenario: No `ns#Case` provider installed degrades to queued, not an error

- GIVEN no installed schema implements `ns#Case`
- WHEN the handoff is triggered
- THEN the request is parked (queue mode) rather than failing, per the
  engine's own documented degradation behaviour
- @e2e exclude backend handoff execution — covered by PHPUnit

#### Scenario: Handoff failure isolates to the triggering submission

- GIVEN a handoff execution that fails (e.g. RBAC refusal, target validation
  failure)
- WHEN the failure occurs
- THEN only that submission's record moves to `status=failed` with
  `errorDetail` set — no unhandled 500, no partial state (the engine's own
  compensation guarantees no orphaned target)
- @e2e exclude backend handoff execution — covered by PHPUnit

### Requirement: Best-effort attachment handling (REQ-005)

OpenConnector MUST best-effort fetch each attachment ref present in a
submission payload and store it via OpenRegister's `FileService::addFile()`
onto the submission object, recording per-attachment outcome
(`fetched`/`failed`) independently of the submission's mapping outcome (an
attachment fetch failure MUST NOT fail the submission's `mapped` status).
Because the `ns#Case` contract has no attachment-carrying field, attachments
cannot flow through the handoff mapping itself; on a successful handoff,
OpenConnector MUST best-effort copy each successfully stored file onto the
created Case object via `FileService::copyFile()`, isolated per file (a copy
failure MUST NOT fail the already-completed handoff).

#### Scenario: Attachment fetch failure does not fail the submission

- GIVEN a submission whose payload includes an attachment ref pointing at an
  unreachable URL
- WHEN the webhook is processed
- THEN the submission still reaches `status=mapped`, with that attachment
  entry recorded `status=failed` and the mapped fields otherwise populated
- @e2e exclude backend attachment handling — covered by PHPUnit

#### Scenario: Successfully stored attachments are copied onto the Case after handoff

- GIVEN a `mapped` submission with one successfully stored attachment
- WHEN the handoff succeeds
- THEN the attachment is copied onto the created Case object; a copy
  failure is recorded but does not revert the already-completed handoff
- @e2e exclude backend attachment handling — covered by PHPUnit

@e2e A signed Open Formulieren submission for a mapped form is received,
normalised, and persisted as `mapped`; an authenticated user then triggers
the handoff and the submission becomes `handed_off` with a linked Case —
end-to-end UI coverage belongs to a future inbox/review surface (out of
scope here, see proposal.md), so this flow is exercised by PHPUnit end to
end instead (`OpenFormulierenControllerTest` + `OpenFormulierenIntakeServiceTest`).

