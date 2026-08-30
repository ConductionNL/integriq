# Design — revive-dead-capabilities (openconnector)

## Verdict table (all 9 gate-52 findings)

Each method was VERIFY-THEN-classified against the current `development` HEAD.
Callers were grepped fleet-wide (`->method(`, dynamic dispatch, register.d,
routes). Verdict is exactly one of: DELETE (superseded), WIRE (dead+intended),
SEAM (kept + documented), NOT-DEAD (already wired — stale triage).

| Class::method | file:line | callers now | verdict | evidence |
|---|---|---|---|---|
| `SearchService::createMongoDBSearchFilter` | lib/Service/SearchService.php | 0 | **DELETE** | `search()` uses Elastic + `directoryService` fan-out; builder never called |
| `SearchService::createMySQLSearchConditions` | lib/Service/SearchService.php | 0 | **DELETE** | dead MySQL fragment builder |
| `SearchService::createMySQLSearchParams` | lib/Service/SearchService.php | 0 | **DELETE** | dead MySQL param builder |
| `SearchService::createSortForMySQL` | lib/Service/SearchService.php | 0 | **DELETE** | dead MySQL sort builder |
| `SearchService::createSortForMongoDB` | lib/Service/SearchService.php | 0 | **DELETE** | dead sort builder, `@todo Not functional yet` in its own docblock |
| `EndpointCacheService::clearCache` | lib/Service/EndpointCacheService.php:316 | 0 → **wired** | **WIRE** | REQ-EP-004 requires "invoked on endpoint create/update/delete" but nothing did; new `EndpointCacheInvalidationListener` fires it on OR endpoint-object events |
| `ConfigurationService::exportRegister` | lib/Service/ConfigurationService.php:825 | 0 → **wired** | **WIRE** | REQ-002; routes.php comment already documented `GET /api/registers/{id}/export`; added controller method + route |
| `ConfigurationService::exportConfiguration` | lib/Service/ConfigurationService.php | 1 (`ConfigurationController::export`, routed `configuration#export`) | **NOT DEAD** | already wired at current HEAD; gate ran at older HEAD `193806fc` — stale-triage false positive, no code change |
| `IBabsConnectorService::createAgendapunt` | lib/Service/IBabsConnectorService.php:311 | 0 | **SEAM (kept)** | spec-required (REQ-RIS-003) outbound-push entry point; the outbound Zaak→PDF→iBabs-upload pipeline that triggers it is unbuilt. `@orphaned-write-capability exclude` + follow-up issue — not deleted (spec-required), not fake-wired |

### Why DELETE the 5 search builders (not WIRE)

`SearchService::search()` is the only search entry point and it resolves hits
through `elasticService->searchObject()` plus a Guzzle fan-out to peer directory
endpoints (`directoryService->listDirectory()`). It never constructs a
MongoDB/MySQL query, so the five `create*` builders are leftovers from an
inline-DB search that was superseded. They had zero callers repo-wide and no
tests. Wiring them would mean re-introducing a query path the app deliberately
replaced. The `mapping-and-search` REQ-005 spec still *described* them — that
description is the "spec-says-done ≠ feature runs" trap — so the delta removes
them from REQ-005 while keeping the live methods (`parseQueryString`,
`unsetSpecialQueryParams`, `search`, facet merging).

### Why WIRE clearCache via an event listener

Endpoint definitions are OpenRegister objects (register `openconnector`, schema
`endpoint`); `EndpointsController` is only the runtime dispatcher and does no
CRUD. Endpoint writes therefore arrive as OpenRegister `ObjectCreated/Updated/
DeletedEvent`s. A dedicated `EndpointCacheInvalidationListener` (registered for
all three events in `Application.php`) resolves the object's register+schema
slug via `RegisterMapper`/`SchemaMapper` — the same gating pattern as the
existing `ViewDeletedEventListener` — and calls `clearCache()` only for endpoint
objects. Unrelated object writes are a cheap two-lookup no-op; unresolvable
register/schema fails safe (no crash, no clear).

## Seed Data

None. No schema, register config, seed rows, or notification templates are
added or modified. The new route reuses the existing `configuration.export`
authorization action.

## ADR-031 (notification dialect)

Not applicable. No object-notification dispatch and no
`x-openregister-notifications` / `lib/Settings/*register*.json` changes. The
canonical dialect is untouched.

## Tests (real numbers, php:8.3-cli, fresh composer install)

- Baseline (clean `origin/development` @ 0944bf69): **1203 tests OK** (ship-time
  re-measure; the 1115 draft baseline predated the FSC-connectivity merge).
- After change: **1211 tests OK** (+8: 6 `EndpointCacheInvalidationListenerTest`
  + 2 `ConfigurationControllerTest`). Zero failures.
- WIRED `clearCache`: `EndpointCacheInvalidationListenerTest` proves an endpoint
  create/update/delete event fires `clearCache()`, and non-endpoint / unrelated
  / unresolvable events do not.
- WIRED `exportRegister`: `ConfigurationControllerTest::
  testExportRegisterReturnsAttachmentWithServiceBundle` proves the routed
  trigger calls `exportRegister()` and returns the downloadable bundle; plus
  403 (unmapped non-admin) and 401 (unauthenticated) guards.
- DELETED builders: re-confirmed zero callers repo-wide and no superseding path
  (Elastic + directory fan-out); no test referenced them; suite green.
