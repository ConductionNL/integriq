# Test Plan: api-product-gateway

## Test Cases

### TC-1: API Product groups endpoints without mutating them
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-api-product-groups-endpoints-into-a-named-versioned-bundle-req-apg-001`
- **type**: api
- **preconditions**: three existing `Endpoint`s
- **steps**: create an `api_product` referencing all three endpoint uuids
- **expected result**: the product persists with all three uuids; none of the three `Endpoint` objects change
- **test command**: /test-api

### TC-2: Product versions are independent
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-api-product-groups-endpoints-into-a-named-versioned-bundle-req-apg-001`
- **type**: api
- **preconditions**: two `api_product` rows sharing a `productSlug`, different `version`
- **steps**: edit one version's `endpoints` array
- **expected result**: the other version's `endpoints` array is unaffected
- **test command**: /test-api

### TC-3: API Products index page renders
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002`
- **type**: functional
- **preconditions**: authenticated admin session
- **steps**: navigate to `/apps/openconnector/products` via sidebar nav
- **expected result**: index page renders inside main content area with content visible
- **test command**: /test-functional

### TC-4: Product detail exposes endpoint picker and tier editor
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-api-products-management-ui-req-apg-002`
- **type**: functional
- **preconditions**: one `api_product` and one `Endpoint` exist
- **steps**: open the product's detail page; add an endpoint; add a tier with a rateLimit
- **expected result**: the endpoint appears in the product's `endpoints`; the tier persists with its policy
- **test command**: /test-functional

### TC-5: Endpoint picker and tier editor are accessible
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#non-functional-requirements`
- **type**: accessibility
- **preconditions**: product detail page loaded
- **steps**: run WCAG 2.2 AA audit against the endpoint picker (`NcSelect`) and tier editor controls
- **expected result**: no missing accessible-name violations; `inputLabel`/`ariaLabelCombobox` present on every `NcSelect`
- **test command**: /test-accessibility

### TC-6: Subscribing to a no-approval tier activates immediately
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-consumer-subscribes-to-an-api-product-at-a-tier-req-apg-003`
- **type**: api
- **preconditions**: product with a `free` tier, `requiresApproval` absent
- **steps**: `POST /api/products/{id}/subscriptions` with `{consumerId, tier:"free"}`
- **expected result**: HTTP 201, `status: active`
- **test command**: /test-api

### TC-7: Subscribing to an unknown tier is rejected
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-consumer-subscribes-to-an-api-product-at-a-tier-req-apg-003`
- **type**: api
- **preconditions**: product with tiers `free`/`gold` only
- **steps**: `POST /api/products/{id}/subscriptions` with `tier:"platinum"`
- **expected result**: HTTP 400, no subscription created
- **test command**: /test-api

### TC-8: Approval-gated tier creates a pending subscription and notifies approvers
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004`
- **type**: api
- **preconditions**: product with a `gold` tier, `requiresApproval: true`, `approverGroup: "gateway-approvers"`
- **steps**: `POST /api/products/{id}/subscriptions` with `tier:"gold"`
- **expected result**: HTTP 202, `status: pending_approval`, `approvalRequestId` present; every member of `gateway-approvers` receives an NC notification
- **test command**: /test-api

### TC-9: Approving a subscription activates it
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004`
- **type**: api
- **preconditions**: a `pending_approval` subscription with its linked `pending` approval_request
- **steps**: an authorized approver calls the approve action
- **expected result**: subscription `status` becomes `active`, `activatedAt` set
- **test command**: /test-api

### TC-10: A pending subscription grants no access
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-subscription-approval-gate-reuses-the-hitl-approvalservice-req-apg-004`
- **type**: api
- **preconditions**: consumer with only a `pending_approval` subscription to a product
- **steps**: call the product's endpoint
- **expected result**: HTTP 403
- **test command**: /test-api

### TC-11: Over-tier request returns 429 without exhausting the consumer's own limit
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-per-tier-rate-limit-enforcement-extends-the-inbound-rate-limiter-req-apg-005`, `openspec/changes/api-product-gateway/specs/consumer-management/spec.md#requirement-per-product-tier-policy-takes-precedence-over-the-consumer-level-rate-limit-req-con-sub-002`
- **type**: api
- **preconditions**: consumer with `rateLimit{1000,60}` AND an active subscription to a product's `free` tier `{2,60}`
- **steps**: call the product's endpoint 3 times within one window
- **expected result**: requests 1-2 succeed, request 3 returns HTTP 429 with `Retry-After`; the consumer's own 1000-budget is unaffected
- **test command**: /test-api

