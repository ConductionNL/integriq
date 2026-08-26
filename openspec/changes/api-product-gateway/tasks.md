# Tasks: api-product-gateway

## Implementation Tasks

### Task 1: Add the api-product-gateway register fragment (schema/migration)
- **spec_ref**: `openspec/changes/api-product-gateway/migration.md`, `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-api-product-groups-endpoints-into-a-named-versioned-bundle-req-apg-001`
- **files**: `lib/Settings/register.d/api-product-gateway.json`
- **acceptance_criteria**:
  - GIVEN the app is upgraded WHEN `occ app:update integriq` runs THEN `api_product` and `api_product_subscription` schemas are registered AND `call_log` gains `product`/`endpoint`/`responseTime` properties without any existing `call_log` row being modified
  - GIVEN the migration re-runs a second time WHEN `occ app:update integriq` runs again THEN no error occurs and no duplicate schema rows are created
- [ ] Implement
- [ ] Test

### Task 2: Resolve per-tier rate-limit policy ahead of the inbound limiter
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-per-tier-rate-limit-enforcement-extends-the-inbound-rate-limiter-req-apg-005`, `openspec/changes/api-product-gateway/specs/consumer-management/spec.md#requirement-per-product-tier-policy-takes-precedence-over-the-consumer-level-rate-limit-req-con-sub-002`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN a consumer with an active subscription to a product's `free` tier `{requestsPerWindow:2,windowSeconds:60}` WHEN it makes 3 requests to the product's endpoint in one window THEN the 3rd receives HTTP 429 with `Retry-After`
  - GIVEN the same consumer also calling an endpoint outside any product WHEN it calls that endpoint THEN its own Consumer-level `rateLimit` applies unchanged
  - GIVEN a request to an endpoint in no `api_product` WHEN it is dispatched THEN behaviour is byte-for-byte identical to before this change (no regression on `REQ-CON-RL-002`)
- [ ] Implement
- [ ] Test

### Task 3: Add Sunset/Deprecation headers for deprecated product versions
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-deprecated-product-version-carries-sunset-and-deprecation-headers-req-apg-006`, `openspec/changes/api-product-gateway/specs/endpoint-runtime/spec.md#requirement-deprecated-product-version-dispatch-attaches-sunset-deprecation-headers-req-ep-008`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN an endpoint that belongs to an `api_product` with `status: deprecated` and a `sunsetDate` WHEN a request is served (fast path or full pipeline) THEN the response carries `Deprecation: true` and `Sunset: <HTTP-date>`
  - GIVEN an endpoint that belongs to an active or no product WHEN a request is served THEN neither header is present
- [ ] Implement
- [ ] Test

### Task 4: Log inbound requests for product-scoped endpoints
- **spec_ref**: `openspec/changes/api-product-gateway/specs/endpoint-runtime/spec.md#requirement-inbound-observability-logging-for-api-product-scoped-endpoints-req-ep-009`
- **files**: `lib/Service/EndpointService.php`
- **acceptance_criteria**:
  - GIVEN an endpoint that belongs to an `api_product` WHEN a request completes (2xx or error) THEN a `call_log` row is persisted with `direction: inbound`, the product/endpoint uuids, `statusCode`, and `responseTime`
  - GIVEN an endpoint in no `api_product` WHEN a successful request completes THEN no `call_log` row is written for it (unchanged from today — only its 429s are logged)
  - GIVEN the `call_log` write throws WHEN a product-scoped request otherwise succeeds THEN the response is still returned and the failure is only logged
- [ ] Implement
- [ ] Test

### Task 5: Add subscription-approval creation to ApprovalService
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004`, `openspec/changes/api-product-gateway/design.md#decision-4-subscription-approval-reuses-approvalservices-generic-state-machine-via-one-new-creation-method-not-suspend`
- **files**: `lib/Service/ApprovalService.php`
- **acceptance_criteria**:
  - GIVEN a tier with `requiresApproval: true` WHEN `suspendForSubscription()` is called THEN a `pending` `approval_request` is created with no FlowToken snapshot and the configured `approverGroup` is notified
- [ ] Implement
- [ ] Test

