---
kind: code
---

## Why

Hydra gate-52 (`orphaned-write-capability`) re-run against `lib/Service/**` at
HEAD `193806fc` (issue #165) flagged 9 public side-effecting service methods
with zero `->method(` callers anywhere in the repo. Per the triage lesson
(2026-07-14), **class-injected ≠ method-called** — and re-verification at the
current `development` HEAD showed the triage was partly stale: one flagged
method has since been wired. Each of the 9 was individually VERIFY-THEN-classified
(callers grepped fleet-wide + routes + register.d + dynamic dispatch), so no
descoped code is fake-triggered and no genuinely-superseded code is left dead.

## What Changes

- **DELETE (superseded)** the 5 `SearchService` MongoDB/MySQL query builders
  (`createMongoDBSearchFilter`, `createMySQLSearchConditions`,
  `createMySQLSearchParams`, `createSortForMySQL`, `createSortForMongoDB`).
  `SearchService::search()` resolves results via Elasticsearch + directory
  fan-out and never calls them; one (`createSortForMongoDB`) even carries
  `@todo Not functional yet`. The live sibling `unsetSpecialQueryParams()`
  (3 controller callers) and `search()` stay.
- **WIRE** `EndpointCacheService::clearCache()` — add
  `EndpointCacheInvalidationListener` on OpenRegister object create/update/delete
  events, gated on register slug `openconnector` + schema slug `endpoint`.
  REQ-EP-004 already required "clearCache invoked on endpoint create/update/delete"
  but nothing invoked it, so the runtime endpoint router served stale routing for
  up to the 1-hour TTL after an endpoint was updated or deleted.
- **WIRE** `ConfigurationService::exportRegister()` — add
  `ConfigurationController::exportRegister()` + `GET /api/registers/{id}/export`
  (the route the routes.php comment block already documented as intended). This
  is REQ-002 ("export every entity reachable from a register"), previously
  reachable only from PHPUnit.
- **No action** on `ConfigurationService::exportConfiguration()` — NOT dead at
  the current HEAD: it is called by `ConfigurationController::export()`, routed
  as `configuration#export`. The gate ran at an older HEAD that predated that
  wiring (connector-catalog-ui). Recorded as a stale-triage false positive.
- **KEEP + mark-seam** `IBabsConnectorService::createAgendapunt()` — a
  spec-required (REQ-RIS-003) outbound-push entry point whose outbound
  Zaak→PDF→iBabs-upload pipeline is not yet built. Deleting spec-required code or
  fabricating a trigger would both be wrong, so it carries an
  `@orphaned-write-capability exclude` marker and a follow-up issue.

## Impact

- Affected specs: `mapping-and-search` (REQ-005), `configuration-export-import`
  (REQ-002), `endpoint-runtime` (REQ-EP-004).
- Affected code: `lib/Service/SearchService.php`,
  `lib/Controller/ConfigurationController.php`, `appinfo/routes.php`,
  `lib/EventListener/EndpointCacheInvalidationListener.php` (new),
  `lib/AppInfo/Application.php`, `lib/Service/IBabsConnectorService.php`,
  `lib/Service/ConfigurationService.php`.
- New route `GET /api/registers/{id}/export` (auth: `configuration.export`
  action, mirroring the configuration export). No migration, no schema change.
