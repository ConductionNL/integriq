---
status: planned
---

# connector-catalog Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- connector-catalog-ui

## Purpose

OpenConnector ships real integration capability — seeded PDOK/BRP/KVK/xWiki/messaging/OpenCorporates sources and four registered category adapters (Azure Virtual Desktop, SharePoint Online, Microsoft 365, S3) — but none of it is browsable. This capability defines a Catalog: a single, register/schema-backed, card-grid page that lists every built-in connector adapter, seeded source template, and importable configuration template, with search, category filtering, live status badges (`available` vs `dormant`), and an authorized Enable/Instantiate action. It is populated by a PHP-side `CatalogRegistryService` that reads the existing OpenRegister `IntegrationRegistry`, a small static descriptor list for adapters not yet in that registry, and the `register.d/*-source.json` seed fragments — no catalog entry is invented; every entry corresponds to real, already-shipped code. See ADR-023 (action-level authorization) for the Enable/Instantiate authorization model.

## ADDED Requirements

### Requirement: Catalog lists adapters, seeded source templates and configuration templates with category filter and status badges (REQ-001)

The system MUST provide a Catalog page listing every registered `catalog_item` object, grouped by `kind` (`adapter`, `source-template`, `configuration-template`), each rendered as a card showing name, category, standards, and a live status badge (`available` or `dormant`). The page MUST support free-text search and a category facet filter, and MUST NOT require a bespoke `type: "custom"` manifest page to do so — the manifest-v2 `type: "index"` page with `config.viewMode: "cards"` MUST be used (see `openconnector-app-manifest` delta).

#### Scenario: Catalog lists built-in adapters and seeded source templates by category
- GIVEN the `catalog_item` register contains entries for the PDOK WMS adapter (category "Geo / Maps"), the BRP HaalCentraal seeded source (category "Government registers"), and the S3 data-infra adapter (category "Data infrastructure")
- WHEN an operator opens the Catalog page
- THEN all three items are rendered as cards
- AND selecting the "Government registers" category filter narrows the grid to only the BRP HaalCentraal card

#### Scenario: Status badge reflects a flag-gated dormant item
- GIVEN the PDOK WMS catalog item has `mechanism: "flag-gated"` and the `pdok.feature_flag` app-config value is unset (default off)
- WHEN the Catalog page renders the PDOK WMS card
- THEN its status badge reads "dormant"

#### Scenario: Status badge reflects a mock-seeded available item
- GIVEN the BRP HaalCentraal catalog item has `mechanism: "mock-seeded"` and its underlying Source object has `isEnabled: true` and `configuration.mock: true`
- WHEN the Catalog page renders the BRP HaalCentraal card
- THEN its status badge reads "available" (mock mode is not treated as dormant — the source is reachable, just returning canned data)

#### Scenario: Search narrows the catalog grid
- GIVEN the Catalog page is open with no filters applied
- WHEN an operator types "brp" into the search field
- THEN only catalog items whose name or description matches "brp" remain visible

### Requirement: Catalog detail modal offers an authorized Enable or Instantiate action (REQ-002)

