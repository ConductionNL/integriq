# open-formulieren-intake Specification

## Purpose
Open Formulieren sends each submitted form to integriq as a signed webhook. Integriq checks the signature, maps the answers onto the case contract per form, keeps one submission record per delivery and hands it over to the case system as the account an administrator chose for the connection. Attachments are fetched on a best-effort basis, and only the intake account, the handlers and administrators can read a submission.

## Requirements

### Requirement: Signed inbound submission webhook (REQ-001)

Integriq MUST expose `POST /api/open-formulieren/submissions` as a `#[PublicPage]` endpoint (no Nextcloud session) gated by HMAC verification via the existing `WebhookSignatureService`. The shared secret, scheme, header and tolerance MUST come from the `authorizationConfiguration` of the instance's one `open-formulieren` consumer, read as an engine read of admin configuration. A missing or invalid signature MUST return 401 with an undifferentiated error body before any state change. A missing or ambiguous connection MUST return 503 with error `openformulieren_connection_not_configured` before any state change, so the sender delivers again.

#### Scenario: Valid signature is accepted

- GIVEN an `open-formulieren` consumer with a configured secret and a usable account
- WHEN a submission POST arrives with a valid HMAC signature over the exact raw body bytes
- THEN the request is accepted and processing proceeds as that account
- @e2e exclude backend HMAC verification: covered by PHPUnit and the live proof, a browser cannot sign a webhook

#### Scenario: Invalid signature is rejected

- GIVEN an `open-formulieren` consumer with a configured secret
- WHEN a submission POST arrives with a signature computed against a different secret
- THEN the endpoint returns HTTP 401 with no state change and no submission record is created
- @e2e exclude backend HMAC verification: covered by PHPUnit

#### Scenario: Missing signature is rejected

- GIVEN an `open-formulieren` consumer with a configured secret
- WHEN a submission POST arrives with no signature header at all
- THEN the endpoint returns HTTP 401 with no state change
- @e2e exclude backend HMAC verification: covered by PHPUnit

#### Scenario: No active source configured fails closed

The trust no longer lives on a source: the "source" of this scenario is now the `open-formulieren` consumer. It still fails closed, with 503 instead of 401, so the sender delivers again.

- GIVEN no `open-formulieren` consumer exists, or two exist
- WHEN a submission POST arrives
- THEN the endpoint returns HTTP 503 with error `openformulieren_connection_not_configured`
- AND nothing is written
- AND the administrators get one notification
- @e2e exclude backend webhook refusal: covered by PHPUnit and the live proof

### Requirement: Per-form mapping onto `ns#Case` contract fields (REQ-002)

Integriq MUST persist per-form mapping configuration as
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

Integriq MUST persist one `openformulieren_submission` OR record per
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
path). Integriq MUST expose an authenticated
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

Integriq MUST best-effort fetch each attachment ref present in a
submission payload and store it via OpenRegister's `FileService::addFile()`
onto the submission object, recording per-attachment outcome
(`fetched`/`failed`) independently of the submission's mapping outcome (an
attachment fetch failure MUST NOT fail the submission's `mapped` status).
Because the `ns#Case` contract has no attachment-carrying field, attachments
cannot flow through the handoff mapping itself; on a successful handoff,
Integriq MUST best-effort copy each successfully stored file onto the
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

### Requirement: Submissions are open to the intake account, the handlers and administrators only (REQ-008)

`openformulieren_submission` holds BSNs. Its OpenRegister authorization block MUST grant `create` and `update` to the group `openformulieren-intake`, `read` and `update` to the group `openformulieren-behandelaars`, and nothing to anyone else. `delete` MUST be granted to nobody. Administrators keep every action. The account of the Open Formulieren connection MUST be a member of `openformulieren-intake`: choosing it in the connection settings MUST add it and remove the previous account, and a repair step MUST add the current account on upgrade. The group names are fixed.

#### Scenario: An ordinary account gets nothing

- GIVEN an account that is in neither group and is not an administrator
- WHEN it reads, creates or updates an `openformulieren_submission` through the OpenRegister API
- THEN every request is refused
- @e2e exclude backend RBAC: covered by the register test and the live proof per role

#### Scenario: A handler reads and updates

- GIVEN an account in `openformulieren-behandelaars`
- WHEN it reads and updates a submission
- THEN both succeed
- AND it cannot create one
- @e2e exclude backend RBAC: covered by the live proof per role

#### Scenario: The intake account creates

- GIVEN the Open Formulieren connection account, a member of `openformulieren-intake`
- WHEN a signed submission arrives
- THEN the submission is created as that account
- @e2e exclude backend RBAC: covered by PHPUnit and the live proof

#### Scenario: The chosen intake account joins the intake group

