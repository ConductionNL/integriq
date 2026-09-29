# Design: integriq-adapter-rod

## Architecture Overview

```
learniq (bron-rod job)                 integriq                          DUO
  DataExchangeRunHandler   --POST-->    RodController::berichten()
  DataExchangePayloadBuilder             -> RodService::sendBericht()
                                             -> RodEnvelopeTranslator (literal-leak guard)
                                             -> RodProviderRegistry
                                                  -> LogRodProvider (default)
                                                  -> RodEdukoppelingClient --WUS/ebMS2-->  ROD koppelvlak
                                             -> persists rod_message (audit)
  ExchangeRejectionDetail  <--event--    RodAcknowledgementReceivedEvent
  (learniq's own listener,                    <- RodAcknowledgementTranslator
   not part of this change)                       <- RodController::retour() <--HMAC-signed retour-- DUO
```

This is the same provider-seam shape as `iwmo-ijw-adapter`
(`IwmoIjwProviderInterface`, log + rest) and
`berichtenbox-digital-post-adapter` (`DigitalPostProviderInterface`, log +
berichtenbox/postex), applied to DUO ROD. `RodEdukoppelingClient` is a thin
wrapper: it builds the envelope, then hands the signed body to
`DigikoppelingAdapter`'s existing WUS (synchronous) or ebMS2 (asynchronous)
transport rather than opening its own HTTP client — Edukoppeling is the
education sector's profile of the same Digikoppeling transport standards.

## API Design

### `POST /api/rod/berichten`
See contract.md — request/response shapes are identical, this section
exists to satisfy the schema's cross-reference requirement.

### `POST /api/rod/retour`
See contract.md.

## Database Changes

One new OpenRegister schema, `rod_message`, added to
`lib/Settings/integriq_register.json` (append, not a new register — same
pattern as `iwmo_ijw_message` and `digitalPostMessage`):

| Field | Type | Notes |
|---|---|---|
| direction | string enum (`outbound`\|`inbound`) | |
| berichtsoort | string enum (`inschrijving`\|`uitschrijving`\|`verblijfsgegevens`\|`schooladvies`) | |
| status | string enum (`sent`\|`failed`\|`pending`\|`acknowledged`\|`rejected`) | |
| ref | string, nullable | provider-returned reference |
| kenmerk | string | caller-supplied correlation id, indexed |
| signaalcode | string, nullable | DUO signal code on the retour leg |
| signaalOmschrijving | string, nullable | |
| bsnHash | string, nullable | SHA-256 of the BSN, never the raw value (REQ-006) |
| error | string, nullable | |
| syncedAt | datetime | |

No migration class is needed beyond the schema-register JSON patch — OR
schemas are declarative (ADR-031 default path); see migration.md for the
patch itself and its rollback.

## Nextcloud Integration

- Controllers: `lib/Controller/RodController.php` (`berichten`, `retour`)
- Services: `lib/Service/Rod/RodService.php`,
  `lib/Service/Rod/RodProviderRegistry.php`,
  `lib/Service/Rod/RodEnvelopeTranslator.php`,
  `lib/Service/Rod/RodAcknowledgementTranslator.php`
- Providers: `lib/Service/Rod/LogRodProvider.php`,
  `lib/Service/Rod/RodEdukoppelingClient.php` (constructor-injects
  `DigikoppelingAdapter`, `WusProfileService`, `Ebms2ReliableMessagingService`,
  `PkiOverheidCredentialResolver`)
- Adapters (catalogue, ADR-017 Rule 1): `lib/Adapters/Rod/RodAdapter.php`
- Mappers/Entities: `RodMessage`/`RodMessageMapper` generated the same way
  `IwmoIjwMessage` is, from the schema register entry (OR-backed, no bespoke
  `QBMapper`)
- Events/Hooks: `lib/Event/RodAcknowledgementReceivedEvent.php` (ADR-041)
- BackgroundJob: `lib/BackgroundJob/RodRetryJob.php` (hourly `TimedJob`)

## Declarative-vs-imperative decision (ADR-031)

This whole change is an **external integration** (transport to a
third-party government koppelvlak with signing, envelope translation and
acknowledgement handling) — one of ADR-031's named exceptions to the
declarative default. `RodRetryJob` is scheduled bulk work over rows with
real side effects (a network call per row), also a named exception, not a
derived/aggregated field. No lifecycle, aggregation, calculation,
notification or dashboard-widget behaviour is introduced by this change, so
no `x-openregister-*` declarative block applies here; `rod_message` is a
plain audit schema (data, not behaviour).

