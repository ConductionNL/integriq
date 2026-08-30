# Design: open-formulieren-intake

## 1. Verified-at-HEAD findings this design binds to

### 1.1 OpenRegister's real handoff API (`lib/Service/Handoff/HandoffService.php`, origin/development)

- The engine is entirely **source-schema-declarative**: a schema declares
  `x-openregister-handoff` (a top-level sibling key inside the schema
  definition, alongside `type`/`properties`/`appendOnly` — verified via
  `SchemaMapper.php:1096` and `HandoffAnnotationValidator.php:81`, both read
  `$schema['x-openregister-handoff']` / `$configuration['x-openregister-handoff']`
  directly, not nested under a generic wrapper).
- `HandoffService::execute(register, schema, id, handoffId, ...)` (1) loads
  the source object **under the caller's RBAC** via `ObjectService::find()`,
  (2) resolves the provider schema for the target kind via
  `SemanticTypeResolver`, (3) RBAC-pre-checks **create** on the target schema
  **as the caller**, (4) evaluates the mapping, (5) writes the target +
  provenance + audit inside a compensated all-or-nothing sequence, (6)
  applies `onSuccess.set`, (7) dispatches `HandoffExecutedEvent`.
- **Critical constraint, verified via `lib/Listener/HandoffLifecycleListener.php`
  line 12 and `docs/features/semantic-object-handoff.md`'s "Key capabilities"
  list**: *"Lifecycle-triggered handoffs for real actors (no system-user
  privilege lane)"*. `design.md` of the engine's own archived change states
  it explicitly: *"`trigger: lifecycle:<state>` handoffs run through the same
  `execute()` from a lifecycle-transition listener; v1 gates them to
  transitions performed by a real actor (the transition's user is the
  handoff actor — no system-user privilege lane)."* There is no background-
  job/system-account lane for either trigger kind in v1.
- **Consequence for this bridge**: an Open Formulieren submission arrives via
  an unauthenticated, signed webhook — there is no NC user session to act as
  the handoff actor. Executing the handoff automatically at webhook-receipt
  time would require either (a) fabricating/impersonating a system NC user
  to call `execute()` — exactly the "system-user privilege lane" the engine
  deliberately does not provide, and would additionally mean an anonymous
  external POST could unilaterally create a Case object under a synthetic
  identity with no real RBAC check — or (b) waiting for the OpenRegister
  engine itself to grow a background/queue-drain execution lane, which is
  out of this change's control. **Binding decision: this bridge does the
  fully-automatic part (receive, verify, map, persist, `status=mapped`) with
  zero human involvement, and exposes a separate authenticated
  `POST .../{id}/handoff` endpoint for the human-in-the-loop final step.**
  This mirrors how a municipal intake clerk works a queue today and is not a
  functionality gap relative to what OpenRegister v1 actually supports.

### 1.2 `ns#Case` contract fields (`hydra/openspec/changes/semantic-object-handoff/specs/handoff-contract-case/spec.md`)

| Field | Mandatory | Meaning | This bridge |
|---|---|---|---|
| `title` | yes | Short human-readable case title | `FormFieldMapper` output `mappedTitle` |
| `summary` | yes | Free-text description of what is asked | `mappedSummary` |
| `channel` | yes | Intake channel (telefoon, email, balie, web, …) | `mappedChannel` (typically `const: "web"` or `"open-formulieren"`) |
| `source` | yes | **Engine-filled** provenance pointer to the emitting object | `{"provenance": true}` — never mapped by this app |
| `requester` | no | ADR-048 semantic reference to the requesting party | **not mapped in v1** — no OR-managed party register exists to resolve a BSN/KvK auth context against in this fleet; the "anonymous request omits the requester" scenario (hydra contract spec) is the supported path |
| `priority` | no | laag/normaal/hoog vocabulary | `mappedPriority`, optional |

`source` being engine-filled is why `openformulieren_form_mapping.fieldMapping`
only ever needs `title`/`summary`/`channel` (mandatory) and optionally
`priority` — mapping it would be rejected as an unknown target (the schema's
`x-openregister-handoff.mapping` block hard-codes `source: {"provenance":
true}` once, at the schema level, not per form).

### 1.3 OR's own mapping-expression evaluator (`HandoffMappingEvaluator.php`)

