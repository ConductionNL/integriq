# Tasks: connectors-digipoort-sbr-filing

Kind: code. Size M. Half for shillinq `tax-digipoort-filing` (rows shillinq `tax-vat-file`, `tax-sbr`).

## Implementation tasks

### Task 1: Source type and providers
- **spec_ref**: `openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-digipoort-source-delivers-through-a-log-or-wus-provider-req-dps-001`
- **files**: `lib/Service/Digipoort/DigipoortProviderInterface.php`, `LogDigipoortProvider.php`, `WusDigipoortProvider.php`, `lib/Settings/integriq_register.json`
- **acceptance_criteria**:
  - GIVEN a source on the `log` provider WHEN an instance is delivered THEN a deterministic delivery reference comes back and no network call is made
  - GIVEN a `wus` source without a resolvable certificate WHEN an instance is delivered THEN it fails closed naming the missing certificate
- [x] Implement
  - `DigipoortProviderInterface` with `LogDigipoortProvider` (reference derived from berichtsoort, kenmerk and identity; scripted statuses from `configuration.mockStatuses`, delivered then accepted by default) and `WusDigipoortProvider` (aanleveren and getStatussenProces signed through `WusProfileService` with `configuration.certificateRef`, sent over two-way TLS with the client certificate from `configuration.authentication`, answer signature verified, endpoints per `configuration.environment` behind the egress guard). The source is seeded dormant on the log provider in `register.d/connectors-digipoort-sbr-filing.json`, not in `integriq_register.json` (ADR-037).
- [x] Test (PHPUnit with recorded WUS responses, signed and unsigned)
  - `tests/Unit/Service/Digipoort/WusDigipoortProviderTest.php` against recorded aanlever, status and fault answers: signed delivery, unsigned answer rejected, missing certificate fails closed naming it and sends nothing, rejection with foutcode, accepted codes per source; `LogDigipoortProviderTest.php`.

### Task 2: The filing consumer and record
- **spec_ref**: `openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002`
- **files**: `lib/Service/Digipoort/SbrFilingService.php`, `lib/Service/SbrFilingConsumer.php`, `lib/AppInfo/Application.php`, `lib/Settings/integriq_register.json` (`sbr_filing`)
- **acceptance_criteria**:
  - GIVEN a request for OB aangifte 2026 Q3 WHEN it is consumed twice THEN one `sbr_filing` and one delivery exist
- [x] Implement
  - `SbrFilingConsumer` (on `ObjectCreatedEvent`, scoped to integriq's own `event` schema through `ListenerSchemaResolver`, registered in `EventListenerBindings`) hands the request to `SbrFilingService::handleRequested()`, which stores one `sbr_filing` per `objectUri` and `filingType`, reads the instance through `UserFilePayloadReader` and delivers it once. A repeated request for a filing past `queued` delivers nothing. The `sbr_filing` schema is administrator-only and written in system context.
- [x] Test (PHPUnit with real `ObjectEntity` instances carrying numeric ids)
  - `SbrFilingServiceTest.php` over an in-memory store of real `ObjectEntity` rows with numeric ids; `SbrFilingConsumerTest.php` (own schema only, fails closed when ids cannot be resolved); `UserFilePayloadReaderTest.php`.

### Task 3: Status poll and status events
- **spec_ref**: `openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-every-status-change-is-reported-back-req-dps-003`
- **files**: `lib/BackgroundJob/SbrFilingStatusJob.php`, `appinfo/info.xml`, `lib/Service/Digipoort/SbrFilingService.php`
- **acceptance_criteria**:
  - GIVEN a delivered filing WHEN the recipient rejects it THEN one status event with the error codes is emitted and the filing is final
- [x] Implement
  - `SbrFilingStatusJob` (every ten minutes, registered in `info.xml`) runs `SbrFilingService::sweep()`: retries queued deliveries with budget left, then polls every delivered filing. Each status change is appended to `statusHistory` and emitted as `nl.conduction.sbr.filing.status` with `{objectUri, deliveryReference, status, errors}`; the first final status ends polling. After three failed attempts the filing is `failed` (dead letter, notified to the connection alert group); an unknown filing type or recipient fails at once.
- [ ] Test (PHPUnit with a scripted log provider; one preproduction delivery with a test certificate when one is available, recorded in the PR)
  - Done: `SbrFilingServiceTest.php` (rejection with codes ends polling, only new changes reported, nothing after a final status, retry then dead letter, the log source takes a filing to accepted) and `SbrFilingStatusJobTest.php`.
  - Owed: one preproduction delivery with a test certificate.

### Task 4: Catalogue item, log page and docs
- **spec_ref**: `openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-an-administrator-sees-every-filing-and-its-status-req-dps-004`
- **files**: `lib/Service/CatalogRegistryService.php`, `src/manifest.json` (log page), `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN the seed WHEN an administrator opens the Digipoort filings page THEN the example filing shows with its two statuses
- [x] Implement
  - Catalogue item `adapter:digipoort-sbr` (Tax filing, mock-seeded, backed by the seeded source); Digipoort filings log page `/messages/digipoort` with a card on the Reports hub; `docs/features/digipoort-sbr-filing.md`; strings in `l10n/en.json` and `l10n/nl.json` (catalogues rebuilt). The demo filing for Adviesbureau Kade B.V. (delivered, then accepted) is in the on-demand demo data, so an install seeds no filing.
- [ ] Test (Playwright for the page; PHPUnit for the catalogue entry)
  - Done: `CatalogDigipoortEntryTest.php`, the catalogue goldens, and `tests/Unit/Settings/DigipoortFilingDemoTest.php` (the demo filing matches the schema).
  - Owed: the Playwright spec for the page against a live instance.

## Verification
- [ ] `openspec validate connectors-digipoort-sbr-filing --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit, Newman and Playwright exit codes read
- [ ] The shillinq lane confirms the event names and fields against `tax-digipoort-filing`
