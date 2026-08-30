# Test Plan: connector-catalog-ui

## Test Cases

### TC-1: Catalog lists items grouped by category
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001`
- **type**: functional
- **persona**: Noor (municipal CISO / functional admin — the operator who onboards new connectors)
- **preconditions**: `catalog_item` objects materialised for PDOK WMS, BRP HaalCentraal, S3 adapter
- **steps**: navigate to `/catalog`; apply the "Government registers" category filter
- **expected result**: only the BRP HaalCentraal card remains visible
- **test command**: `/test-functional`

### TC-2: Status badge distinguishes flag-gated dormant vs mock-seeded available
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001`
- **type**: functional
- **persona**: Noor
- **preconditions**: `pdok.feature_flag` unset (default off); BRP source `isEnabled: true`, `configuration.mock: true`
- **steps**: open `/catalog`; inspect the PDOK WMS and BRP HaalCentraal cards
- **expected result**: PDOK WMS badge reads "dormant"; BRP HaalCentraal badge reads "available"
- **test command**: `/test-functional`

### TC-3: Search narrows the catalog grid
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001`
- **type**: functional
- **preconditions**: catalog populated with ≥3 items, only one matching "brp"
- **steps**: type "brp" into the catalog search field
- **expected result**: only the matching item(s) remain visible
- **test command**: `/test-functional`

### TC-4: Enable action flips a feature flag (flag-gated item)
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002`
- **type**: functional
- **persona**: Noor
- **preconditions**: operator has `catalog.instantiate` action permission; PDOK WMS dormant
- **steps**: open PDOK WMS detail modal; click "Enable"
- **expected result**: `pdok.feature_flag` becomes enabled; status badge updates to "available" on next check
- **test command**: `/test-functional`

### TC-5: Instantiate action creates a Source from a seeded template
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002`
- **type**: functional
- **persona**: Noor
- **preconditions**: operator has `catalog.instantiate` permission; a not-yet-instantiated source-template item exists
- **steps**: open its detail modal; click "Instantiate"
- **expected result**: a new Source object appears in the Sources list
- **test command**: `/test-functional`

### TC-6: catalog.instantiate action denial blocks the write (API)
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002`
- **type**: security
- **preconditions**: a non-admin user whose groups are NOT mapped to `catalog.instantiate` (admins always pass the existing `ActionAuthService::requireAction()` break-glass)
- **steps**: `POST /api/catalog/items/{id}/instantiate` as that user
- **expected result**: request rejected (403 via `OCSForbiddenException` from the existing `ActionAuthService`), no Source or app-config write occurs
- **test command**: `/test-security`

### TC-7: Action-matrix pass but data-layer admin-only lock still blocks a non-admin
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002`
- **type**: security
- **preconditions**: a non-admin user IS mapped to `catalog.instantiate` in the action matrix
- **steps**: `POST /api/catalog/items/{id}/instantiate` (source-template item) as that user
- **expected result**: the underlying Source create is rejected by OpenRegister's admin-only `source` schema authorization, independent of the action-matrix pass
- **test command**: `/test-security`

### TC-8: Catalog materialises new IntegrationRegistry providers with no frontend change
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/connector-catalog/spec.md#requirement-a-single-php-side-adapter-metadata-registry-is-the-source-of-truth-for-catalog-entries-req-003`
- **type**: api
- **preconditions**: a 5th `IntegrationProvider` registered into `IntegrationRegistry`
- **steps**: run the `MaterializeCatalogItems` repair step; call `GET /apps/openregister/api/objects/openconnector/catalog_item`
- **expected result**: a 5th `catalog_item` object exists
- **test command**: `/test-api`

### TC-9: Materialization is idempotent across repeated runs
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/connector-catalog/spec.md#requirement-a-single-php-side-adapter-metadata-registry-is-the-source-of-truth-for-catalog-entries-req-003`
- **type**: api
- **preconditions**: `catalog_item` objects already materialised once
- **steps**: run the repair step a second time with no underlying change
- **expected result**: object count unchanged, no duplicates
- **test command**: `/test-api`

### TC-10: Export from UI produces a redacted file
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/configuration-export-import/spec.md#requirement-req-006-export-a-configuration-from-the-ui`
- **type**: functional
- **persona**: Noor
- **preconditions**: a configuration group with a Source carrying `apikey`/`secret`; operator has `configuration.export` permission
- **steps**: open the Configuration UI page for that group; click "Export"
- **expected result**: downloaded JSON file contains no `apikey`, `secret`, or other REQ-005-redacted field
- **test command**: `/test-functional`

### TC-11: configuration.export action denial blocks export (API)
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/configuration-export-import/spec.md#requirement-req-006-export-a-configuration-from-the-ui`
- **type**: security
- **preconditions**: non-admin user not mapped to `configuration.export` (admins always pass the existing `ActionAuthService` break-glass)
- **steps**: `POST /api/configurations/{id}/export`
- **expected result**: 403, no file produced
- **test command**: `/test-security`

