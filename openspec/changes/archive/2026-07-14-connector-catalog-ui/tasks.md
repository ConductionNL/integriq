# Tasks: connector-catalog-ui

## Implementation Tasks

### Task 1: Seed the three new actions into the existing ADR-023 matrix
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002`
- **files**: `lib/actions.seed.json`
- **acceptance_criteria**:
  - GIVEN the existing `lib/actions.seed.json` (38 actions, `<domain>.<verb>` convention — `source.test`, `job.run`, `pdok.suggest`) WHEN `catalog.instantiate`, `configuration.export`, `configuration.import` are appended, each `["admin"]` THEN the existing `lib/Repair/InitializeActions.php` applies them on its next run and the existing admin Action authorization panel (`ActionMatrixController`) lists all three
  - No new auth service or controller is created — the existing `lib/Service/ActionAuthService.php::requireAction()` is reused unchanged by Tasks 5, 8, 9 and 10
- [x] Implement
- [x] Test — the three keys' enforcement (default-deny for unmapped non-admins, admin break-glass pass) is exercised by `CatalogControllerTest` and `ConfigurationControllerTest` against a real `ActionAuthService`; seed application by `InitializeActions` is the pre-existing, already-shipped mechanism (the "panel lists all three" leg needs a live instance — see Verification note)

### Task 2: catalog_item schema fragment
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001`
- **files**: `lib/Settings/register.d/catalog-item-schema.json`
- **acceptance_criteria**:
  - GIVEN the fragment is shipped WHEN `occ upgrade` runs THEN `catalog_item` appears as a schema under the `openconnector` register with fields `name`, `description`, `category`, `kind`, `mechanism`, `flagKey`, `sourceTemplateSlug`, `standards`, `icon`
- [x] Implement
- [ ] Test — structural validation passes (`npm run check:register` / `check:json-strict` PASS, and the fragment-merge path is covered by the pre-existing `RegisterFragmentMergeTest`), but the acceptance as written (`occ upgrade` materialises the schema under the register) requires a live Nextcloud instance, which this build environment does not have; the repair step applies it automatically on the next deploy

