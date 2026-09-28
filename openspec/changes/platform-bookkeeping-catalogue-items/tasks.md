# Tasks: platform-bookkeeping-catalogue-items

Kind: config. Size S. Half for shillinq `platform-integration-catalogue` (row shillinq `plt-marketplace`).

## Implementation tasks

### Task 1: Four source templates
- **spec_ref**: `openspec/changes/platform-bookkeeping-catalogue-items/specs/connector-catalog/spec.md#requirement-bookkeeping-connectors-are-catalogue-templates-in-bookkeeping-categories-req-cat-bk-001`
- **files**: `lib/Settings/register.d/peppol-access-point-source.json`, `psd2-bank-aggregator-source.json`, `corporate-card-feed-source.json`, `mollie-payments-source.json`
- **acceptance_criteria**:
  - GIVEN an upgrade WHEN the catalogue is materialised THEN the four templates are `catalog_item` objects, dormant
- [ ] Implement
- [ ] Test (PHPUnit on `CatalogRegistryService::collect()`; `occ upgrade` on a dev instance shows them on the Catalog page)

### Task 2: Bookkeeping categories and their test
- **spec_ref**: `openspec/changes/platform-bookkeeping-catalogue-items/specs/connector-catalog/spec.md#requirement-bookkeeping-connectors-are-catalogue-templates-in-bookkeeping-categories-req-cat-bk-001`
- **files**: `lib/Service/CatalogRegistryService.php`, `tests/Unit/Service/CatalogRegistryServiceTest.php`
- **acceptance_criteria**:
  - GIVEN the bookkeeping slug list WHEN the test resolves each category THEN each is one of `Bank`, `Payments`, `E-invoicing`, `Tax filing`, `Commerce`, `Payroll`
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 3: Docs
- **spec_ref**: `openspec/changes/platform-bookkeeping-catalogue-items/specs/connector-catalog/spec.md#requirement-bookkeeping-connectors-are-catalogue-templates-in-bookkeeping-categories-req-cat-bk-001`
- **files**: `docs/features/connector-catalog.md`
- **acceptance_criteria**:
  - GIVEN the catalogue docs WHEN a developer adds a bookkeeping connector THEN the page names the six categories and says the sandbox seed records are for demos only
- [ ] Implement
- [ ] Test (docs build)

## Verification
- [ ] `openspec validate platform-bookkeeping-catalogue-items --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit exit code read