## Security Considerations

- Auth: `berichten` requires an authenticated NC session
  (`#[NoAdminRequired]`); `retour` is `#[PublicPage]` + HMAC verification via
  the existing `WebhookSignatureService` before any processing (REQ-004).
- No PEM ever appears in a method signature, source configuration, or
  app-config key — `dispatch()`/`send()` take a `certificateRef` string,
  resolved to signing material inside `PkiOverheidCredentialResolver` for
  the instant needed (mirrors `berichtenbox-digital-post-adapter` REQ-DPA-004).
- BSN hygiene: raw BSN travels in the outbound envelope (legally required),
  hashed (SHA-256) before any persistence (REQ-006), consistent with
  `AvgBsnPolicyRule`.
- Input validation: `RodEnvelopeTranslator` raises before building any XML
  when a required field is missing/null/empty (REQ-002) — the literal-leak
  guard scans the rendered envelope for `{{`/`}}`/`%%UNRESOLVED%%` markers
  as defense in depth, mirroring `iwmo-ijw-adapter`.

## File Structure

```
lib/
  Adapters/Rod/RodAdapter.php
  Controller/RodController.php
  Service/Rod/
    RodProviderInterface.php
    RodProviderRegistry.php
    LogRodProvider.php
    RodEdukoppelingClient.php
    RodEnvelopeTranslator.php
    RodAcknowledgementTranslator.php
    RodService.php
    RodProviderException.php
    RodTranslationException.php
  Event/RodAcknowledgementReceivedEvent.php
  BackgroundJob/RodRetryJob.php
  Settings/integriq_register.json (rod_message schema appended)
appinfo/routes.php (2 routes appended)
tests/unit/Service/Rod/*Test.php
tests/fixtures/rod/*.json
```

## Seed Data

Deliberately none, checked against precedent rather than assumed: neither
`iwmo_ijw_message` nor `digitalPostMessage` (this app's two closest
audit-log schemas, both from a fully-shipped adapter change) carry any rows
in `lib/Settings/integriq_seed_data.json`, and of the eight seeded
`source` rows only three adapter families (peppol, psd2, cardfeed) seed a
source at all — most, including every StUF/iStandaarden/Digikoppeling
family, seed neither. A fresh install showing a pre-populated "already
synced to DUO" audit trail would misrepresent the instance's real state
more than it would help a first-run demo. The fixtures under
`tests/fixtures/rod/` (contract tests) serve the "is this testable"
question ADR-016 is really asking, for this kind of schema.

## Trade-offs

- **Reuse `DigikoppelingAdapter`'s transport vs. a bespoke Edukoppeling
  client.** Chosen: reuse. Edukoppeling is documented (Kennisnet/Programma
  van Eisen) as riding on Digikoppeling's WUS/ebMS2 transport with
  education-sector message conventions layered on top; integriq already
  has the signing, WS-Security and reliable-messaging machinery. A bespoke
  client would duplicate `WsSecuritySigner`/`Ebms2ReliableMessagingService`
  for no benefit. Alternative considered: a standalone SOAP client per
  ADR-011's "search before duplicating" — rejected because the transport
  layer, not the message content, is what's shared.
- **Envelope shape is an assumption.** The exact ROD berichtdefinitie/XSD
  was not in the corpus (see proposal.md Out of Scope). Isolating it behind
  `RodEnvelopeTranslator` means correcting it later touches one class and
  its fixtures, not the provider interface, the controller, or the audit
  schema.
- **Certificate gate is a runtime refusal, not a build-time block.** Chosen
  so the mock/log path, translation, audit and retry machinery are fully
  testable now; `RodEdukoppelingClient` simply refuses closed until
  `certificateRef` resolves, the same shape as `BerichtenboxClientUnavailable`.
- **`GatewayCatalogue`'s `rod` entry omits `transport`.** phpmd's
  `ExcessiveMethodLength` flagged `GatewayCatalogue::entries()` once the
  `rod` entry pushed it to 102 lines (threshold 100, a NEW finding this
  change caused). Fixed by dropping `'transport' => 'https'` from the `rod`
  entry rather than editing any pre-existing entry: `GatewayDescriptor::
  fromArray()` already defaults `transport` to `'https'` when absent, so the
  omission is a no-op change in behaviour, not a shortcut.
