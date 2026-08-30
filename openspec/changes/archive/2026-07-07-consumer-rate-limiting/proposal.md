---
kind: code
depends_on: []
---

# openconnector — inbound per-consumer rate limiting and quotas on exposed endpoints

## Why

OpenConnector's README opens with *"API gateway and integration hub for
Nextcloud"* and the app exposes its own API endpoints (the *Endpoints* +
*Consumers* model). Inbound rate limiting and quota enforcement per API
consumer is the single most defining table-stake of every product in
OpenConnector's direct-competitor set — **Kong Gateway** (43k★), **Apache
APISIX** (16k★), **Tyk** (10k★), **Gravitee**, **WSO2 API Manager**, **Apigee**,
and **Traefik** are all API gateways whose headline feature is
rate-limit/quota/throttle plugins. In the Dutch market intelligence the demand
this maps to is large: the requirement clusters *"API availability"* (240
tenders) and *"Interface/API"* (213 tenders) are near the top of OpenConnector's
assignment, and both routinely specify throttling / fair-use / availability
guarantees.

OpenConnector cannot do this today. Verified at HEAD:

- The `consumer` schema (`lib/Settings/openconnector_register.json`) has fields
  `uuid, name, description, domains, ips, authorizationType,
  authorizationConfiguration, created, updated, userId` — **no rate-limit or
  quota fields at all.**
- `consumer-management` spec `REQ-CON-001` enforces consumer *authentication*
  (401/403) and nothing else; there is no throttling requirement.
- Every `rateLimit*` field and every piece of rate-limit logic in the codebase
  (`CallService::checkAndResetRateLimit()`, `clampRateLimitHeader()`,
  `sourceRateLimit()`; the `source` schema's `rateLimitLimit/Remaining/Reset/
  Window`) is **outbound** — it throttles OpenConnector's own calls *to*
  upstream sources so it does not hammer an API that returned 429. There is no
  inbound counterpart.

So a consumer can be authenticated but then call an OpenConnector endpoint an
unbounded number of times. For an app that positions itself as an API gateway
in front of registers and government systems, an authenticated consumer with no
rate limit is a denial-of-service and a fair-use gap, and it is a straight
"missing table-stake" versus the competitor set.

## What Changes

- **Consumer rate-limit + quota configuration**: the `consumer` schema gains a
  `rateLimit` object — `{requestsPerWindow, windowSeconds}` (short-window
  throttle, e.g. 100 req / 60 s) and `quota` — `{limit, period}` where `period`
  is one of `hour|day|month` (longer-horizon fair-use cap). Both are optional;
  absent = unlimited (backward compatible with every existing consumer).
- **Inbound enforcement at the consumer-resolution point**: after a consumer is
  resolved and passes authentication (`AuthorizationService`, the same point
  that enforces `REQ-CON-001`), the request is counted against that consumer's
  rate-limit window and quota. Over the short-window limit → **HTTP 429** with a
  `Retry-After` header; over the quota → **HTTP 429** until the quota period
  rolls over. A configured-but-not-exceeded consumer receives standard
  `RateLimit-Limit` / `RateLimit-Remaining` / `RateLimit-Reset` response headers
  (IETF `draft-ietf-httpapi-ratelimit-headers`) so clients can self-pace.
- **Counter store, atomic and shared**: counters are keyed by consumer +
  window and stored so they are correct under concurrency and across web
  workers (Nextcloud `ICacheFactory` distributed cache with an atomic
  increment; the DB row is the fallback for quota accounting where cache TTL is
  insufficient). No unbounded growth: window keys expire on TTL.
- **Observability**: a 429 rate-limit rejection is recorded on the inbound
  CallLog / metrics surface so operators can see throttling. This reuses the
  existing `429` metric label the `prometheus-metrics` spec already emits for
  outbound rate limiting — the label is extended to distinguish
  `direction=inbound`.
- **Management UI**: the Consumer editor (existing *Consumers* view) gains
  rate-limit and quota inputs alongside the auth configuration it already
  renders.

## Impact

- Affected specs: `consumer-management` (owning capability — ADDED requirements
  for the config fields, enforcement, response headers, and UI). Touches the
  edge of `prometheus-metrics` (a new `direction` label value on the existing
  429 counter) — described here, not restated there.
- Affected code (implementation phase, not this change): the `consumer` schema
  in `lib/Settings/openconnector_register.json`; a new inbound rate-limit
  service (window counter via `ICacheFactory` + quota accounting) invoked from
  `AuthorizationService`/the endpoint auth path right after consumer resolution;
  `RateLimit-*` / `Retry-After` header emission on the endpoint response;
  the Consumer editor Vue view; unit tests + a Newman case for 429.
- Not affected: outbound source rate limiting (`CallService`), the CallLog
  envelope shape, existing consumers (no rate limit unless configured), and
  authentication semantics (`REQ-CON-001` is unchanged — enforcement runs
  *after* auth passes).
