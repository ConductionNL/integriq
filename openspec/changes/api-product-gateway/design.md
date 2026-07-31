# Design: api-product-gateway

## Architecture Overview

```
                         ┌────────────────────┐
  Consumer subscribes    │  api_product        │  groups N Endpoints (uuid[])
  ───────────────────►   │  - tiers{name:policy}│  version + status + sunsetDate
                         │  - defaultTier       │  visibility
                         └─────────┬───────────┘
                                   │ 1:N
                         ┌─────────▼───────────┐        requiresApproval? ──► ApprovalService
                         │ api_product_         │             (approval_request, generic)
                         │ subscription          │
                         │ - consumer, tier      │
                         │ - status              │
                         └─────────┬───────────┘
                                   │ resolved at request time
  inbound request ──► EndpointService::doHandleRequest()
                         │
                         ├─ resolveProductTierPolicy() ──► InboundRateLimitService::enforce()
                         │     (NEW — reads api_product+subscription; falls back to           (UNCHANGED)
                         │      Consumer.rateLimit/quota when no product/tier applies)
                         │
                         ├─ recordInboundCallLog() ──► call_log (product, endpoint, responseTime)
                         │     (NEW — only for product-attached endpoints)
                         │
                         └─ handleRequest() header-merge loop
                               ├─ RateLimit-* / Retry-After  (UNCHANGED, REQ-CON-RL-003)
                               └─ Sunset / Deprecation        (NEW, RFC 8594)

  GET /api/metrics ──► MetricsController
                         ├─ declarative groupBy(call_log, product) → request/error counts
                         └─ OpenConnectorMetricsProvider (escape hatch) → p50/p95/p99 gauges
```

## API Design

`api_product` and `api_product_subscription` are plain OpenRegister-backed
schemas; CRUD goes through OpenRegister's generic object API
(`/api/objects/openconnector/api_product`, `/api/objects/openconnector/
api_product_subscription`) exactly like `endpoint` and `consumer` today — no
bespoke controller (`openconnector-direct-or-usage` / redundant-controller
avoidance). Two small custom endpoints are needed for the approval-gated
subscribe flow, mirroring `ApprovalsController`'s shape:

### `POST /api/products/{productId}/subscriptions`

**Request:**
```json
{ "consumerId": "<uuid>", "tier": "gold" }
```
**Response (201, auto-approved tier):**
```json
{ "uuid": "<uuid>", "status": "active", "tier": "gold", "product": "<uuid>", "consumer": "<uuid>" }
```
**Response (202, approval-required tier):**
```json
{ "uuid": "<uuid>", "status": "pending_approval", "approvalRequestId": "<uuid>" }
```

### `GET /api/products/{productId}/analytics`

**Request:** (query params `window` seconds, default 3600)

**Response (200):**
```json
{
  "requestCount": 4213,
  "errorRate": 0.012,
  "latency": { "p50": 42, "p95": 180, "p99": 410 }
}
```

## Database Changes

Shipped as one `register.d` fragment (ADR-037):
`lib/Settings/register.d/api-product-gateway.json`.

- **New schema `api_product`** — `uuid`, `name` (required), `description`,
  `productSlug` (required — groups version-rows of the same logical
  product), `version` (semver, default `1.0.0`), `visibility`
  (`public`|`private`, default `public`), `status`
  (`active`|`deprecated`, default `active`), `sunsetDate` (date-time,
  required when `status: deprecated`), `endpoints` (array of Endpoint uuid
  strings — same array-of-string-ref pattern as `endpoint.rules`), `tiers`
  (object map `tierName -> {rateLimit, quota, requiresApproval}`, same
  `rateLimit`/`quota` shape as the `consumer` schema), `defaultTier`
  (string), `created`/`updated`.
- **New schema `api_product_subscription`** — `uuid`, `product` (uuid FK →
  `api_product`, onDelete CASCADE), `consumer` (uuid FK → `consumer`,
  onDelete CASCADE), `tier` (string), `status`
  (`pending_approval`|`active`|`rejected`|`revoked`, default
  `pending_approval`), `approvalRequestId` (uuid FK → `approval_request`,
  onDelete SET_NULL, nullable), `requesterUserId`, `createdAt`,
  `activatedAt`, `revokedAt`.
