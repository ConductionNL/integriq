# Tasks: gateway-design-findings-do-not-block-products

## Task 1: Gateway-scoped rules do not block
- **spec_ref**: `openspec/changes/gateway-design-findings-do-not-block-products/specs/api-design-rules/spec.md#requirement-a-product-with-an-open-error-is-not-published-req-adrc-003`
- **files**: `lib/Settings/api-design-rules/nl-api-design-rules.json`, `lib/Service/ApiDesign/ApiDesignRuleService.php`
- [x] Implement
- [x] Test (PHPUnit `NlApiDesignRuleSetTest::testGatewayScopedFindingsDoNotBlock`, the known-bad document now blocks on 10 of its 14 errors)

## Verification
- [x] `openspec validate gateway-design-findings-do-not-block-products --type change --strict` passes
- [x] PHPUnit and Playwright run, exit codes read
  - PHPUnit 2026-11-25, exit 0: `tests/Unit/Service/ApiDesign` (50), `ApiDesignRulesFragmentTest` (4), `ApiProductPublishGuardListenerTest` (8), `ApiDesignCheckControllerTest` (5). Playwright on a live Nextcloud 34: `tests/e2e/api-design-rules.spec.ts` 2 passed. Live probe: before, `POST api_product {status: active, endpoints: []}` answered 422 with 3 open design errors; after, 201.
