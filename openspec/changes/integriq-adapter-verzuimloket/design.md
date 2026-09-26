# Design: integriq-adapter-verzuimloket

## Architecture Overview

```
learniq (leerplicht job)               integriq                          DUO
  DataExchangeRunHandler   --POST-->    VerzuimloketController::berichten()
  DataExchangePayloadBuilder::           -> VerzuimloketService::sendMelding()
    composeLeerplichtFile()                  -> VerzuimloketEnvelopeTranslator (literal-leak guard)
                                               -> VerzuimloketProviderRegistry
                                                    -> LogVerzuimloketProvider (default)
                                                    -> VerzuimloketEdukoppelingClient --WUS-->  Verzuimloket koppelvlak
                                               -> persists verzuim_message (audit)
  (learniq's own correction     <--event--    VerzuimloketAcknowledgementReceivedEvent
   mechanism, not part of this        <- VerzuimloketAcknowledgementTranslator
   change)                                        <- VerzuimloketController::retour() <--HMAC-signed retour-- DUO
```

Identical shape to `integriq-adapter-rod`, applied to DUO Verzuimloket.
`VerzuimloketEdukoppelingClient` reuses the same WUS transport and
certificate-resolution machinery as `RodEdukoppelingClient` — both DUO
families share "1 certificaat per softwareleverancier"
(`parnassys#13.1`), so there is no reason for two separate transport
implementations.

## API Design

See contract.md for `POST /api/verzuimloket/berichten` and `POST
/api/verzuimloket/retour` — identical shape to this section's cross-reference requirement.

## Database Changes

One new OpenRegister schema, `verzuim_message`, appended to
`lib/Settings/integriq_register.json` (same pattern as `rod_message`):

| Field | Type | Notes |
|---|---|---|
| direction | string enum (`outbound`\|`inbound`) | |
| meldingType | string enum (`eerste-melding`\|`herhaalmelding`\|`langdurig-relatief-verzuim`) | |
| status | string enum (`sent`\|`failed`\|`pending`\|`acknowledged`\|`rejected`) | |
| ref | string, nullable | |
| kenmerk | string | indexed |
| signaalcode | string, nullable | |
| signaalOmschrijving | string, nullable | |
| bsnHash | string, nullable | SHA-256, never the raw value |
| error | string, nullable | |
| syncedAt | datetime | |

Declarative schema-register patch only, no migration class, same as
`rod_message`.

## Nextcloud Integration

- Controllers: `lib/Controller/VerzuimloketController.php`
- Services: `lib/Service/VerzuimloketService.php`,
  `lib/Service/Verzuimloket/VerzuimloketProviderRegistry.php`,
  `lib/Service/Verzuimloket/VerzuimloketEnvelopeTranslator.php`,
  `lib/Service/Verzuimloket/VerzuimloketAcknowledgementTranslator.php`
- Providers: `lib/Service/Verzuimloket/LogVerzuimloketProvider.php`,
  `lib/Service/Verzuimloket/VerzuimloketEdukoppelingClient.php`
- Adapters (catalogue, ADR-017 Rule 1):
  `lib/Adapters/Verzuimloket/VerzuimloketAdapter.php`
- Events/Hooks:
  `lib/Event/VerzuimloketAcknowledgementReceivedEvent.php` (ADR-041)
- BackgroundJob: `lib/BackgroundJob/VerzuimloketRetryJob.php`

## Declarative-vs-imperative decision (ADR-031)

Same as `integriq-adapter-rod`: this is an external-integration change
(ADR-031 named exception), and `VerzuimloketRetryJob` is scheduled bulk
work with real network side effects (also a named exception). No
lifecycle/aggregation/calculation/notification/widget behaviour is
introduced; `verzuim_message` is a plain audit schema.

## Security Considerations

- Auth: `berichten` requires an authenticated NC session
  (`#[NoAdminRequired]`); `retour` is `#[PublicPage]` + HMAC verification.
- No PEM ever appears in a method signature, source configuration, or
  app-config key.
- BSN hygiene: raw BSN travels in the outbound envelope (legally
  required), hashed (SHA-256) before persistence.
- Input validation: `VerzuimloketEnvelopeTranslator` raises before
  building any XML when a required field is missing/null/empty.

## File Structure

```
lib/
  Adapters/Verzuimloket/VerzuimloketAdapter.php
  Controller/VerzuimloketController.php
  Service/Verzuimloket/
    VerzuimloketProviderInterface.php
    VerzuimloketProviderRegistry.php
    LogVerzuimloketProvider.php
    VerzuimloketEdukoppelingClient.php
    VerzuimloketEnvelopeTranslator.php
    VerzuimloketAcknowledgementTranslator.php
  Service/VerzuimloketService.php
  Exception/VerzuimloketProviderException.php
  Exception/VerzuimloketTranslationException.php
  Event/VerzuimloketAcknowledgementReceivedEvent.php
  BackgroundJob/VerzuimloketRetryJob.php
  Settings/integriq_register.json (verzuim_message schema appended)
appinfo/routes.php (2 routes appended)
tests/Unit/{Service/Verzuimloket,Service,Controller,BackgroundJob}/*Test.php
tests/fixtures/verzuimloket/*.xml
```

## Seed Data

Deliberately none, same reasoning as `integriq-adapter-rod`'s "Seed Data"
section: no comparable audit-log schema in this app (`iwmo_ijw_message`,
`digitalPostMessage`, `rod_message`) carries seed rows.

## Trade-offs

- **Reuse `RodEdukoppelingClient`'s transport pattern rather than a shared
  abstract base class.** Chosen: duplicate the thin wrapper shape (as ROD
  duplicated it from `iwmo-ijw-adapter`/`berichtenbox-digital-post-adapter`
  rather than introducing a shared base), because the actual DUO endpoint,
  berichtsoort vocabulary and audit schema all differ per family — a shared
  base would need as many override points as it saves lines. Matches this
  app's own existing precedent of per-family providers, not a generic one.
- **`meldingType` as a free-form string, not a learniq-matched enum.**
  Chosen so a future learniq change (modelling LRV/herhaalmelding) needs no
  integriq-side change — the translator already accepts all three DUO
  melding kinds.