### TC-12: Import preview classifies creates/updates/collisions without writing
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/configuration-export-import/spec.md#requirement-req-007-preview-an-import-before-writing-anything`
- **type**: api
- **preconditions**: OAS document with one existing-slug Source and one new-slug Source
- **steps**: `POST /api/configurations/import/preview`
- **expected result**: response correctly classifies each; no object is created or modified
- **test command**: `/test-api`

### TC-13: Preview surfaces an unresolvable slug reference as a blocking warning
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/configuration-export-import/spec.md#requirement-req-007-preview-an-import-before-writing-anything`
- **type**: functional
- **preconditions**: OAS document with a Rule referencing a non-existent Source slug
- **steps**: upload the document in the import UI; observe the preview dialog
- **expected result**: `unresolvedReferences` is shown; the confirm button is disabled until the operator explicitly acknowledges the warning
- **test command**: `/test-functional`

### TC-14: Import without confirmation is rejected
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/configuration-export-import/spec.md#requirement-req-008-import-requires-explicit-confirmation-after-preview`
- **type**: api
- **preconditions**: valid OAS document
- **steps**: `POST /api/configurations/import` with `confirmed` omitted
- **expected result**: HTTP 400, nothing written
- **test command**: `/test-api`

### TC-15: Confirmed import writes via the existing unchanged pipeline
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/configuration-export-import/spec.md#requirement-req-008-import-requires-explicit-confirmation-after-preview`
- **type**: api
- **preconditions**: valid OAS document
- **steps**: `POST /api/configurations/import` with `confirmed: true`
- **expected result**: response reflects actual creates/updates; entities appear in their respective index pages
- **test command**: `/test-api`

### TC-16: Imported Source with stripped credentials is flagged for re-entry
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/configuration-export-import/spec.md#requirement-req-009-imported-sources-with-redacted-credentials-are-flagged-for-re-entry`
- **type**: functional
- **persona**: Noor
- **preconditions**: import document containing a Source with no credential fields (post-REQ-005 export)
- **steps**: confirm the import; view the post-import summary
- **expected result**: `credentialsNeedingReentry` lists the Source and its missing fields, with a link to its edit form; the created Source itself has no credential values
- **test command**: `/test-functional`

### TC-17: Catalog page manifest conformance
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/openconnector-app-manifest/spec.md#requirement-manifest-must-declare-a-catalog-page-and-menu-entry`
- **type**: regression
- **preconditions**: `src/manifest.json` updated
- **steps**: run `check:manifest` (existing `validateManifest` script per `openconnector-app-manifest` spec)
- **expected result**: validation passes with zero errors; Catalog page/menu entries present as specified
- **test command**: `/test-regression`

### TC-18: Existing pages/routes unaffected (regression)
- **spec_ref**: `openspec/changes/connector-catalog-ui/proposal.md#impact`
- **type**: regression
- **preconditions**: full manifest with Catalog added
- **steps**: spot-check Sources, Endpoints, Jobs, Synchronizations pages and their existing actions
- **expected result**: no behavioural change to any pre-existing page
- **test command**: `/test-regression`

### TC-19: Accessibility of catalog search/filter controls
- **spec_ref**: `openspec/changes/connector-catalog-ui/specs/connector-catalog/spec.md#non-functional-requirements`
- **type**: accessibility
- **preconditions**: Catalog page rendered
- **steps**: run automated WCAG 2.1 AA check against the search field and category filter chips
- **expected result**: no critical/serious violations; controls carry accessible labels
- **test command**: `/test-accessibility`

## Coverage Summary

| Requirement | Covered by |
|---|---|
| connector-catalog REQ-001 (list + filter + status badges) | TC-1, TC-2, TC-3, TC-19 |
| connector-catalog REQ-002 (Enable/Instantiate authorized action) | TC-4, TC-5, TC-6, TC-7 |
| connector-catalog REQ-003 (single registry source of truth) | TC-8, TC-9 |
| configuration-export-import REQ-006 (export from UI) | TC-10, TC-11 |
| configuration-export-import REQ-007 (import preview) | TC-12, TC-13 |
| configuration-export-import REQ-008 (confirmation required) | TC-14, TC-15 |
| configuration-export-import REQ-009 (credential re-entry flagging) | TC-16 |
| openconnector-app-manifest (Catalog page + menu entry) | TC-17, TC-18 |

## Out of Scope

- Load/performance testing of the O(all entities) export cost — pre-existing, documented limitation (`configuration-export-import` REQ-001 Notes), not changed or newly tested by this change.
- Testing a remote/community template marketplace — explicitly out of scope per proposal.md.
- Full environments/credential-rebinding promotion flow — deferred per proposal.md until `source-broker-credentials` lands.
