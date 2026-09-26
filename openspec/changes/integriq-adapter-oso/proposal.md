---
kind: code
---

# Proposal: integriq-adapter-oso

## Summary

Give the `oso` DataExchangeJob a live wire adapter over Kennisnet's
Overstapservice Onderwijs (OSO), both directions: export (an overstapdossier
leaving this school, already gated by learniq's own `OsoDossierReviewGuard`
parent-review lifecycle) and import (an overstapdossier arriving from
another school). learniq already declares the job type and, on the export
side, the review gate; a sibling learniq change
(`oso-inbound-contract`) adds the import-side schema
(`OsoImportDossier`). This change is the adapter only, per D3's
abstract-integration split, mirroring `integriq-adapter-rod`'s provider-
seam shape.

## Motivation

`M3-integrations.md` row I3 (learniq round 1 competitor comparison,
2026-09-25) finds `oso` declared on learniq's side as "job type `oso`,
parent-review gate, review view is a dark page" (m1#3.5) — export-only,
against every PO/VO competitor in the round: ParnasSys ("full export
(data-minimisation, parent inzage) + import flow"), po-las/esis ("Inlezen
in ESIS"), vo-las/magister ("Leerling registreren vanuit het OSO dossier").
`decisions.md` D3 assigns the adapter to integriq (MUST, size L),
explicitly noting "export review already has a lifecycle gate
(`OsoDossierReviewGuard`), import is new".

Read (read-only) the sibling `lq-contracts` checkout, which is building
learniq's own data-exchange contracts in this same round: its
`oso-inbound-contract` change (committed at `78b8ddb` on
`feat/oso-inbound-contract`, verified all-green per its own lane log — a
push-tooling issue unrelated to content blocks only the PR, not the
commit) adds `OsoImportDossier` (`dataExchangeJobId`, `sourceSchoolBrin`,
`learnerEckId`, `receivedAt`, `categories[]` — `{category, included, data}`
with an illustrative starter enum `basisgegevens|onderwijskundig-rapport|
uitstroomgegevens|toetsgegevens|verzuimgegevens|zorggegevens` —
`draftProfile` (nullable snapshot, NOT a live `LearnerProfile`),
`attachmentRefs[]`, `rejectionReason`, `reviewedBy`/`reviewedAt`), with a
lifecycle `received` → `under-review` → `accepted`|`rejected` gated by
`OsoImportAcceptGuard`/`OsoImportRejectGuard`. This adapter's inbound leg is
designed directly against that schema's field names so no rename is needed
once it merges — flagged as read from an uncommitted-to-`development`
sibling branch, not fabricated.

Also read (read-only): `DataExchangeRunGuard::GATED_TARGETS = ['oso',
'swv']` confirms `oso` is one of the two targets gated on
pending-parent-review before a job leaves `queued` — by the time an export
job reaches this adapter, learniq's own review has already happened. This
adapter does not duplicate that check.

## Affected Projects

- [x] Project: `integriq` — new OSO provider seam (export + import),
      Edukoppeling/HTTPS binding, mock binding, category-aware envelope
      translation, inbound XML parsing, audit persistence, retry job,
      push/pull/retour endpoints, catalogue card (ADR-017 Rule 1)

## Scope

### In Scope

- `OsoProviderInterface` with `getProviderId()`, `getConfigSchema()`,
  `sendExport(sourceConfiguration, kenmerk, payload)`, mirroring the
  outbound half of `RodProviderInterface`.
- Two bindings: `log` (default) and `kennisnet` (`OsoKennisnetClient`,
  reuses `DigikoppelingAdapter`'s WUS transport and
  `PkiOverheidCredentialResolver` for the export leg — Kennisnet's own
  OSO aansluiting approval is the separate M3(c) governance gate, not a
  DUO certificate, but the transport machinery is the same shape).
- `OsoExportEnvelopeTranslator`: builds the outbound overstapdossier
  envelope from learniq's already-approved export payload (categories,
  attachments, learner identity) with a literal-leak guard. Does NOT
  re-check parent-review — the job reaching this adapter already implies
  `DataExchangeRunGuard` cleared it.
- `OsoImportTranslator`: parses an inbound OSO XML overstapdossier
  (received via `POST /api/oso/import`) into the field set
  `OsoImportDossier` expects (`sourceSchoolBrin`, `learnerEckId`,
  `categories[]`, `draftProfile` fields, `attachmentRefs[]`), with XXE
  hardening via the shared `StufXmlParser`.