- **Deep-merge onto existing `call_log`** (per `99-source-secrets-writeonly.json`
  precedent — a fragment can add properties to a pre-existing schema without
  touching the monolith): `product` (uuid FK → `api_product`, onDelete
  SET_NULL), `endpoint` (uuid FK → `endpoint`, onDelete SET_NULL),
  `responseTime` (integer, milliseconds — top-level so it is directly
  aggregatable for percentile queries, unlike the outbound path's nested
  `response.responseTime`).

Full migration plan: see `migration.md`.

## Decisions

### Decision 1: `api_product` rows are per-(product, version), not nested version arrays

**Choice:** One `api_product` OR object per product **version**, grouped by
a shared `productSlug`. Deprecating "v1" means setting `status: deprecated`
+ `sunsetDate` on the v1 row; v2 is untouched.

**Alternative considered:** A single `api_product` row per product name with
a nested `versions[]` array (each carrying its own `endpoints`/`status`/
`sunsetDate`). Rejected: every existing versioned entity in this schema
(`endpoint.version`, `source` has no version) uses a flat row + slug/
reference-grouping pattern, not nested version arrays; nesting would be the
first of its kind in this register and complicates the tier/rate-limit
resolution query (`resolveProductTierPolicy()` would need to reach *into* a
JSON array instead of a direct object lookup by uuid).

### Decision 2: A subscription in `pending_approval` blocks access (403), it does not fall back to `defaultTier`

**Choice:** A request from a Consumer whose only subscription to the
product is `pending_approval` (or has none at all) is rejected with 403 —
"subscribe" is opt-in access, and falling back to `defaultTier` would let an
unapproved consumer bypass the approval gate the operator explicitly
configured for that tier.

**Alternative considered:** Silently applying `defaultTier`'s policy while
approval is pending, so the consumer isn't blocked. Rejected: this defeats
the entire purpose of `requiresApproval` — an operator who gates a tier
behind approval expects zero access until approved, not degraded-but-open
access.

### Decision 3: Percentiles are computed from a bounded per-product `call_log` window at scrape time, not pre-aggregated storage

