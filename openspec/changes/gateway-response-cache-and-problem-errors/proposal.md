---
kind: code
depends_on: []
---

# Proposal: gateway-response-cache-and-problem-errors

## Summary

Every call to an integriq endpoint reaches the source, even when a hundred callers ask the same question a minute apart. And when a call fails, the error comes back in one of several ad hoc shapes that a client cannot parse reliably. This change adds a response cache per endpoint, and makes every error from the gateway an RFC 9457 problem document with the `application/problem+json` content type, as the Dutch API design rules ask.

## Why

Two rows of integriq's capability matrix, gateway area (the core area), decided `build` in the OpenSpec pass of 2026-09-27.

| row | rating | decision |
|---|---|---|
| `integriq:gw-cache` | no | build: core area, five competitors yes |
| `integriq:gw-problem-json` | no | build: core area (no competitor rates yes; five rate partial) |

Competitor cells, quoted from the matrix:

- `gw-cache`: MuleSoft https://docs.mulesoft.com/gateway/latest/policies-included-http-caching.md "HTTP Caching policy caches HTTP responses from an API implementation". Tyk v5.15.0 `apidef/oas/middleware.go:742` global cache with timeout, safe-request caching, cached response codes and cache-by-headers. APISIX 3.18.0 `apisix/plugins/proxy-cache/init.lua:67` cache strategy, key, status and method. WSO2 v4.7.0 publisher "Response Caching". Frank!Framework v10.2.0 `PipeLine.java:666` `setCache`.
- `gw-problem-json`: every competitor is rated partial; none returns problem documents by default. The matrix evidence for integriq: `grep "problem\+json|application/problem"` over `lib/` and `src/` returns nothing, and errors use ad hoc shapes.

The matrix note on `gw-cache`: "EndpointCacheService is a routing-lookup cache, easy to mistake for a response cache from its name alone, it never caches an endpoint's answer."

## What integriq already has

- `EndpointCacheService` (`lib/Service/EndpointCacheService.php`) caches the endpoint configuration list for path matching through Nextcloud's `ICacheFactory`, not responses.
- `EndpointService::transformError()` (`lib/Service/EndpointService.php:280`) builds a body with `type`, `title`, `status`, `instance` and `detail`, but puts the message in `type`, sends `application/json`, and runs on three of the error paths (`:647`, `:711`, `:715`). The other error paths in `EndpointService` return `new JSONResponse(['error' => ..., 'details' => ...])` directly.

## What this change builds

1. A response cache per endpoint: on or off, a lifetime, the methods and status codes cached, and the headers and consumer that make up the key. Hits skip the source and the call log records a hit.
2. `Cache-Control` from the upstream is honoured unless the endpoint overrides it, and `private` or `no-store` answers are never cached.
3. An administrator clears one endpoint's cache from its page.
4. One problem document class used by every gateway error: `type` a URI, `title`, `status`, `detail`, `instance`, and the old `error` and `details` members kept as extensions for one release so existing clients do not break.

## Out of scope

- Caching for register endpoints that write. Only safe methods are cached.
- Problem documents from integriq's own admin API. This change covers the gateway (endpoint) responses.
