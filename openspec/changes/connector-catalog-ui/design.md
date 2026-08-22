# Design: connector-catalog-ui

## Architecture Overview

Two independent additive surfaces, both consuming existing backend logic rather than duplicating it:

1. **Catalog** — a new `catalog_item` OpenRegister schema (like every other Integriq entity, per ADR-001/`openconnector-direct-or-usage`), populated by a new `CatalogRegistryService` at repair-step time from three existing sources (OR's `IntegrationRegistry`, a small static descriptor list for the adapters not in that registry, and the `register.d/*-source.json` seed fragments), browsed via a manifest-v2 `type: "index"` + `viewMode: "cards"` page (zero `nextcloud-vue` changes — this exact pattern already ships in `openbuild`'s `VirtualApps` page and `softwarecatalog`'s `Organisaties` page).
2. **Configuration import/export UI** — a new, thin `ConfigurationController` (Controller layer per ADR-008) that wraps the existing, already-tested `ConfigurationService` (Service layer) unchanged, exposed via new routes, consumed by a new manifest page + import-preview modal.

```
Catalog:
  register.d seeds ──┐
  IntegrationRegistry ├──► CatalogRegistryService ──► catalog_item OR objects ──► CnIndexPage (viewMode:cards)
  static descriptors ─┘         (repair step)              (register/schema)         │
                                                                                       ▼
                                                                        CatalogItemCard.vue (detail modal,
                                                                        Enable/Instantiate action)
                                                                                       │
                                                                                       ▼
                                                                        CatalogActionController
                                                                        (dispatches to existing Source/
                                                                        AppConfig write paths — see
                                                                        Decision 3)

Configuration import/export:
  ConfigurationController (new, thin) ──► ConfigurationService (existing, unchanged)
        │  export/import/preview                    │
        ▼                                            ▼
  Configuration UI page + import-preview modal   ConfigurationHandlers (existing, unchanged)
```

## API Design

### `GET /api/catalog/items`
Lists catalog items (backs the Catalog `index` page — actually served by OpenRegister's generic object-list endpoint for the `catalog_item` schema, `GET /apps/openregister/api/objects/openconnector/catalog_item`, per ADR-022; no bespoke list endpoint is added). Live status (`available` / `dormant`) is computed at read time by `CatalogRegistryService::resolveStatus()` and written onto each object at materialisation time PLUS re-checked by a light-weight status endpoint below, so a flag flip between repair-step runs is still reflected without waiting for the next materialisation.

### `GET /api/catalog/items/{id}/status`
**Response:**
```json
{ "id": "pdok-wms", "status": "available", "mechanism": "flag-gated", "flagKey": "pdok.feature_flag" }
```
Live re-check of a single item's gating mechanism (`flag-gated` reads `IConfig::getValueString('openconnector', flagKey)`; `mock-seeded` reads the live Source object's `configuration.mock` / `isEnabled`). Called by the detail modal before rendering the Enable/Instantiate action so a stale materialised card never offers an action that's already been taken.

### `POST /api/catalog/items/{id}/instantiate`
**Request:** `{}` (no body — the seed template is looked up server-side by catalog item id)
**Response (201):**
```json
{ "created": true, "type": "source", "id": "<new-or-existing-uuid>", "action": "enabled" }
```
`#[NoAdminRequired]` + `$this->actionAuth->requireAction($user, 'catalog.instantiate')` (ADR-023) as the action-level gate; the underlying write itself still passes through the Source schema's existing `99-source-lockdown.json` admin-only OpenRegister authorization (data-layer RBAC, ADR-023 Rule 1) — so even if the action matrix is misconfigured to allow a non-admin group, the OR-level write is still rejected for non-admins. Two independent layers, as ADR-023 intends. See Decision 3.

### `POST /api/configurations/{id}/export`
**Response:** `200`, `Content-Disposition: attachment`, body = the existing `ConfigurationService::exportConfiguration($id)` OAS JSON (redacted per REQ-005, unchanged).

### `POST /api/configurations/import/preview`
**Request:** multipart file upload or raw JSON body (the OAS document).
**Response:**
```json
{
  "creates": [{ "type": "source", "slug": "new-source" }],
  "updates": [{ "type": "endpoint", "slug": "existing-endpoint", "id": "<uuid>" }],
  "collisions": [{ "type": "source", "slug": "ambiguous-slug", "reason": "slug matches an object of a different schema" }],
  "unresolvedReferences": [{ "type": "rule", "slug": "r1", "field": "sourceId", "value": "unknown-source-slug" }],
  "credentialsNeedingReentry": [{ "type": "source", "slug": "new-source", "fields": ["apikey", "secret"] }]
}
```
Non-mutating dry-run: calls `getEntitiesByConfiguration()`-equivalent diffing against the *target* environment's current slug maps (reusing `resetMappings()` + the handlers' existing slug-resolution, REQ-004) without calling `saveObject()`. `unresolvedReferences` surfaces the REQ-004 "left verbatim" dangling-reference case as a **blocking** warning requiring explicit confirmation (see proposal Open Questions).

