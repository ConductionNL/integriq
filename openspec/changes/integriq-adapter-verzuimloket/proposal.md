---
kind: code
---

# Proposal: integriq-adapter-verzuimloket

## Summary

Give the `leerplicht` DataExchangeJob a live wire adapter: a DUO Verzuimloket
(VSV-M2M) provider seam over Edukoppeling transport for the 16-uur/4-weken
melding (Leerplichtwet art. 21a), with DUO acknowledgement handling. learniq
already declares the job type and composes the leerplicht dossier
(`AttendanceFlag` + resolved `AttendanceRecord`s + interventions); today
nothing sends it. This change is the adapter only, per D3's abstract-
integration split, and reuses the ROD adapter's provider-seam pattern
(`integriq-adapter-rod`) rather than inventing a new shape.

## Motivation

`M3-integrations.md` row I2 (learniq round 1 competitor comparison,
2026-09-25) finds `leerplicht` declared on learniq's side with "detection
never fires" (m1#13.6) — this has since been partly addressed by
`attendance-threshold-calculation` (D02), which gives `AttendanceFlag` a
real lifecycle so the 16-uur crossing can fire; the wire adapter to
actually transmit the resulting melding to DUO is still missing. Every
comparable LAS reports a live connection: ParnasSys ("Verzuimregister
digital reporting: 16u/4wk, LRV, herhaalmeldingen", parnassys#4.8,13.6),
po-las (verzuimmeldingen to DUO Verzuimregister), vo-las (dedicated
Magister training course "verzuimkoppeling-duo", EUR 407) and mbo-he-sis
("SIS=>DUO Verzuimloket `/student/verzuimmelding`"). `decisions.md` D3
assigns the adapter to integriq (MUST, size L).

`recon/legal-po-2026-09-25.md`'s legal checklist states the deadline
directly: "16 uur/4 weken to verzuimloket within 5 werkdagen" — a school
crossing the Leerplichtwet art. 21a threshold (16 unexcused lesuren within
a rolling 4-week window) must report to DUO Verzuimloket within 5 working
days. learniq's own `AttendanceThreshold` schema already models exactly
this rule (`kind: leerplicht-16uur`, `window: {type: rolling-weeks, weeks:
4}`, `metric: unexcused-lesuren`, `limit: 16`) — confirmed by reading
`lib/Settings/learniq_register.json` directly in the sibling `lq-contracts`
checkout (read-only; this lane never edits another lane's directory).
learniq does not yet model langdurig relatief verzuim (LRV) or
herhaalmelding as distinct `AttendanceThreshold.kind` values, so this
adapter accepts a caller-supplied `meldingType` to express DUO's fuller
melding vocabulary even though only the 16-uur trigger fires from learniq
today — an explicit, documented assumption, not fabricated learniq schema.

Per `DataExchangeRunGuard::GATED_TARGETS` (read in the sibling checkout),
`leerplicht` is NOT one of the gated targets (`oso`, `swv` are) — a
leerplicht report is a mandatory statutory report, not a discretionary
transfer requiring pending-review, so this adapter transmits on dispatch
without duplicating a review gate.

## Affected Projects

- [x] Project: `integriq` — new Verzuimloket provider seam, Edukoppeling
      binding, mock binding, acknowledgement translation, audit
      persistence, retry job, push/retour endpoints, catalogue card
      (ADR-017 Rule 1)

## Scope

### In Scope

- `VerzuimloketProviderInterface` with `getProviderId()`, `getConfigSchema()`,
  `send(sourceConfiguration, meldingType, kenmerk, payload)`, mirroring
  `RodProviderInterface`.
- Two bindings: `log` (default) and `edukoppeling`
  (`VerzuimloketEdukoppelingClient`, reusing `DigikoppelingAdapter`'s WUS
  transport and `PkiOverheidCredentialResolver` — the same DUO
  certificate-by-reference pattern as ROD, and the same M3(c) governance
  gate, since ROD/Verzuim/OSO/Doorstroomtoets share "1 certificaat per
  softwareleverancier", per `recon/legal-po-2026-09-25.md` and
  `parnassys#13.1`).
- A `VerzuimloketEnvelopeTranslator` for three melding kinds:
  `eerste-melding` (the initial 16-uur/4-weken report), `herhaalmelding`
  (a repeat report for the same pupil), and `langdurig-relatief-verzuim`
  (LRV) — covering the vocabulary named in `M3-integrations.md` row I2 even
  though learniq's `AttendanceThreshold` only computes the first kind
  today. Carries `breachingRecords` (resolved attendance records) and
  `interventions` from the composed dossier, per
  `DataExchangePayloadBuilder::composeLeerplichtFile()`.
- Acknowledgement handling: a `VerzuimloketAcknowledgementReceivedEvent`
  (ADR-041), mirroring `RodAcknowledgementReceivedEvent`, for learniq's own
  correction/worklist mechanism (whatever it may be — this change does not
  presume `ExchangeRejectionDetail` also covers leerplicht; it emits a
  generically-named event learniq can subscribe to).
- Per-message audit persistence (`verzuim_message`) and an hourly
  `VerzuimloketRetryJob`, mirroring ROD's `rod_message`/`RodRetryJob`
  exactly.
- `POST /api/verzuimloket/berichten` and `POST /api/verzuimloket/retour`,
  HMAC-verified inbound, mirroring `RodController`.
- A catalogue descriptor (`VerzuimloketAdapter`, ADR-017 Rule 1).
- Fixtures and PHPUnit contract tests for the mock binding, envelope shape,
  and acknowledgement translation.

### Out of Scope

- The DUO software-vendor certificate itself (M3(c), open) — same
  operational gate as ROD, not code.
- The 5-werkdagen deadline enforcement itself. That is learniq's own
  `attendance-threshold-calculation`/lifecycle concern (D02) — this
  adapter transmits whatever melding learniq's job dispatches, whenever it
  dispatches it; it does not compute or police the deadline.
- Modelling LRV or herhaalmelding as learniq `AttendanceThreshold.kind`
  values — that is a learniq-side schema change D3 leaves for learniq to
  make; this adapter's `meldingType` parameter is forward-compatible with
  it (a free-form string on the wire, not an enum learniq must match
  today).
- `bron-rod` and `oso` job types — separate changes
  (`integriq-adapter-rod`, shipped; `integriq-adapter-oso`, next in this
  lane) even though they share the DUO certificate gate.

## Approach

Add `lib/Service/Verzuimloket/` alongside `lib/Service/Rod/`, following the
identical shape: interface, log provider, Edukoppeling provider (thin
wrapper over the same Digikoppeling transport classes ROD uses), envelope
translator with a literal-leak guard, acknowledgement translator, a
`VerzuimloketService` orchestrating send/retour/retry (mirrors
`RodService`), a controller, an OR schema for `verzuim_message`, and a
`VerzuimloketRetryJob`.

## New Dependencies

None. Reuses the same Digikoppeling transport, `PkiOverheidCredentialResolver`
and `WebhookSignatureService` as `integriq-adapter-rod`.

## Impact

- New: `lib/Service/Verzuimloket/*`, `lib/Service/VerzuimloketService.php`,
  `lib/Adapters/Verzuimloket/VerzuimloketAdapter.php`,
  `lib/Controller/VerzuimloketController.php`,
  `lib/BackgroundJob/VerzuimloketRetryJob.php`,
  `lib/Event/VerzuimloketAcknowledgementReceivedEvent.php`,
  `lib/Settings/integriq_register.json` (`verzuim_message` schema
  addition), `appinfo/routes.php` (two new routes).
- No existing file's public behaviour changes.

## Cross-Project Dependencies

learniq: `leerplicht` job type, `AttendanceFlag`/`AttendanceThreshold`
schemas and `DataExchangePayloadBuilder::composeLeerplichtFile()` already
exist. `attendance-threshold-calculation` (D02) is what makes the 16-uur
crossing fire at all — without it, no job ever reaches this adapter, but
that is a learniq-side prerequisite, not a blocker for building the
adapter itself.

## Risks

### Risk 1: `meldingType` vocabulary (herhaalmelding, LRV) is forward-looking, not yet triggered by learniq
**Severity:** Low — **Mitigation:** the translator accepts any of the three
kinds now; when learniq eventually models LRV/herhaalmelding as distinct
threshold kinds, no integriq change is needed, only a learniq-side
`meldingType` value change.

### Risk 2: Same DUO certificate gate as ROD blocks live traffic
**Severity:** Low — **Mitigation:** identical fail-closed shape as
`RodEdukoppelingClient`; the mock/log path, translation, audit and retry
are fully testable now.

## Rollback Strategy

Revert the branch. No migration touches existing data; only adds a new
`verzuim_message` schema and two new routes.

## Open Questions

- Who holds the DUO software-vendor certificate centrally (M3(c), open in
  `decisions.md`) — shared with ROD and OSO, not new to this change.