### TC-12: Non-product endpoints are unaffected by tier enforcement
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-per-tier-rate-limit-enforcement-extends-the-inbound-rate-limiter-req-apg-005`
- **type**: regression
- **preconditions**: an endpoint that belongs to no `api_product`
- **steps**: call it repeatedly within the consumer's own `rateLimit`
- **expected result**: behaviour identical to pre-change `REQ-CON-RL-002`
- **test command**: /test-regression

### TC-13: Deprecated product version emits Sunset/Deprecation headers
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-deprecated-product-version-carries-sunset-and-deprecation-headers-req-apg-006`, `openspec/changes/api-product-gateway/specs/endpoint-runtime/spec.md#requirement-deprecated-product-version-dispatch-attaches-sunset-deprecation-headers-req-ep-008`
- **type**: api
- **preconditions**: `api_product` with `status: deprecated`, `sunsetDate` set, grouping an endpoint
- **steps**: call the endpoint
- **expected result**: response carries `Deprecation: true` and `Sunset: <HTTP-date>`
- **test command**: /test-api

### TC-14: Active product version emits neither header (both dispatch paths)
- **spec_ref**: `openspec/changes/api-product-gateway/specs/endpoint-runtime/spec.md#requirement-deprecated-product-version-dispatch-attaches-sunset-deprecation-headers-req-ep-008`
- **type**: api
- **preconditions**: one simple (fast-path) and one full-pipeline endpoint, both in an `active` product
- **steps**: call each
- **expected result**: neither response carries `Deprecation` or `Sunset`
- **test command**: /test-api

### TC-15: Product-scoped requests are logged with duration (success and error)
- **spec_ref**: `openspec/changes/api-product-gateway/specs/endpoint-runtime/spec.md#requirement-inbound-observability-logging-for-api-product-scoped-endpoints-req-ep-009`
- **type**: api
- **preconditions**: an endpoint in an `api_product`
- **steps**: call it once successfully, once forcing a 500
- **expected result**: two `call_log` rows persisted with `direction:inbound`, product/endpoint uuids, correct `statusCode`, and a positive `responseTime`
- **test command**: /test-api

### TC-16: Non-product endpoint successful requests remain unlogged
- **spec_ref**: `openspec/changes/api-product-gateway/specs/endpoint-runtime/spec.md#requirement-inbound-observability-logging-for-api-product-scoped-endpoints-req-ep-009`
- **type**: regression
- **preconditions**: an endpoint in no `api_product`
- **steps**: call it successfully
- **expected result**: no new `call_log` row for that call
- **test command**: /test-regression

### TC-17: Gateway analytics reflect recent traffic
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-gateway-analytics-per-api-product-req-apg-007`
- **type**: api
- **preconditions**: a product with a mix of recorded inbound rows (some errors)
- **steps**: `GET /api/products/{id}/analytics`
- **expected result**: `requestCount`, `errorRate`, and `latency.p50/p95/p99` reflect the recorded rows
- **test command**: /test-api

### TC-18: Analytics for a traffic-free product report zero, not an error
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-gateway-analytics-per-api-product-req-apg-007`
- **type**: api
- **preconditions**: newly created product, no `call_log` rows
- **steps**: `GET /api/products/{id}/analytics`
- **expected result**: `requestCount:0`, `errorRate:0`, all percentiles `0`, HTTP 200
- **test command**: /test-api

### TC-19: Per-product Prometheus request/error gauges
- **spec_ref**: `openspec/changes/api-product-gateway/specs/prometheus-metrics/spec.md#requirement-per-api-product-request-and-error-gauges-req-prom-012`
- **type**: api
- **preconditions**: inbound rows across two products, mixed status codes
- **steps**: `GET /api/metrics`
- **expected result**: `openconnector_api_product_requests_total{product,status}` and `openconnector_api_product_errors_total{product}` present with correct counts; zero-value placeholder for a traffic-free product
- **test command**: /test-api

### TC-20: Per-product Prometheus latency percentile gauges
- **spec_ref**: `openspec/changes/api-product-gateway/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013`
- **type**: api
- **preconditions**: a product with recorded `responseTime` values
- **steps**: `GET /api/metrics`
- **expected result**: `openconnector_api_product_latency_seconds{product,quantile}` present for `0.5`/`0.95`/`0.99`, in seconds
- **test command**: /test-api

