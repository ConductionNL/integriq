# openconnector-app-manifest Specification (delta: connector-catalog-ui)

## ADDED Requirements

### Requirement: Manifest MUST declare a Catalog page and menu entry

The manifest `pages` array MUST contain an entry with `id: "Catalog"`, `route: "/catalog"`, `type: "index"`, backed by `config.register: "openconnector"` and `config.schema: "catalog_item"`, with `config.viewMode: "cards"` and a `config.cardComponent` set. The manifest `menu` array MUST contain a corresponding entry (`id: "Catalog"`, `route: "Catalog"`) so the page is reachable from primary navigation.

Notes: This requirement is scoped narrowly to the Catalog addition. It does not attempt to reconcile the base `openconnector-app-manifest` spec's existing drift against `src/manifest.json` at HEAD (missing `roadmap` type in the type enum, stale page count/list, a phantom `Import` page, `Settings` vs. `AppSettings` id mismatch, flat-vs-grouped menu) — that drift predates this change and is out of scope here (see connector-catalog-ui proposal.md Out of Scope).

#### Scenario: Catalog page entry is present and uses the cards index pattern
- GIVEN the manifest file is loaded
- WHEN inspecting the page with id `"Catalog"`
- THEN its `type` field MUST be `"index"`
- AND `config.viewMode` MUST be `"cards"`
- AND `config.register` MUST be `"openconnector"` and `config.schema` MUST be `"catalog_item"`

#### Scenario: Catalog menu entry is present and routes to the Catalog page
- GIVEN the manifest file is loaded
- WHEN inspecting `manifest.menu` (including nested `children` arrays, per the existing grouped-nav structure)
- THEN an entry with id `"Catalog"` MUST exist
- AND its `route` MUST equal `"Catalog"`, matching the `pages[].id` of the Catalog page entry

#### Scenario: Catalog page does not require a new manifest page type
- GIVEN the manifest schema's `pages[].type` enum
- WHEN validating the Catalog page entry against it
- THEN validation succeeds using the existing `"index"` type — no new type value is introduced by this change
