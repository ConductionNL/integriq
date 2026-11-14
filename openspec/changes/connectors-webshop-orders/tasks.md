# Tasks: connectors-webshop-orders

Kind: config. Size S to M. Half for shillinq `sales-webshop-orders` (row shillinq `sal-webshop-sync`).

## Implementation tasks

### Task 1: Source templates
- **spec_ref**: `openspec/changes/connectors-webshop-orders/specs/webshop-order-connectors/spec.md#requirement-a-shop-platform-is-a-source-template-req-wso-001`
- **files**: `lib/Settings/register.d/woocommerce-shop-source.json`, `shopify-shop-source.json`, `lightspeed-shop-source.json`, `lib/Service/CatalogRegistryService.php` (category overrides)
- **acceptance_criteria**:
  - GIVEN an upgrade WHEN the catalogue is materialised THEN the three templates are listed under `Commerce`, dormant
- [x] Implement
  - One ADR-037 fragment per platform (`woocommerce-shop-source.json`, `shopify-shop-source.json`, `lightspeed-shop-source.json`), each source dormant with no location and its secret behind a `credentialRef`; the three slugs map to `Commerce` in `SLUG_CATEGORY_OVERRIDES`. The channel id and administration id live on each mapping, not on the source: a mapping's Twig context is the order alone, so a value on the source could not reach the `WebshopOrder`. The run guard (`sourceConfig.requiredMappingValues`) refuses a run until both are filled.
- [x] Test (PHPUnit on `CatalogRegistryService::collect()`)
  - `WebshopOrderSetsTest::testEveryShopTemplateIsDormantBrokeredAndListedUnderCommerce`; `CatalogRegistrySeedGoldenTest` re-pinned after checking the only change to the catalogue is the three new entries.

### Task 2: Mappings onto WebshopOrder
- **spec_ref**: `openspec/changes/connectors-webshop-orders/specs/webshop-order-connectors/spec.md#requirement-an-order-becomes-a-webshoporder-as-shillinq-defines-it-req-wso-002`
- **files**: `lib/Settings/integriq_seed_data.json` (three mappings), recorded order fixtures under `tests/fixtures/webshop/`
- **acceptance_criteria**:
  - GIVEN a recorded WooCommerce order with prices including VAT WHEN it is mapped THEN every `WebshopOrder` field of shillinq's contract is filled and `pricesIncludeVat` is true
- [ ] Implement
  - WooCommerce and Shopify mappings are in their fragments (not in `integriq_seed_data.json`, which no code loads; the course marketplace sets set the same precedent). Lightspeed eCom is open: its order list names the lines only as a link to a separate products resource, so the order cannot be mapped with its lines from the list call.
- [ ] Test (PHPUnit per platform against its recorded order)
  - WooCommerce and Shopify: `WebshopOrderSetsTest` runs the real MappingService over `tests/fixtures/webshop/` and validates each object against `webshop-order.schema.json`, shillinq's D2 contract written out (shillinq has not built the schema yet). Order 100231 is checked field for field, prices including VAT; a Shopify business order covers prices excluding VAT. Lightspeed open with its mapping.

### Task 3: Incremental synchronizations
- **spec_ref**: `openspec/changes/connectors-webshop-orders/specs/webshop-order-connectors/spec.md#requirement-a-later-refund-updates-the-same-order-req-wso-003`
- **files**: `lib/Settings/integriq_seed_data.json` (three disabled synchronizations)
- **acceptance_criteria**:
  - GIVEN order 100231 written as paid WHEN the shop refunds it and the synchronization runs THEN the same `WebshopOrder` shows `payment.status` refunded and no second object exists
- [ ] Implement
  - WooCommerce and Shopify: `syncMode: incremental`, the changed-since parameter rendered from `{{ cursor }}`, `cursorField` on the order's modified time, `idPosition` the shop's order id, no disappearance policy, target `shillinq/WebshopOrder`, off by default. Lightspeed open with its mapping.
- [ ] Test (PHPUnit with two recorded fetches; one run against a WooCommerce test shop on a dev instance with shillinq installed)
  - PHPUnit half for WooCommerce: `testARefundFetchOfTheSameOrderKeepsItsIdentityAndIsRefunded` maps two recorded fetches of order 100231 (same origin id, paid then refunded, cursor moved). The live run needs a WooCommerce test shop and shillinq with the schema.

### Task 4: Docs and strings
- **spec_ref**: `openspec/changes/connectors-webshop-orders/specs/webshop-order-connectors/spec.md#requirement-a-shop-platform-is-a-source-template-req-wso-001`
- **files**: `docs/features/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN the docs WHEN a shop owner connects a shop THEN the steps name where to find the channel id in shillinq
- [x] Implement
  - `docs/features/webshop-orders.md`: connecting a shop names the shillinq settings gear, Web shop channels, for the channel and administration ids. No new UI strings: catalogue category labels are not translated anywhere in the app.
- [ ] Test (docs build)

## Verification
- [ ] `openspec validate connectors-webshop-orders --strict` passes
- [ ] `composer check:strict` once before push; PHPUnit exit code read
