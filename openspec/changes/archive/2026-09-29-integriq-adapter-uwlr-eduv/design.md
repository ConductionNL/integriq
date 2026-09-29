# Design: integriq-adapter-uwlr-eduv

## Architecture Overview

```
learniq (uwlr/edu-v/basispoort/           integriq                        Kennisnet /
 entree-content DataExchangeJob)                                          publisher network
  --POST /api/uwlr-eduv/{target}-->  UwlrEduVController::{uwlr,eduV,basispoort,entreeContent}()
                                          -> UwlrEduVService::send(target, subtype, payload)
                                               -> {Uwlr,EduV,BasispoortSync,EntreeContentSync}Translator
                                                    (literal-leak guard, target-specific required fields)
                                               -> UwlrEduVProviderRegistry
                                                    -> LogUwlrEduVProvider (default)
                                                    -> UwlrEduVKennisnetClient --transport-->  koppelvlak
                                               -> persists uwlr_eduv_message (audit, target-tagged)
  (learniq's own ack handling,      <--event--     UwlrEduVAcknowledgementReceivedEvent
   not part of this change)                <- UwlrEduVAcknowledgementTranslator
                                            <- UwlrEduVController::retour() <--HMAC-signed retour--
```

One provider seam serves all four targets: `send()` takes a `target`
discriminator so `UwlrEduVKennisnetClient` can route to the right
downstream endpoint once real transport details exist, without needing
four separate provider interfaces. Each target still gets its own
translator, because the required-field shape genuinely differs (UWLR's
three export subtypes vs. Edu-V's three qualified data services vs.
Basispoort's/Entree-content's sync-with-SSO-handoff shape).

## API Design

See contract.md for the five endpoints.

## Database Changes

One new OpenRegister schema, `uwlr_eduv_message`:

| Field | Type | Notes |
|---|---|---|
| target | string enum (`uwlr`\|`edu-v`\|`basispoort`\|`entree-content`) | |
| subtype | string, nullable | `pupil`/`group`/`teacher` for uwlr; `onderwijsdeelnemers`/`onderwijsgroepen`/`onderwijsmedewerkers` for edu-v; null for basispoort/entree-content |
| direction | string enum (`export`\|`sync`) | `uwlr`/`edu-v` are `export`; `basispoort`/`entree-content` are `sync` |
| status | string enum (`sent`\|`failed`\|`pending`\|`acknowledged`\|`rejected`) | |
| ref | string, nullable | |
| kenmerk | string, nullable | |
| eckId | string, nullable | |
| error | string, nullable | |
| syncedAt | datetime | |

Declarative schema-register patch only, no migration class.

## Nextcloud Integration

- Controllers: `lib/Controller/UwlrEduVController.php` (`uwlr`, `eduV`,
  `basispoort`, `entreeContent`, `retour`)
- Services: `lib/Service/UwlrEduVService.php`,
  `lib/Service/UwlrEduV/UwlrEduVProviderRegistry.php`,
  `lib/Service/UwlrEduV/UwlrExportEnvelopeTranslator.php`,
  `lib/Service/UwlrEduV/EduVExportEnvelopeTranslator.php`,
  `lib/Service/UwlrEduV/BasispoortSyncTranslator.php`,
  `lib/Service/UwlrEduV/EntreeContentSyncTranslator.php`,
  `lib/Service/UwlrEduV/UwlrEduVAcknowledgementTranslator.php`
- Providers: `lib/Service/UwlrEduV/LogUwlrEduVProvider.php`,
  `lib/Service/UwlrEduV/UwlrEduVKennisnetClient.php`
- Adapters (catalogue, ADR-017 Rule 1):
  `lib/Adapters/UwlrEduV/UwlrEduVAdapter.php`
- Events: `lib/Event/UwlrEduVAcknowledgementReceivedEvent.php`
- BackgroundJob: `lib/BackgroundJob/UwlrEduVRetryJob.php`

## Declarative-vs-imperative decision (ADR-031)