### `POST /api/configurations/import`
**Request:** `{ "document": {...}, "confirmed": true }` — `confirmed` MUST be `true` or the request is rejected with 400; the frontend always calls `/preview` first and requires the operator to acknowledge before setting `confirmed`.
**Response (200):** the same shape as `/preview`'s response, now reflecting what was actually written, i.e. `ConfigurationService::importConfiguration($document)`'s result plus the preview diff for the UI's post-import summary.

Both configuration endpoints are `#[NoAdminRequired]` + `$this->actionAuth->requireAction($user, 'configuration.export')` / `'configuration.import')` at the action layer; the Source-touching writes inside `importConfiguration()` still pass through OR's admin-only `source` schema lock, same double-layer reasoning as catalog instantiate.

## Database Changes

No native table changes. One new OpenRegister schema fragment (`lib/Settings/register.d/catalog-item-schema.json`) defining `catalog_item` (fields: `name`, `description`, `category`, `kind` [`adapter`|`source-template`|`configuration-template`], `status` [computed, not stored as source of truth — see below], `mechanism` [`flag-gated`|`mock-seeded`|`always-available`], `flagKey`, `sourceTemplateSlug`, `standards[]`, `icon`), materialised by a new repair step `MaterializeCatalogItems` (parallel to the existing `InitializeRegister` pattern) that upserts one `catalog_item` object per registry/descriptor/seed entry, keyed by a stable slug so re-runs update in place rather than duplicating.

## Nextcloud Integration

- Controllers: `OCA\Integriq\Controller\CatalogController` (new — `status`, `instantiate`), `OCA\Integriq\Controller\ConfigurationController` (new — `export`, `previewImport`, `import`).
- Services: `OCA\Integriq\Service\CatalogRegistryService` (new), `OCA\Integriq\Service\ConfigurationService` (existing, unchanged), `OCA\Integriq\Service\ActionAuthService` (**existing, reused** — Integriq already ships the ADR-023 implementation at `lib/Service/ActionAuthService.php` with `requireAction()`/`can()` over an `IAppConfig`-stored matrix, an admin matrix editor at `lib/Controller/ActionMatrixController.php`, and seed application via `lib/Repair/InitializeActions.php` from `lib/actions.seed.json`; it is already consumed by SourcesController, MappingsController, EventsController, JobsController and others. No new auth service is introduced by this change).
- Mappers/Entities: none new — `catalog_item` is an OpenRegister-managed object, no native Doctrine/QBMapper entity.
- Events/Hooks: repair step registered in `lib/AppInfo/Application.php` alongside the existing `InitializeRegister` repair step registration.

## Security Considerations

- **Action-level auth (ADR-023)**: `catalog.instantiate`, `configuration.export`, `configuration.import` are new action keys added to the **existing** `lib/actions.seed.json` matrix seed (following the established `<domain>.<verb>` naming convention already in that file — `source.test`, `job.run`, `pdok.suggest`, etc.), defaulting to `["admin"]` per ADR-023's safe-default rule, applied by the existing `lib/Repair/InitializeActions.php` and enforced by the existing `ActionAuthService::requireAction()`. Note `requireAction()` gives admins an unconditional break-glass pass, so the default posture keeps all three actions admin-only until an admin broadens them via the existing Action authorization settings panel.
- **Data-level auth (unchanged)**: the `source` schema stays admin-only end to end (`99-source-lockdown.json`) — the catalog/import write paths do not introduce a new way to read or write Source credentials that bypasses this lock; they call the same `ObjectService`/`ConfigurationHandlers\SourceHandler` code the existing admin-only Sources UI calls.
- **Import is untrusted input** (REQ-003 Notes: no schema validation beyond the top-level `components` key) — the new `/preview` endpoint does NOT execute the import; it only reads/diffs. The `/import` endpoint requires `confirmed: true` from a UI that has shown the preview, but this is a UX safeguard, not a security control — the actual security control remains the admin-only action-matrix gate plus OR's own object-write validation. This is called out explicitly rather than treated as a security boundary in itself.
- **catalog_item objects carry no credentials** — `CatalogRegistryService` writes only metadata (name/category/status/mechanism), never Source config, so `catalog_item` itself does not need the same lockdown as `source`. Confirmed in the schema field list above.
- No CORS/CSRF changes — both controllers use standard Nextcloud CSRF-protected session auth like every other Integriq controller.

## NL Design System

Catalog cards (`CatalogItemCard.vue`) and the import-preview modal use standard NC components (`NcButton`, `NcModal`/`NcDialog` per the modal-isolation gate — the preview lives in its own `src/dialogs/ImportPreviewDialog.vue`, not inline) and CSS variables only, no hardcoded colors, consistent with every other Integriq page.