### TC-21: Percentile gauge degrades gracefully on query failure
- **spec_ref**: `openspec/changes/api-product-gateway/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013`
- **type**: api
- **preconditions**: simulate the underlying `call_log` query throwing
- **steps**: `GET /api/metrics`
- **expected result**: zero-value fallback emitted, warning logged, endpoint still returns HTTP 200
- **test command**: /test-api

### TC-22: Metrics scrape stays within budget at moderate scale
- **spec_ref**: `openspec/changes/api-product-gateway/specs/prometheus-metrics/spec.md#requirement-per-api-product-latency-percentile-gauges-req-prom-013`
- **type**: performance
- **preconditions**: 50 active products, up to 1000 rows each
- **steps**: `GET /api/metrics`, measure wall time
- **expected result**: completes within the existing 500ms `REQ-PROM-001` scrape budget
- **test command**: /test-performance

### TC-23: Consumer detail lists active and pending subscriptions
- **spec_ref**: `openspec/changes/api-product-gateway/specs/consumer-management/spec.md#requirement-consumer-detail-surfaces-its-api-product-subscriptions-req-con-sub-001`
- **type**: functional
- **preconditions**: a Consumer with one active and one pending subscription
- **steps**: open that Consumer's detail view
- **expected result**: both subscriptions listed with product name, tier, status
- **test command**: /test-functional

### TC-24: Consumer detail shows an empty state with no subscriptions
- **spec_ref**: `openspec/changes/api-product-gateway/specs/consumer-management/spec.md#requirement-consumer-detail-surfaces-its-api-product-subscriptions-req-con-sub-001`
- **type**: functional
- **preconditions**: a Consumer with zero subscriptions
- **steps**: open that Consumer's detail view
- **expected result**: empty state renders, no error thrown
- **test command**: /test-functional

### TC-25: Gateway operator persona — subscribe, over-limit, deprecate end to end
- **spec_ref**: `openspec/changes/api-product-gateway/specs/api-product-gateway/spec.md#requirement-consumer-subscribes-to-an-api-product-at-a-tier-req-apg-003`
- **type**: persona
- **persona**: Mark (MKB Software Vendor integrating against the gateway)
- **preconditions**: a published API Product with tiers
- **steps**: subscribe a consumer at a low tier, exceed its limit, observe the 429 and Retry-After, then have an admin deprecate the product version and re-call the endpoint
- **expected result**: the full lifecycle behaves per REQ-APG-003/005/006 from an integrator's point of view — clear 429 with retry guidance, clear deprecation signal
- **test command**: /test-persona-mark

## Coverage Summary

| Requirement | Covered by |
|---|---|
| api-product-gateway REQ-APG-001 | TC-1, TC-2 |
| api-product-gateway REQ-APG-002 | TC-3, TC-4, TC-5 |
| api-product-gateway REQ-APG-003 | TC-6, TC-7, TC-25 |
| api-product-gateway REQ-APG-004 | TC-8, TC-9, TC-10 |
| api-product-gateway REQ-APG-005 | TC-11, TC-12, TC-25 |
| api-product-gateway REQ-APG-006 | TC-13, TC-14, TC-25 |
| api-product-gateway REQ-APG-007 | TC-17, TC-18 |
| consumer-management REQ-CON-SUB-001 | TC-23, TC-24 |
| consumer-management REQ-CON-SUB-002 | TC-11 |
| endpoint-runtime REQ-EP-008 | TC-13, TC-14 |
| endpoint-runtime REQ-EP-009 | TC-15, TC-16 |
| prometheus-metrics REQ-PROM-012 | TC-19 |
| prometheus-metrics REQ-PROM-013 | TC-20, TC-21, TC-22 |

All 13 ADDED requirements across the 4 spec deltas have at least one covering
test case; every requirement with an error/degraded-path scenario has a
dedicated negative test case (TC-7, TC-10, TC-12, TC-16, TC-18, TC-21).

## Out of Scope

- Self-service developer portal / API key issuance UI — out of scope for
  this change (proposal.md), no test cases written.
- Monetization/billing on tiers — out of scope, no test cases written.
- Load/soak testing of the distributed rate-limit cache under concurrency
  beyond what `consumer-management`'s existing "counters are correct under
  concurrency" test already covers for the underlying
  `InboundRateLimitService` — this change does not modify that service, so
  its concurrency guarantee is inherited, not re-verified here.