### Task 6: Add ProductSubscriptionsController (subscribe / approve / reject / analytics)
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-consumer-subscribes-to-an-api-product-at-a-tier-req-apg-003`, `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004`, `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-gateway-analytics-per-api-product-req-apg-007`
- **files**: `lib/Controller/ProductSubscriptionsController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN `POST /api/products/{id}/subscriptions` with a tier that requires no approval WHEN called THEN HTTP 201 and `status: active` are returned
  - GIVEN the same call with a tier requiring approval WHEN called THEN HTTP 202, `status: pending_approval`, and an `approvalRequestId` are returned
  - GIVEN an unknown tier name WHEN subscribing THEN HTTP 400 is returned and no subscription is created
  - GIVEN `GET /api/products/{id}/analytics` on a product with no traffic WHEN called THEN `requestCount: 0`, `errorRate: 0`, all percentiles `0` — no error
- [ ] Implement
- [ ] Test

### Task 7: Add per-product latency percentile gauges (AppHost provider escape hatch)
- **spec_ref**: `openspec/changes/api-product-gateway/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013`
- **files**: `lib/Observability/IntegriqMetricsProvider.php`
- **acceptance_criteria**:
  - GIVEN a product's inbound `call_log` rows with varying `responseTime` WHEN `/api/metrics` is scraped THEN `integriq_api_product_latency_seconds{product,quantile}` reflects p50/p95/p99 in seconds
  - GIVEN a product with zero traffic WHEN scraped THEN all three quantile samples are `0`, not omitted
  - GIVEN the underlying query throws WHEN scraped THEN a zero-value fallback is emitted with a warning logged and the endpoint still returns HTTP 200
- [ ] Implement
- [ ] Test

### Task 8: Extend declarative request/error gauges with a product label
- **spec_ref**: `openspec/changes/api-product-gateway/specs/prometheus-metrics/spec.md#requirement-per-api-product-request-and-error-gauges-req-prom-012`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN inbound `call_log` rows carrying a `product` uuid WHEN `/api/metrics` is scraped THEN `integriq_api_product_requests_total{product,status}` and `integriq_api_product_errors_total{product}` are exposed
  - GIVEN a product with no traffic WHEN scraped THEN a zero-value placeholder is emitted
- [ ] Implement
- [ ] Test

### Task 9: Add the API Products SPA pages (index + detail)
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002`
- **files**: `src/manifest.json`, `src/views/ApiProducts/ApiProductDetail.vue`
- **acceptance_criteria**:
  - GIVEN an authenticated admin WHEN they navigate to `/apps/integriq/products` THEN the API Products index page renders with content visible
  - GIVEN a product's detail page WHEN opened THEN the admin can add/remove endpoints and add/edit named tiers, and see the analytics panel (request count, error rate, p50/p95/p99) and pending subscriptions with approve/reject actions
- [ ] Implement
- [ ] Test

### Task 10: Surface subscriptions on the Consumer detail view
- **spec_ref**: `openspec/changes/api-product-gateway/specs/consumer-management/spec.md#requirement-consumer-detail-surfaces-its-api-product-subscriptions-req-con-sub-001`
- **files**: `src/manifest.json` (Consumer detail config), or the Consumer detail component it references
- **acceptance_criteria**:
  - GIVEN a consumer with active and pending subscriptions WHEN its detail view is opened THEN both are listed with product name, tier, and status
  - GIVEN a consumer with no subscriptions WHEN its detail view is opened THEN an empty state renders, not an error
- [ ] Implement
- [ ] Test

### Task 11: Seed data for api_product and api_product_subscription
- **spec_ref**: `openspec/changes/api-product-gateway/design.md#seed-data`
- **files**: `lib/Settings/register.d/api-product-gateway.json` (`x-openregister-seed`)
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the app is enabled THEN 3 `api_product` rows (one deprecated) and 2 `api_product_subscription` rows (one active, one pending_approval) exist, using the general organization/publications domain data already established by this app's other seeds
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/Service/EndpointServiceTierPolicyTest.php`, `tests/Unit/Service/ApprovalServiceSubscriptionTest.php`, `tests/Unit/Observability/IntegriqMetricsProviderTest.php`, `tests/Unit/Controller/ProductSubscriptionsControllerTest.php`)
- [ ] Newman/Postman tests for the new API endpoints (subscribe/approve/reject/analytics; over-tier 429; deprecated-product headers)
- [ ] Browser tests (Playwright MCP) for the API Products index/detail pages and the Consumer detail subscription list
- [ ] All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/` (API Products concept, tier configuration, deprecation headers)
- [ ] Screenshot captured and committed to `docs/images/`

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added for all new SPA strings (product/tier labels, subscription status, deprecation notices, analytics panel)