Same as the other three adapters in this lane: external-integration
change (ADR-031 named exception); `UwlrEduVRetryJob` is scheduled bulk
work with real network side effects (also named exception).
`uwlr_eduv_message` is a plain audit schema.

## Security Considerations

- Auth: `uwlr`/`edu-v`/`basispoort`/`entree-content` require an
  authenticated NC session (`#[NoAdminRequired]`); `retour` is
  `#[PublicPage]` + HMAC verification.
- No PEM ever appears in a method signature, source configuration, or
  app-config key — `certificateRef` only, resolved via
  `PkiOverheidCredentialResolver`.
- XXE hardening: `UwlrEduVAcknowledgementTranslator` parses the inbound
  retour via the shared `StufXmlParser` (`LIBXML_NONET` only).
- `eckId` is a pseudonymous identifier by design (Nummervoorziening); this
  adapter transmits it as received and never resolves it back to a BSN or
  live `LearnerProfile` — that resolution, if it exists, is learniq's own
  concern, not this adapter's.

## Open Questions (deliberate simplifications)

- **A single acknowledgement shape for four targets.** UWLR (SOAP-era
  Kennisnet web service), Edu-V (REST, keurmerk-audited per data
  service), Basispoort and Entree-content each likely have their own
  real acknowledgement format, none of which the corpus documents in
  wire-level detail (unlike DUO's StUF-based ROD/Verzuimloket, or
  Kennisnet's own published OSO XSD). `UwlrEduVAcknowledgementTranslator`
  uses one generic `{kenmerk, signaalcode-style status, accepted}` shape
  today. This is flagged, not hidden: production traffic for any of the
  four targets is already blocked on its own certification/aansluiting
  (M3(c)), so the real format becomes available before the mock shape
  could ever mislead a live integration.
- **One provider interface for all four targets**, rather than four,
  because the corpus gives no evidence the four targets use genuinely
  different transport protocols (all four are Kennisnet-adjacent
  services); if that assumption is wrong once certification detail
  arrives, only `UwlrEduVProviderInterface`'s `send()` signature and
  `UwlrEduVKennisnetClient`'s internals need to change — the four
  translators and the controller's endpoint shape stay stable.

## File Structure

```
lib/
  Adapters/UwlrEduV/UwlrEduVAdapter.php
  Controller/UwlrEduVController.php
  Service/UwlrEduV/
    UwlrEduVProviderInterface.php
    UwlrEduVProviderRegistry.php
    LogUwlrEduVProvider.php
    UwlrEduVKennisnetClient.php
    UwlrExportEnvelopeTranslator.php
    EduVExportEnvelopeTranslator.php
    BasispoortSyncTranslator.php
    EntreeContentSyncTranslator.php
    UwlrEduVAcknowledgementTranslator.php
  Service/UwlrEduVService.php
  Exception/UwlrEduVProviderException.php
  Exception/UwlrEduVTranslationException.php
  Event/UwlrEduVAcknowledgementReceivedEvent.php
  BackgroundJob/UwlrEduVRetryJob.php
  Settings/integriq_register.json (uwlr_eduv_message schema appended)
appinfo/routes.php (5 routes appended)
tests/Unit/{Service/UwlrEduV,Service,Controller,BackgroundJob}/*Test.php
tests/fixtures/uwlr-eduv/*.xml
```

## Seed Data

Deliberately none, same reasoning as the other three adapters in this
lane.

## Trade-offs

- **Four translators behind one provider interface**, not four provider
  interfaces. Chosen because the required-field shape differs per target
  (translation concern) but the transport does not (provider concern),
  given the corpus evidence available — see Open Questions above for the
  explicit risk if that transport assumption turns out wrong.
- **One shared `uwlr_eduv_message` schema with a `target` discriminator**,
  not four schemas. Chosen for the same reason `oso_message` uses a
  `direction` discriminator rather than two schemas — one audit trail per
  connection family is easier to query and retry than four, and the
  fields genuinely overlap (ref/kenmerk/eckId/status/error/syncedAt).