Exactly five expression kinds exist in OR's dialect: `from` (optional
`default`), `const`, `template` (`{{prop}}`, HTML-escaped), `semanticRef`,
`provenance`. Critically: **`from` on an absent source property with no
`default` returns `null`, and a `null` contract-field value is silently
*omitted*** (`HandoffMappingEvaluator::evaluate()`, "fields evaluating to
null are omitted"). This is safe (never leaks a literal) but is a different,
looser policy than what this bridge's own upstream mapping layer needs — see
§2.2.

### 1.4 Webhook-signature storage convention (verified across 3 existing inbound webhooks)

`grep -rn "webhookSignature" lib/` at HEAD shows `PeppolController::inbound()`,
`NotifyNlController::inbound()`, and `PaymentsController`/
`PaymentIntentService`'s webhook handling all read
`configuration.webhookSignature.secret` **directly off the source object,
in plaintext** — none of them route it through `OCP\Security\ICrypto`.
`ICrypto` is used exactly twice in this app, both for materially different
material: `RestNotifyNlProvider`'s NotifyNL **API key** (an outbound bearer
credential) and `EudiIssuerKeyService`'s **private signing key**. A shared
HMAC verification secret sits at the same trust tier as the rest of a
source's admin-configured `configuration` (admin-only write, not user input,
no SSRF/injection surface) — the three existing precedents treat it as such.
**Binding decision: follow the verified 3-for-3 convention (plaintext in
`configuration.webhookSignature.secret`) rather than introduce a fourth,
inconsistent pattern.** This deliberately departs from this task's initial
framing ("shared secret stored encrypted via ICrypto") because that framing
does not match what the SMS-leaf (or any leaf) actually does at HEAD for a
*webhook signature* specifically — the ICrypto convention in
`RestNotifyNlProvider` is for the *outbound API key*, a different piece of
config on a different kind of source.

### 1.5 `FileService`'s real API (verified, stub was stale)

`tests/stubs/OCA/OpenRegister/Service/FileService.php` declared
`storeFile()`/`getFiles(register, schema, objectId)`/`deleteFile(register,
schema, objectId, fileId)`. None of these match
`lib/Service/FileService.php` at HEAD:
`addFile(ObjectEntity|string $objectEntity, string $fileName, string
$content, bool $share=false, array $tags=[], ...): File`,
`getFiles(ObjectEntity|string $object, ?bool $sharedFilesOnly=false): array`,
`copyFile(ObjectEntity $sourceObject, int $fileId, ObjectEntity
$targetObject): File`. `grep`-verified no existing test stubs the stale
method names, so the stub is corrected in this change with no test breakage
(`lib/Service/EndpointService.php` and `lib/Service/SynchronizationService.php`
already call the *real* `addFile`/`getFiles` signatures via
`$containerInterface->get(...)`, so production code was already correct —
only the test double had drifted).

## 2. Mapping design

### 2.1 Two distinct mapping layers (do not conflate)

1. **This app's own layer** (`FormFieldMapper` + `openformulieren_form_mapping`):
   raw Open Formulieren submitted values → this bridge's own normalised
   `openformulieren_submission` properties (`mappedTitle`, `mappedSummary`,
   `mappedChannel`, `mappedPriority`). Fully within this app's control and
   test surface.
2. **OpenRegister's own layer** (`x-openregister-handoff` dialect on the
   `openformulieren_submission` schema): those normalised properties →
   `ns#Case` contract fields, via OR's own shipped `HandoffMappingEvaluator`
   (§1.3). This bridge only *declares* the mapping (all `from`/`provenance`
   expressions reading the already-normalised properties); it never
   re-implements OR's evaluator.

### 2.2 Literal-leak guard (this app's layer only — REQ)

Project memory records a known bug class (`oc-mapping-literal-leak`):
OpenConnector's `sourceTargetMapping` returns the **literal dot-path string**
when a bare-path source key is absent, rather than null or an error — e.g.
`tender.procedure = "procedure.omschrijving"` for a market consultation
lacking that key. `FormFieldMapper` deliberately does **not** replicate this:

- `{"type": "from", "value": "<key>"}` — if `<key>` is absent from the
  submitted values, `FormFieldMapper::resolve()` throws
  `MappingResolutionException` naming the field and the missing key. It
  never returns the key name itself.
- `{"type": "template", "value": "Aanvraag: {{aanvraagType}}"}` — if
  `aanvraagType` is absent, the same exception is thrown. It never returns
  the unexpanded `"Aanvraag: {{aanvraagType}}"` string as if it were real
  data (the literal-leak failure mode this guards against).
