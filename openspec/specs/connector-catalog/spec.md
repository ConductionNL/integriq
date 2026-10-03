# connector-catalog Specification

## Purpose
The Store: one card per adapter integriq ships, per seeded source and per template in the connector template library, with its category, standards, tier and status, and an Enable or Instantiate action. Templates are listed without being installed; a source exists only after Instantiate. Placeholders and duplicates are not counted.

## Requirements

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

The system MUST provide a detail modal for each catalog item, opened from its card, showing the item's full description and standards, plus a primary action: "Enable" for a `flag-gated` item, or "Instantiate" for a `mock-seeded` or `always-available` item. The action MUST be gated at the action layer by Integriq's existing ADR-023 implementation — `ActionAuthService::requireAction()` (`lib/Service/ActionAuthService.php`) against a new `catalog.instantiate` action key seeded `["admin"]` in the existing `lib/actions.seed.json` (following its established `<domain>.<verb>` naming, e.g. `source.test`, `job.run`) — and MUST still pass through the underlying OpenRegister data-layer authorization for the object being created or updated (e.g. the `source` schema's admin-only lock). The catalog action MUST NOT introduce a new authorization service or a bypass of existing data-layer authorization.

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
- @e2e exclude OpenRegister data-layer authorization (`99-source-lockdown.json`) is enforced inside OR's saveObject, not reachable as an integriq UI flow — verified by the ocon#147 lockdown fragment; the action-layer gate is covered by PHPUnit

### Requirement: A single PHP-side adapter metadata registry is the source of truth for catalog entries (REQ-003)

The system MUST assemble catalog entries from exactly one service, `CatalogRegistryService`, which MUST source its data from (a) OpenRegister's existing `IntegrationRegistry` for adapters already registered there, (b) a static descriptor list for built-in adapters not registered there, and (c) the `register.d/*-source.json` seed fragments for seeded source templates. The frontend MUST NOT hardcode any catalog entry — every card rendered on the Catalog page MUST originate from a `catalog_item` OpenRegister object materialized by this service.

#### Scenario: A newly registered IntegrationRegistry provider appears in the catalog without a frontend change
- GIVEN a fifth `IntegrationProvider` is registered into OpenRegister's `IntegrationRegistry` by Integriq
- WHEN the next `CatalogRegistryService` materialization repair-step run occurs
- THEN a corresponding `catalog_item` object is created or updated
- AND it appears on the Catalog page without any change to `CatalogItemCard.vue` or the manifest
- @e2e exclude backend registry/materialisation behaviour (registering a 5th provider is not a browser flow) — covered by PHPUnit `CatalogRegistryServiceTest::testNewProviderAppearsWithoutCodeChange`

#### Scenario: Materialization is idempotent
- GIVEN a `catalog_item` object already exists for the PDOK WMS adapter with a given slug
- WHEN the materialization repair step runs again with no underlying change
- THEN the existing object is updated in place (not duplicated)
- @e2e exclude backend repair-step idempotency (occ maintenance:repair, no browser UI) — slug-keyed upsert in `MaterializeCatalogItems`; slug uniqueness covered by PHPUnit `CatalogRegistryServiceTest::testCollectAssemblesFromAllThreeSources`

### Requirement: The Store lists templates it does not install (REQ-CCX-001)

The catalogue registry MUST list every template in
`lib/Settings/connector-templates/` as a Store card, and the register import
MUST NOT create a `source` object for any of them. Instantiating a template
from the Store MUST create one `source` from that template's payload.

#### Scenario: a fresh install has no template sources
- GIVEN a fresh install with the template library present
- WHEN an administrator opens the Sources page
- THEN no source from the library is listed, and the Store shows the library's cards
- e2e: `tests/e2e/connector-catalogue.spec.ts`

#### Scenario: an administrator instantiates a Salesforce template
- GIVEN the Salesforce card in the Store
- WHEN an administrator chooses Instantiate
- THEN one Salesforce source exists with the template's base URL and auth scheme, and no secret
- e2e: `tests/e2e/connector-catalogue.spec.ts`

### Requirement: A back-office template names its standard and where it was checked (REQ-CCX-002)

Every curated template MUST carry `vendor`, `system`, `standard` and
`verifiedAgainst`, and MUST configure an interface integriq already speaks.
Every system named in the Stein tender requirements MUST end with either a
curated template or a recorded reason in the back-office README.

#### Scenario: a buyer finds the GWS connector and its standard
- GIVEN a municipality evaluating integriq against the Stein requirements
- WHEN an administrator searches the Store for GWS
- THEN either a card names Centric GWS with the standard it is reached over, or the back-office README states why no template exists
- e2e: `tests/e2e/connector-catalogue.spec.ts`

#### Scenario: a template without a checked source is rejected
- GIVEN a curated template with no `verifiedAgainst`
- WHEN the library is validated
- THEN validation fails naming the file
- @e2e exclude a build-time validation; covered by `tests/validate-connector-templates.js`

### Requirement: Generated SaaS templates come from a pinned directory and a reviewed allow-list (REQ-CCX-003)

Generated templates MUST be produced from a committed snapshot of the
APIs.guru OpenAPI directory, only for entries on
`lib/Settings/connector-templates/saas/allow-list.json`. Each MUST carry
`tier: generated` and the snapshot date, MUST name the auth scheme and MUST
NOT carry a credential. The Store card MUST say the template is generated.

#### Scenario: Google Sheets, Salesforce and Slack are in the Store
- GIVEN the first allow-list
- WHEN an administrator opens the Store
- THEN Google Sheets and Slack are listed, each marked generated with its snapshot date, and Salesforce is listed as a checked template, because the directory's only Salesforce entry is Einstein Vision and Language, not the CRM API
- e2e: `tests/e2e/connector-catalogue.spec.ts`

#### Scenario: an entry off the allow-list never appears
- GIVEN an API in the snapshot that is not on the allow-list
- WHEN the generator runs
- THEN no template is written for it
- @e2e exclude a build-time script; covered by PHPUnit on the generator

### Requirement: The Store counts only real connectors, once each (REQ-CCX-004)

The Store MUST NOT list a source whose slug starts with `environment-`, and
MUST list a system that has both an adapter and a template once, as the
adapter. Every card MUST carry its tier, and the Store MUST offer a filter per
template tier, so the count per tier is the filtered count.

#### Scenario: placeholders are gone from the count
- GIVEN a fresh install
- WHEN an administrator opens the Store
- THEN no card names `environment-local-source` or `environment-acceptance-source`, and SmartDocuments and Xential appear once each
- e2e: `tests/e2e/connector-catalogue.spec.ts`
