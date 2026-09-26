---
kind: code
---

# Proposal: integriq-adapter-uwlr-eduv

## Summary

Give learniq's data-exchange layer live wire adapters for the four
connection families the round-1 comparison found with zero or near-zero
implementation: UWLR (pupil/group/teacher export to Kennisnet-registered
publishers and test suppliers), Edu-V (three separately qualified data
services — Onderwijsdeelnemers, Onderwijsgroepen, Onderwijsmedewerkers),
Basispoort (PO pupil/group/staff sync + method/publisher SSO hand-off) and
Entree content SSO hand-off (the VO equivalent, distinct from Entree's own
login-federation use in `entree-surfconext-sso-contract`). A sibling
learniq change, `uwlr-eduv-basispoort-contract`, adds the `DataMappingProfile`
seeds and job-type declarations for all four targets; this change is the
wire adapter only, per D3's abstract-integration split, mirroring
`integriq-adapter-rod`/`-verzuimloket`/`-oso`'s provider-seam shape.

**Out of scope, explicitly:** UWLR's own "results back" import direction
(Cito/IEP/Boom/Dia test results) is a separate change,
`integriq-adapter-lvs-imports`, depending on a different sibling contract
(`lvs-import-contract`) that reuses the `LvsResult` schema. This change
ships UWLR/Edu-V/Basispoort/Entree-content **export and sync only**.

## Motivation

`M3-integrations.md` row I4 (learniq round 1 competitor comparison,
2026-09-25) finds "zero hits for UWLR" on learniq's own side (m1#13.3),
against ParnasSys ("UWLR link type reserved for suppliers"; partial Edu-V),
po-las/esis ("UWLR Leerlinggegevens" coupling type; Edu-V 5 components
qualified, keurmerk pending), vo-las/magister and vo-las/somtoday (ECK-iD/
SCIM as a UWLR alternative; Edu-V keurmerk, 13 qualified data services).
Row I6 finds the same zero-hit gap for Basispoort against ParnasSys's
explicit koppeling article (sends pupil/group/ECK-iD/staff/BRIN) and
po-las's automatic exchange. `decisions.md` D3 assigns all of these to
integriq (MUST, size L), and `change-plan.md`'s row for this change notes
"none of the three has an existing contract (`eckId` alone is done)".

Unlike ROD (7-day statutory deadline) and Verzuimloket (5-werkdagen
statutory deadline), `recon/legal-po-2026-09-25.md`'s legal checklist names
no statutory deadline for UWLR, Edu-V, Basispoort or Entree content —
these are operational data-exchange couplings a school chooses to activate
with a publisher or test supplier, not a legally mandated report. That
changes the urgency framing but not the build: D3 still assigns the
adapter to integriq, and `change-plan.md` schedules it in wave 15 (held to
the end alongside the other three DUO/Kennisnet-certification-gated
families) purely because M3(c)'s governance question is open, not because
the code is less real.

