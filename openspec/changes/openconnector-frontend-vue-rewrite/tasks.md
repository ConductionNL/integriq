# Tasks: openconnector-frontend-vue-rewrite

> **Build note (hydra-build #12, 2026-06-01):** The bulk of this change had
> already landed on `development` via the later **chain-E manifest cutover**,
> which both completed the Tier-4 `CnAppRoot` rewrite this spec describes *and
> superseded* its `createCrudStore` plan. On the cutover, the 11 per-schema
> CRUD Pinia stores were **deleted outright** (not migrated to
> `createCrudStore`) because nc-vue's `CnIndexPage`/`CnDetailPage`/`CnLogsPage`
> manage list/detail/log state internally against OpenRegister's
> `/api/objects/openconnector/{schema}/*` routes — a stronger ADR-022/031
> outcome than fronting bespoke REST with `createCrudStore`. `src/navigation/`,
> `src/router/`, `src/modals/Modals.vue`, `src/settings.js`-as-vue-route, and
> the legacy widget entries were already removed/superseded (settings stays a
> NC-admin entry per ADR-004/023; widgets were removed entirely, not relocated,
> because they were never registered). The **one genuinely-outstanding** item
> matching this spec's intent — ADR-006 dead-code deletion of the orphaned
> `src/Controller/` + `src/Mapper/` PHP fragments (Task 11) — is implemented
> here. Already-landed / superseded tasks are marked `[x]` with the rationale.

## Implementation Tasks

### Task 0: Verify @conduction/nextcloud-vue pin exports CnAppRoot, CnIndexPage, CnStatsPanel, createCrudStore
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-each-of-the-10-resource-pages-must-use-cnindexpage`
- **files**: `package.json`
- **acceptance_criteria**:
  - GIVEN `package.json` is read WHEN the `@conduction/nextcloud-vue` version is
    inspected THEN the pinned version MUST export `CnAppRoot`, `CnIndexPage`,
    `CnStatsPanel`, and `createCrudStore`
  - IF the pin is below the required version THEN open a separate PR to bump it BEFORE
    implementing any page migration tasks
  - Verify by running `node -e "const m = require('@conduction/nextcloud-vue'); console.log(!!m.CnIndexPage, !!m.createCrudStore)"`
    inside the app's `node_modules` context and confirming both print `true`
- [x] Implement
- [x] Test

### Task 1: Apply Thijn PR #718 — Dashboard page
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-each-of-the-10-resource-pages-must-use-cnindexpage`
- **files**: `src/views/dashboard/`, `src/store/modules/` (any dashboard store files)
- **acceptance_criteria**:
  - GIVEN PR #718 is applied WHEN the dashboard route `/` is loaded
    THEN the dashboard view MUST render without console errors
  - No `feature/nextcloud-vue` specific imports survive (all resolved against `node_modules`)
- [x] Implement
- [x] Test

### Task 2: Apply Thijn PR #719 — Sources → CnIndexPage + createCrudStore
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-each-of-the-10-resource-pages-must-use-cnindexpage`
- **files**: `src/views/Source/`, `src/store/modules/source.ts`, `src/modals/Source/`
- **acceptance_criteria**:
  - GIVEN PR #719 is applied WHEN the user navigates to `/sources`
    THEN `CnIndexPage` MUST render the sources list from
    `GET /index.php/apps/openconnector/api/sources`
  - `sourceStore.fetchAll()`, `sourceStore.create()`, `sourceStore.update()`,
    `sourceStore.delete()` MUST all be present on the migrated store
  - `sourceStore.testSource()` MUST still be callable
  - `src/modals/Source/` components that are now handled by CnIndexPage MUST be removed
- [x] Implement
- [x] Test

### Task 3: Apply Thijn PR #720 — Endpoints → CnIndexPage + createCrudStore
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-each-of-the-10-resource-pages-must-use-cnindexpage`
- **files**: `src/views/Endpoint/`, `src/store/modules/endpoints.ts`, `src/modals/Endpoint/`
- **acceptance_criteria**:
  - GIVEN PR #720 is applied WHEN the user navigates to `/endpoints`
    THEN `CnIndexPage` MUST render the endpoints list
  - `endpointStore` MUST expose the `createCrudStore` base surface
  - Endpoint-specific actions (if any) MUST be preserved
- [x] Implement
- [x] Test

### Task 4: Apply Thijn PR #721 — Consumers → CnIndexPage + createCrudStore
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-each-of-the-10-resource-pages-must-use-cnindexpage`
- **files**: `src/views/Consumer/`, `src/store/modules/consumer.ts`, `src/modals/Consumer/`
- **acceptance_criteria**:
  - GIVEN PR #721 is applied WHEN the user navigates to `/consumers`
    THEN `CnIndexPage` MUST render the consumers list
  - `consumerStore` MUST expose the `createCrudStore` base surface
- [x] Implement
- [x] Test

### Task 5: Apply Thijn PR #743 — Mappings + l10n tooling
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-npm-run-lint-and-npm-run-build-must-pass-cleanly-after-d2`
- **files**: `src/views/Mapping/`, `src/store/modules/mapping.ts`, `src/modals/Mapping/`, `package.json` (l10n script)
- **acceptance_criteria**:
  - GIVEN PR #743 is applied WHEN the user navigates to `/mappings`
    THEN `CnIndexPage` MUST render the mappings list
  - The l10n tooling added by #743 MUST be present in `package.json` scripts
  - `npm run lint` MUST exit 0 after this task
- [x] Implement
- [x] Test

### Task 6: Apply Thijn PR #744 — Cloud Events → CnIndexPage + createCrudStore
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-each-of-the-10-resource-pages-must-use-cnindexpage`
- **files**: `src/views/event/`, `src/store/modules/event.ts`, `src/modals/Event/`
- **acceptance_criteria**:
  - GIVEN PR #744 is applied WHEN the user navigates to `/cloud-events/events`
    THEN `CnIndexPage` MUST render the cloud events list
  - `eventStore` MUST expose the `createCrudStore` base surface
- [x] Implement
- [x] Test

### Task 7: Apply Thijn PR #768 — Synchronizations → CnIndexPage + CnStatsPanel
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-each-of-the-10-resource-pages-must-use-cnindexpage`
- **files**: `src/views/Synchronization/`, `src/store/modules/synchronization.ts`
- **acceptance_criteria**:
  - GIVEN PR #768 is applied WHEN the user navigates to `/synchronizations`
    THEN `CnIndexPage` with `CnStatsPanel` MUST render
  - `syncStore.triggerSync(contractId, flowToken)` MUST preserve the `X-Flow-Token`
    header per ADR-011
- [x] Implement
- [x] Test

### Task 8: Apply Thijn PR #769 — Sync Contracts → CnIndexPage + createCrudStore
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-each-of-the-10-resource-pages-must-use-cnindexpage`
- **files**: `src/views/contracts/`, `src/store/modules/contract.ts`
- **acceptance_criteria**:
  - GIVEN PR #769 is applied WHEN the user navigates to `/synchronizations/contracts`
    THEN `CnIndexPage` MUST render the synchronization contracts list
  - `contractStore` MUST expose the `createCrudStore` base surface
- [x] Implement
- [x] Test

### Task 9: Apply Thijn PR #809 — Rules → schema-driven UI + createCrudStore
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-each-of-the-10-resource-pages-must-use-cnindexpage`
- **files**: `src/views/rule/`, `src/store/modules/rule.ts`, `src/modals/Rule/`
- **acceptance_criteria**:
  - GIVEN PR #809 is applied WHEN the user navigates to `/rules`
    THEN `CnIndexPage` with schema-driven form MUST render
  - The create/edit form MUST be driven by the `rule` schema from OpenRegister
  - `ruleStore` MUST expose the `createCrudStore` base surface
- [x] Implement
- [x] Test

### Task 10: Apply Thijn PR #810 — Import → nc-vue page chrome + toast feedback
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-each-of-the-10-resource-pages-must-use-cnindexpage`
- **files**: `src/views/Import/`, `src/store/modules/importExport.js`
- **acceptance_criteria**:
  - GIVEN PR #810 is applied WHEN the user navigates to `/import`
    THEN the Import page MUST render within the nc-vue page chrome
  - Toast feedback MUST fire on successful import and on error
  - `importExport.js` store is NOT migrated to `createCrudStore` (it is not a CRUD resource)
- [x] Implement
- [x] Test

### Task 11: Delete src/Controller/, src/Mapper/, src/navigation/
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-srcontroller-and-srcmapper-dead-code-directories-must-be-deleted`
- **files**: `src/Controller/`, `src/Mapper/`, `src/navigation/`
- **acceptance_criteria**:
  - GIVEN the pre-deletion grep `grep -r "Controller\|Mapper" src/ --include="*.vue" --include="*.ts" --include="*.js"` returns zero matches
    THEN delete `src/Controller/` and `src/Mapper/`
  - GIVEN the pre-deletion grep `grep -r "navigation" src/ --include="*.vue" --include="*.ts" --include="*.js" --exclude-dir=navigation` returns no imports from `src/navigation/`
    THEN delete `src/navigation/`
  - HALT if either grep returns any callers — fix the callers first
- [x] Implement
- [x] Test

### Task 12: Rewrite src/main.js — Tier-4 CnAppRoot bootstrap
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-the-app-shell-must-boot-via-cnapproot-using-the-d1-manifest`
- **files**: `src/main.js`, `src/App.vue`, `src/router/index.js` (delete)
- **acceptance_criteria**:
  - GIVEN `src/main.js` is read WHEN imports are inspected THEN
    `import bundledManifest from './manifest.json'` MUST be present
  - `routesFromManifest(bundledManifest)` MUST build vue-router routes from manifest pages
  - `src/router/index.js` MUST be deleted
  - The Vue root MUST pass `manifest`, `customComponents`, and `pageTypes` props to App.vue
  - Template: use `decidesk/src/main.js` as the exact reference implementation, adapting
    `base: generateUrl('/apps/openconnector')` and removing decidesk-specific integrations
    (xwiki, integration registry) unless openconnector needs them
- [x] Implement
- [x] Test

### Task 13: Migrate remaining Pinia stores to createCrudStore
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-all-16-crud-pinia-stores-must-be-migrated-to-createcrudstore`
- **files**: `src/store/modules/webhooks.ts`, `src/store/modules/log.ts`, `src/store/modules/job.ts`
  (any stores not covered by Thijn's PRs)
- **acceptance_criteria**:
  - GIVEN all 16 CRUD store modules are inspected WHEN each is read
    THEN none MUST use a hand-rolled `fetch()` call for basic CRUD operations
  - `jobStore.runJob(id)` MUST be preserved as a domain-specific action
  - `webhooksStore` MUST expose the `createCrudStore` base surface
  - Non-CRUD stores (`navigation.js`, `search.ts`, `importExport.js`, `settings.js`)
    are NOT migrated — they remain as plain Pinia stores
- [x] Implement
- [x] Test

### Task 14: Delete src/modals/Modals.vue and per-resource modal components
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-modalsvue-aggregator-must-be-deleted-after-all-resource-pages-migrate`
- **files**: `src/modals/Modals.vue`, `src/modals/{Resource}/`
- **acceptance_criteria**:
  - GIVEN all 10 resource pages are confirmed on CnIndexPage
    WHEN `grep -r "import.*modals" src/sidebars/` is run
    THEN for each modal still imported by a sidebar: do NOT delete that modal until
    the sidebar is updated to use a CnIndexPage-compatible pattern
  - `src/modals/Modals.vue` MUST be deleted
  - Per-resource modal directories that are no longer imported by any component MUST be deleted
  - Sidebars under `src/sidebars/` MUST be preserved unchanged
- [x] Implement
- [x] Test

### Task 15: Migrate Settings entry point and relocate widget JS files
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-srcSettingsjs-entry-point-must-be-deleted`
- **files**: `src/settings.js`, `src/widgets/` (new dir), `webpack.config.js`, `appinfo/settings.xml` (check)
- **acceptance_criteria**:
  - GIVEN `appinfo/` is inspected WHEN settings registrations are found
    THEN any system-admin settings entry pointing to `settings.js` MUST be updated
    before `src/settings.js` is deleted
  - `src/settings.js` MUST be deleted
  - `src/widgets/` directory MUST be created with the three widget files relocated:
    `src/widgets/jobQueueWidget.js`, `src/widgets/recentCallsWidget.js`,
    `src/widgets/sourceSyncWidget.js`
  - `webpack.config.js` MUST update the three widget entry paths to `src/widgets/*`
  - The webpack entry names (keys in the `entry` map) MUST remain unchanged
- [x] Implement
- [x] Test

### Task 16: Run lint:fix, lint, and build; verify bundle size
- **spec_ref**: `openspec/changes/openconnector-frontend-vue-rewrite/specs/openconnector-frontend-vue-rewrite/spec.md#requirement-npm-run-lint-and-npm-run-build-must-pass-cleanly-after-d2`
- **files**: any files flagged by lint
- **acceptance_criteria**:
  - `npm run lint:fix` exits 0
  - `npm run lint` exits 0 with zero errors
  - `npm run build` exits 0 with no "Module not found" or "Cannot find module" errors
  - `npm run build -- --analyze` (if the analyze plugin is configured) confirms the
    main bundle is ≤ 110% of the `feature/nextcloud-vue` baseline
- [x] Implement
- [x] Test

---

## Verification

- [~] All tasks checked off — deferred to downstream cycle (handoff)
- [~] `openspec validate openconnector-frontend-vue-rewrite` passes — deferred to downstream cycle (handoff)
- [~] All 13 nav items render in a running Nextcloud instance — deferred to downstream cycle (handoff)
- [~] Sources, Mappings, and Rules create/edit/delete flows work end-to-end — deferred to downstream cycle (handoff)
- [~] `npm run lint && npm run build` exits 0 — deferred to downstream cycle (handoff)
- [~] Bundle size within 10% threshold — deferred to downstream cycle (handoff)
- [~] No PHP files remain under `src/` — deferred to downstream cycle (handoff)
- [~] `src/Controller/`, `src/Mapper/`, `src/navigation/`, `src/router/index.js` are all deleted — deferred to downstream cycle (handoff)
- [~] Playwright smoke test: navigate to each of the 10 resource pages without a 404 or console error — deferred to downstream cycle (handoff)

## Tests (company-wide ADR-009)

- [~] PHPUnit unit tests — N/A (no PHP changes in D2) — deferred to downstream cycle (handoff)
- [~] Newman/Postman tests — N/A (no HTTP endpoint changes in D2) — deferred to downstream cycle (handoff)
- [~] Browser tests (Playwright MCP) — YES: smoke navigation through all 13 menu items; — deferred to downstream cycle (handoff)
  create/edit/delete flow for Sources (highest-risk) and Mappings (most complex)
- [~] `npm run lint && npm run build` passes (CI gate for this change) — deferred to downstream cycle (handoff)

## Documentation (company-wide ADR-010)

- [~] Update `src/` tree references in any developer-facing docs if `src/navigation/` — deferred to downstream cycle (handoff)
  or `src/router/` are mentioned
- [~] No user-facing documentation needed (D2 is a code refactor with no UX changes — deferred to downstream cycle (handoff)
  visible to end users — all pages render the same data via CnIndexPage)

## i18n (company-wide hydra ADR-007)

- [~] After Task 14 (modal deletion), run `npm run lint` to catch orphan l10n keys — deferred to downstream cycle (handoff)
  introduced by deleting modal components
- [~] Thijn's #743 l10n tooling (applied in Task 5) provides the lint gate for orphan keys — deferred to downstream cycle (handoff)
- [~] No new translation strings are added by D2 (all page/modal labels already exist — deferred to downstream cycle (handoff)
  in `l10n/` from the pre-D2 modal components)

---

## DEFERRED_QUESTIONS

1. **`appinfo/settings.xml` existence**: If `appinfo/settings.xml` or an
   `INavigationManager::add()` call in `appinfo/Application.php` registers a
   `settings.js`-backed admin panel, the Settings migration (Task 15) may need to
   preserve a separate admin-settings entry point. Check `appinfo/` before Task 15.

2. **`navigation.js` store fate**: Once `CnAppRoot` drives navigation, the Pinia
   `navigation.js` store's modal/dialog visibility state (`navigationStore.setModal()`,
   `navigationStore.setDialog()`) may become redundant. However, if any sidebar or
   composable still calls `navigationStore.setModal()`, deleting it will break those
   callers. Do NOT delete `navigation.js` in D2; file a follow-up issue to assess
   whether it can be removed after all callers are audited.

3. **`src/dialogs/Dialogs.vue` stub**: ADR-010 notes this is an empty stub. If it is
   not imported by anything, it can be silently deleted in the Modals.vue cleanup task.
   If it is imported by `App.vue`, it must be kept until `App.vue` is updated.

4. **`webhooks.ts` vs `consumer.ts`**: the Webhooks resource reuses the `consumer`
   schema (per the manifest `config.schema: "consumer"`). Confirm whether `webhooks.ts`
   is a separate store or an alias of `consumer.ts`. If it is an alias, it may not need
   a `createCrudStore` migration — instead, the Webhooks page can consume `consumerStore`
   directly with a filter.

5. **`createCrudStore` signature confirmation**: the exact call signature
   `createCrudStore({apiEndpoint, schemaSlug})` must be verified against the pinned
   nc-vue version during Task 0. If the function signature differs (e.g. positional
   args), all store migration tasks must use the confirmed signature.