- `OsoDossierReceivedEvent` (ADR-041 typed event): dispatched with the
  parsed import fields for learniq's own `DataMappingProfile`-driven
  listener (part of `oso-inbound-contract`, not this change) to
  materialise into `OsoImportDossier`. Integriq never writes learniq's
  schema directly, per D3.
- `OsoAcknowledgementReceivedEvent`: for the export leg's DUO-style
  acknowledgement, mirroring `RodAcknowledgementReceivedEvent`.
- Per-message audit persistence (`oso_message`) and an hourly
  `OsoRetryJob`, mirroring `rod_message`/`RodRetryJob`.
- `POST /api/oso/export` (push, authenticated), `POST /api/oso/import`
  (Kennisnet delivering an inbound dossier, HMAC-signed), `POST
  /api/oso/retour` (export acknowledgement, HMAC-signed).
- A catalogue descriptor (`OsoAdapter`, ADR-017 Rule 1).
- Fixtures and PHPUnit contract tests for both directions.

### Out of Scope

- `OsoImportDossier`'s schema, lifecycle and guards — that is
  `oso-inbound-contract`, a learniq-side change already built in a sibling
  lane, not this one. This adapter only dispatches the event that
  change's listener would consume.
- The dead `OsoDossierReviewView` registry bug (D01,
  `registry-component-fix`) — a separate learniq-side frontend fix, not
  blocking this adapter's backend code.
- The Kennisnet OSO aansluiting approval itself (M3(c), open) — an
  operational/governance gate, not code.
- Re-implementing the parent-review gate — already shipped
  (`OsoDossierReviewGuard`) and enforced before the job reaches this
  adapter (`DataExchangeRunGuard::GATED_TARGETS`).

## Approach

Add `lib/Service/Oso/` alongside `lib/Service/Rod/` and
`lib/Service/Verzuimloket/`: interface, log provider, Kennisnet provider
(thin wrapper over the shared Digikoppeling transport), an export
translator and an import translator (two directions, two translators, one
provider interface for the outbound leg since import is push-received, not
provider-dispatched), an `OsoService` orchestrating export/import/retour/
retry, a controller, an OR schema for `oso_message`, and an `OsoRetryJob`.

## New Dependencies

None. Reuses the same Digikoppeling transport, `PkiOverheidCredentialResolver`,
`StufXmlParser` and `WebhookSignatureService` as the other two adapters in
this lane.

## Impact

- New: `lib/Service/Oso/*`, `lib/Service/OsoService.php`,
  `lib/Adapters/Oso/OsoAdapter.php`, `lib/Controller/OsoController.php`,
  `lib/BackgroundJob/OsoRetryJob.php`,
  `lib/Event/OsoDossierReceivedEvent.php`,
  `lib/Event/OsoAcknowledgementReceivedEvent.php`,
  `lib/Settings/integriq_register.json` (`oso_message` schema addition),
  `appinfo/routes.php` (three new routes).
- No existing file's public behaviour changes.

## Cross-Project Dependencies

learniq: `oso` job type and `OsoDossierReviewGuard` already exist.
`oso-inbound-contract` (sibling lane, committed but not yet on
`development`) adds `OsoImportDossier` — this adapter's import event shape
is designed against it directly; if that schema's field names change before
merge, this adapter's event payload would need a matching follow-up (noted
as a real, not hypothetical, coupling risk given both changes are in
flight simultaneously).

## Risks

### Risk 1: `OsoImportDossier`'s field names could still shift before merge
**Severity:** Medium — **Mitigation:** `oso-inbound-contract` is already
committed (not merely proposed) with a full green verification pass
recorded in its own lane log; the field shape is stable enough to design
against. If it does shift, only `OsoDossierReceivedEvent`'s constructor
and `OsoImportTranslator`'s output keys need a follow-up — isolated to two
files.

### Risk 2: Kennisnet OSO aansluiting approval blocks live traffic
**Severity:** Low — **Mitigation:** same shape as ROD/Verzuimloket's DUO
certificate gate; mock/log path, both translators, audit and retry are
fully testable now.

## Rollback Strategy

Revert the branch. No migration touches existing data; only adds a new
`oso_message` schema and three new routes.

## Open Questions

- Whether `oso-inbound-contract` merges before or after this change —
  either order works since this change never writes learniq's schema
  directly, only dispatches an event.
