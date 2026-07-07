# Tasks — inbound per-consumer rate limiting and quotas

> Order: schema fields first, then the counter service, then enforcement +
> headers at the consumer-resolution choke point, then observability and UI.

- [ ] Extend the `consumer` schema in `lib/Settings/openconnector_register.json`: add optional `rateLimit` object `{requestsPerWindow:int, windowSeconds:int}` and optional `quota` object `{limit:int, period:"hour"|"day"|"month"}`; document that absent = unlimited; keep every existing field unchanged (backward compatible)
- [ ] Create an inbound rate-limit service: `ICacheFactory::createDistributed()` atomic `inc()` on key `oc_rl_{consumerId}_{windowStart}` with TTL = `windowSeconds` (short-window limiter); period-bucketed quota tally persisted per consumer with cache read-through (day/month limiter); return a typed decision `{allowed:bool, limit, remaining, resetSeconds, retryAfter}`
- [ ] Wire enforcement into the consumer-resolution path (`AuthorizationService` / endpoint auth stage), AFTER authentication passes and BEFORE the endpoint rules/target run; anonymous (`authorizationType: none`) requests key on client IP
- [ ] Emit IETF headers on rate-limited-consumer responses: `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset`; on a 429 add `Retry-After`; quota exhaustion returns 429 with `RateLimit-Reset` at the period rollover (not 403)
- [ ] Record inbound 429 rejections on the CallLog/metrics surface; extend the existing `prometheus-metrics` 429 counter with a `direction` label (`inbound`|`outbound`)
- [ ] Extend the Consumer editor Vue view (existing *Consumers* section) with rate-limit (requests/window) and quota (limit/period) inputs next to the auth configuration
- [ ] Unit tests: window increment + expiry, over-limit → 429 + `Retry-After`, under-limit → correct `RateLimit-*` headers, quota rollover, `authorizationType: none` IP keying, unlimited when unconfigured, atomicity of the counter (concurrent increments)
- [ ] Newman case: a consumer with `rateLimit {2, 60}` gets 200,200,429 across three rapid calls, with `Retry-After` present on the 429
- [ ] Run `composer check:strict` and fix anything it flags in touched files

Acceptance criteria (plain bullets — verified by /opsx-verify):

- A consumer with no `rateLimit`/`quota` behaves exactly as today (unlimited); no existing consumer is affected
- A consumer over its short-window `rateLimit` receives HTTP 429 with a `Retry-After` header; under the limit it receives `RateLimit-Limit/Remaining/Reset` headers
- A consumer over its `quota` receives HTTP 429 until the period (hour/day/month) rolls over
- Rate limiting runs only after authentication passes (an unauthenticated caller still gets 401, never a 429)
- Counters are correct under concurrent requests across workers (distributed atomic increment), and window keys self-expire with no cleanup job
- Inbound 429s are visible on the metrics surface distinguished from outbound source backoff by a `direction` label
