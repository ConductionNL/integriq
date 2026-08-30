# api-product-gateway Specification

## Purpose
TBD - created by archiving change api-product-gateway. Update Purpose after archive.
## Requirements
### Requirement: API Product groups Endpoints into a named, versioned bundle (REQ-APG-001)

The system MUST provide an `api_product` OpenRegister schema representing a
named bundle of existing `Endpoint`s at a specific `version`, with a
`productSlug` grouping multiple version-rows of the same logical product, a
`visibility` (`public`|`private`), a `status` (`active`|`deprecated`), and a
`tiers` map of named rate-limit/quota policies with a `defaultTier`. An
`api_product`'s `endpoints` array MUST reference existing `Endpoint` uuids;
creating or updating an `api_product` MUST NOT create, modify, or delete the
`Endpoint`s it references.

@e2e exclude backend schema definition — covered by PHPUnit, no browser UI

#### Scenario: an API Product groups multiple endpoints

- GIVEN three existing `Endpoint`s serving `/publications`, `/publications/{id}`, and `/publications/{id}/attachments`
- WHEN an administrator creates an `api_product` with `productSlug: "woo-publications"`, `version: "2.0.0"`, and those three endpoint uuids in `endpoints`
- THEN the `api_product` is persisted with all three endpoint uuids AND none of the three `Endpoint` objects are modified

#### Scenario: a product version is independent of other versions of the same product

- GIVEN two `api_product` rows sharing `productSlug: "woo-publications"` — one `version: "1.0.0"`, one `version: "2.0.0"`
- WHEN the `1.0.0` row's `endpoints` array is edited
- THEN the `2.0.0` row's `endpoints` array is unaffected

### Requirement: API Products management UI (REQ-APG-002)

Integriq MUST provide an **API Products** section in its SPA where an
administrator can browse, create, edit, and delete API Products, pick which
existing Endpoints belong to a product, and define/edit its named tiers.

#### Scenario: API Products list page mounts and shows content

- GIVEN an authenticated admin visits the integriq app
- WHEN they navigate to the API Products section via the sidebar nav or direct URL `/apps/integriq/products`
- THEN the API Products index page renders inside the main content area with content visible

#### Scenario: product detail page exposes an endpoint picker and tier editor

- GIVEN at least one `api_product` and at least one `Endpoint` exist
- WHEN the administrator opens the product's detail page
- THEN they can add/remove Endpoints from the product's `endpoints` array and add/edit named tiers with a `rateLimit`/`quota`/`requiresApproval` configuration

### Requirement: Consumer subscribes to an API Product at a tier (REQ-APG-003)

The system MUST let a Consumer be subscribed to an `api_product` at one of
its named `tiers` via `POST /api/products/{productId}/subscriptions`,
creating an `api_product_subscription` referencing the product, the
consumer, and the chosen tier. The chosen tier MUST exist in the product's
`tiers` map; an unknown tier MUST be rejected with HTTP 400.

@e2e exclude backend subscription creation — covered by Newman, not browser UI

#### Scenario: subscribing to a tier that requires no approval activates immediately

- GIVEN an `api_product` with a `free` tier where `requiresApproval` is absent (falsy)
- WHEN a Consumer subscribes at the `free` tier
- THEN an `api_product_subscription` is created with `status: active` and HTTP 201 is returned

#### Scenario: subscribing to an unknown tier is rejected

- GIVEN an `api_product` whose `tiers` map contains only `free` and `gold`
- WHEN a subscription request names tier `platinum`
- THEN the response is HTTP 400 and no `api_product_subscription` is created

### Requirement: Subscription approval gate reuses the HITL ApprovalService (REQ-APG-004)

When the chosen tier's `requiresApproval` is `true`, subscribing MUST create
the `api_product_subscription` with `status: pending_approval`, create a
`pending` `approval_request` via `ApprovalService::suspendForSubscription()`
(no `FlowToken` snapshot — see design.md Decision 4), notify the configured
`approverGroup`, and return HTTP 202 with the subscription id and the
approval_request id. Approving the request MUST flip the subscription's
`status` to `active` and stamp `activatedAt`; rejecting it MUST flip it to
`rejected`. A subscription that is not `active` MUST NOT receive its tier's
rate-limit/quota policy (`REQ-APG-005`); requests from a consumer with no
`active` subscription to a product's endpoint MUST receive HTTP 403.

@e2e exclude backend approval-gated subscription flow — covered by PHPUnit/Newman, not browser UI

#### Scenario: a gold tier requiring approval creates a pending subscription

- GIVEN an `api_product` whose `gold` tier has `requiresApproval: true`
- WHEN a Consumer subscribes at the `gold` tier
- THEN an `api_product_subscription` is created with `status: pending_approval`, a `pending` `approval_request` is created, the configured `approverGroup` is notified, and HTTP 202 is returned

#### Scenario: approving the request activates the subscription

- GIVEN a `pending_approval` subscription with its linked `pending` `approval_request`
- WHEN an authorized approver approves the request
- THEN the subscription's `status` becomes `active` with `activatedAt` set

#### Scenario: a pending subscription grants no access

- GIVEN a Consumer with only a `pending_approval` subscription to a product
- WHEN that consumer calls one of the product's endpoints
- THEN the response is HTTP 403 and no rate-limit policy from that product is applied

