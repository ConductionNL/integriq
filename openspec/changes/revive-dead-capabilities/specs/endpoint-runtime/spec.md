# endpoint-runtime Specification (delta)

---
status: proposed
---

## Purpose

Make the code match REQ-EP-004: `clearCache` was required to be "invoked on
endpoint create/update/delete" but nothing invoked it, so the runtime router
served stale endpoint routing for up to the 1-hour TTL after an endpoint was
updated or deleted (issue #165, gate-52 orphaned-write-capability). A new
listener now fires it on OpenRegister endpoint-object writes.

## MODIFIED Requirements

### Requirement: Endpoint Resolution Cache (REQ-EP-004)

The system MUST cache the registered endpoint set to avoid an OpenRegister
query on every request. Resolution (`findByPathRegex`) MUST filter cached
endpoints by compiled `endpointRegex` and method; on a cache miss it MUST
refresh once and retry (single retry, no infinite recursion). The cache MUST be
layered: a request-lifetime in-memory copy plus a distributed cache with a
1-hour TTL, with a fall-back to a direct OpenRegister query when the cache
errors. The system MUST provide explicit `refreshCache`, `clearCache` (invoked
on endpoint create/update/delete), regex compilation (`createEndpointRegex`),
and a `getCacheStats` diagnostics method.

The `clearCache` invocation MUST be wired: an `EndpointCacheInvalidationListener`
registered for OpenRegister `ObjectCreatedEvent`, `ObjectUpdatedEvent`, and
`ObjectDeletedEvent` MUST call `clearCache()` whenever the affected object is an
endpoint (register slug `openconnector`, schema slug `endpoint`), and MUST NOT
clear the cache for any other object, so a stale endpoint set is never served
after an endpoint is created, updated, or deleted.

@e2e exclude backend cache internals — covered by Newman/PHPUnit, not browser UI

#### Scenario: populated cache resolves without an OR query

- **GIVEN** a populated cache
- **WHEN** `findByPathRegex` is called
- **THEN** the matching endpoint is resolved from cache without an OpenRegister
  query.

#### Scenario: cache miss refreshes once and retries once

- **GIVEN** an empty cache and a path that should match
- **WHEN** `findByPathRegex` finds no match on the first pass
- **THEN** it refreshes the cache once and retries exactly once before returning
  null.

#### Scenario: multiple cached matches throw ambiguous-routing

- **GIVEN** a path that matches more than one cached endpoint
- **WHEN** `findByPathRegex` runs
- **THEN** it throws an ambiguous-routing exception listing the matching
  endpoint names.

#### Scenario: distributed cache error falls back to direct query

- **GIVEN** the distributed cache raises an error
- **WHEN** `getAllEndpoints` runs
- **THEN** it logs a warning and falls back to a direct OpenRegister query via
  `fetchEndpointsFromOr`.

#### Scenario: clearCache drops both cache layers

- **GIVEN** endpoints are modified
- **WHEN** `clearCache` is called
- **THEN** the in-memory and distributed caches are dropped so the next
  resolution reloads fresh data.

#### Scenario: endpoint object write invalidates the cache

- **GIVEN** an OpenRegister object create/update/delete event for an object in
  register `openconnector`, schema `endpoint`
- **WHEN** `EndpointCacheInvalidationListener` handles the event
- **THEN** it calls `EndpointCacheService::clearCache()` so the next resolution
  reloads fresh endpoints
- **AND** for any non-endpoint object (or an unresolvable register/schema) it
  does nothing.

**Notes:**
- `createEndpointRegex` duplicates `EndpointMapper::createEndpointRegex` by
  design (the docblock notes this is "to maintain cache service independence").
  Documented as observed duplication.