### Task 3: CatalogRegistryService — assemble catalog entries from existing sources
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-a-single-php-side-adapter-metadata-registry-is-the-source-of-truth-for-catalog-entries-req-003`
- **files**: `lib/Service/CatalogRegistryService.php`
- **acceptance_criteria**:
  - GIVEN OpenRegister's `IntegrationRegistry` has 4 registered providers WHEN `CatalogRegistryService::collect()` runs THEN it returns 4 descriptor entries sourced from that registry, plus static entries for PDOK/Digikoppeling/Berichtenbox/DSO, plus one entry per `register.d/*-source.json` seed fragment found
  - GIVEN a fifth provider is registered into `IntegrationRegistry` WHEN `collect()` runs again THEN a 5th entry appears with no code change to the static descriptor list
- [x] Implement
- [x] Test — `tests/Unit/Service/CatalogRegistryServiceTest.php` (7 tests: three-source assembly, 5th-provider pickup, mechanism/category mapping, flag-gated + mock-seeded status resolution, seed-payload lookup)

### Task 4: MaterializeCatalogItems repair step
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-a-single-php-side-adapter-metadata-registry-is-the-source-of-truth-for-catalog-entries-req-003`
- **files**: `lib/Repair/MaterializeCatalogItems.php`, `appinfo/info.xml` (registered as a `<post-migration>` repair step alongside `InitializeRegister`/`InitializeActions` — the repo's actual repair-step wiring lives in info.xml, not `Application.php` as this task's file list sketched)
- **acceptance_criteria**:
  - GIVEN `CatalogRegistryService::collect()` returns N entries WHEN the repair step runs THEN N `catalog_item` objects exist, keyed by stable `kind:slug`
  - GIVEN the repair step runs a second time with no underlying change WHEN it completes THEN the object count is unchanged (idempotent upsert, not duplicate creation)
- [x] Implement
- [x] Test — `tests/Unit/Repair/MaterializeCatalogItemsTest.php` (first run creates with no uuid; re-run passes the existing object's uuid to saveObject — in-place update, no duplicate)

### Task 5: CatalogController — status + instantiate endpoints
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002`
- **files**: `lib/Controller/CatalogController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a flag-gated catalog item WHEN `GET /api/catalog/items/{id}/status` is called THEN the response reflects the live `IConfig` value for its `flagKey`
  - GIVEN an operator with `catalog.instantiate` permission WHEN `POST /api/catalog/items/{id}/instantiate` is called on a flag-gated item THEN the app-config flag is enabled
  - GIVEN an operator with `catalog.instantiate` permission WHEN the same endpoint is called on a mock-seeded/template item not yet instantiated THEN a new Source object is created
  - GIVEN a user without `catalog.instantiate` permission WHEN either endpoint is called THEN the request is rejected before any write
  - GIVEN an operator with `catalog.instantiate` permission but not a Nextcloud admin WHEN instantiate is called THEN the underlying Source write is still rejected by the `source` schema's admin-only OpenRegister authorization
- [x] Implement
- [x] Test — `tests/Unit/Controller/CatalogControllerTest.php` (8 tests: live flag status, 404, denial-before-write, flag flip, 409 idempotency guard, enable-existing-source, create-from-seed-template, 401s). The final criterion (OR data-layer rejection) is enforced inside OpenRegister by `99-source-lockdown.json` (ocon#147) and cannot be unit-tested from this app — reason-bearing @e2e exclude recorded in the spec

### Task 6: Catalog manifest page + card component
- **spec_ref**: `openspec/specs/openconnector-app-manifest/spec.md#requirement-manifest-must-declare-a-catalog-page-and-menu-entry`
- **files**: `src/manifest.json`, `src/components/CatalogItemCard.vue`, `src/store/catalog.js`
- **acceptance_criteria**:
  - GIVEN the manifest is loaded WHEN inspecting the `Catalog` page entry THEN `type` is `"index"`, `config.viewMode` is `"cards"`, `config.register`/`config.schema` are `"openconnector"`/`"catalog_item"`
  - GIVEN the manifest is loaded WHEN inspecting `menu` THEN a `Catalog` entry routes to the `Catalog` page id
  - GIVEN the Catalog page is open WHEN the category filter is applied THEN only matching cards render (uses `CnIndexPage`'s existing `filters` config, no new component logic beyond the card itself)
- [x] Implement — kind facets via `config.quickFilters` (the shipped CnIndexPage facet-chip mechanism; a bare `config.filters` key does not exist as a CnIndexPage prop), category facets via the enabled sidebar; the store's `filteredItems` getter carries the search+category narrowing logic
- [x] Test — `npm run check:manifest` (Ajv against app-manifest-v2.schema 2.19.0: PASS, 0 errors) covers criteria 1+2; criterion 3 covered by `tests/vitest/catalogStore.spec.js` filter tests plus the static manifest-conformance tests in `tests/e2e/spec-coverage/connector-catalog.spec.ts`

### Task 7: Catalog detail modal (Enable / Instantiate)
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-catalog-detail-modal-offers-an-authorized-enable-or-instantiate-action-req-002`
- **files**: `src/dialogs/CatalogItemDetailDialog.vue`
- **acceptance_criteria**:
  - GIVEN a card is clicked WHEN the detail dialog opens THEN it shows description, standards, and a live status re-check via `GET /api/catalog/items/{id}/status`
  - GIVEN the item is dormant WHEN the operator clicks the primary action THEN the correct endpoint (Enable vs Instantiate) is called based on `mechanism`
- [x] Implement — dialog in its own file (modal-isolation gate), mounted once in `ModalHost`, opened via the modal bus from `CatalogItemCard`
- [ ] Test — behavioural coverage lives in `tests/e2e/spec-coverage/connector-catalog.spec.ts` (@e2e-tagged), written per the test plan but NOT executed: no live Nextcloud instance in this build environment. The store calls the dialog makes (fetchStatus/instantiate) are vitest-covered

### Task 8: ConfigurationController — export
- **spec_ref**: `openspec/specs/configuration-export-import/spec.md#requirement-req-006-export-a-configuration-from-the-ui`
- **files**: `lib/Controller/ConfigurationController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a configuration group with a Source containing credentials WHEN `POST /api/configurations/{id}/export` is called by an authorized operator THEN a redacted JSON file is returned matching the existing `ConfigurationService::exportConfiguration()` output unchanged
  - GIVEN a user without `configuration.export` permission WHEN the endpoint is called THEN the request is rejected
- [x] Implement
- [x] Test — `tests/Unit/Controller/ConfigurationControllerTest.php` (attachment header + unchanged delegation; 403 for unmapped non-admin; the redaction itself is the untouched, already-tested `ConfigurationService`/`SourceHandler` REQ-005 path)

### Task 9: ConfigurationController — import preview (non-mutating)
- **spec_ref**: `openspec/specs/configuration-export-import/spec.md#requirement-req-007-preview-an-import-before-writing-anything`
- **files**: `lib/Controller/ConfigurationController.php`, `lib/Service/ConfigurationImportPreviewService.php`
- **acceptance_criteria**:
  - GIVEN an OAS document with one existing-slug and one new-slug Source WHEN `POST /api/configurations/import/preview` is called THEN the response correctly classifies each under `updates`/`creates` and no object is written
  - GIVEN an OAS document with a Rule referencing an unresolvable Source slug WHEN previewed THEN `unresolvedReferences` lists it
- [x] Implement
- [x] Test — `tests/Unit/Service/ConfigurationImportPreviewServiceTest.php` (7 tests: creates/updates with `saveObject` never called, cross-schema collision, unresolved nested rule reference, resolvable reference not flagged, endpoint top-level references, credential flagging, missing-components throw) plus controller-level preview tests

### Task 10: ConfigurationController — confirmed import
- **spec_ref**: `openspec/specs/configuration-export-import/spec.md#requirement-req-008-import-requires-explicit-confirmation-after-preview`
- **files**: `lib/Controller/ConfigurationController.php`
- **acceptance_criteria**:
  - GIVEN `confirmed` is omitted or false WHEN `POST /api/configurations/import` is called THEN the response is HTTP 400 and nothing is written
  - GIVEN `confirmed: true` WHEN called THEN the system delegates unchanged to `ConfigurationService::importConfiguration()` and returns what was created/updated
- [x] Implement
- [x] Test — `ConfigurationControllerTest` (400 on omitted AND explicit-false `confirmed` with `importConfiguration` never called; confirmed path delegates the exact document and reports `written` per type)

### Task 11: Credential re-entry flagging in import response
- **spec_ref**: `openspec/specs/configuration-export-import/spec.md#requirement-req-009-imported-sources-with-redacted-credentials-are-flagged-for-re-entry`
- **files**: `lib/Service/ConfigurationImportPreviewService.php`
- **acceptance_criteria**:
  - GIVEN an imported Source document with no credential fields WHEN import completes THEN the response's `credentialsNeedingReentry` lists that Source's slug and missing field names
- [x] Implement
- [x] Test — `ConfigurationImportPreviewServiceTest::testCredentialsNeedingReentryFlagsStrippedSources` + `ConfigurationControllerTest::testConfirmedImportDelegatesToExistingPipeline` (flag present in the post-import summary)

### Task 12: Configuration import/export UI page + preview dialog
- **spec_ref**: `openspec/specs/configuration-export-import/spec.md#requirement-req-006-export-a-configuration-from-the-ui`
- **files**: `src/manifest.json`, `src/dialogs/ImportPreviewDialog.vue`, `src/dialogs/ExportConfigurationDialog.vue`
- **acceptance_criteria**:
  - GIVEN an operator uploads an OAS document WHEN the preview dialog opens THEN it shows creates/updates/collisions/unresolved-references, and any unresolved reference blocks confirmation until acknowledged
  - GIVEN the operator confirms WHEN the import completes THEN a post-import summary shows `credentialsNeedingReentry` with links to each Source's edit form
- [x] Implement — hosted as Catalog-page header actions rather than a separate Configurations index page: OR Configuration groups are a native OR table (`OCA\OpenRegister\Db\Configuration`, served by `/apps/openregister/api/configurations`), not an object register/schema, so `type:index` cannot back them and `type:custom` is exactly what this change avoids (custom-widget-ratchet); see the manifest's `_configurationUiNote`
- [ ] Test — behavioural coverage lives in `tests/e2e/spec-coverage/configuration-import-export-ui.spec.ts` (@e2e-tagged), written per the test plan but NOT executed (no live instance in this build environment); the underlying preview/confirm/flag logic is PHPUnit-covered (Tasks 9-11)

### Task 13: PHPUnit — CatalogRegistryService + import preview diff logic
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-a-single-php-side-adapter-metadata-registry-is-the-source-of-truth-for-catalog-entries-req-003`
- **files**: `tests/Unit/Service/CatalogRegistryServiceTest.php`, `tests/Unit/Service/ConfigurationImportPreviewServiceTest.php` (plus `tests/Unit/Controller/CatalogControllerTest.php`, `tests/Unit/Controller/ConfigurationControllerTest.php`, `tests/Unit/Repair/MaterializeCatalogItemsTest.php`)
- **acceptance_criteria**:
  - GIVEN mocked `IntegrationRegistry` + seed fragments WHEN `collect()` is unit-tested THEN entry count and shape are asserted
  - GIVEN a fixture OAS document with a known create/update/collision/unresolved mix WHEN the preview service is unit-tested THEN each category is asserted
- [x] Implement
- [x] Test — full suite green in oc-phpunit-83: 909 tests / 2741 assertions, 0 failures (875 tests at baseline; 34 added by this change)

### Task 14: vitest — catalog Pinia store
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001`
- **files**: `tests/vitest/catalogStore.spec.js` (moved from the sketched `src/store/catalog.spec.js` — vitest.config.js includes only `tests/vitest/**` and excludes `src/**`)
- **acceptance_criteria**:
  - GIVEN the catalog store is loaded WHEN filtering by category or search term THEN the store's filtered getter returns the expected subset
- [x] Implement
- [x] Test — `npm run test:unit`: 49 tests pass (40 baseline + 9 new: category narrowing, case-insensitive name/description search, AND-composition, clear-restores, distinct categories, fetchStatus/instantiate/fetchItems endpoint wiring)

### Task 15: Playwright e2e — catalog browse + import flow
- **spec_ref**: `openspec/specs/connector-catalog/spec.md#requirement-catalog-lists-adapters-seeded-source-templates-and-configuration-templates-with-category-filter-and-status-badges-req-001`
- **files**: `tests/e2e/spec-coverage/connector-catalog.spec.ts`, `tests/e2e/spec-coverage/configuration-import-export-ui.spec.ts` (repo convention `tests/e2e/spec-coverage/*.spec.ts`, not the sketched `tests/playwright/*.spec.js` — this repo has no `tests/playwright/` and playwright.config.ts's testDir is `tests/e2e`)
- **acceptance_criteria**:
  - GIVEN a logged-in admin WHEN they navigate to `/catalog`, filter by category, open a detail modal, and instantiate a seeded source THEN the new Source appears in the Sources list
  - GIVEN an admin exports a configuration, then re-imports the downloaded file WHEN the preview dialog appears THEN it shows the expected update classification and completing the import re-flags credential re-entry
- [x] Implement — every UI scenario of the changed specs carries an `@e2e` tag in these files (gate-19); backend-only scenarios carry reason-bearing `@e2e exclude` lines in the spec deltas
- [ ] Test — NOT executed: running Playwright against a live instance is not available in this build environment (per the apply brief). Run `npx playwright test tests/e2e/spec-coverage/connector-catalog.spec.ts tests/e2e/spec-coverage/configuration-import-export-ui.spec.ts` against a provisioned instance (needs the repair steps applied first)

## Verification
- [x] All tasks checked off — all 15 Implement boxes ticked; 4 Test boxes intentionally open with reasons (live-instance-only verification: Tasks 2, 7, 12, 15)
- [x] `openspec validate` passes (`openspec validate connector-catalog-ui --type change --strict`: valid)
- [ ] Manual testing against acceptance criteria — requires a live instance; not available in this build environment
- [x] Code review against spec requirements — every scenario of the three spec deltas walked against the implementation (see the apply report)

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests for new/changed business logic (`tests/Unit/`) — 34 new tests, full suite 909/909 green in oc-phpunit-83
- [ ] Newman/Postman tests for new/changed API endpoints — not added: the new endpoints' HTTP contracts are covered by the PHPUnit controller suites; extending `tests/postman/openconnector.postman_collection.json` needs a live instance to record/verify against (follow-up)
- [ ] Browser tests (Playwright MCP) for UI changes — spec files written and @e2e-tagged (Task 15); not executed, no live instance in this environment
- [ ] All tests pass (`composer test`, `newman run`) — `composer check:strict` (incl. `test:all`) green in oc-phpunit-83; newman not run (see above)

## Documentation (company-wide ADR-010)

- [x] Feature documentation updated in `docs/` — `docs/features/connector-catalog.md`
- [ ] Screenshot captured and committed to `docs/images/` — requires the Catalog page rendering on a live instance; capture on next deploy

## i18n (company-wide hydra ADR-007)

- [x] Dutch (`nl`) and English (`en`) translation strings added for Catalog page labels, status badges, and import-preview dialog text — 42 new keys in `l10n/en.json` (extraction check OK) plus hand-translated Dutch in `l10n/nl.json` and `l10n/nl.js`; category names come from the backend registry (materialised object data), not t() calls. NOTE: the 36-locale parity gate (`check-l10n-parity.js`) was already failing at baseline (26 json + 10 js keys missing in the 35 non-Dutch locales from earlier changes); this change keeps nl + en complete but does not author the other 35 locales — pre-existing debt, flagged for follow-up