**Choice:** `OpenConnectorMetricsProvider::metrics()` gains one new sample
producer that, per `api_product`, fetches the most recent N (bound: 1000,
matching the existing `REQ-PROM-007` top-100-cardinality-class precedent
scaled for a per-row not per-series cap) inbound `call_log` rows with that
`product` uuid within the query window, sorts their `responseTime` values,
and computes p50/p95/p99 by index. No new aggregate table, no background
job — consistent with every other `REQ-PROM-*` metric ("computed at query
time from existing tables").

**Alternative considered:** A dedicated `api_product_latency_bucket`
pre-aggregation table updated on every request (true HDR-histogram style).
Rejected: violates the "no new data model entities" precedent every other
metric in this app follows, and adds a write on the hot request path for a
metric that only needs to be right at scrape granularity (15s per
`REQ-PROM-001`), not per-request.

### Decision 4: Subscription approval reuses `ApprovalService`'s generic state machine via one new creation method, not `suspend()`

**Choice:** Add `ApprovalService::suspendForSubscription(string
$subscriptionId, string $approverGroup, string $onReject, int $ttlSeconds):
ObjectEntity`, structurally identical to the existing
`suspendForSynchronization()` (empty `snapshot`, no FlowToken, persists
`pending` `approval_request`, calls the existing `notifyApprovers()`).
`SubscriptionsController::approve()`/`reject()` call the existing, already
subject-agnostic `ApprovalService::completeApproval()` /
`ApprovalService::reject()` directly — no new approve/reject logic. On
`completeApproval()`, the controller (not `ApprovalService`, keeping the
orchestration split `ApprovalService`'s own docblock already documents)
flips the `api_product_subscription.status` to `active` and stamps
`activatedAt`.

**Alternative considered:** Generalizing `ApprovalService::suspend()` itself
to accept an arbitrary "subject" instead of `(endpoint, rule, flowToken)`.
Rejected: `suspend()`'s snapshot-stripping and `resumeOrder` fields are
meaningless for a subscription and would become dead parameters on this call
path — `suspendForSynchronization()` already proved the "small dedicated
creation method, shared everything else" pattern is the lower-risk fork
point.

### Decision 5: Per-tier rate-limit resolution is a new step ahead of `enforceInboundRateLimit()`, `InboundRateLimitService::enforce()` is untouched

**Choice:** `EndpointService` gains a private
`resolveProductTierPolicy(ObjectEntity $endpoint, ObjectEntity $consumer):
?array` returning `['key' => string, 'rateLimit' => ?array, 'quota' =>
?array]` or `null`. When non-null, `enforceInboundRateLimit()` uses its
`key`/`rateLimit`/`quota` instead of deriving them from the Consumer
directly; when `null` (endpoint isn't in any `api_product`, or the consumer
has no `active` subscription to that product), behaviour is byte-for-byte
today's `REQ-CON-RL-002` path. The resolved `key` is namespaced
`product:{productUuid}:consumer:{consumerKey}` so it never collides with a
plain `consumer:{key}`/`ip:{addr}` counter in the same distributed cache.

**Alternative considered:** A new `ProductRateLimitService` wrapping
`InboundRateLimitService`. Rejected: `enforce()`'s contract (`consumerKey`,
`rateLimit`, `quota` → `RateLimitDecision`) is already exactly what's
needed; a wrapper service would just forward three resolved values to the
same method, adding a layer with no behaviour of its own — the resolution
logic belongs next to where the Consumer's own `rateLimit`/`quota` are
already read (`enforceInboundRateLimit()`), not behind a new service
boundary.

### Decision 6: Deprecation headers reuse the existing `handleRequest()` header-merge choke point

**Choice:** `$this->deprecationHeaders` (new instance array, same lifecycle
as the existing `$this->rateLimitHeaders`) is populated when the matched
endpoint belongs to an `api_product` with `status: deprecated`:
`Deprecation: true` and `Sunset: <sunsetDate, HTTP-date format>` (RFC 8594).
`handleRequest()`'s existing header-merge `foreach` loop (today only over
`rateLimitHeaders`) iterates both bags.

**Alternative considered:** A new `after`-timing rule type
(`deprecation_headers`), dispatched from `processRules()` like
`selfurl_hal` (`REQ-EP-006`). Rejected: `selfurl_hal` needs to be opt-in
per-Endpoint because it's a general-purpose output helper; deprecation
headers are not opt-in — they are a direct, non-optional consequence of the
product's own `status` field, so gating them behind a Rule an operator must
remember to attach on every endpoint is the wrong default and an easy way to
silently under-deliver RFC 8594 compliance.

## Risks / Trade-offs

- [Risk] Product-scoped inbound `call_log` writes add one extra OR
  `saveObject()` call per request on product-attached endpoints → [Mitigation]
  scoped only to product-attached endpoints (see proposal.md Risk 1); the
  write is best-effort (same try/catch-and-log pattern as
  `recordInboundThrottle()` — a logging failure never blocks the response).
- [Risk] A consumer with an `active` subscription whose product is later
  deleted leaves `api_product_subscription.product` null (`SET_NULL`) →
  [Mitigation] `resolveProductTierPolicy()` treats a subscription with a
  null `product` as "no policy" (falls back to Consumer-level), so the
  subscription row becomes an inert audit record rather than a crash.
- [Risk] Percentile computation reads up to 1000 rows per product per scrape
  → [Mitigation] see Decision 3; a query failure falls back to a zero-value
  sample with a warning logged (matches `REQ-PROM-011`'s existing degraded
  pattern), never a 500.

## Migration Plan

See `migration.md`.

## Nextcloud Integration

- Controllers: `lib/Controller/ProductSubscriptionsController.php` (new,
  thin — subscribe/approve/reject/analytics; everything else is generic OR
  object CRUD).
- Services: `lib/Service/EndpointService.php` (extended),
  `lib/Service/ApprovalService.php` (extended, one new method).