### Requirement: Per-tier rate-limit enforcement extends the inbound rate limiter (REQ-APG-005)

The system MUST, for a request to an `Endpoint` that belongs to an
`api_product`, resolve the caller's `active` subscription to that product
and enforce the subscription's tier `rateLimit`/`quota` via the existing
`InboundRateLimitService::enforce()` (unmodified — see design.md Decision 5),
keyed on `(consumer, product)` so product-tier counters never share a bucket
with the consumer's plain per-endpoint counters. A request exceeding the
tier's `rateLimit.requestsPerWindow` or `quota.limit` MUST receive HTTP 429
with the same `RateLimit-*`/`Retry-After` header contract as
`consumer-management` `REQ-CON-RL-003`. When the endpoint is not part of any
`api_product`, or the consumer has no `active` subscription to that product,
today's Consumer-level `rateLimit`/`quota` (`REQ-CON-RL-002`) applies
unchanged.

@e2e exclude backend enforcement — covered by PHPUnit/Newman, not browser UI

#### Scenario: over-tier request returns 429

- GIVEN an `active` subscription at the `free` tier (`rateLimit {requestsPerWindow: 2, windowSeconds: 60}`)
- WHEN the subscribed consumer makes 3 requests to the product's endpoint within the same window
- THEN the first 2 succeed and the 3rd receives HTTP 429 with `Retry-After`

#### Scenario: product-tier counters are independent of the consumer's own rateLimit

- GIVEN a Consumer with its own `rateLimit {requestsPerWindow: 100, windowSeconds: 60}` AND an `active` subscription to a product's `free` tier `{requestsPerWindow: 2, windowSeconds: 60}`
- WHEN the consumer calls the product's endpoint 3 times in the window
- THEN the 3rd request receives HTTP 429 from the tier limit even though the consumer's own 100-request budget is far from exhausted

#### Scenario: a non-product endpoint is unaffected

- GIVEN an `Endpoint` that belongs to no `api_product`
- WHEN its consumer calls it repeatedly within its own `rateLimit`
- THEN enforcement follows `consumer-management` `REQ-CON-RL-002` exactly as before this change

### Requirement: Deprecated product version carries Sunset and Deprecation headers (REQ-APG-006)

The system MUST, when an `api_product`'s `status` is `deprecated`, ensure
every response served through any of that product's `endpoints` carries a
`Deprecation: true` header and a `Sunset` header (RFC 8594, HTTP-date
format) reflecting the product's `sunsetDate`. An `api_product` with
`status: active` MUST NOT add either header.

@e2e exclude backend response headers — covered by Newman, not browser UI

#### Scenario: a deprecated product version's endpoint responses carry Sunset and Deprecation

- GIVEN an `api_product` with `status: deprecated` and `sunsetDate: "2026-10-01T00:00:00+00:00"`, grouping an endpoint `/publications`
- WHEN a request is served through `/publications`
- THEN the response carries `Deprecation: true` and `Sunset: Thu, 01 Oct 2026 00:00:00 GMT`

#### Scenario: an active product version's endpoint responses carry neither header

- GIVEN an `api_product` with `status: active` grouping an endpoint `/publications`
- WHEN a request is served through `/publications`
- THEN the response carries neither `Deprecation` nor `Sunset`

#### Scenario: an endpoint shared by an active and a deprecated version reflects only the version it was dispatched through

- GIVEN `productSlug: "woo-publications"` has a `deprecated` `1.0.0` row and an `active` `2.0.0` row, each grouping its own endpoint set
- WHEN a request is served through the `1.0.0` row's endpoint
- THEN Deprecation/Sunset headers are present, regardless of `2.0.0`'s status

### Requirement: Gateway analytics per API Product (REQ-APG-007)

The system MUST compute, per `api_product`, a request count, an error rate
(share of requests with `statusCode >= 400`), and p50/p95/p99 response-time
latency percentiles from inbound `call_log` rows carrying that product's
uuid (see `endpoint-runtime` `REQ-EP-009` for how those rows are produced),
and surface them both on the API Products detail page
(`GET /api/products/{productId}/analytics`) and as Prometheus gauges (see
`prometheus-metrics` `REQ-PROM-012`/`REQ-PROM-013`).

@e2e exclude backend analytics computation — covered by PHPUnit, no browser UI

#### Scenario: analytics reflect recent traffic

- GIVEN a product with 100 recorded inbound `call_log` rows in the last hour, 5 with `statusCode >= 400`
- WHEN `GET /api/products/{productId}/analytics` is called
- THEN `requestCount` is 100 and `errorRate` is 0.05

#### Scenario: latency percentiles are computed from responseTime

- GIVEN a product's recent inbound `call_log` rows with `responseTime` values ranging 10ms-500ms
- WHEN the analytics endpoint (or the Prometheus scrape) computes percentiles
- THEN `p50`/`p95`/`p99` reflect the 50th/95th/99th percentile of the recorded `responseTime` values

#### Scenario: a product with no recorded traffic reports zero, not an error

- GIVEN a newly created product with no inbound `call_log` rows yet
- WHEN analytics are requested
- THEN `requestCount` is 0, `errorRate` is 0, and latency percentiles are 0 — no error is raised

