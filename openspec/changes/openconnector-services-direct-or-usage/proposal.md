---
kind: code
depends_on:
  - openconnector-register-schema-declaration
---

# Proposal: openconnector-services-direct-or-usage (OR cutover)

> **Scope pivot 2026-05-20:** this change absorbs the previous
> `openconnector-register-storage` (chain B) scope. The strangler-fig
> `ObjectMapperFacade` is gone — OR + nc-vue already deliver per-schema CRUD
> generically (storage via `ObjectService`, HTTP via OR's
> `/api/objects/{register}/{schema}/*` routes, UI via nc-vue's
> `CnIndexPage`/`CnDetailPage`/`CnLogsPage`). The per-schema mapper / entity /
> controller / Pinia-store layers are redundant against that surface and are
> deleted outright in this change.

## Why

OR delivers schema-driven CRUD generically:

| Layer | Per-schema today (delete) | Generic OR/nc-vue surface (keep) |
|---|---|---|
| Storage | 15 `lib/Db/*Mapper.php` | `\OCA\OpenRegister\Service\ObjectService::find/findAll/saveObject/deleteObject` |
| Domain types | 15 `lib/Db/*.php` entities | `\OCA\OpenRegister\Db\ObjectEntity` (one type for all schemas) |
| HTTP CRUD | per-schema controllers | OR's `/api/objects/{register}/{schema}/*` routes |
| Frontend list | 15 `src/views/*Index.vue` | `CnIndexPage` (chain D2) |
| Frontend state | 16 Pinia stores in `src/store/modules/` | nc-vue's built-in store (chain D2) |

Openconnector keeps **only the connector-specific behavior** that has no
generic OR equivalent — outbound HTTP execution, mapping engine, rule
pipeline, inbound endpoint dispatch, sync pass execution, event fan-out,
cron jobs, configuration export.

## What Changes

### 1. Data migration (absorbed from old chain B)