- GIVEN an administrator in the Open Formulieren connection section
- WHEN they choose an account and save
- THEN the account is a member of `openformulieren-intake`
- AND the account chosen before is no longer a member
- AND an account the save refuses is not left in the group
- @e2e exclude group membership: covered by PHPUnit and the live proof

#### Scenario: An upgraded instance keeps its intake account

- GIVEN an instance whose Open Formulieren connection already names an account
- WHEN the upgrade runs the repair step
- THEN the four intake and handler groups exist
- AND the account is a member of `openformulieren-intake`
- AND nobody was added to `openformulieren-behandelaars`
- @e2e exclude repair step: covered by PHPUnit and the live proof

### Requirement: The intake acts as the Open Formulieren connection's account (REQ-006)

Every OpenRegister write of the intake MUST run as the `userId` of the `open-formulieren` consumer, inside OpenRegister's `ObjectService::runAs()`, with the previous user restored afterwards. That covers the submission's create and updates and the stored attachment files. Before the first write the intake MUST check that the account exists, is enabled, and holds `create` and `update` on `openformulieren_submission`. When any check fails, or cannot run, the endpoint MUST answer 503 with a named error, write nothing, log an error and notify the administrators at most once per reason per hour. The stored submission MUST record `receivedVia: {consumer, account}`. A repeated delivery of the same `submission.uuid` MUST NOT create a second record. The intake MUST NOT use `runAsSystem()` or `_rbac: false` on a write.

#### Scenario: The submission is stored as the configured account

- GIVEN an `open-formulieren` consumer with `userId` `of-intake`, an account with `create` and `update` on `openformulieren_submission`
- WHEN a correctly signed submission arrives without a login
- THEN the submission is stored and mapped with owner `of-intake`
- AND its attachments are stored as `of-intake`
- AND `receivedVia` names the consumer and `of-intake`
- @e2e exclude backend identity: covered by PHPUnit through the real path and by the live proof

#### Scenario: A missing account fails loud

- GIVEN an `open-formulieren` consumer whose `userId` is empty, unknown or disabled
- WHEN a correctly signed submission arrives
- THEN the endpoint answers 503 with error `openformulieren_account_unavailable`
- AND nothing is written
- AND the administrators are told the Open Formulieren connection has no usable account
- @e2e exclude backend identity: covered by PHPUnit and the live proof

#### Scenario: An account without rights writes nothing half

- GIVEN an account with `create` but not `update` on `openformulieren_submission`, or rights that cannot be checked
- WHEN a correctly signed submission arrives
- THEN the endpoint answers 503 with error `openformulieren_account_lacks_rights`
- AND no `received` record is left behind
- @e2e exclude backend identity: covered by PHPUnit and the live proof

#### Scenario: A submission that was not stored is answered with 503

- GIVEN a usable account
- WHEN OpenRegister refuses a write of the submission anyway
- THEN the endpoint answers 503 with error `submission_not_stored`
- AND the administrators get one notification
- @e2e exclude backend refusal path: covered by PHPUnit

#### Scenario: A repeated delivery creates no second record

- GIVEN a submission with `submission.uuid` U was stored and mapped
- WHEN the same submission arrives again
- THEN the endpoint answers with the stored record
- AND nothing is written and no attachment is fetched again
- @e2e exclude backend retry matching: covered by PHPUnit and the live proof

#### Scenario: Existing configuration migrates without an account

- GIVEN no `open-formulieren` consumer and an enabled `open-formulieren` source with `configuration.webhookSignature.secret`
- WHEN the repair step runs
- THEN one `open-formulieren` consumer exists with that trust and an empty `userId`
- AND the administrators get one notification to choose the account
- AND a second run creates nothing
- @e2e exclude repair step: covered by PHPUnit and the live proof

### Requirement: The Open Formulieren connection's account is chosen and checked by an administrator (REQ-007)

The integriq admin settings MUST hold an Open Formulieren connection section that edits the one `open-formulieren` consumer: scheme, secret, header, tolerance and account. The secret MUST never be returned; a blank secret keeps the stored one. On save the account MUST be refused with a field error when it does not exist, is disabled, or lacks `create` and `update` on `openformulieren_submission`. An administrator account MUST save with a warning. The section MUST show in one line which account the intake acts as, or that none is set.

#### Scenario: Choosing a valid account

- GIVEN an administrator in the Open Formulieren connection section
- WHEN they choose an account that holds the rights and save
- THEN the consumer stores that account
- AND the section reads "Intake acts as" that account

#### Scenario: An account without rights is refused

- GIVEN an administrator in the Open Formulieren connection section
- WHEN they choose an account that does not exist, is disabled or cannot store submissions
- THEN the save is refused with a field error naming the account
- AND the stored account is unchanged

#### Scenario: No account set is shown plainly

- GIVEN an `open-formulieren` consumer without an account
- WHEN an administrator opens the section
- THEN it reads "No account set: Open Formulieren submissions are refused with 503"