The system MUST provide a detail modal for each catalog item, opened from its card, showing the item's full description and standards, plus a primary action: "Enable" for a `flag-gated` item, or "Instantiate" for a `mock-seeded` or `always-available` item. The action MUST be gated at the action layer by OpenConnector's existing ADR-023 implementation — `ActionAuthService::requireAction()` (`lib/Service/ActionAuthService.php`) against a new `catalog.instantiate` action key seeded `["admin"]` in the existing `lib/actions.seed.json` (following its established `<domain>.<verb>` naming, e.g. `source.test`, `job.run`) — and MUST still pass through the underlying OpenRegister data-layer authorization for the object being created or updated (e.g. the `source` schema's admin-only lock). The catalog action MUST NOT introduce a new authorization service or a bypass of existing data-layer authorization.

#### Scenario: Enable action flips a feature flag for a flag-gated item
- GIVEN an operator with the `catalog.instantiate` action permission opens the PDOK WMS detail modal while it is dormant
- WHEN the operator clicks "Enable"
- THEN the system sets the `pdok.feature_flag` app-config value to enabled
- AND the catalog item's status badge updates to "available" on next status check

#### Scenario: Instantiate action creates a Source from a seeded template
- GIVEN an operator with the `catalog.instantiate` action permission opens a seeded source-template catalog item that has not yet been instantiated as a live Source
- WHEN the operator clicks "Instantiate"
- THEN a new Source object is created in the `openconnector` register from the template
- AND the response indicates the created Source's id

#### Scenario: A user without the catalog.instantiate action permission cannot enable or instantiate
- GIVEN a non-admin user whose groups are not mapped to the `catalog.instantiate` action in the admin-configured matrix (admins always pass `ActionAuthService::requireAction()` — documented break-glass behaviour)
- WHEN that user calls the instantiate endpoint for any catalog item
- THEN the request is rejected with `OCSForbiddenException` before any Source or app-config write occurs
- @e2e exclude API-level action-matrix denial (no UI surface for an unmapped user) — covered by PHPUnit `CatalogControllerTest::testInstantiateDeniedForUnmappedNonAdmin`

#### Scenario: Instantiate action still respects the Source schema's data-layer admin-only lock
- GIVEN an operator's groups ARE mapped to `catalog.instantiate` in the action matrix, but that operator is not a Nextcloud admin
- WHEN the operator calls the instantiate endpoint for a source-template catalog item
- THEN the underlying Source create call is rejected by OpenRegister's admin-only authorization on the `source` schema, independent of the action-matrix result
- @e2e exclude OpenRegister data-layer authorization (`99-source-lockdown.json`) is enforced inside OR's saveObject, not reachable as an openconnector UI flow — verified by the ocon#147 lockdown fragment; the action-layer gate is covered by PHPUnit

### Requirement: A single PHP-side adapter metadata registry is the source of truth for catalog entries (REQ-003)

The system MUST assemble catalog entries from exactly one service, `CatalogRegistryService`, which MUST source its data from (a) OpenRegister's existing `IntegrationRegistry` for adapters already registered there, (b) a static descriptor list for built-in adapters not registered there, and (c) the `register.d/*-source.json` seed fragments for seeded source templates. The frontend MUST NOT hardcode any catalog entry — every card rendered on the Catalog page MUST originate from a `catalog_item` OpenRegister object materialized by this service.

#### Scenario: A newly registered IntegrationRegistry provider appears in the catalog without a frontend change
- GIVEN a fifth `IntegrationProvider` is registered into OpenRegister's `IntegrationRegistry` by OpenConnector
- WHEN the next `CatalogRegistryService` materialization repair-step run occurs
- THEN a corresponding `catalog_item` object is created or updated
- AND it appears on the Catalog page without any change to `CatalogItemCard.vue` or the manifest
- @e2e exclude backend registry/materialisation behaviour (registering a 5th provider is not a browser flow) — covered by PHPUnit `CatalogRegistryServiceTest::testNewProviderAppearsWithoutCodeChange`

#### Scenario: Materialization is idempotent
- GIVEN a `catalog_item` object already exists for the PDOK WMS adapter with a given slug
- WHEN the materialization repair step runs again with no underlying change
- THEN the existing object is updated in place (not duplicated)
- @e2e exclude backend repair-step idempotency (occ maintenance:repair, no browser UI) — slug-keyed upsert in `MaterializeCatalogItems`; slug uniqueness covered by PHPUnit `CatalogRegistryServiceTest::testCollectAssemblesFromAllThreeSources`

## Non-Functional Requirements

- **Performance:** Catalog page list and search MUST use OpenRegister's standard object-list endpoint (no bespoke N+1 status check per card on initial render); the live per-item status re-check (REQ-002 scenarios) is deferred to the detail-modal open, not the grid render.
- **Accessibility:** Category filter chips and the search field MUST carry accessible labels (WCAG 2.1 AA), consistent with existing `CnIndexPage` facet-filter usage elsewhere in the fleet.
- **Internationalization:** All catalog item labels, category names, and action labels MUST be translatable via the existing i18n mechanism; i18n keys MUST be English source strings (per fleet convention).

## Acceptance Criteria

- [ ] Catalog page renders as a manifest-v2 `type: "index"` + `viewMode: "cards"` page — no new `nextcloud-vue` component or schema.
- [ ] Every catalog card corresponds to a real, already-shipped adapter or seeded source (no fabricated entries).
- [ ] Enable/Instantiate action is gated by both the ADR-023 action matrix and existing OpenRegister data-layer authorization.
- [ ] Catalog materialization repair step is idempotent and re-runnable without duplication.

## Notes

Deferred: promoting PDOK/Digikoppeling/Berichtenbox/DSO into full `IntegrationRegistry` `IntegrationProvider`s (see design.md Trade-offs) — this capability consumes them via a lighter descriptor list instead. A community/remote template marketplace is explicitly out of scope (see proposal.md).
