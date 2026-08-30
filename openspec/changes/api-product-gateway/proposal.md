# Proposal: api-product-gateway

## Summary

Integriq already lets an administrator define individual inbound
`Endpoint`s and gate them behind a `Consumer`'s authentication, per-consumer
rate limit, and quota (`consumer-management`, `endpoint-runtime`). It has no
concept of an **API Product** — a named, versioned bundle of endpoints that a
Consumer can discover and subscribe to at a rate-limit tier, with an optional
approval gate. This change adds API Products, tiered subscriptions, per-tier
rate-limit enforcement, RFC 8594 deprecation headers on sunset product
versions, and gateway analytics (request count, error rate, and p50/p95/p99
latency per product) — the multi-tenancy and analytics table stakes every
competing API gateway ships.

## Motivation

Specter user-story synthesis for the API-product cluster surfaces five
recurring asks: "create an API product definition in the gateway", "rate
limit per consumer tier", "view current rate-limit usage per consumer",
"gateway latency percentiles per API product", and "deprecate an old API
version". `consumer-rate-limiting` (archived) already delivered per-consumer
limits; `consumer-apikey-enforcement` (archived 2026-07-14) just made
Consumer-backed apiKey auth real. What is still missing is the *product*
layer on top: grouping endpoints into a sellable/discoverable unit, giving
each subscribing consumer a named tier instead of one flat per-consumer
limit, and surfacing usage/latency so an operator can actually run the
gateway as a product.

## Affected Projects

- [x] Project: `integriq` — new `api_product` and
  `api_product_subscription` OR schemas, extended `call_log` schema, extended
  `InboundRateLimitService` call site (not the service itself),
  `EndpointService` dispatch (deprecation headers + product-scoped inbound
  logging), `ApprovalService` (one new subscription-approval creation
  method), `IntegriqMetricsProvider` (latency percentile gauges), SPA
  manifest (`API Products` page).

## Scope

### In Scope

1. `api_product` OR schema: a named, versioned bundle of `Endpoint`s (by
   uuid) with a `visibility` (public/private), a set of named `tiers` (each
   carrying its own `rateLimit`/`quota`, mirroring the existing Consumer
   shape, plus a `requiresApproval` flag), a `defaultTier`, and a
   `status`/`sunsetDate` pair for version deprecation.
2. `api_product_subscription`: a Consumer's subscription to a Product at a
   named tier, gated by an approval workflow when the tier requires it
   (reusing `hitl-approval-rule-action`'s `ApprovalService` state machine —
   see design.md Decision 4), auto-activated otherwise.
3. Per-tier rate-limit/quota enforcement at the endpoint runtime: extends
   `InboundRateLimitService::enforce()`'s call site in `EndpointService`
   (the service itself is unchanged) to resolve a subscription's tier policy
   ahead of the existing consumer-level policy, keyed on
   `(consumer, product, tier)` so product-tier counters are independent of a
   consumer's plain per-endpoint counters. A request past its tier's
   `rateLimit`/`quota` receives HTTP 429 exactly like today's consumer-level
   429 (`REQ-CON-RL-002`/`003`).
4. Gateway analytics: per-product request count and error rate (declarative
   Prometheus gauge, extending the existing `calls_total` groupBy) and
   p50/p95/p99 latency (AppHost provider escape hatch, computed from
   inbound `call_log.responseTime` — see design.md Decision 3), surfaced on
   a new **API Products** SPA page and via `/api/metrics`.
5. API version deprecation: marking an `api_product` `status: deprecated`
   with a `sunsetDate` makes every response served through that product's
   endpoints carry `Sunset` (RFC 8594) and `Deprecation` headers.
6. Tests: PHPUnit for tier-policy resolution, percentile calculation, and
   Sunset/Deprecation header emission; Newman for consumer-over-tier → 429
   and deprecated-product → header scenarios.

### Out of Scope

- A full self-service developer portal or API-key issuance UI for
  prospective consumers — follow-up, filed as an issue at apply time.
