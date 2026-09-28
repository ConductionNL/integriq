# Tasks: sources-ecb-exchange-rates

Kind: code. Size S. Half for shillinq `banking-fx-at-booking` (row shillinq `bnk-fx-rates`).

## Implementation tasks

### Task 1: Source, schema, mapping, synchronizations and job
- **spec_ref**: `openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001`
- **files**: `lib/Settings/register.d/ecb-exchange-rates-source.json`, a stored copy of the daily and 90-day files under `tests/fixtures/ecb/`
- **acceptance_criteria**:
  - GIVEN the stored daily file WHEN the synchronization runs twice THEN one `exchange_rate` per currency exists for that date
- [ ] Implement
- [ ] Test (PHPUnit against the stored files; one live run on a dev instance, count recorded in the PR)

### Task 2: The rate request
- **spec_ref**: `openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-a-sibling-app-asks-for-a-rate-on-a-date-req-fx-002`
- **files**: `lib/Event/ExchangeRateRequestedEvent.php`, `lib/EventListener/ExchangeRateRequestedListener.php`, `lib/Service/ExchangeRateService.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN rates for Friday 2026-09-25 WHEN USD to EUR is asked for Sunday 2026-09-27 THEN the answer is 1 / 1.1203 with rate date 2026-09-25
  - GIVEN rates for that date WHEN USD to GBP is asked THEN the answer is derived through the euro and marked derived
  - GIVEN no rate within seven days WHEN a pair is asked THEN the refusal is `no-rate` with the newest date
- [ ] Implement
- [ ] Test (PHPUnit with the real event class)

### Task 3: Published event and catalogue item
- **spec_ref**: `openspec/changes/sources-ecb-exchange-rates/specs/exchange-rate-source/spec.md#requirement-the-ecb-reference-rates-are-stored-every-working-day-req-fx-001`
- **files**: `lib/Service/ExchangeRateService.php`, `lib/Service/CatalogRegistryService.php`, `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a run that stores a new date WHEN it finishes THEN one `nl.conduction.fx.rates.published` event names that date
- [ ] Implement
- [ ] Test (PHPUnit; the catalogue lists the item under `Bank`)

## Verification
- [ ] `openspec validate sources-ecb-exchange-rates --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit exit code read
