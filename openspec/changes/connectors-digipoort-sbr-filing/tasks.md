# Tasks: connectors-digipoort-sbr-filing

Kind: code. Size M. Half for shillinq `tax-digipoort-filing` (rows shillinq `tax-vat-file`, `tax-sbr`).

## Implementation tasks

### Task 1: Source type and providers
- **spec_ref**: `openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-digipoort-source-delivers-through-a-log-or-wus-provider-req-dps-001`
- **files**: `lib/Service/Digipoort/DigipoortProviderInterface.php`, `LogDigipoortProvider.php`, `WusDigipoortProvider.php`, `lib/Settings/integriq_register.json`
- **acceptance_criteria**:
  - GIVEN a source on the `log` provider WHEN an instance is delivered THEN a deterministic delivery reference comes back and no network call is made
  - GIVEN a `wus` source without a resolvable certificate WHEN an instance is delivered THEN it fails closed naming the missing certificate
- [ ] Implement
- [ ] Test (PHPUnit with recorded WUS responses, signed and unsigned)

### Task 2: The filing consumer and record
- **spec_ref**: `openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-a-filing-request-becomes-one-delivery-req-dps-002`
- **files**: `lib/Service/Digipoort/SbrFilingService.php`, `lib/Service/SbrFilingConsumer.php`, `lib/AppInfo/Application.php`, `lib/Settings/integriq_register.json` (`sbr_filing`)
- **acceptance_criteria**:
  - GIVEN a request for OB aangifte 2026 Q3 WHEN it is consumed twice THEN one `sbr_filing` and one delivery exist
- [ ] Implement
- [ ] Test (PHPUnit with real `ObjectEntity` instances carrying numeric ids)

### Task 3: Status poll and status events
- **spec_ref**: `openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-every-status-change-is-reported-back-req-dps-003`
- **files**: `lib/BackgroundJob/SbrFilingStatusJob.php`, `appinfo/info.xml`, `lib/Service/Digipoort/SbrFilingService.php`
- **acceptance_criteria**:
  - GIVEN a delivered filing WHEN the recipient rejects it THEN one status event with the error codes is emitted and the filing is final
- [ ] Implement
- [ ] Test (PHPUnit with a scripted log provider; one preproduction delivery with a test certificate when one is available, recorded in the PR)

### Task 4: Catalogue item, log page and docs
- **spec_ref**: `openspec/changes/connectors-digipoort-sbr-filing/specs/digipoort-sbr-connector/spec.md#requirement-an-administrator-sees-every-filing-and-its-status-req-dps-004`
- **files**: `lib/Service/CatalogRegistryService.php`, `src/manifest.json` (log page), `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN the seed WHEN an administrator opens the Digipoort filings page THEN the example filing shows with its two statuses
- [ ] Implement
- [ ] Test (Playwright for the page; PHPUnit for the catalogue entry)

## Verification
- [ ] `openspec validate connectors-digipoort-sbr-filing --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit, Newman and Playwright exit codes read
- [ ] The shillinq lane confirms the event names and fields against `tax-digipoort-filing`
