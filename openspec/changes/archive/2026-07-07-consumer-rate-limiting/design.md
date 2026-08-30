# Design — inbound per-consumer rate limiting and quotas

## Context

OpenConnector already resolves a `consumer` per inbound endpoint request and
enforces its `authorizationType` (`AuthorizationService::findIssuer()` +
`consumer-management` `REQ-CON-001`). The gap is purely the throttling stage that
should follow a successful auth. This design adds that stage without touching
auth semantics and without duplicating the outbound rate-limit machinery.

## Decisions

### D1 — Two independent limiters: short-window rate limit + long-horizon quota
API gateways universally offer both a burst limiter (req/second-or-minute) and a
fair-use quota (req/day-or-month). Modelling them separately keeps each simple:

- `rateLimit`: `{requestsPerWindow:int, windowSeconds:int}` — sliding/fixed
  window, small TTL, cache-backed.
- `quota`: `{limit:int, period:"hour"|"day"|"month"}` — accounted against a
  period bucket that rolls over on a calendar boundary.

Both optional; both absent → unlimited. This is the backward-compatible default
for every existing consumer (none have these fields today).

### D2 — Enforce right after consumer resolution, before dispatch
The counter increment + limit check runs at the same choke point as
`REQ-CON-001` auth, immediately after the consumer is resolved and auth passes,
and before the endpoint's rules/target run. Ordering matters: **auth first**
(so an unauthenticated caller gets 401, not a rate-limit 429 that would leak
which endpoints exist), **then rate limit**. `authorizationType: none`
consumers can still be rate limited (a public endpoint can be throttled) — the
limiter keys on the resolved consumer identity, or on client IP when the
consumer is anonymous.

### D3 — Distributed atomic counter via ICacheFactory, DB fallback for quota
Correctness under concurrency and across PHP-FPM workers requires a shared
atomic counter, not a per-process variable. Use `ICacheFactory::createDistributed()`
with an atomic `inc()` on a key `oc_rl_{consumerId}_{windowStart}` and TTL =
`windowSeconds`; the key self-expires so there is no cleanup job and no unbounded
growth. Quota (day/month) spans longer than is safe for cache TTL alone, so the
authoritative quota tally is a period-bucketed counter persisted per consumer
(cache used as a read-through accelerator). When no distributed cache is
configured (single-node dev), the local cache backend is used — documented as
best-effort in that topology.

### D4 — IETF RateLimit headers + Retry-After, not bespoke headers
Emit the standard `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset`
(seconds-to-reset) on every rate-limited-consumer response, and `Retry-After` on
the 429. This matches what Kong/APISIX/Tyk emit, so existing gateway clients
self-pace without special-casing OpenConnector. Quota exhaustion also returns
429 (a distinct `RateLimit-Reset` pointing at the period rollover), not 403 —
429 is the semantically correct "try later" for both.

### D5 — Reuse the existing 429 metric with a direction label
`prometheus-metrics` already counts `status=429`. Rather than a new metric, add
a `direction` label (`inbound`|`outbound`) so a single counter distinguishes
consumer throttling from upstream backoff. This keeps the metrics surface small
and is a one-label extension described in this change's `consumer-management`
delta (not a restatement of the metrics spec).

## Alternatives considered

- **Nextcloud's built-in bruteforce/rate-limit middleware** — designed for
  login/CSRF abuse keyed on IP + action, not per-API-consumer quotas with
  client-visible headers. Wrong granularity; rejected.
- **A per-endpoint rate limit** — the demand and the competitor model are
  per-*consumer* (the API key holder), so a plan can differ per consumer on the
  same endpoint. Per-consumer is the right key; a per-endpoint cap can be a
  later additive limiter.

## Non-goals

- Not tiered "plans"/billing (a consumer references one rate limit + one quota,
  not a named plan object) — that is a possible later change.
- Not changing outbound source rate limiting or authentication semantics.
- Not a global instance-wide limiter (that is a reverse-proxy concern).