- Monetization/billing on top of tiers.
- Multi-version endpoint routing (an endpoint moving between product
  versions) — a product version references the endpoints that exist today;
  endpoint versioning itself is unchanged.

## Approach

New OR schemas (`api_product`, `api_product_subscription`) shipped as a
per-change `register.d` fragment (ADR-037), deep-merging two new fields
(`product`, `endpoint`) plus a `responseTime` field onto the existing
`call_log` schema. Tier-policy resolution is a new private method in
`EndpointService` that runs *before* today's `enforceInboundRateLimit()` and
substitutes its `rateLimit`/`quota` inputs and cache key — `enforce()` on
`InboundRateLimitService` is not touched. Subscription approval reuses
`ApprovalService`'s generic `approval_request` state machine via one new
creation method mirroring the existing `suspendForSynchronization()`
(no FlowToken, no rule-pipeline coupling). Deprecation headers reuse the
existing `handleRequest()` header-merge choke point that already attaches
`RateLimit-*` headers. Analytics split across the two existing observability
mechanisms: declarative `groupBy` for counts, the `IMetricsProvider`
escape hatch for percentiles (the same split the codebase already uses for
`calls_total` vs `circuit_breaker_state`).

## New Dependencies

None. No new packages, libraries, or external services.

## Impact

- **Schema**: `integriq_register.json` gains `product`/`endpoint`/
  `responseTime` on `call_log` (register.d fragment). New `api_product`,
  `api_product_subscription` schemas.
- **Backend**: `lib/Service/EndpointService.php` (tier-policy resolution,
  deprecation headers, product-scoped inbound logging),
  `lib/Service/ApprovalService.php` (one new method),
  `lib/Observability/IntegriqMetricsProvider.php` (percentile gauges),
  `src/manifest.json` (declarative `calls_total` groupBy extension + new
  metric descriptor).
- **Frontend**: new `ApiProducts` (index) and `ApiProductDetail` (custom)
  manifest pages, new `ConnectionsGroup` menu entry.
- **No changes** to `InboundRateLimitService`, the `consumer` schema, or any
  existing endpoint's dispatch behaviour when it is not part of a product.

## Cross-Project Dependencies

None. This is entirely within Integriq; the API Products surface is
consumed by external API clients, not by other apps-extra projects.

## Risks

### Risk 1: Inbound call_log volume growth from product-scoped logging

**Severity:** Medium — **Mitigation:** Logging is scoped to endpoints that
belong to an `api_product` only (today's non-product endpoints keep their
current behaviour — only 429s are logged, per `REQ-CON-RL-004`). Retention
follows the existing `expires` convention (ADR-004); no new retention floor
is introduced.

### Risk 2: Percentile computation cost at scrape time

**Severity:** Medium — **Mitigation:** Bounded per-product row window (last
N inbound rows, consistent with `REQ-PROM-007`'s top-100 cardinality cap and
`REQ-PROM-001`'s 500ms scrape budget); a query failure falls back to a
zero-value sample, matching every other `REQ-PROM-*` degraded-not-broken
pattern.

### Risk 3: Two overlapping rate-limit keys per consumer

**Severity:** Low — **Mitigation:** Product-tier keys
(`product:{uuid}:consumer:{key}`) are namespaced separately from plain
consumer keys (`consumer:{key}` / `ip:{addr}`) in the same distributed
cache, so they cannot collide or double-count against each other.

## Rollback Strategy

Revert the `register.d` fragment (drops the two new schemas and the three
new `call_log` fields — additive fields, no data loss for existing rows),
revert the `EndpointService`/`ApprovalService`/`IntegriqMetricsProvider`
changes, and remove the manifest page/menu entries. No endpoint that is not
attached to an `api_product` observes any behaviour change, so rollback is
safe on a live instance with active traffic.

## Open Questions

- Should a `api_product_subscription` in `pending_approval` block the
  consumer from calling the product's endpoints entirely, or fall back to
  the product's `defaultTier`? Resolved in design.md Decision 2 — it
  blocks (403), consistent with "subscribe" implying opt-in access, not
  ambient access.
