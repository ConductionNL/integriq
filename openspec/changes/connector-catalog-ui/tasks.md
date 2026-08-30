# Tasks: connector-catalog-ui

## Implementation Tasks

### Task 1: Seed the three new actions into the existing ADR-023 matrix
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002`
- **files**: `lib/actions.seed.json`
- **acceptance_criteria**:
  - GIVEN the existing `lib/actions.seed.json` (38 actions, `<domain>.<verb>` convention — `source.test`, `job.run`, `pdok.suggest`) WHEN `catalog.instantiate`, `configuration.export`, `configuration.import` are appended, each `["admin"]` THEN the existing `lib/Repair/InitializeActions.php` applies them on its next run and the existing admin Action authorization panel (`ActionMatrixController`) lists all three
  - No new auth service or controller is created — the existing `lib/Service/ActionAuthService.php::requireAction()` is reused unchanged by Tasks 5, 8, 9 and 10
- [ ] Implement
- [ ] Test

### Task 2: catalog_item schema fragment
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001`
- **files**: `lib/Settings/register.d/catalog-item-schema.json`
- **acceptance_criteria**:
  - GIVEN the fragment is shipped WHEN `occ upgrade` runs THEN `catalog_item` appears as a schema under the `openconnector` register with fields `name`, `description`, `category`, `kind`, `mechanism`, `flagKey`, `sourceTemplateSlug`, `standards`, `icon`
- [ ] Implement
- [ ] Test