## File Structure

```
lib/
  Controller/
    CatalogController.php          (new)
    ConfigurationController.php    (new)
  Service/
    CatalogRegistryService.php     (new)
    ActionAuthService.php          (existing — reused unchanged)
  Repair/
    MaterializeCatalogItems.php    (new)
    InitializeActions.php          (existing — reused unchanged; applies the seed below)
  actions.seed.json                (existing — append catalog.instantiate, configuration.export, configuration.import)
  Settings/
    register.d/
      catalog-item-schema.json     (new)
appinfo/
  routes.php                       (append catalog + configuration routes)
src/
  manifest.json                    (append Catalog page + menu entry)
  components/
    CatalogItemCard.vue            (new)
  dialogs/
    ImportPreviewDialog.vue        (new)
  store/
    catalog.js                     (new Pinia store, createObjectStore pattern per project convention)
tests/
  Unit/Service/CatalogRegistryServiceTest.php   (new)
  Unit/Controller/ConfigurationControllerTest.php (new — import preview diff logic)
  playwright/catalog-browse.spec.js  (new — e2e-coverage gate)
  playwright/configuration-import.spec.js (new)
```

## Seed Data

### Schema: `catalog_item`

| Field | Object 1 | Object 2 | Object 3 | Object 4 |
|-------|----------|----------|----------|----------|
| slug | `pdok-wms` | `brp-haalcentraal` | `s3-data-infra` | `xwiki-source` |
| name | PDOK WMS | BRP HaalCentraal | S3 object storage | xWiki source |
| kind | source-template | source-template | adapter | source-template |
| category | Geo / Maps | Government registers | Data infrastructure | Document / CMS |
| mechanism | flag-gated | mock-seeded | always-available | mock-seeded |
| flagKey | `pdok.feature_flag` | — | — | — |
| status (computed at read) | dormant (flag off by default) | available (mock mode) | available | dormant (`isEnabled:false`) |
| standards | OGC WMS | Haal Centraal BRP | S3 API | xWiki REST |

These are not hand-authored "seed objects" in the usual sense (3-5 illustrative records) — they are the **actual, real** materialised entries the repair step produces from the genuinely-seeded PDOK/BRP/S3/xWiki adapters and sources already present in the codebase (verified at HEAD; see discovery.md finding 2). No fictional catalog entries are introduced.

**Related items per object:** none — `catalog_item` has no file/note/task/contact attachments; it is a pure metadata/catalog record pointing at the real Source/adapter it describes.

## Trade-offs

- **Extending `IntegrationRegistry` vs. a new lightweight descriptor list** (Decision, see discovery.md): chose the lightweight list for PDOK/Digikoppeling/Berichtenbox/DSO rather than promoting them to full `IntegrationProvider`s, because `IntegrationProvider`'s interface (auth requirements, storage strategy, health checks) is shaped for the 4 already-registered category adapters and forcing PDOK etc. into that shape is separate, riskier work with no catalog-specific payoff (the catalog only needs name/category/status/standards, not health/storage-strategy). Revisit if a future change needs PDOK health-checked through the same registry OR wants generally does.
- **Thin `ConfigurationController` wrapping existing service vs. building against OR's generic export/import endpoints**: chose the thin wrapper because OR's endpoints operate at register granularity while Integriq's configuration groups span 6 entity types by `configurations[]` membership — not expressible through OR's generic endpoints without re-deriving the same grouping logic client-side, which would duplicate `ConfigurationService` rather than reuse it.
- **`type: "index"` + `viewMode: "cards"` vs. a bespoke `type: "custom"` catalog page**: chose the typed-primitive path per the constraint (#814, hydra custom-widget-ratchet gate) and because two live precedents (openbuild `VirtualApps`, softwarecatalog `Organisaties`) prove it handles a card grid with a custom card component and facet filters without any `nextcloud-vue` change. The one place a custom page remains justified in the ecosystem (openbuild's `Templates`/`TemplateGallery`) is specifically for *remote* template-store search — explicitly out of scope here (proposal Out of Scope), so that justification does not apply to this change.

## Open Questions

- Exact slug-stability strategy for `catalog_item` re-materialisation (e.g. `adapter:pdok-wms` vs. `source-template:brp-haalcentraal`) needs to be finalized in tasks.md/apply — namespaced by `kind:` prefix is proposed to avoid cross-kind slug collisions.

(Resolved during authoring: Integriq already ships the full ADR-023 stack — `lib/Service/ActionAuthService.php`, `lib/Controller/ActionMatrixController.php`, `lib/Repair/InitializeActions.php`, `lib/actions.seed.json` — verified at HEAD; this change only appends three action keys to the existing seed.)