- Mappers/Entities: none new — everything is an OpenRegister object, no
  app-local `Db\` entity/mapper per `openconnector-direct-or-usage`.
- Observability: `lib/Observability/OpenConnectorMetricsProvider.php`
  (extended), `src/manifest.json` `observability.metrics[]` (extended
  `calls_total` groupBy + one new declarative descriptor).

## Security Considerations

- Subscription creation/approve/reject follow the same two-layer
  authorization `ApprovalService` already enforces
  (`isAuthorizedApprover()` — NC admin or `approverGroup` member); no new
  authorization primitive is introduced.
- The `POST /api/products/{id}/subscriptions` endpoint requires an
  authenticated NC admin session (creating subscriptions on behalf of a
  Consumer is an administrative action, same posture as Consumer
  create/edit today) — `#[NoAdminRequired]` is deliberately NOT used here,
  matching `ConsumersController`'s existing posture.
- Per-tier rate-limit counters use the same hashed, TTL-bound distributed
  cache keys as the existing consumer-level limiter — no new attack surface
  (no new secret, no new plaintext credential field).
- Sunset/Deprecation headers disclose only information the operator already
  configured (a product's own deprecation status) — no information
  disclosure risk.

## File Structure

```
lib/
  Controller/
    ProductSubscriptionsController.php   (new)
  Service/
    EndpointService.php                  (modified — tier resolution, deprecation headers, inbound logging)
    ApprovalService.php                  (modified — suspendForSubscription())
  Observability/
    OpenConnectorMetricsProvider.php     (modified — percentile gauges)
  Settings/
    register.d/
      api-product-gateway.json           (new)
src/
  manifest.json                          (modified — pages, menu, observability.metrics)
  views/
    ApiProducts/
      ApiProductsIndex.vue               (new, if custom list chrome is needed beyond generic index)
      ApiProductDetail.vue               (new — endpoint picker, tier editor, analytics panel, subscriptions)
tests/
  Unit/Service/EndpointServiceTierPolicyTest.php   (new)
  Unit/Observability/OpenConnectorMetricsProviderTest.php (extended)
  postman/ (Newman collection additions — over-tier 429, deprecated headers)
```

## Seed Data

### Schema: `api_product`

| Field | Object 1 | Object 2 | Object 3 |
|-------|----------|----------|----------|
| slug | `api-product-woo-publications-v1` | `api-product-woo-publications-v2` | `api-product-kvk-lookup-v1` |
| name | WOO Publications API | WOO Publications API | KVK Lookup API |
| productSlug | `woo-publications` | `woo-publications` | `kvk-lookup` |
| version | 1.0.0 | 2.0.0 | 1.0.0 |
| status | deprecated | active | active |
| sunsetDate | 2026-10-01T00:00:00+00:00 | — | — |
| visibility | public | public | private |
| defaultTier | free | free | gold |
| tiers | `{free:{rateLimit:{requestsPerWindow:60,windowSeconds:60}},gold:{rateLimit:{requestsPerWindow:600,windowSeconds:60},requiresApproval:true}}` | same shape | `{gold:{rateLimit:{requestsPerWindow:1000,windowSeconds:60},requiresApproval:true}}` |

### Schema: `api_product_subscription`

| Field | Object 1 | Object 2 |
|-------|----------|----------|
| slug | `sub-acme-woo-v2-free` | `sub-acme-kvk-gold-pending` |
| product | (woo-publications v2 uuid) | (kvk-lookup v1 uuid) |
| consumer | (existing seeded Consumer uuid) | (existing seeded Consumer uuid) |
| tier | free | gold |
| status | active | pending_approval |

**Related items per object:** none (Files/Notes/Tasks/Contacts not
applicable to this domain).

## Trade-offs

- Chose flat versioned rows over nested version arrays (Decision 1) —
  trades a small amount of query-time joining (resolve "the deprecated
  version of product X" by `productSlug` + `status`) for consistency with
  every other entity in this register and a much simpler tier-resolution
  lookup.
- Chose scoped (product-attached-only) inbound logging over universal
  inbound logging — trades "analytics only exist for product-fronted
  endpoints" for avoiding an unbounded volume/retention change to every
  endpoint in the app (see discovery.md Risk Uncovered).
