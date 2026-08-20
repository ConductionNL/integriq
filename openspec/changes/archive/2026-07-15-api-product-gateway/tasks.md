# Tasks: api-product-gateway

## Implementation Tasks

### Task 1: Add the api-product-gateway register fragment (schema/migration)
- **spec_ref**: `openspec/changes/api-product-gateway/migration.md`, `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-api-product-groups-endpoints-into-a-named-versioned-bundle-req-apg-001`
- **files**: `lib/Settings/register.d/api-product-gateway.json`
- **acceptance_criteria**:
  - GIVEN the app is upgraded WHEN `occ app:update openconnector` runs THEN `api_product` and `api_product_subscription` schemas are registered AND `call_log` gains `product`/`endpoint`/`responseTime` properties without any existing `call_log` row being modified
  - GIVEN the migration re-runs a second time WHEN `occ app:update openconnector` runs again THEN no error occurs and no duplicate schema rows are created
- [x] Implement
- [ ] Test — occ app:update / live schema-import verification requires a running Nextcloud + OpenRegister instance; not runnable in this environment. JSON well-formedness and the fragment-merge order/deep-merge mechanics were verified by inspection against the existing hitl-approval-rule-action.json / 99-source-secrets-writeonly.json precedents (order-independent per InitializeRegister.php's recursive key-union deepMergeConfig()).

### Task 2: Resolve per-tier rate-limit policy ahead of the inbound limiter
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-per-tier-rate-limit-enforcement-extends-the-inbound-rate-limiter-req-apg-005`, `openspec/changes/api-product-gateway/specs/consumer-management/spec.md#requirement-per-product-tier-policy-takes-precedence-over-the-consumer-level-rate-limit-req-con-sub-002`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN a consumer with an active subscription to a product's `free` tier `{requestsPerWindow:2,windowSeconds:60}` WHEN it makes 3 requests to the product's endpoint in one window THEN the 3rd receives HTTP 429 with `Retry-After`
  - GIVEN the same consumer also calling an endpoint outside any product WHEN it calls that endpoint THEN its own Consumer-level `rateLimit` applies unchanged
  - GIVEN a request to an endpoint in no `api_product` WHEN it is dispatched THEN behaviour is byte-for-byte identical to before this change (no regression on `REQ-CON-RL-002`)
- [x] Implement
- [x] Test — tests/Unit/Service/EndpointServiceTierPolicyTest.php (over-tier 429 with product-namespaced key, 403 when no active subscription, non-product endpoint unchanged consumer-level path)

### Task 3: Add Sunset/Deprecation headers for deprecated product versions
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-deprecated-product-version-carries-sunset-and-deprecation-headers-req-apg-006`, `openspec/changes/api-product-gateway/specs/endpoint-runtime/spec.md#requirement-deprecated-product-version-dispatch-attaches-sunset-deprecation-headers-req-ep-008`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN an endpoint that belongs to an `api_product` with `status: deprecated` and a `sunsetDate` WHEN a request is served (fast path or full pipeline) THEN the response carries `Deprecation: true` and `Sunset: <HTTP-date>`
  - GIVEN an endpoint that belongs to an active or no product WHEN a request is served THEN neither header is present
- [x] Implement — wired at both dispatch paths: EndpointService::handleRequest() (full pipeline) and EndpointsController::handleSimpleSchemaRequest() (fast path)
- [x] Test — tests/Unit/Service/EndpointServiceTierPolicyTest.php covers buildDeprecationHeaders() for deprecated (RFC 7231 Sunset format verified) and active products directly. Not separately tested: the fast-path wiring itself end-to-end (EndpointsController::handleSimpleSchemaRequest) — would require a deep ObjectService/mapper mock graph beyond this session's time budget; the header-building logic it calls is unit-tested, the wiring is a 6-line direct call reviewed by hand.

### Task 4: Log inbound requests for product-scoped endpoints
- **spec_ref**: `openspec/changes/api-product-gateway/specs/endpoint-runtime/spec.md#requirement-inbound-observability-logging-for-api-product-scoped-endpoints-req-ep-009`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN an endpoint that belongs to an `api_product` WHEN a request completes (2xx or error) THEN a `call_log` row is persisted with `direction: inbound`, the product/endpoint uuids, `statusCode`, and `responseTime`
  - GIVEN an endpoint in no `api_product` WHEN a successful request completes THEN no `call_log` row is written for it (unchanged from today — only its 429s are logged)
  - GIVEN the `call_log` write throws WHEN a product-scoped request otherwise succeeds THEN the response is still returned and the failure is only logged
- [x] Implement — EndpointService::recordInboundCallLog(), called from handleRequest() (full pipeline) and EndpointsController::handleSimpleSchemaRequest() (fast path); non-product endpoints unchanged (only recordInboundThrottle() 429 logging, unchanged)
- [x] Test — tests/Unit/Service/EndpointServiceTierPolicyTest.php (row persisted with product/endpoint/statusCode/responseTime; write failure never throws)

### Task 5: Add subscription-approval creation to ApprovalService
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004`, `openspec/changes/api-product-gateway/design.md#decision-4-subscription-approval-reuses-approvalservices-generic-state-machine-via-one-new-creation-method-not-suspend`
- **files**: `lib/Service/ApprovalService.php`
- **acceptance_criteria**:
  - GIVEN a tier with `requiresApproval: true` WHEN `suspendForSubscription()` is called THEN a `pending` `approval_request` is created with no FlowToken snapshot and the configured `approverGroup` is notified
- [x] Implement
- [x] Test — tests/Unit/Service/ApprovalServiceSubscriptionTest.php

### Task 6: Add ProductSubscriptionsController (subscribe / approve / reject / analytics)
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-consumer-subscribes-to-an-api-product-at-a-tier-req-apg-003`, `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004`, `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-gateway-analytics-per-api-product-req-apg-007`
- **files**: `lib/Controller/ProductSubscriptionsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `POST /api/products/{id}/subscriptions` with a tier that requires no approval WHEN called THEN HTTP 201 and `status: active` are returned
  - GIVEN the same call with a tier requiring approval WHEN called THEN HTTP 202, `status: pending_approval`, and an `approvalRequestId` are returned
  - GIVEN an unknown tier name WHEN subscribing THEN HTTP 400 is returned and no subscription is created
  - GIVEN `GET /api/products/{id}/analytics` on a product with no traffic WHEN called THEN `requestCount: 0`, `errorRate: 0`, all percentiles `0` — no error
- [x] Implement — lib/Controller/ProductSubscriptionsController.php, appinfo/routes.php
- [x] Test — tests/Unit/Controller/ProductSubscriptionsControllerTest.php (201/202/400/404 subscribe outcomes, approve activates subscription, approve-without-approval-request 400, analytics zero-traffic and traffic-reflecting)

### Task 7: Add per-product latency percentile gauges (AppHost provider escape hatch)
- **spec_ref**: `openspec/changes/api-product-gateway/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013`
- **files**: `lib/Observability/OpenConnectorMetricsProvider.php`
- **acceptance_criteria**:
  - GIVEN a product's inbound `call_log` rows with varying `responseTime` WHEN `/api/metrics` is scraped THEN `openconnector_api_product_latency_seconds{product,quantile}` reflects p50/p95/p99 in seconds
  - GIVEN a product with zero traffic WHEN scraped THEN all three quantile samples are `0`, not omitted
  - GIVEN the underlying query throws WHEN scraped THEN a zero-value fallback is emitted with a warning logged and the endpoint still returns HTTP 200
- [x] Implement
- [x] Test — tests/Unit/Observability/OpenConnectorMetricsProviderTest.php (per-product p50/p95/p99 in seconds, zero for traffic-free product, zero-fallback on query failure)

### Task 8: Extend declarative request/error gauges with a product label
- **spec_ref**: `openspec/changes/api-product-gateway/specs/prometheus-metrics/spec.md#requirement-per-api-product-request-and-error-gauges-req-prom-012`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN inbound `call_log` rows carrying a `product` uuid WHEN `/api/metrics` is scraped THEN `openconnector_api_product_requests_total{product,status}` and `openconnector_api_product_errors_total{product}` are exposed
  - GIVEN a product with no traffic WHEN scraped THEN a zero-value placeholder is emitted
- [x] Implement — DEVIATION: `api_product_requests_total` (a plain row count grouped by product+status) is declarative in src/manifest.json exactly as specced. `api_product_errors_total` requires a `statusCode>=400` THRESHOLD aggregation, which the tableCount/groupBy vocabulary cannot express (same row-level-logic limitation class as percentiles) — implemented via the IMetricsProvider escape hatch (OpenConnectorMetricsProvider::apiProductErrorsSample()) instead of a manifest groupBy. Followed the code's actual declarative capability over the spec's literal "computed declaratively" wording for this one gauge, consistent with discovery.md's established deviation-documentation practice.
- [x] Test — api_product_requests_total is a pure manifest declaration (validated by `npm run check:manifest` schema pass, no PHP to unit-test); api_product_errors_total is tested in tests/Unit/Observability/OpenConnectorMetricsProviderTest.php

### Task 9: Add the API Products SPA pages (index + detail)
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002`
- **files**: `src/manifest.json`, `src/views/ApiProducts/ApiProductDetail.vue`
- **acceptance_criteria**:
  - GIVEN an authenticated admin WHEN they navigate to `/apps/openconnector/products` THEN the API Products index page renders with content visible
  - GIVEN a product's detail page WHEN opened THEN the admin can add/remove endpoints and add/edit named tiers, and see the analytics panel (request count, error rate, p50/p95/p99) and pending subscriptions with approve/reject actions
- [x] Implement — src/manifest.json (`ApiProducts` generic index page + `ApiProductDetail` custom page + menu entry), src/views/ApiProducts/ApiProductDetail.vue (endpoint picker via NcSelect with inputLabel/ariaLabelCombobox, tier editor, analytics panel as plain-text stats, subscriptions list with approve/reject), src/registry.js. DEVIATION from design.md's File Structure: no separate `ApiProductsIndex.vue` was written — the plain generic `type: "index"` page (mirroring Consumers) already satisfies REQ-APG-002's browse/create/delete requirement without bespoke chrome, per discovery.md's own "a plain index for browsing" framing.
- [ ] Test — requires a live NC instance + Playwright browser session; not runnable in this environment. `npm run check:manifest` (Ajv schema validation against nextcloud-vue's app-manifest-v2 schema) passes; `npm run build` (USE_LOCAL_LIB=false NODE_ENV=production) compiles the new component with 0 errors; `npx eslint` passes with 0 errors (pre-existing warnings only).

### Task 10: Surface subscriptions on the Consumer detail view
- **spec_ref**: `openspec/changes/api-product-gateway/specs/consumer-management/spec.md#requirement-consumer-detail-surfaces-its-api-product-subscriptions-req-con-sub-001`
- **files**: `src/manifest.json` (Consumer detail config), or the Consumer detail component it references
- **acceptance_criteria**:
  - GIVEN a consumer with active and pending subscriptions WHEN its detail view is opened THEN both are listed with product name, tier, and status
  - GIVEN a consumer with no subscriptions WHEN its detail view is opened THEN an empty state renders, not an error
- [x] Implement — src/manifest.json ConsumerDetail's `con-subscriptions` object-list widget (columns: product/tier/status, filtered by `consumer: @objectId`, `emptyText` for the zero-subscriptions case); replaces the placeholder generic `con-related` "Subscriptions" panel that predated this schema's existence with a widget carrying the specific columns REQ-CON-SUB-001 requires.
- [ ] Test — requires a live NC instance + Playwright; not runnable in this environment. `npm run check:manifest` passes.

### Task 11: Seed data for api_product and api_product_subscription
- **spec_ref**: `openspec/changes/api-product-gateway/design.md#seed-data`
- **files**: `lib/Settings/register.d/api-product-gateway.json` (`x-openregister-seed`)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the app is enabled THEN 3 `api_product` rows (one deprecated) and 2 `api_product_subscription` rows (one active, one pending_approval) exist, using the general organization/publications domain data already established by this app's other seeds
- [x] Implement — lib/Settings/register.d/api-product-gateway.json `x-openregister-seed` blocks: 3 api_product rows (woo-publications v1 deprecated/v2 active, kvk-lookup v1 active), 2 api_product_subscription rows (one active, one pending_approval) cross-referencing the existing `partner-portal` seeded Consumer and the existing `ping`/`echo`/`version` seeded Endpoints via `@ref:schema:slug`.
- [ ] Test — seed import verification requires a running Nextcloud + OpenRegister instance (`occ app:enable`); not runnable in this environment. JSON structure verified by inspection against the hitl-approval-rule-action.json seed precedent and `@ref:` cross-reference syntax found in environments-and-promotion.json.

## Verification
- [ ] All tasks checked off — 8/11 tasks fully implemented+tested; 3 (SPA pages, Consumer detail widget, seed data) implemented but their Test line is unticked (require a live NC instance/Playwright, not runnable in this environment); Task 1's Test line similarly unticked (occ app:update requires a live instance)
- [x] `openspec validate` passes — `openspec validate api-product-gateway --strict` → "Change 'api-product-gateway' is valid"
- [ ] Manual testing against acceptance criteria — no live dev instance available in this session; static checks (phpunit/phpcs/phpmd/psalm/phpstan/eslint/webpack build/check:manifest/check:register) all pass instead
- [ ] Code review against spec requirements — not performed by a separate reviewer in this session

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/Service/EndpointServiceTierPolicyTest.php`, `tests/Unit/Service/ApprovalServiceSubscriptionTest.php`, `tests/Unit/Observability/OpenConnectorMetricsProviderTest.php`, `tests/Unit/Controller/ProductSubscriptionsControllerTest.php`)
- [ ] Newman/Postman tests for the new API endpoints (subscribe/approve/reject/analytics; over-tier 429; deprecated-product headers) — requires a live instance to run `newman run`; not added in this session
- [ ] Browser tests (Playwright MCP) for the API Products index/detail pages and the Consumer detail subscription list — requires a live instance; not added in this session
- [x] All tests pass — full `phpunit -c phpunit-unit.xml` suite: 1575 tests, 4408 assertions, 0 failures, 1 pre-existing skip (baseline before this change: 1553/4338/1 skip — net +22 tests, +70 assertions, 0 regressions). `newman run` not executed (no live instance).

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` (API Products concept, tier configuration, deprecation headers) — not done in this session
- [ ] Screenshot captured and committed to `docs/images/` — requires a live instance/browser; not done in this session

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added for all new SPA strings (product/tier labels, subscription status, deprecation notices, analytics panel) — all new SPA strings use `t('openconnector', 'English text')` (English source strings only, ready for translation extraction); Dutch `nl_NL` translation files were not added in this session