- `lib/Migration/Version2Date20260520000001.php` (already on `feature/i18n-complete-translations`) is the upgrade-time entrypoint: calls `ConfigurationService::importFromApp()` to materialise the openconnector register, then runs `LegacyToRegisterMigrator::migrateAll()` to copy every row out of `oc_openconnector_*` tables into `oc_openregister_objects`.
- `lib/Service/Migration/LegacyToRegisterMigrator.php` (already on branch) handles the actual row copy + FK rewrite + `Synchronization.sourceId/targetId` 3-format branching + plaintext-credentials assertion + summary audit-trail entry.
- After upgrade the `openconnector.storage_migrated` IAppConfig flag flips to `true`. After that, the legacy tables are read-only and slated for deletion in follow-up issue [#820](https://github.com/ConductionNL/openconnector/issues/820).
- An admin OCC command (`occ openconnector:migrate-storage [--dry-run] [--entity <slug>] [--batch-size N]`) wraps the migrator for retry + per-entity inspection.

### 2. Delete the per-schema CRUD layer

- Delete all 15 `lib/Db/*Mapper.php` files.
- Delete all 15 `lib/Db/*.php` domain-data entity classes.
- Delete every per-schema CRUD controller under `lib/Controller/` (e.g. `SourcesController`, `EndpointsController`, `ConsumersController`, `JobsController`, `MappingsController`, `RulesController`, `SynchronizationsController`, `EventsController`, etc.) that exposes only standard CRUD over an entity.
- Delete the corresponding routes from `appinfo/routes.php`.
- Delete the corresponding per-schema Pinia stores under `src/store/modules/` (chain D2 scope).
- Delete the corresponding hand-rolled `src/views/*Index.vue` + `src/views/*Detail.vue` files (chain D2 scope).
- Delete `lib/Service/Storage/` if it ever existed (the prior facade plan was reverted — no facade ships).

### 2a. Delete reinvented-wheel infrastructure that OR + nc-vue already provide

Investigation of 2026-05-20 showed that several openconnector controllers + services reimplement functionality that OR's HTTP surface and nc-vue's widget system already deliver. These ARE also deleted in this change:

- **`lib/Controller/ImportController.php` + `lib/Service/ImportService.php` (433 LOC total)** — replaced by OR's `POST /api/registers/{id}/import`, `POST /api/configurations/{id}/import`, and `POST /api/objects/{register}/{schema}/` endpoints. Openconnector's YAML support, if still needed, is preserved by a small format-shim — NOT by reimplementing the entire import service.
- **`lib/Controller/ExportController.php` + `lib/Service/ExportService.php` (217 LOC total)** — replaced by OR's `GET /api/registers/{id}/export`, `GET /api/objects/{register}/{schema}/export`, and `GET /api/objects/{register}/{schema}/{id}` endpoints. Slug-translation logic (per local ADR-015) survives as a thin decorator on OR's ConfigurationService (see § 2b below).
- **`lib/Controller/DashboardController.php` (187 LOC)** — replaced by declarative dashboard widgets in `src/manifest.json` of type `stats-block` / `chart` / `info` with `dataSource: { register, schema, filter, aggregate }` blocks. `CnStatsBlockWidget` (and friends from nc-vue) resolve the dataSource against OR's generic aggregate endpoint. Decidesk's manifest (which references the same nc-vue widget set) demonstrates the pattern.
- **`lib/Controller/SettingsController.php` shrinks** (~200 LOC → ~80 LOC) — drop `stats()` (replaced by manifest widgets), drop `getSettings()`/`updateSettings()` (replaced by OR's `/api/settings/*` endpoints), drop `rebase()` (replaced by re-running the migrator via the new `MigrateStorageController`). Keep ONLY the openconnector-specific `applyRetention` action — itself deprecated pending [#822](https://github.com/ConductionNL/openconnector/issues/822) Postgres portability fix.

### 2b. Shrink openconnector's ConfigurationService to a slug-translation decorator

`lib/Service/ConfigurationService.php` (835 LOC) currently reimplements export/import with openconnector-specific slug translation (per local ADR-015). Most of this duplicates OR's `\OCA\OpenRegister\Service\ConfigurationService`. The post-chain-C shape:

- Rename to `lib/Service/SlugTranslatorService.php` (~150 LOC) to avoid the namespace collision with OR's class.
- Keep ONLY the slug-translation methods: `translateOnExport(array $object): array` and `translateOnImport(array $object): array`. These walk an object's payload, find integer FK fields (`sourceId`, `synchronizationId`, etc.) and the relation-named uuid fields, and replace them with portable slug references.
- Export workflow becomes: client calls OR's export endpoint → OR returns raw object JSON → `SlugTranslatorService::translateOnExport()` decorates the output (only if openconnector is in the request chain — easiest as a custom OR `ObjectIntegration`).
- Import workflow inverses: `SlugTranslatorService::translateOnImport()` runs before OR's import resolves uuids.
- Drop `getEntitiesByConfiguration`, `exportConfiguration`, `exportRegister`, `importConfiguration` — all subsumed by OR.

### 3. Refactor the remaining connector-specific services

The following services stay but inject `ObjectService` instead of mappers, using OR's named-parameter API (verified against `openregister/lib/Service/ObjectService.php:573`):

- `CallService` — outbound HTTP execution + CallLog emission
- `MappingService` — Twig-templated A→B transformation (per local ADR-002)
- `RuleService` — conditional pipeline evaluator (per local ADR-002 + ADR-011 FlowToken)
- `EndpointService` — inbound endpoint dispatch with polymorphic `targetType` routing (per local ADR-008)
- `SynchronizationService` — sync pass execution (per local ADR-005 triad)
- `EventService` — event fan-out → EventMessage delivery (per local ADR-013)
- `JobService` + `lib/Cron/JobTask.php` — scheduled execution (per local ADR-014)
- `ConfigurationService` — slug-based export/import (per local ADR-015)
- `AuthorizationService`, `AuthenticationService` — keep as-is (no domain data)

Each service's mapper-typed constructor params (`SourceMapper $sourceMapper`, etc.) become `ObjectService $objectService` with the appropriate `register: 'openconnector', schema: '<slug>'` named-arg pattern.

### 4. Quality gate

A `composer check:strict` gate (PHPCS custom sniff or grep-based scripts entry) fails the build if any of the deleted PHP types appears in a `use` statement under `lib/` or `tests/`. The gate's deleted-types list:

- 15 entity classes: `OCA\OpenConnector\Db\<Resource>` for each of the 15 schemas
- 15 mapper classes: `OCA\OpenConnector\Db\<Resource>Mapper`

(No `ObjectMapperFacade` entry — the facade never shipped.)

### 5. Tests

- Delete `tests/Unit/Db/` (mapper tests for now-deleted classes).
- Rewrite service tests to mock `ObjectService` instead of mappers. Test scope shrinks ~50% (no per-schema mapper test files).
- Newman collection covers the OR `/api/objects/openconnector/{schema}` routes (those come from OR, openconnector doesn't define them) plus the connector-specific action endpoints (run-job, test-source, trigger-sync, import/export, etc.).

## Impact

- **Code deleted**: ~30 entity/mapper PHP files + ~10-15 per-schema CRUD controllers + ~15 Pinia stores + ~30 Vue views = roughly **~8000 LOC removed**.
- **Code rewritten**: 8 connector-specific services (~2000 LOC adjusted, not rewritten).
- **Code added**: migrator + Version2Date + OCC command + quality gate = ~1500 LOC.
- **Net diff**: **strongly negative** — the refactor primarily removes code.
- **DB schema changes**: none post-migration. Legacy tables stay read-only one release, then drop per [#820](https://github.com/ConductionNL/openconnector/issues/820).
- **Public API**: no breaking change for OR-style CRUD (URLs change from `/api/sources` to `/api/objects/openconnector/source` but the new path was already available). Connector-specific action endpoints (run-job, test-source, etc.) keep their existing URLs.

## Affected Projects

- [x] Project: `openconnector` — primary scope. ~8000 LOC removed, ~2000 adjusted, ~1500 added.
- [ ] Project: `openregister` — passive. Used as a dependency; no changes.

## Open Questions

- Which controllers under `lib/Controller/` qualify as "per-schema CRUD only" vs "connector-specific actions"? Need an explicit per-file audit during apply. Initial assessment: `SourcesController`, `EndpointsController`, `ConsumersController`, `EventsController`, `MappingsController`, `RulesController`, `JobsController`, `SynchronizationsController` are mostly CRUD over their entity — delete. `CallController`, `ExportController`, `ImportController`, `SettingsController`, `DashboardController`, `MigrateStorageController` (new), `EndpointController` (the inbound dispatch one, not the CRUD one) — keep.
- Does `SettingsService::applyRetention()` continue to exist post-chain-C, or is its role taken over by OR's archival workflow? Tracked at [#822](https://github.com/ConductionNL/openconnector/issues/822) (Postgres portability) which may co-resolve when the function is rewritten or dropped.
- Custom widgets registered in `src/registry.js` (MappingEditor, RuleConditions, CronBuilder, EventSubscriptionsManager, SourceTester, JobRunner) are kept as **widget slots** in standard-type manifest pages (chain D2), not as entire custom pages.

## Risks

- **Risk**: legacy `oc_openconnector_*` tables stay populated but read-only for one release window. Mitigation: the migrator is idempotent + auditable; [#820](https://github.com/ConductionNL/openconnector/issues/820) tracks the drop as a follow-up.
- **Risk**: cross-app consumers that imported `OCA\OpenConnector\Db\Source` (or similar) directly will break. Mitigation: known consumers are decidesk, pipelinq, openbuilt — all under our control; they'll be notified to switch to `ObjectEntity`.
- **Risk**: any service we forget to refactor crashes at runtime when a mapper is gone. Mitigation: quality gate fails CI; `composer test:coverage` flags uncovered call sites.

## Cleanup follow-ups

- [#820](https://github.com/ConductionNL/openconnector/issues/820) — drop `oc_openconnector_*` tables
- [#821](https://github.com/ConductionNL/openconnector/issues/821) — rename legacy `*Id` fields to target-schema-name
- [#822](https://github.com/ConductionNL/openconnector/issues/822) — `SettingsService::applyRetention()` Postgres portability fix (or removal if absorbed by OR archival)
