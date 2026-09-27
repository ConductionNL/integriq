---
kind: code
---

# Proposal: integriq-adapter-rod

## Summary

Give the `bron-rod` DataExchangeJob a live wire adapter: a DUO ROD (Register
Onderwijsdeelnemers) provider seam over Edukoppeling transport, with
acknowledgement and DUO signaalcode handling that feeds learniq's
`ExchangeRejectionDetail` worklist through a typed event. learniq already
declares the job type and composes the payload (inschrijving, leerjaar,
groep, OPP dates, schooladvies); today nothing sends it. This change is the
adapter only, per D3's abstract-integration pattern (learniq: contract,
integriq: wire).

## Motivation

`M3-integrations.md` row I1 (learniq round 1 competitor comparison,
2026-09-25) finds `bron-rod` declared on learniq's side with "no adapter"
(m1#13.1), while every comparable LAS in the corpus — ParnasSys ("1
certificaat per softwareleverancier" for ROD/Verzuim/OSO/Doorstroomtoets,
parnassys#13.1), po-las (direct/real-time exchange to DUO on save, DUO
signaalcodes, po-las#3.3,13.1), vo-las and mbo-he-sis all report a live ROD
connection. `decisions.md` D3 assigns every such adapter to integriq, split
one change per connection family; this is the ROD family
(`integriq-adapter-rod`, MUST, size L). The legal-research pass
(`recon/legal-po-2026-09-25.md`, checklist item 1) confirms ROD submission
is a statutory 7-day deadline from the triggering event (inschrijving,
uitschrijving, or a leerjaar/groep change) — the adapter itself does not
enforce that deadline (that is learniq's `attendance`/enrolment-side timing,
outside D3's adapter split), but it is why the job type exists as a
same-day dispatch rather than a batched export.

Integriq already owns the Digikoppeling M2M transport
(`lib/Adapters/Digikoppeling/DigikoppelingAdapter.php`, WUS and ebMS2
profiles, PKIoverheid signing via `PkiOverheidCredentialResolver`) and the
provider-seam pattern with mock/log + REST bindings used for
`iwmo-ijw-adapter` and `berichtenbox-digital-post-adapter`. Edukoppeling is
the education sector's profile built on the same Digikoppeling transport
conventions (WUS for synchronous bevragingen, ebMS2 for asynchronous
meldingen), so this change reuses that transport rather than inventing a new
one.

## Affected Projects

- [x] Project: `integriq` — new ROD provider seam, Edukoppeling binding, mock
      binding, acknowledgement/signaalcode translation, audit persistence,
      retry job, push/retour endpoints, catalogue card (ADR-017 Rule 1)

## Scope

### In Scope

- `RodProviderInterface` with `getProviderId()`, `getConfigSchema()`,
  `send(sourceConfiguration, berichtsoort, payload)`, mirroring
  `IwmoIjwProviderInterface`/`DigitalPostProviderInterface`.
- Two bindings: `log` (default, no configuration, returns a synthetic
  `MOCK-ROD-<n>` ref) and `edukoppeling` (`RodEdukoppelingClient`, built on
  `DigikoppelingAdapter`'s WUS/ebMS2 transport and
  `PkiOverheidCredentialResolver` — credentials by reference, never by
  value).
- An outbound `RodMessage` DTO for the four `berichtsoort` kinds the corpus
  evidence names: `inschrijving`, `uitschrijving`, `verblijfsgegevens`
  (leerjaar/groep changes) and `schooladvies`. learniq's payload builder
  supplies the field values; integriq only shapes the Edukoppeling envelope
  and transmits it.
- Acknowledgement/signaalcode handling: a `RodAcknowledgementReceivedEvent`
  (ADR-041 typed event) carrying the DUO signaalcode, its description, and
  the originating `kenmerk`, so learniq's own `ExchangeRejectionDetail`
  worklist can subscribe without integriq knowing learniq's schema.
- Per-message audit persistence (`rod_message` OR record: direction,
  berichtsoort, status, ref, kenmerk, signaalcode, error, syncedAt) and an
  hourly `RodRetryJob` re-attempting `failed`/`pending` rows, one-message
  isolation (one failure does not abort the sweep), mirroring
  `IwmoIjwRetryJob`.
- `POST /api/rod/berichten` (an authenticated sibling app dispatches a
  message) and `POST /api/rod/retour` (DUO's asynchronous acknowledgement,
  HMAC-verified via the existing `WebhookSignatureService` before any
  processing, always acknowledging `{received: true}` once verified).
- A catalogue descriptor (`RodAdapter`, ADR-017 Rule 1 — a card in the
  Adapters catalogue plus a JSON configuration schema, never a new menu item
  or `/beheer` route).
- Fixtures and PHPUnit contract tests for the mock binding and the
  Edukoppeling envelope shape, plus signaalcode-to-event translation.

### Out of Scope

- The DUO software-vendor certificate itself and who holds it centrally
  (M3(c), open in `decisions.md`) — an operational/governance gate, not
  code. `RodEdukoppelingClient`'s live network leg is written but refuses
  to run without a configured certificate reference, naming what is
  missing, the same shape as `BerichtenboxClientUnavailable`.
- learniq's `ExchangeRejectionDetail` schema and worklist UI — learniq-owned
  per D3; this change only emits the event learniq subscribes to.
- The exact DUO ROD berichtdefinitie/XSD. It was not in the corpus read for
  this change (the closest primary source, `mbo-he-sis/round1/sources.md`'s
  Osiris SURF DPIA appendix, documents endpoint shape for MBO's
  Landelijk koppelvlak, not PO/VO ROD's own message schema). The envelope
  shape here follows the Edukoppeling/StUF convention already used by
  `DigikoppelingAdapter` and `iwmo-ijw-adapter` as the best-evidenced
  structural analogue — **this is an assumption, not a verified DUO
  contract**, and is called out again in design.md. Field-level validation
  against DUO's real berichtdefinitie is deferred to DUO test-environment
  access, which itself waits on the certificate (M3(c)).
- `leerplicht` (verzuimloket) and `oso` job types — separate changes
  (`integriq-adapter-verzuimloket`, `integriq-adapter-oso`) per D3's
  one-change-per-family split, even though all three share the DUO
  certificate gate.

## Approach

Add `lib/Service/Rod/` alongside the existing `lib/Service/IwmoIjw/` and
`lib/Service/DigitalPost/` provider seams: interface, registry, log
provider, Edukoppeling provider (thin wrapper over
`DigikoppelingAdapter`/`WusProfileService`/`Ebms2ReliableMessagingService`),
DTOs, a translator that maps a `berichtsoort` + field payload to an
Edukoppeling envelope with a literal-leak guard (no empty/null required
field reaches the XML, mirroring `iwmo-ijw-adapter` REQ-002), a controller
for the push/retour endpoints, an OR schema for `rod_message`, and a
`BackgroundJob\RodRetryJob`. Register the catalogue descriptor and DI
bindings in `Application.php` next to the existing adapter registrations.

## New Dependencies

None. Reuses `DigikoppelingAdapter`'s existing WUS/ebMS2 transport,
`PkiOverheidCredentialResolver`, and `WebhookSignatureService`.

## Impact

- New: `lib/Service/Rod/*`, `lib/Adapters/Rod/RodAdapter.php` (catalogue
  descriptor), `lib/Controller/RodController.php`,
  `lib/BackgroundJob/RodRetryJob.php`, `lib/Event/RodAcknowledgementReceivedEvent.php`,
  `lib/Settings/integriq_register.json` (`rod_message` schema addition),
  `appinfo/routes.php` (two new routes).
- No existing file's public behaviour changes; the Digikoppeling transport
  classes are read-only dependencies (constructor-injected), not modified.

## Cross-Project Dependencies

learniq: `bron-rod` job type and payload mapping already exist
(`DataExchangeRunHandler`, `DataExchangePayloadBuilder`, per
`decisions.md` line 80). learniq's `ExchangeRejectionDetail` worklist is the
intended subscriber of `RodAcknowledgementReceivedEvent`; wiring that
subscription is learniq's change to make, not this one's.

## Risks

### Risk 1: the Edukoppeling envelope shape is an assumption, not a verified DUO contract
**Severity:** Medium — **Mitigation:** the translator isolates envelope
construction behind one class (`RodEnvelopeTranslator`) with a literal-leak
guard and full fixture coverage, so a future correction against DUO's real
berichtdefinitie is a localized change, not a rewrite. Documented plainly in
design.md rather than presented as verified.

### Risk 2: the certificate gate means this ships code with no path to production traffic yet
**Severity:** Low — **Mitigation:** this is explicitly an operational gate
(M3(c)), not an engineering blocker; the mock/log binding and every
surrounding path (translation, audit, retry, catalogue card) are fully
buildable and testable now, and `RodEdukoppelingClient` refuses closed
rather than silently degrading when no certificate reference is configured.

## Rollback Strategy

Revert the branch. No migration touches existing data (only adds a new
`rod_message` schema and two new routes); no existing adapter or job type
is modified.

## Open Questions

- Who holds the DUO software-vendor certificate centrally for a self-hosted
  multi-tenant deployment (M3(c), open in `decisions.md`) — blocks
  `edukoppeling` binding activation, not this change's code.
