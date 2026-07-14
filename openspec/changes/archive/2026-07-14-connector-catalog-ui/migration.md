# Migration: connector-catalog-ui

## Current State

No `catalog_item` schema exists. `lib/Settings/openconnector_register.json` + `lib/Settings/register.d/*.json` fragments define the `openconnector` register's other 15 schemas (source, endpoint, mapping, rule, job, synchronization, consumer, event, event_subscription, event_message, call_log, job_log, synchronization_log, synchronization_contract — see `openconnector-storage-migration` spec). Fragments are merged and imported via `OCA\OpenRegister\Service\ConfigurationService::importFromApp()`, invoked both from `lib/Repair\InitializeRegister` (repeatable repair step, runs on every `occ upgrade` and app enable) and, historically, one-shot from `lib/Migration\Version2Date20260520000001` for the chain-B storage cutover. There is no `catalog_item` register/schema, and no repair step materialises catalog data from the registries described in design.md.

## Target State

- A new schema fragment `lib/Settings/register.d/catalog-item-schema.json` defines `catalog_item` (fields: `name`, `description`, `category`, `kind`, `mechanism`, `flagKey`, `sourceTemplateSlug`, `standards[]`, `icon`) alongside the existing 15 schemas, merged by the existing `InitializeRegister` fragment-merge mechanism — **no new migration class is needed for the schema itself**, since `register.d/*.json` fragments are picked up automatically by the existing repair step on every run (same mechanism as the `99-source-lockdown.json` and `brp-haalcentraal-source.json` fragments already in the repo).
- A new repair step `lib/Repair/MaterializeCatalogItems.php` (implementing `\OCP\Migration\IRepairStep`, registered in `lib/AppInfo/Application.php` alongside `InitializeRegister`) runs `CatalogRegistryService::materialize()` on every `occ upgrade` / app enable, upserting one `catalog_item` OpenRegister object per real adapter/seed-source entry (see design.md Decisions), keyed by a stable `kind:slug` identifier so re-runs update in place.
- Three new action keys — `catalog.instantiate`, `configuration.export`, `configuration.import` — are appended to the **existing** `lib/actions.seed.json` (ADR-023 matrix seed, verified present at HEAD with 38 actions in `<domain>.<verb>` style, e.g. `source.test`, `job.run`, `pdok.suggest`), defaulting to `["admin"]`, applied by the existing `lib/Repair/InitializeActions.php` repair step. No new auth service, controller, or repair step is needed for authorization.

## Migration Class

No native-table `lib/Migration/VersionXXXXXXXXXX.php` schema migration is required — `catalog_item` is an OpenRegister-managed schema (JSON fragment + repeatable repair step), not a native Doctrine/QBMapper table, matching the pattern already used for every other OpenConnector entity (`openconnector-direct-or-usage`). If a one-shot trigger is later found necessary (e.g. to force an immediate materialisation on upgrade rather than waiting for the next repair-step pass), it would follow the `Version2Date20260520000001` pattern exactly: `preSchemaChange()` no-op, `changeSchema()` returns `null`, `postSchemaChange()` resolves `CatalogRegistryService` from the container and calls `materialize()` idempotently, guarded the same way (`class_exists` check for OpenRegister availability, try/catch around service resolution). This is deferred to the apply step's judgment — the repair step alone is expected to be sufficient since it already runs on every upgrade.

```
Version: (none required — see above)
File: lib/Repair/MaterializeCatalogItems.php (repair step, not a versioned migration)
Key operations:
- Read IntegrationRegistry-registered providers (4 category adapters)
- Read static descriptor list (PDOK, Digikoppeling, Berichtenbox, DSO)
- Read register.d/*-source.json seed fragments (BRP, KVK, xWiki, messaging, OpenCorporates, PDOK)
- Upsert one catalog_item object per entry, keyed by kind:slug
```

## Migration Steps

1. Ship `lib/Settings/register.d/catalog-item-schema.json` — picked up automatically by the existing `InitializeRegister` repair step's fragment merge on the next `occ upgrade` or app enable. Verifiable: `catalog_item` appears as a schema under the `openconnector` register.
2. Ship `lib/Repair/MaterializeCatalogItems.php`, registered as an `IRepairStep` in `Application.php`. Verifiable: repair step name appears in `occ upgrade` output.
3. First repair-step run materialises `catalog_item` objects for every real adapter/seed source found (see design.md Seed Data — these are not fictional, they mirror already-shipped code). Verifiable: `GET /apps/openregister/api/objects/openconnector/catalog_item` returns one object per entry.
4. Append `catalog.instantiate`, `configuration.export`, `configuration.import` (each `["admin"]`) to the existing `lib/actions.seed.json`; the existing `InitializeActions` repair step applies them on the next run. Verifiable: the existing admin action-matrix settings panel (`ActionMatrixController`) lists the three new actions.
5. Re-running steps 1–4 (idempotency check) produces no duplicate `catalog_item` objects and no duplicate action-matrix entries.

## Data Impact

- Additive only: creates new `catalog_item` objects (expected count: ~4 category adapters + 4 hand-described adapters [PDOK, Digikoppeling, Berichtenbox, DSO] + ~6 seeded source templates [PDOK, BRP, KVK, xWiki, messaging (grouped or per-channel), OpenCorporates] ≈ 12-16 objects at initial rollout). No existing Source/Endpoint/Mapping/Rule/Job/Synchronization/Consumer object is read, modified, or deleted by this migration.
- Safe on live data: the repair step only writes to the new `catalog_item` schema; it performs read-only queries against `IntegrationRegistry` and the existing seed fragments.
- No downtime: repair steps run as part of the normal `occ upgrade` flow, same as `InitializeRegister` today.

## Rollback Procedure

Remove `lib/Settings/register.d/catalog-item-schema.json`, `lib/Repair/MaterializeCatalogItems.php`, and its registration in `Application.php`. The `catalog_item` schema and its objects become orphaned (no longer written to) but are not automatically deleted — an operator MAY run `occ openregister:schema:delete openconnector catalog_item` (existing OpenRegister command) to remove them if a clean rollback is required. No other schema or object is touched, so rollback carries zero risk to existing Source/Endpoint/Configuration data (matches proposal.md Rollback Strategy).

## Validation

- `occ upgrade` completes without error; log output shows the `MaterializeCatalogItems` repair step ran.
- `GET /apps/openregister/api/objects/openconnector/catalog_item` returns the expected object count (~12-16) with no duplicates.
- Re-running `occ upgrade` a second time produces the same object count (idempotency).
- The Catalog page (`/catalog`) renders all materialised items as cards without error.
- The admin action-matrix settings panel shows `catalog.instantiate`, `configuration.export`, `configuration.import` all defaulted to `["admin"]`.