### Task 3: CatalogRegistryService — assemble catalog entries from existing sources
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-a-single-php-side-adapter-metadata-registry-is-the-source-of-truth-for-catalog-entries-req-003`
- **files**: `lib/Service/CatalogRegistryService.php`
- **acceptance_criteria**:
  - GIVEN OpenRegister's `IntegrationRegistry` has 4 registered providers WHEN `CatalogRegistryService::collect()` runs THEN it returns 4 descriptor entries sourced from that registry, plus static entries for PDOK/Digikoppeling/Berichtenbox/DSO, plus one entry per `register.d/*-source.json` seed fragment found
  - GIVEN a fifth provider is registered into `IntegrationRegistry` WHEN `collect()` runs again THEN a 5th entry appears with no code change to the static descriptor list
- [ ] Implement
- [ ] Test

### Task 4: MaterializeCatalogItems repair step
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-a-single-php-side-adapter-metadata-registry-is-the-source-of-truth-for-catalog-entries-req-003`
- **files**: `lib/Repair/MaterializeCatalogItems.php`, `lib/AppInfo/Application.php`
- **acceptance_criteria**:
  - GIVEN `CatalogRegistryService::collect()` returns N entries WHEN the repair step runs THEN N `catalog_item` objects exist, keyed by stable `kind:slug`
  - GIVEN the repair step runs a second time with no underlying change WHEN it completes THEN the object count is unchanged (idempotent upsert, not duplicate creation)
- [ ] Implement
- [ ] Test

### Task 5: CatalogController — status + instantiate endpoints
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002`
- **files**: `lib/Controller/CatalogController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a flag-gated catalog item WHEN `GET /api/catalog/items/{id}/status` is called THEN the response reflects the live `IConfig` value for its `flagKey`
  - GIVEN an operator with `catalog.instantiate` permission WHEN `POST /api/catalog/items/{id}/instantiate` is called on a flag-gated item THEN the app-config flag is enabled
  - GIVEN an operator with `catalog.instantiate` permission WHEN the same endpoint is called on a mock-seeded/template item not yet instantiated THEN a new Source object is created
  - GIVEN a user without `catalog.instantiate` permission WHEN either endpoint is called THEN the request is rejected before any write
  - GIVEN an operator with `catalog.instantiate` permission but not a Nextcloud admin WHEN instantiate is called THEN the underlying Source write is still rejected by the `source` schema's admin-only OpenRegister authorization
- [ ] Implement
- [ ] Test

### Task 6: Catalog manifest page + card component
- **spec_ref**: `openspec/specs/openconnector-app-manifest/spec.md#requirement-manifest-must-declare-a-catalog-page-and-menu-entry`
- **files**: `src/manifest.json`, `src/components/CatalogItemCard.vue`, `src/store/catalog.js`
- **acceptance_criteria**:
  - GIVEN the manifest is loaded WHEN inspecting the `Catalog` page entry THEN `type` is `"index"`, `config.viewMode` is `"cards"`, `config.register`/`config.schema` are `"openconnector"`/`"catalog_item"`
  - GIVEN the manifest is loaded WHEN inspecting `menu` THEN a `Catalog` entry routes to the `Catalog` page id
  - GIVEN the Catalog page is open WHEN the category filter is applied THEN only matching cards render (uses `CnIndexPage`'s existing `filters` config, no new component logic beyond the card itself)
- [ ] Implement
- [ ] Test

### Task 7: Catalog detail modal (Enable / Instantiate)
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002`
- **files**: `src/dialogs/CatalogItemDetailDialog.vue`
- **acceptance_criteria**:
  - GIVEN a card is clicked WHEN the detail dialog opens THEN it shows description, standards, and a live status re-check via `GET /api/catalog/items/{id}/status`
  - GIVEN the item is dormant WHEN the operator clicks the primary action THEN the correct endpoint (Enable vs Instantiate) is called based on `mechanism`
- [ ] Implement
- [ ] Test

### Task 8: ConfigurationController — export
- **spec_ref**: `openspec/specs/configuration-export-import/spec.md#requirement-req-006-export-a-configuration-from-the-ui`
- **files**: `lib/Controller/ConfigurationController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a configuration group with a Source containing credentials WHEN `POST /api/configurations/{id}/export` is called by an authorized operator THEN a redacted JSON file is returned matching the existing `ConfigurationService::exportConfiguration()` output unchanged
  - GIVEN a user without `configuration.export` permission WHEN the endpoint is called THEN the request is rejected
- [ ] Implement
- [ ] Test

### Task 9: ConfigurationController — import preview (non-mutating)
- **spec_ref**: `openspec/specs/configuration-export-import/spec.md#requirement-req-007-preview-an-import-before-writing-anything`
- **files**: `lib/Controller/ConfigurationController.php`, `lib/Service/ConfigurationImportPreviewService.php`
- **acceptance_criteria**:
  - GIVEN an OAS document with one existing-slug and one new-slug Source WHEN `POST /api/configurations/import/preview` is called THEN the response correctly classifies each under `updates`/`creates` and no object is written
  - GIVEN an OAS document with a Rule referencing an unresolvable Source slug WHEN previewed THEN `unresolvedReferences` lists it
- [ ] Implement
- [ ] Test

### Task 10: ConfigurationController — confirmed import
- **spec_ref**: `openspec/specs/configuration-export-import/spec.md#requirement-req-008-import-requires-explicit-confirmation-after-preview`
- **files**: `lib/Controller/ConfigurationController.php`
- **acceptance_criteria**:
  - GIVEN `confirmed` is omitted or false WHEN `POST /api/configurations/import` is called THEN the response is HTTP 400 and nothing is written
  - GIVEN `confirmed: true` WHEN called THEN the system delegates unchanged to `ConfigurationService::importConfiguration()` and returns what was created/updated
- [ ] Implement
- [ ] Test

### Task 11: Credential re-entry flagging in import response
- **spec_ref**: `openspec/specs/configuration-export-import/spec.md#requirement-req-009-imported-sources-with-redacted-credentials-are-flagged-for-re-entry`
- **files**: `lib/Service/ConfigurationImportPreviewService.php`
- **acceptance_criteria**:
  - GIVEN an imported Source document with no credential fields WHEN import completes THEN the response's `credentialsNeedingReentry` lists that Source's slug and missing field names
- [ ] Implement
- [ ] Test

### Task 12: Configuration import/export UI page + preview dialog
- **spec_ref**: `openspec/specs/configuration-export-import/spec.md#requirement-req-006-export-a-configuration-from-the-ui`
- **files**: `src/manifest.json`, `src/dialogs/ImportPreviewDialog.vue`
- **acceptance_criteria**:
  - GIVEN an operator uploads an OAS document WHEN the preview dialog opens THEN it shows creates/updates/collisions/unresolved-references, and any unresolved reference blocks confirmation until acknowledged
  - GIVEN the operator confirms WHEN the import completes THEN a post-import summary shows `credentialsNeedingReentry` with links to each Source's edit form
- [ ] Implement
- [ ] Test

### Task 13: PHPUnit — CatalogRegistryService + import preview diff logic
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-a-single-php-side-adapter-metadata-registry-is-the-source-of-truth-for-catalog-entries-req-003`
- **files**: `tests/Unit/Service/CatalogRegistryServiceTest.php`, `tests/Unit/Service/ConfigurationImportPreviewServiceTest.php`
- **acceptance_criteria**:
  - GIVEN mocked `IntegrationRegistry` + seed fragments WHEN `collect()` is unit-tested THEN entry count and shape are asserted
  - GIVEN a fixture OAS document with a known create/update/collision/unresolved mix WHEN the preview service is unit-tested THEN each category is asserted
- [ ] Implement
- [ ] Test

### Task 14: vitest — catalog Pinia store
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001`
- **files**: `src/store/catalog.spec.js`
- **acceptance_criteria**:
  - GIVEN the catalog store is loaded WHEN filtering by category or search term THEN the store's filtered getter returns the expected subset
- [ ] Implement
- [ ] Test

### Task 15: Playwright e2e — catalog browse + import flow
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001`
- **files**: `tests/playwright/catalog-browse.spec.js`, `tests/playwright/configuration-import.spec.js`
- **acceptance_criteria**:
  - GIVEN a logged-in admin WHEN they navigate to `/catalog`, filter by category, open a detail modal, and instantiate a seeded source THEN the new Source appears in the Sources list
  - GIVEN an admin exports a configuration, then re-imports the downloaded file WHEN the preview dialog appears THEN it shows the expected update classification and completing the import re-flags credential re-entry
- [ ] Implement
- [ ] Test

## Verification
- [ ] All tasks checked off
- [ ] `openspec validate` passes
- [ ] Manual testing against acceptance criteria
- [ ] Code review against spec requirements

## Tests (company-wide ADR-009)

- [ ] PHPUnit unit tests for new/changed business logic (`tests/Unit/`)
- [ ] Newman/Postman tests for new/changed API endpoints
- [ ] Browser tests (Playwright MCP) for UI changes
- [ ] All tests pass (`composer test`, `newman run`)

## Documentation (company-wide ADR-010)

- [ ] Feature documentation updated in `docs/`
- [ ] Screenshot captured and committed to `docs/images/`

## i18n (company-wide hydra ADR-007)

- [ ] Dutch (`nl_NL`) and English (`en_US`) translation strings added for Catalog page labels, category names, status badges, and import-preview dialog text