- `{"type": "const", "value": "..."}` always resolves (no key lookup).

### 2.3 Unmapped-field policy

A `fieldMapping` config entry is **opt-in per field**: a target field simply
absent from `fieldMapping` is intentionally unmapped (valid for the optional
`priority` field). But **every mandatory contract field (`title`, `summary`,
`channel`) MUST be a key in `fieldMapping`** — validated when the mapping
record is loaded (`FormFieldMapper::validateConfig()`); a config missing a
mandatory key is rejected before any submission is processed against it.
**Any field that IS present in `fieldMapping` MUST resolve at runtime** (per
§2.2) — there is no silent-omit path for a declared-but-unresolvable mapping,
mandatory or optional. This removes the ambiguity a runtime "policy" flag
would otherwise need: the policy is entirely expressed by whether the admin
declared the field in config.

Consequence: an unresolved mandatory field (a submission missing a raw value
the mapping depends on) fails only *that* submission (`status=failed`,
`errorDetail` set) — per-submission isolation (§3), never a partial/silent
Case with a blank title.

## 3. Submission lifecycle & isolation

`openformulieren_submission.status`: `received → mapped → handed_off |
failed`. Each submission is one OR object; `OpenFormulierenIntakeService::
ingest()` wraps mapping + attachment fetch in a single per-submission
try/catch — one submission's mapping error or unreachable attachment URL
never aborts or corrupts another submission's processing (each webhook POST
is one independent `ingest()` call; there is no shared mutable batch state).
`handoff()` similarly isolates: a `HandoffException`/`NotAuthorizedException`
during handoff execution updates only that submission's `status=failed` +
`errorDetail`, never throws past the controller as an unhandled 500.

## 4. Attachments

Verified: the `ns#Case` contract (§1.2) has **no attachment-carrying field**
— `title`/`summary`/`channel`/`source`/`requester`/`priority` only. So
attachments structurally **cannot** flow through the handoff mapping itself,
regardless of implementation effort here. Given that:

- At receipt time, `OpenFormulierenIntakeService` best-effort fetches each
  attachment ref (`GuzzleHttp\Client`, thin, no OF SDK) and stores it via
  `FileService::addFile()` onto the **submission object** (not the eventual
  Case) — each attachment's outcome (`fetched`/`failed` + `fileId`/`error`)
  is recorded per-entry in `submission.attachments`, isolated from the
  mapping outcome (a failed attachment fetch does not fail the submission's
  `mapped` status).
- On successful handoff, `handoff()` best-effort copies each successfully
  stored file from the submission object onto the newly created Case object
  via `FileService::copyFile()` (verified real signature, §1.5) — again
  isolated per file; a copy failure is logged and recorded, never fails the
  already-completed handoff.
- **Documented follow-up** (not built here): the `ns#Case` kind contract
  itself has no attachment field to formally carry "this case has N
  attachments" through the handoff engine's own provenance/audit trail; a
  future hydra contract-spec change could add one. Out of scope for this
  bridge, which works around it via the copy-after-handoff best effort above.

## 5. Open Formulieren payload — binding assumption

Cannot be verified against a live Open Formulieren instance from this
environment. Binding assumption, documented so a future correction is
isolated to `OpenFormulierenController::inbound()`'s payload parsing:

```json
{
  "form": {"slug": "vergunning-aanvraag", "uuid": "...", "name": "Vergunning aanvraag"},
  "submission": {"uuid": "...", "submittedAt": "2026-07-14T10:00:00+02:00"},
  "values": {"aanvraagType": "kapvergunning", "toelichting": "...", "...": "..."},
  "attachments": [{"key": "bijlage", "url": "https://...", "filename": "foto.jpg", "contentType": "image/jpeg"}],
  "auth": {"plugin": "digid", "bsn": "111222333"}
}
```

Signature: `X-OpenFormulieren-Signature` header (configurable per source,
default header name), verified via the existing
`WebhookSignatureService::verify()` (Stripe-style `t=<unix>,v1=<hex>`,
already proven for three other inbound webhooks — no new scheme introduced,
per the task's explicit "implement a thin receiver, not an SDK dependency").
BSN/KvK, when present, are stored on the submission's `authContext` property
(OR-managed, same RBAC tier as the rest of the object) and are **never
logged** (mirrors how `RestNotifyNlProvider` never logs decrypted secret
material).
