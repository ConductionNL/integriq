# Tasks: connectors-webshop-orders

Kind: config. Size S to M. Half for shillinq `sales-webshop-orders` (row shillinq `sal-webshop-sync`).

## Implementation tasks

### Task 1: Source templates
- **spec_ref**: `openspec/changes/connectors-webshop-orders/specs/webshop-order-connectors/spec.md#requirement-a-shop-platform-is-a-source-template-req-wso-001`
- **files**: `lib/Settings/register.d/woocommerce-shop-source.json`, `shopify-shop-source.json`, `lightspeed-shop-source.json`, `lib/Service/CatalogRegistryService.php` (category overrides)
- **acceptance_criteria**:
  - GIVEN an upgrade WHEN the catalogue is materialised THEN the three templates are listed under `Commerce`, dormant
- [ ] Implement
- [ ] Test (PHPUnit on `CatalogRegistryService::collect()`)

### Task 2: Mappings onto WebshopOrder
- **spec_ref**: `openspec/changes/connectors-webshop-orders/specs/webshop-order-connectors/spec.md#requirement-an-order-becomes-a-webshoporder-as-shillinq-defines-it-req-wso-002`
- **files**: `lib/Settings/integriq_seed_data.json` (three mappings), recorded order fixtures under `tests/fixtures/webshop/`
- **acceptance_criteria**:
  - GIVEN a recorded WooCommerce order with prices including VAT WHEN it is mapped THEN every `WebshopOrder` field of shillinq's contract is filled and `pricesIncludeVat` is true
- [ ] Implement
- [ ] Test (PHPUnit per platform against its recorded order)

### Task 3: Incremental synchronizations
- **spec_ref**: `openspec/changes/connectors-webshop-orders/specs/webshop-order-connectors/spec.md#requirement-a-later-refund-updates-the-same-order-req-wso-003`
- **files**: `lib/Settings/integriq_seed_data.json` (three disabled synchronizations)
- **acceptance_criteria**:
  - GIVEN order 100231 written as paid WHEN the shop refunds it and the synchronization runs THEN the same `WebshopOrder` shows `payment.status` refunded and no second object exists
- [ ] Implement
- [ ] Test (PHPUnit with two recorded fetches; one run against a WooCommerce test shop on a dev instance with shillinq installed)

### Task 4: Docs and strings
- **spec_ref**: `openspec/changes/connectors-webshop-orders/specs/webshop-order-connectors/spec.md#requirement-a-shop-platform-is-a-source-template-req-wso-001`
- **files**: `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN the docs WHEN a shop owner connects a shop THEN the steps name where to find the channel id in shillinq
- [ ] Implement
- [ ] Test (docs build)

## Verification
- [ ] `openspec validate connectors-webshop-orders --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit exit code read
