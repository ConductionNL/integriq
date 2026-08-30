# Proposal: connector-catalog-ui

## Summary

Integriq today has real integration capability — seeded PDOK/BRP/KVK/xWiki/messaging sources, a working (but unrouted) configuration export/import service with slug-translation and credential redaction, and four registered category adapters (Azure Virtual Desktop, SharePoint Online, Microsoft 365, S3) — but none of it is discoverable from the UI. Onboarding is 100% tribal-knowledge/API-only. This change adds a browsable **Catalog** page (connector adapters, seeded source templates, importable configuration templates, with search + category filter and an Enable/Instantiate action) and a **Configuration import/export UI** (export-to-file with redaction, import-with-preview and confirmation, redacted-credential re-entry flagging), built entirely from existing manifest-v2 typed primitives (`CnIndexPage` with `viewMode: "cards"`, matching the precedent already shipped in `openbuild`'s `VirtualApps` page and `softwarecatalog`'s `Organisaties` page) — no `nextcloud-vue` library changes.

## Motivation

Every competitor in this space leads with a template/catalog gallery as the #1 onboarding device (n8n: 600+ templates; Workato: tens of thousands of recipes). Integriq's seeded sources (PDOK behind `pdok.feature_flag`; BRP/KVK/xWiki/messaging/OpenCorporates seeded via `lib/Settings/register.d/*.json` in mock mode) sit dormant with no surface for an operator to find, understand, or enable them. Separately, `ConfigurationService` (`lib/Service/ConfigurationService.php`) already implements a complete, tested export/import/redaction/slug-translation pipeline (`openspec/specs/configuration-export-import/spec.md`, retrofit, status `done`) but has **no controller, no route, and no UI** — it is reachable only from PHPUnit tests. This is the highest-leverage, lowest-net-new-code opportunity in the app: surface what already exists rather than build new integration logic.

## Affected Projects

- [x] Project: `integriq` — new Catalog page (manifest + PHP catalog API + adapter metadata registry), new Configuration import/export UI (manifest + a thin `ConfigurationController` wrapping the existing `ConfigurationService`), new `catalog_item` register schema seeded by a repair step.

## Scope

### In Scope

1. **Catalog page** (`src/manifest.json` page `id: "Catalog"`, `type: "index"`, `viewMode: "cards"`) browsing three kinds of catalog items — built-in connector adapters (PDOK, Berichtenbox, Digikoppeling, the four `IntegrationProvider` category adapters, DSO), seeded source templates (BRP/KVK/xWiki/messaging/OpenCorporates/PDOK), and importable configuration templates — each with category, status (`available` / `dormant` behind a feature flag), and a detail modal offering an "Enable" (flip `*.feature_flag` app-config) or "Instantiate" (create a Source/Configuration from a seed) action, gated by the existing ADR-023 authorization matrix and respecting feature-flag state.
2. **Adapter metadata registry** (PHP-side, single source of truth) — a new `CatalogRegistryService` in Integriq that assembles catalog entries from (a) the existing OR-side `IntegrationRegistry` for the 4 registered category adapters, (b) a small hand-written descriptor list for PDOK/Digikoppeling/Berichtenbox/DSO (not currently in any registry), and (c) the `register.d/*.json` seeded-source fragments, materialised into `catalog_item` OpenRegister objects by a repair step so the Catalog page can be a standard register/schema-backed `index` page.
3. **Configuration import/export UI** — a thin `ConfigurationController` (new route group) wrapping the existing, already-tested `ConfigurationService::exportConfiguration()` / `importConfiguration()`; export produces a redacted download; import shows a preview (creates vs. updates vs. slug collisions) and requires explicit confirmation before writing; imported Sources with redacted credential placeholders are flagged for operator re-entry.
4. **Tests**: PHPUnit for the catalog registry and import-preview diff logic; vitest for the catalog Pinia store; Playwright e2e for catalog-browse and the import flow (satisfies the `e2e-coverage` hydra gate).
5. **Specs**: new capability spec `connector-catalog`; delta to `configuration-export-import` adding the UI-facing scenarios (export-from-UI, import-preview, confirmation, redacted-credential flagging); delta to `openconnector-app-manifest` adding the `Catalog` page and menu entry (scoped to this addition — the base manifest spec is already stale against `src/manifest.json` at HEAD on unrelated axes; this change does not attempt a full resync).

### Out of Scope