Read (read-only) the sibling `lq-contracts` checkout's
`uwlr-eduv-basispoort-contract` change (committed at `1c437d6` on
`feat/uwlr-eduv-basispoort-contract`, PR #914 open against learniq's
`development`): it ships four `DataMappingProfile` seeds —
`target: uwlr` (pupil/group/teacher export, each carrying `eckId`),
`target: edu-v` (three seeds, one per qualified data service, each naming
a distinct `targetSchema`: `EduV:Onderwijsdeelnemers`,
`EduV:Onderwijsgroepen`, `EduV:Onderwijsmedewerkers`), `target: basispoort`
(`direction: sync`) and `target: entree-content` (`direction: sync`). This
adapter's job-target names and payload shapes are designed directly
against those four seeds. Also read `entree-surfconext-sso-contract`
(committed, PR #925 open): it explicitly scopes itself to Entree
Federatie/SURFconext/eduID as learniq's own **login** boundary
(`user_saml`/`user_oidc`, M3-integrations row I7), separate from this
change's `entree-content` target, which hands a pupil off to a
**third-party** method/publisher site for content access, not learniq's
own authentication.

## Affected Projects

- [x] Project: `integriq` — new UWLR/Edu-V/Basispoort/Entree-content
      provider seam (one shared interface, four translators), mock and
      live bindings, audit persistence, retry job, four export/sync
      endpoints plus one shared acknowledgement endpoint, catalogue card
      (ADR-017 Rule 1)

## Scope

### In Scope

- `UwlrEduVProviderInterface` with `getProviderId()`, `getConfigSchema()`,
  `send(sourceConfiguration, target, subtype, envelopeXml)`, mirroring
  `OsoProviderInterface`'s outbound shape. One provider seam for all four
  targets since they share the same Kennisnet-adjacent transport family
  (M3-integrations groups I4/I6 under one governance discussion, and D3
  files them as one integriq change).
- Two bindings: `log` (default) and `uwlr-eduv` (`UwlrEduVKennisnetClient`,
  reuses the shared Digikoppeling transport and
  `PkiOverheidCredentialResolver`, same fail-closed shape as the other
  three adapters in this lane).
- `UwlrExportEnvelopeTranslator`: three subtypes (`pupil`, `group`,
  `teacher`), each carrying `eckId` per the sibling contract's scenario,
  with a literal-leak guard.
- `EduVExportEnvelopeTranslator`: three qualified-data-service subtypes
  (`onderwijsdeelnemers`, `onderwijsgroepen`, `onderwijsmedewerkers`), each
  naming its own `targetSchema`, with a literal-leak guard. Edu-V
  qualifies certification per data service, per product — the three
  subtypes are not interchangeable, matching the sibling contract's three
  distinct seeds.
- `BasispoortSyncTranslator`: PO pupil/group/staff export plus an SSO
  hand-off token for method/publisher content, `direction: sync`.
- `EntreeContentSyncTranslator`: VO content-access SSO hand-off,
  `direction: sync`, explicitly not a login-federation concern (that is
  `entree-surfconext-sso-contract`, a different learniq change entirely).
- `UwlrEduVAcknowledgementTranslator` + `UwlrEduVAcknowledgementReceivedEvent`
  (ADR-041), shared across all four targets — a single acknowledgement
  shape (`ref`, `signaalcode`-style status, `accepted`) since none of the
  four wire formats are publicly documented in the corpus in enough detail
  to justify four distinct ack shapes today (see design.md "Open
  Questions" — this is a deliberate simplification pending real WSDL/API
  access once certification is granted, not a hidden gap).
- Per-message audit persistence (`uwlr_eduv_message`, one schema for all
  four targets distinguished by a `target` field) and an hourly
  `UwlrEduVRetryJob`, mirroring the other three adapters.
- `POST /api/uwlr-eduv/uwlr`, `POST /api/uwlr-eduv/edu-v`,
  `POST /api/uwlr-eduv/basispoort`, `POST /api/uwlr-eduv/entree-content`
  (all four push/sync, authenticated), `POST /api/uwlr-eduv/retour`
  (shared acknowledgement, HMAC-signed).
- A catalogue descriptor (`UwlrEduVAdapter`, ADR-017 Rule 1).
- Fixtures and PHPUnit contract tests for all four targets plus the
  acknowledgement leg.

### Out of Scope

- UWLR's "results back" import direction (Cito/IEP/Boom/Dia) — that is
  `integriq-adapter-lvs-imports`, a separate change depending on the
  separate `lvs-import-contract` sibling, reusing `LvsResult` rather than
  a second results schema. This adapter transmits UWLR/Edu-V/Basispoort/
  Entree-content data outward (and Basispoort/Entree-content's SSO
  hand-off), nothing more.
- `entree-surfconext-sso-contract`'s login-federation boundary
  (`user_saml`/`user_oidc`) — a Nextcloud-app-level auth change, not a
  data-exchange adapter, and explicitly out of D3's abstract-integration
  pattern.
- The `uwlr-eduv-basispoort-contract` `DataMappingProfile` seeds
  themselves — that is the sibling learniq change (committed, PR #914
  open), not this one.
- Edu-V keurmerk certification (per data service, M3(c), open) and the
  UWLR/Basispoort connection agreements themselves — operational/
  governance gates, not code.

## Approach

Add `lib/Service/UwlrEduV/` alongside `lib/Service/{Rod,Verzuimloket,Oso}/`:
one provider interface/registry/log-provider/live-client for the shared
transport, four target-specific envelope translators (no provider
dispatch needed for the two sync targets either — Basispoort/Entree-content
still go through the same provider seam, "sync" here describes learniq's
own scheduling cadence, not a different transport shape), one shared
acknowledgement translator, one `UwlrEduVService` orchestrating all four
sends plus the shared retour leg and retry, one controller with five
routes, one OR schema (`uwlr_eduv_message`) distinguishing targets via a
`target` field, and one `UwlrEduVRetryJob`.

## New Dependencies

None. Reuses the same Digikoppeling transport, `PkiOverheidCredentialResolver`,
`StufXmlParser` and `WebhookSignatureService` as the other three adapters
in this lane.

## Impact

- New: `lib/Service/UwlrEduV/*`, `lib/Service/UwlrEduVService.php`,
  `lib/Adapters/UwlrEduV/UwlrEduVAdapter.php`,
  `lib/Controller/UwlrEduVController.php`,
  `lib/BackgroundJob/UwlrEduVRetryJob.php`,
  `lib/Event/UwlrEduVAcknowledgementReceivedEvent.php`,
  `lib/Settings/integriq_register.json` (`uwlr_eduv_message` schema
  addition), `appinfo/routes.php` (five new routes).
- No existing file's public behaviour changes.

## Cross-Project Dependencies

learniq: `uwlr-eduv-basispoort-contract` (sibling lane, committed, PR #914
open against `development`) supplies the `DataMappingProfile` seeds this
adapter's target/subtype names are designed against. `entree-surfconext-sso-contract`
(committed, PR #925 open) is read only to confirm the boundary between its
login-federation concern and this change's `entree-content` data hand-off
— no code dependency between them.

## Risks

### Risk 1: the four wire formats' real acknowledgement shapes are not documented in the corpus
**Severity:** Medium — **Mitigation:** a single generic ack shape
(`ref`/status/`accepted`) is used for all four targets today, explicitly
flagged as a simplification in design.md. None of UWLR, Edu-V, Basispoort
or Entree-content traffic can go live before their respective
certification/aansluiting is granted (M3(c)) — by the time real traffic
flows, the actual WSDL/API contract will be available to correct this
translator, isolated to one file (`UwlrEduVAcknowledgementTranslator`).

### Risk 2: `DataMappingProfile` seed field names could still shift before `uwlr-eduv-basispoort-contract` merges
**Severity:** Low — **Mitigation:** same shape as `integriq-adapter-oso`'s
Risk 1; that change is committed (not merely proposed) with PR #914 open,
so the seed shape is stable enough to design against. This adapter never
writes learniq's `DataMappingProfile` records directly — it only accepts
a payload shaped by them, so a rename is isolated to translator field
lookups, not a schema migration.

### Risk 3: Edu-V keurmerk is certified per data service, not once for the family
**Severity:** Low, operational not architectural — **Mitigation:** the
three Edu-V subtypes are already modelled as three distinct translator
paths (not one generic "edu-v export"), so certifying (or decertifying) a
single data service never blocks the other two — this is a governance
fact reflected directly in the code shape, not just noted in prose.

## Rollback Strategy

Revert the branch. No migration touches existing data; only adds a new
`uwlr_eduv_message` schema and five new routes.

## Open Questions

- Whether `uwlr-eduv-basispoort-contract` merges before or after this
  change — either order works since this change never writes learniq's
  `DataMappingProfile` records directly, only accepts a payload shaped by
  them.
- The real UWLR/Edu-V/Basispoort wire formats (SOAP vs. REST, exact
  acknowledgement shape) are gated behind certification the same way DUO's
  ROD/Verzuimloket formats are — this proposal deliberately does not
  invent WSDL-level detail the corpus does not evidence.
