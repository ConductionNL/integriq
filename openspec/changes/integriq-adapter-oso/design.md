# Design: integriq-adapter-oso

## Architecture Overview

```
Export leg:
learniq (oso job, export)              integriq                          Kennisnet
  DataExchangeRunGuard (already      --POST-->  OsoController::export()
   cleared parent-review)                          -> OsoService::sendExport()
                                                         -> OsoExportEnvelopeTranslator (literal-leak guard)
                                                         -> OsoProviderRegistry
                                                              -> LogOsoProvider (default)
                                                              -> OsoKennisnetClient --WUS-->  OSO koppelvlak
                                                         -> persists oso_message (audit)
  (learniq's export ack        <--event--     OsoAcknowledgementReceivedEvent
   handling, not part of              <- OsoAcknowledgementTranslator
   this change)                              <- OsoController::retour() <--HMAC-signed retour-- Kennisnet

Import leg:
Kennisnet --HMAC-signed POST--> OsoController::import()
                                    -> OsoService::receiveImport()
                                         -> OsoImportTranslator (XXE-hardened parse)
                                         -> persists oso_message (audit, direction: import)
                                         -> dispatches OsoDossierReceivedEvent
learniq's oso-inbound-contract listener (not part of this change)
  <-- consumes the event, materialises OsoImportDossier
```

Same shape as `integriq-adapter-rod`/`integriq-adapter-verzuimloket`,
extended with a second, receive-only leg for import (no provider dispatch —
Kennisnet pushes to us, we don't pull).

## API Design

See contract.md for the three endpoints.

## Database Changes

One new OpenRegister schema, `oso_message`:

| Field | Type | Notes |
|---|---|---|
| direction | string enum (`export`\|`import`\|`retour`) | |
| status | string enum (`sent`\|`failed`\|`pending`\|`received`\|`acknowledged`\|`rejected`) | |
| ref | string, nullable | |
| kenmerk | string, nullable | present on export/retour, absent on a fresh import |
| sourceSchoolBrin | string, nullable | inbound only |
| learnerEckId | string, nullable | both directions |
| error | string, nullable | |
| syncedAt | datetime | |

Declarative schema-register patch only, no migration class.

## Nextcloud Integration

- Controllers: `lib/Controller/OsoController.php` (`export`, `import`, `retour`)
- Services: `lib/Service/OsoService.php`,
  `lib/Service/Oso/OsoProviderRegistry.php`,
  `lib/Service/Oso/OsoExportEnvelopeTranslator.php`,
  `lib/Service/Oso/OsoImportTranslator.php`,
  `lib/Service/Oso/OsoAcknowledgementTranslator.php`
- Providers: `lib/Service/Oso/LogOsoProvider.php`,
  `lib/Service/Oso/OsoKennisnetClient.php`
- Adapters (catalogue, ADR-017 Rule 1): `lib/Adapters/Oso/OsoAdapter.php`
- Events: `lib/Event/OsoDossierReceivedEvent.php`,
  `lib/Event/OsoAcknowledgementReceivedEvent.php`
- BackgroundJob: `lib/BackgroundJob/OsoRetryJob.php`

## Declarative-vs-imperative decision (ADR-031)

Same as the other two adapters in this lane: external-integration change
(ADR-031 named exception); `OsoRetryJob` is scheduled bulk work with real
network side effects (also named exception). `oso_message` is a plain
audit schema.

## Security Considerations

- Auth: `export` requires an authenticated NC session
  (`#[NoAdminRequired]`); `import`/`retour` are `#[PublicPage]` + HMAC
  verification.
- No PEM ever appears in a method signature, source configuration, or
  app-config key.
- XXE hardening: `OsoImportTranslator` parses via the shared
  `StufXmlParser` (`LIBXML_NONET` only) — an inbound dossier originates
  from an external party.
- Data minimisation is a pass-through (REQ-006): integriq transmits
  exactly what learniq's payload marks included/excluded, never deciding
  categories itself.

## File Structure

```
lib/
  Adapters/Oso/OsoAdapter.php
  Controller/OsoController.php
  Service/Oso/
    OsoProviderInterface.php
    OsoProviderRegistry.php
    LogOsoProvider.php
    OsoKennisnetClient.php
    OsoExportEnvelopeTranslator.php
    OsoImportTranslator.php
    OsoAcknowledgementTranslator.php
  Service/OsoService.php
  Exception/OsoProviderException.php
  Exception/OsoTranslationException.php
  Event/OsoDossierReceivedEvent.php
  Event/OsoAcknowledgementReceivedEvent.php
  BackgroundJob/OsoRetryJob.php
  Settings/integriq_register.json (oso_message schema appended)
appinfo/routes.php (3 routes appended)
tests/Unit/{Service/Oso,Service,Controller,BackgroundJob}/*Test.php
tests/fixtures/oso/*.xml
```

## Seed Data

Deliberately none, same reasoning as the other two adapters in this lane.

## Trade-offs

- **One provider interface for export, none for import.** Chosen because
  import is Kennisnet-initiated (a push we receive), not something this
  adapter dispatches — there is nothing to "bind" a provider to on that
  leg. `OsoImportTranslator` is a plain parser, not behind
  `OsoProviderInterface`.
- **`OsoImportTranslator`'s output field names match `OsoImportDossier`
  exactly, not integriq's own naming convention.** Chosen so
  `OsoDossierReceivedEvent`'s payload needs zero translation on learniq's
  side — the coupling is explicit and named (proposal.md Risk 1), not
  hidden behind a generic event shape that would need its own mapping
  layer.