- Full environments/promotion with credential re-binding across environments — deferred until `source-broker-credentials` lands (per context brief).
- A community/remote template marketplace (openbuild's `TemplateGallery` pattern of a remote registry search) — catalog entries are local/seeded only.
- Retrofitting the existing drift in `openconnector-app-manifest` spec (stale page count, phantom `Import` page, flat-vs-grouped menu) beyond the one addition this change makes.
- Registering PDOK/Digikoppeling/Berichtenbox/DSO into the OR `IntegrationRegistry` as first-class `IntegrationProvider`s — they are catalogued via a lighter descriptor list in this change; promoting them to full `IntegrationProvider`s is a separate follow-up (see design.md).

## Approach

Reuse the manifest-v2 `index` page type in `viewMode: "cards"` (the same pattern already shipped by `openbuild`'s `VirtualApps` page and `softwarecatalog`'s `Organisaties` page — a `cardComponent` override, `filters: [...]` for category/status facets, all config-only, zero `nextcloud-vue` changes) backed by a new `catalog_item` register/schema, itself populated at boot/repair time from a small new `CatalogRegistryService`. The Configuration import/export UI resurrects a thin, spec-referenced `ConfigurationController` over the pre-existing `ConfigurationService` rather than duplicating its logic or routing through OpenRegister's generic (register-scoped, not configuration-group-scoped) export/import endpoints, which do not model Integriq's own configuration-group semantics (sources+endpoints+mappings+rules+jobs+syncs bundled by `configurations[]` membership). Full technical detail in `design.md`.

## New Dependencies

None. No new npm/composer packages; reuses existing `@conduction/nextcloud-vue` primitives, existing `ConfigurationService`, existing OR `IntegrationRegistry`.

## Impact

- New: `lib/Controller/ConfigurationController.php`, `lib/Service/CatalogRegistryService.php`, `lib/Settings/register.d/catalog-item-schema.json` (or equivalent schema fragment), a repair/migration step to materialise `catalog_item` objects, `appinfo/routes.php` entries, `src/manifest.json` `Catalog` page + menu entry, a `CatalogItemCard.vue` card component, an import-preview modal, a catalog Pinia store.
- Touched: `lib/actions.seed.json` — three new ADR-023 action keys appended (`catalog.instantiate`, `configuration.export`, `configuration.import`, each `["admin"]`) to the existing 38-action matrix seed; enforced by the existing `lib/Service/ActionAuthService.php` and applied by the existing `lib/Repair/InitializeActions.php`, both reused unchanged. None of the existing Source/Configuration CRUD paths change behaviour — this is additive (a new read/browse surface + a new write path for import that reuses the existing `ConfigurationService` write logic unchanged).
- No database schema changes to existing Integriq tables; `catalog_item` is a new OpenRegister schema, not a native table.

## Cross-Project Dependencies

Depends on OpenRegister's `IntegrationRegistry` (`OCA\OpenRegister\Service\Integration\IntegrationRegistry`) for the 4 already-registered category adapters — read-only consumption, no changes requested to OpenRegister. No other apps are affected; nothing in `apps-extra` currently consumes Integriq's configuration export/import surface.

## Risks

### Risk 1: "Enable"/"Instantiate" actions bypass or duplicate authorization already enforced elsewhere
**Severity:** High — **Mitigation:** the Catalog page's actions call into the *existing* Source/Configuration create/update code paths (already governed by ADR-023's action matrix and the `99-source-lockdown.json` admin-only CRUD lock on the `source` schema) rather than introducing a new write path; the catalog action handler is a thin dispatcher, not a new authorization surface. Verified in design.md against `99-source-lockdown.json`.

### Risk 2: Two incompatible "dormant" mechanisms (container `*.feature_flag` app-config vs. per-object `configuration.mock`/`isEnabled`) collapse into one UI affordance incorrectly
**Severity:** Medium — **Mitigation:** the catalog registry's status field distinguishes the mechanism explicitly (`flag-gated` vs. `mock-seeded`) and the detail-modal action dispatches to the correct handler per mechanism; specs enumerate both paths as separate scenarios.

### Risk 3: Resurrecting a `ConfigurationController` reopens an unrouted, security-relevant surface (substring-based, not allowlist, credential redaction per REQ-005 Notes)
**Severity:** Medium — **Mitigation:** the controller is additive over already-audited logic; the import endpoint is admin-only (mirrors the `source` schema's admin-only lockdown), and the redaction gap is pre-existing and documented (not introduced by this change) — noted explicitly in the spec delta rather than silently relied upon.

### Risk 4: `catalog_item` materialisation drifts from the live registry state (stale cards)
**Severity:** Low — **Mitigation:** materialisation runs on every repair-step pass (same cadence as existing `register.d` fragment application), and feature-flag/mock status is read live at request time by the catalog API endpoint, not baked into the stored object, so status badges cannot go stale even if the object list itself is momentarily behind.

## Rollback Strategy

Entirely additive: remove the `Catalog` page + menu entry from `src/manifest.json`, remove the `ConfigurationController` route registrations, and drop the `catalog_item` schema fragment (its repair step is idempotent and re-runnable). No existing Source/Endpoint/Configuration data or behaviour is touched, so rollback carries no data-migration risk.

## Open Questions

- Should PDOK/Digikoppeling/Berichtenbox/DSO be promoted to full OR `IntegrationRegistry` `IntegrationProvider`s in a follow-up so the catalog has one registry instead of two sources (registry + descriptor list)? Deferred — see design.md decision and DEFERRED_QUESTIONS.
- Should the import-preview UI surface the REQ-004 "unresolvable slug left verbatim" dangling-reference risk as a blocking warning, or an informational note? Proposed: blocking warning requiring explicit acknowledgement, since it is a silent-failure mode today (see configuration-export-import delta).
