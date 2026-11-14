# Tasks: gateway-usage-per-consumer-and-endpoint

Kind: code. Matrix row `integriq:obs-analytics`.

### Task 1: Split the analytics per consumer and per endpoint
- **spec_ref**: openspec/changes/gateway-usage-per-consumer-and-endpoint/specs/api-product-gateway/spec.md#requirement-product-usage-is-split-per-consumer-and-per-endpoint-req-apgu-001
- **files**: `lib/Controller/ProductSubscriptionsController.php` (`analytics()`, `fetchProductCallLogRows()`), `tests/Unit/Controller/ProductSubscriptionsControllerTest.php`
- **acceptance_criteria**:
  - GIVEN call log rows for two consumers WHEN analytics are read THEN `byConsumer` holds two entries whose counts add up to the product total
  - GIVEN call log rows for three endpoints WHEN analytics are read THEN `byEndpoint` holds three entries, highest request count first
  - GIVEN a non-administrator WHEN analytics are read THEN 403
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 2: Show both lists on the usage tab
- **spec_ref**: openspec/changes/gateway-usage-per-consumer-and-endpoint/specs/api-product-gateway/spec.md#requirement-product-usage-is-split-per-consumer-and-per-endpoint-req-apgu-001
- **files**: `src/views/ApiProducts/ApiProductDetail.vue`
- **acceptance_criteria**:
  - GIVEN a product with traffic WHEN the usage tab opens THEN a "Per consumer" and a "Per endpoint" table show the figures, as drawn on IqApiProduct
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/api-product-usage.spec.ts`)

## Verification

- `openspec validate gateway-usage-per-consumer-and-endpoint --type change --strict`
