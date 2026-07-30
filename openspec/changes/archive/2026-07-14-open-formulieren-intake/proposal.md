---
kind: code
depends_on: []
---

# Proposal: open-formulieren-intake

## Summary

Add an Open Formulieren submission bridge to OpenConnector: a signed inbound
webhook receiver (`POST /api/open-formulieren/submissions`), a per-form
mapping layer (`openformulieren_form_mapping` OR records) that normalises an
arbitrary Open Formulieren submission onto the `ns#Case` semantic-handoff
contract fields, and a persisted `openformulieren_submission` OR record
tracking `received → mapped → handed_off | failed` with per-submission
isolation. The normalised submission declares OpenRegister's
`x-openregister-handoff` dialect targeting `https://openregister.app/ns#Case`
(the shipped semantic-object-handoff engine, ADR-051); a new authenticated
`POST /api/open-formulieren/submissions/{id}/handoff` endpoint executes it.

## Motivation

A research audit of zaaksysteem evaluations found that a no-code form-builder
bridge was the most-requested integration gap: Open Formulieren (Maykin/
Dimpact, the NL government's de-facto standard form builder) has no bridge
into fleet case intake, while Decos customers buy the proprietary "Seneca"
e-forms product to fill exactly this gap. OpenRegister ships a generic
semantic-case-intake handoff engine (`SemanticTypeResolver` /
`HandoffService`, ADR-051) that procest's `case` schema already implements
(`implements: ["https://openregister.app/ns#Case"]`) via the
`semantic-case-intake` change — this bridge is the first Open Formulieren-side
emitter for that contract. Per ADR-022, all integrations live in
openconnector, never as an nc-vue leaf and never re-implemented per app.

## Capabilities

- `open-formulieren-intake` — new capability (this spec).

## Affected Projects

- [ ] Project: `openconnector` — new webhook receiver, per-form mapping
  layer, `openformulieren_submission` / `openformulieren_form_mapping` OR
  schemas, and the handoff-trigger REST endpoint.
- [ ] Project: `procest` — no code change here; procest's `case` schema
  already implements `ns#Case` (semantic-case-intake, prior change) and
  requires no modification to receive handed-off submissions.

## Scope

### In Scope

- `POST /api/open-formulieren/submissions` — signed (HMAC) inbound webhook
  receiver, `#[PublicPage]` (no NC session — mirrors
  `NotifyNlController::inbound()` / `PeppolController::inbound()`), accepts
  form slug/uuid, submitted values, attachment refs, optional BSN/KvK auth
  context.
- `openformulieren_form_mapping` OR schema — one testable mapper class
  (`FormFieldMapper`) resolving a form's raw submitted values onto the
  `ns#Case` contract fields `title`/`summary`/`channel`/`priority` via three
  expression kinds (`from`, `const`, `template`); an unresolved `from`/
  `template` reference is a hard error (never a literal-leak — see design.md
  and the known `oc-mapping-literal-leak` bug class this deliberately avoids).
- `openformulieren_submission` OR schema tracking
  `received → mapped → handed_off | failed` with per-submission error
  isolation (one submission's mapping/attachment failure never affects
  another), declaring `x-openregister-handoff` targeting
  `https://openregister.app/ns#Case` (`trigger: manual`,
  `whenUnavailable: queue`).
- `GET /api/open-formulieren/submissions/{id}` — authenticated status read.
- `POST /api/open-formulieren/submissions/{id}/handoff` — authenticated
  (real NC user, per HandoffService's v1 "no system-user privilege lane"
  constraint — see design.md) endpoint that executes the declared handoff via
  OpenRegister's `HandoffService::execute()`.
- Best-effort attachment fetch (thin Guzzle client, no OF SDK) + store via
  `OCA\OpenRegister\Service\FileService::addFile()` onto the submission
  object at receipt time; on successful handoff, a best-effort
  `FileService::copyFile()` onto the created Case object (the `ns#Case`
  contract carries no attachment field — see design.md for why attachments
  cannot flow through the handoff mapping itself).
- HMAC verification reusing the existing `WebhookSignatureService` (no new
  signing scheme).

### Out of Scope

- A settings/inbox UI for reviewing submissions and clicking "hand off" —
  backend-focused change; the REST surface is the contract a future UI (or
  procest's own inbox) consumes.
- Automatic, unattended handoff execution at webhook-receipt time — verified
  against OpenRegister HEAD that v1 `HandoffService` deliberately has no
  system-user privilege lane (`lib/Listener/HandoffLifecycleListener.php`:
  "not fire handoffs, so there is no system-user privilege lane."); a real
  authenticated actor must call the handoff-trigger endpoint. Documented as a
  deliberate binding decision in design.md, not a shortcut.
- `requester` contract field mapping (ADR-048 semantic reference to the
  requesting party) — Open Formulieren's BSN/KvK auth context has no
  corresponding OR-managed party register to resolve against in this fleet
  today; the contract field is optional and the "anonymous request omits the
  requester" scenario is the supported path (see hydra's `ns#Case` contract
  spec).
- MessageBird/Twilio-style multi-provider abstraction — Open Formulieren is
  one product with one documented submission-delivery shape; no provider
  interface is introduced.

## Approach

Model the Open Formulieren connection as an openconnector `Source`
(`type=open-formulieren`) whose `configuration.webhookSignature` carries the
shared HMAC secret — **plaintext in the source config**, matching the
verified HEAD convention for all three existing webhook receivers
(`PeppolController::inbound()`, `NotifyNlController::inbound()`,
`PaymentsController`/`PaymentIntentService`'s webhook signature), not
ICrypto-encrypted (ICrypto is reserved, at HEAD, for asymmetric API
keys/private keys — `RestNotifyNlProvider`'s NotifyNL API key,
`EudiIssuerKeyService`'s private key — a materially different threat model
than a shared HMAC secret already at the same trust tier as the rest of a
source's admin-configured `configuration`). See design.md for the full
verification trail.

`OpenFormulierenController::inbound()` verifies the signature, then calls
`OpenFormulierenIntakeService::ingest()`, which: (1) persists the raw
submission (`status=received`); (2) resolves the `openformulieren_form_mapping`
record for the form slug and runs `FormFieldMapper` against the submitted
values, producing `mappedTitle`/`mappedSummary`/`mappedChannel`/
`mappedPriority` (mandatory-field resolution failure -> `status=failed`,
isolated to that submission); (3) best-effort fetches + stores any attachment
refs; (4) persists `status=mapped`. A separate, authenticated
`OpenFormulierenController::handoff()` calls OpenRegister's
`HandoffService::execute()` against the submission's declared
`submission-to-case` handoff entry, under the calling user's own RBAC — no
impersonation, no system account (mirrors the documented HandoffService
contract exactly). `source` (the `ns#Case` provenance field) is engine-filled
by OR's own `provenance` expression kind — never mapped by this app.

## New Dependencies

None. Reuses `guzzlehttp/guzzle` (already an app dependency, for the
attachment fetch), `WebhookSignatureService`, `ActionAuthService`, and
OpenRegister's shipped `ObjectService`/`FileService`/`Handoff\HandoffService`
(cross-app DI, same pattern as `SmsDispatchService`'s `ObjectService`
injection).

## Impact

- New: `lib/Controller/OpenFormulierenController.php`,
  `lib/Service/OpenFormulierenIntakeService.php`,
  `lib/Service/OpenFormulieren/FormFieldMapper.php`,
  `lib/Exception/OpenFormulierenException.php`,
  `lib/Exception/MappingResolutionException.php`, `appinfo/routes.php`
  entries, `openformulieren_submission` + `openformulieren_form_mapping`
  schemas in `lib/Settings/openconnector_register.json`.
- Reused: `WebhookSignatureService`, `ActionAuthService`, OpenRegister's
  `ObjectService`, `FileService`, `Handoff\HandoffService`.
- Fixed in passing (pre-existing, encountered while wiring `FileService`):
  `tests/stubs/OCA/OpenRegister/Service/FileService.php` declared
  `storeFile()`/`getFiles(register, schema, objectId)`/`deleteFile(register,
  schema, objectId, fileId)` — none of which match the real OpenRegister
  `FileService` at HEAD (`addFile(objectEntity, fileName, content, ...)`,
  `getFiles(object, sharedFilesOnly)`, `copyFile(sourceObject, fileId,
  targetObject)`). No existing test stubbed the stale methods (verified via
  grep), so corrected to match HEAD with no test breakage.

## Cross-Project Dependencies

- procest's `case` schema (prior `semantic-case-intake` change) is the
  intended production `ns#Case` provider; no procest code change in this PR.

## Risks

### Risk 1: Automatic handoff is not possible at webhook-receipt time

**Severity:** Medium — **Mitigation:** documented explicitly (see Out of
Scope and design.md) as a deliberate binding to OpenRegister's v1
`HandoffService` constraint, not an oversight. The submission still reaches
`status=mapped` fully automatically; only the final Case-creation step
requires a real authenticated actor, exactly matching how a human reviews a
municipal intake queue today.

### Risk 2: Open Formulieren's exact webhook/HMAC wire shape cannot be verified against a live instance

**Severity:** Medium — **Mitigation:** the receiver reuses the existing,
already-shipped `WebhookSignatureService` (Stripe-style
`t=<unix>,v1=<hex>` timestamped HMAC, already proven against three other
inbound webhooks) rather than inventing a new scheme or depending on an
unverified Open Formulieren SDK; the payload shape (form slug/uuid, submitted
values, attachment refs, optional BSN/KvK auth context) is documented as a
binding assumption in design.md so a future correction is isolated to
`OpenFormulierenController::inbound()`'s payload parsing alone.

### Risk 3: Register file concurrency with another in-flight openconnector change

**Severity:** Low — **Mitigation:** per the task brief, `origin/development`
is merged and the full suite rerun before the PR; `components.schemas` /
`components.registers.openconnector.schemas` are keyed structures so a
textual conflict (if any) is a mechanical union, not a logical one.

## Rollback Strategy

The connector is additive. Revert by removing the new controller/services/
routes and the two new schema entries; no existing source, sync, rule, or
event behaviour changes, so removal cannot regress current integrations.

## Open Questions

None blocking — the design binds the automatic portion (receive → map →
persist) fully, and documents the human-in-the-loop portion (handoff trigger)
as the correct v1 behaviour per OpenRegister's own documented constraint. A
settings/inbox UI and the `requester` contract-field mapping are explicitly
deferred (see Out of Scope).
