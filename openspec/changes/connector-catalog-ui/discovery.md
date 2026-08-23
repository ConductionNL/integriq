# Discovery: connector-catalog-ui

## Question

Three open feasibility questions from the context brief needed resolving before specs/design could be written with confidence:
1. Can the Catalog page be built from an existing manifest-v2 typed primitive (no `nextcloud-vue` change), or is a bespoke `type: "custom"` page unavoidable?
2. Does an adapter/connector metadata registry already exist in Integriq or OpenRegister that this change should extend rather than duplicate?
3. Is configuration export/import really "API-only" today (implying a route to wrap a UI around), or something else?

## Approach Taken

- Read `src/manifest.json` in full (26 pages) and traced `FeaturesRoadmap`'s `type: "roadmap"` through `src/main.js` / `src/registry.js` to confirm it is a library-supplied typed primitive, not a bespoke component — precedent that typed primitives beyond plain CRUD exist.
- Searched sibling `apps-extra` repos' `src/manifest.json` for `"cards"`/`"gallery"`/`"filters"` usage and found two live precedents: `openbuild`'s `VirtualApps` page (`type: "index"`, `viewMode: "cards"`, `cardComponent: "ApplicationCard"`) and `softwarecatalog`'s `Organisaties` page (same pattern, `cardComponent: "OrganisatieCard"`). Also found `openbuild`'s `Templates` page, which uses a genuinely bespoke `type: "custom"` component (`TemplateGallery`) — but only because it integrates a *remote* template-store search, a capability explicitly out of scope here.
- Read `@conduction/nextcloud-vue`'s `CnIndexPage.vue` source directly (checked out at `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/nextcloud-vue`) and confirmed `viewMode`/`viewModes`, `cardComponent`, `filters` (facet chips), and search are all config-driven props on the existing component — no library change needed to get a searchable, filterable card grid.
- Grepped `lib/AppInfo/Application.php` for `IntegrationRegistry`/`addProvider` and read `lib/Service/Adapter/AbstractCategoryAdapterProvider.php` to establish what registry machinery already exists.
- Grepped `appinfo/routes.php` for `configuration`/`Configuration` and read the "Import & Export" comment block plus `lib/Service/ConfigurationService.php` and its callers (via `tests/Unit/Service/ConfigurationServiceTest.php`, the only caller found) to establish the real current reachability of export/import.

## Findings

1. **Page type**: `type: "index"` + `config.viewMode: "cards"` + `config.cardComponent` + `config.filters: [...]` is an established, twice-shipped, config-only pattern for a browsable, filterable card catalog backed by an OpenRegister register/schema. It satisfies the "prefer typed primitives over custom pages" constraint directly — no `nextcloud-vue` change, no custom-widget-ratchet gate exposure.
2. **Adapter registry**: A registry already exists but is narrower than the brief assumed — `OCA\OpenRegister\Service\Integration\IntegrationRegistry` (OR-side) plus Integriq's `AbstractCategoryAdapterProvider` covers exactly 4 adapters (Azure Virtual Desktop, SharePoint Online, Microsoft 365, S3), registered by hand in `Application.php::registerIntegrationProviders()`. PDOK, Digikoppeling, Berichtenbox, DSO, and the `register.d`-seeded sources (BRP/KVK/xWiki/messaging/OpenCorporates) are **not** in this registry. Two seeding mechanisms exist and are not interchangeable: container-level `*.feature_flag` app-config (PDOK, Berichtenbox) vs. per-object `configuration.mock`/`isEnabled` on seeded Source objects (everything else).
3. **Configuration export/import**: There is no `ConfigurationController` and no route — `ConfigurationService::exportConfiguration()`/`importConfiguration()` are called only from PHPUnit tests today. The brief's framing ("API-only") is inaccurate; the correct framing is "fully implemented, fully tested, entirely unrouted." OpenRegister's generic `/api/registers/{id}/export` / `/api/configurations/{id}/import` endpoints (mentioned in the routes.php dead-code comment) operate at register granularity, not at Integriq's configuration-group granularity (`configurations[]` membership spanning 6 entity types) — they are not a drop-in substitute.
4. Source "type" has no enforced enum (contra the brief); the live vocabulary is `lib/Settings/integriq_register.json`'s free-form `type` field with recognised values `api, database, file, soap, dso, peppol, psd2, sms, payment`.
5. **Action-level authorization (ADR-023) already fully implemented in Integriq** (correction of an earlier draft that assumed it was absent): `lib/Service/ActionAuthService.php` (`requireAction()`/`can()` over an `IAppConfig` matrix, admin break-glass pass), `lib/Controller/ActionMatrixController.php` (admin matrix editor), `lib/Repair/InitializeActions.php`, and `lib/actions.seed.json` (38 actions, `<domain>.<verb>` convention — `source.test`, `job.run`, `pdok.suggest`) — already consumed by SourcesController, MappingsController, EventsController, JobsController and others. This change only appends three action keys to the existing seed; no new auth machinery.

## Recommendation

- **Catalog page**: build with `type: "index"` + `viewMode: "cards"`, backed by a new `catalog_item` register/schema. Do not request a new `nextcloud-vue` typed primitive and do not write a `type: "custom"` page — the cards pattern is proven and sufficient.
- **Adapter registry**: do not extend `IntegrationRegistry` in this change. Its `IntegrationProvider` interface is shaped for the 4 category adapters (auth requirements, storage strategy, health) and promoting PDOK/Digikoppeling/Berichtenbox/DSO into it is a larger, separate refactor with its own risk surface. Instead, build a lightweight Integriq-local `CatalogRegistryService` that (a) reads the 4 already-registered providers from `IntegrationRegistry` for their metadata, (b) hand-describes PDOK/Digikoppeling/Berichtenbox/DSO in a small static descriptor list colocated with each adapter's namespace, and (c) reads the `register.d/*-source.json` seed fragments for seeded-source templates. This is additive and reuses rather than duplicates; promoting (b) into full `IntegrationProvider`s is recorded as a follow-up, not done here.
- **Configuration import/export UI**: resurrect a thin `ConfigurationController` in Integriq wrapping the existing `ConfigurationService` unchanged, rather than building against OR's generic endpoints. This preserves Integriq's configuration-group semantics and reuses fully-tested logic; it does not reopen review of the underlying redaction/slug-translation behaviour (documented as retrofit-accurate in `configuration-export-import/spec.md` REQ-001–REQ-005), only adds a route + UI layer on top.

## Risks Uncovered

- The `ConfigurationController` import endpoint becomes a new privileged write surface (creates/updates Source/Endpoint/Mapping/Rule/Job/Synchronization objects from an uploaded, largely unvalidated OAS document — REQ-003 Notes: "Import performs no schema validation of the per-entity payload beyond the top-level `components` check"). Gated via the existing `ActionAuthService` with a `configuration.import` action seeded `["admin"]` (finding 5), matching the existing `99-source-lockdown.json` admin-only lock on the `source` schema.
- `catalog_item` materialisation (repair step) must not race with `register.d` fragment application at boot, since it reads seed fragment files as one of its inputs.

## Next Steps

Proceed to specs and design with the three decisions above locked in.
