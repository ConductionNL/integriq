# Design: gateway-response-cache-and-problem-errors

Kind: code. Size M. `EndpointService`, a new `EndpointResponseCache`, a new `ProblemResponse`, the endpoint schema and editor.

## Context at development 92f282bc

- `EndpointCacheService` (`lib/Service/EndpointCacheService.php:81`) receives `ICacheFactory` and caches the endpoint list for `findByPathRegex()`.
- `EndpointService::transformError()` (`lib/Service/EndpointService.php:280-301`) and its three callers (`:647`, `:711`, `:715`); 33 `new JSONResponse` sites in `EndpointService`, most error returns shaped `['error' => ..., 'details' => ...]` (for example the authentication failures in `processAuthenticationRule()`, `:3006-3060`).
- The call log (`call_log`) records every proxied call.

## D1. Cache in Nextcloud's distributed cache

`EndpointResponseCache` uses `ICacheFactory::createDistributed('integriq.endpoint.response')`, so a cluster shares hits and an instance without Redis falls back to Nextcloud's local cache. The key is a hash of endpoint uuid, method, path, sorted query, the endpoint's configured vary headers, and the resolved consumer uuid unless the endpoint marks its answers as the same for every consumer. Scoped per consumer by default, because a response may be filtered by the caller's rights.

Rejected: a cache table in the database. It adds a migration for data that is by nature disposable.

## D2. What is cached

Endpoint fields: `cache.enabled`, `cache.ttlSeconds`, `cache.methods` (default GET and HEAD), `cache.statusCodes` (default 200), `cache.varyHeaders`, `cache.sharedAcrossConsumers` (default false). An upstream `Cache-Control: private` or `no-store` is never cached; `max-age` shortens the lifetime when it is lower. The authentication and rate limit checks run before the cache lookup, so a cached answer never reaches a caller that could not have made the call, and a hit still counts against the caller's quota.

A hit returns the stored status, body and content type with an `X-Cache: HIT` header, and writes a call log entry with `cacheHit: true` and no upstream request.

## D3. Clearing

`POST /api/endpoints/{id}/cache/clear` (admin, `ActionAuthService` action `endpoint.cache.clear`) removes the endpoint's entries by bumping a per-endpoint generation number that is part of the key. No scan of the cache is needed.

## D4. One problem document

`lib/Http/ProblemResponse.php` extends `JSONResponse`, sets `Content-Type: application/problem+json` and builds RFC 9457 members: `type` a URI under `https://integriq.nl/problems/<slug>` (for example `authentication-failed`, `rate-limited`, `validation-failed`, `upstream-unavailable`), `title` a short sentence per type, `status`, `detail` the specific message, `instance` the request id. Validation errors carry an `invalid-params` array of `{ name, reason }`, the extension the Dutch API design rules use. The old `error` and `details` members stay as extensions for one release, documented as deprecated.

Every error return in `EndpointService` goes through `ProblemResponse`. `transformError()` becomes a thin wrapper over it.

## Declarative versus imperative

No lifecycle, aggregation or notification behaviour. Request-time caching and error shaping stay in code.

## Seed data

The seeded example endpoints get `cache.enabled: false`, and one read-only example endpoint gets a 60 second cache.

## Risks

- A cached answer outlives a change at the source. Mitigation: short default lifetime (60 seconds), upstream `max-age` respected, and the clear action.
- A client parses the old error shape. Mitigation: `error` and `details` stay for one release, and the change log names the switch.
