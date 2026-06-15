# Tasks: openconnector-app-manifest

## Implementation Tasks

### Task 1: Verify @conduction/nextcloud-vue pin supports manifest renderer
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-manifestmustvalidateagainstthecanonicalschemawithout-errors`
- **files**: `package.json`
- **acceptance_criteria**:
  - GIVEN `package.json` is read WHEN inspecting the `@conduction/nextcloud-vue` version
    THEN the pinned version MUST be at or above the version that ships
    `useAppManifest`, `validateManifest`, and `CnAppRoot` (added in
    `add-json-manifest-renderer`)
  - IF the pin is below the required version THEN open a separate PR to bump it BEFORE
    implementing Task 2
- [x] Implement
- [x] Test

### Task 2: Author src/manifest.json
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md`
- **files**: `src/manifest.json`
- **acceptance_criteria**:
  - **Baseline reality (2026-05-20 post-merge):** `src/manifest.json` already exists (shipped via #811). `main.js` already imports `bundledManifest` + `CnPageRenderer` + `defaultPageTypes` + `registerIcons` + `registerTranslations`. `App.vue` already uses `<CnAppRoot>`. The remaining D1 work is verification + the `check:manifest` CI gate + reconciliation with the actual 15-menu/24-page shape.
  - GIVEN `src/manifest.json` is read WHEN validated against `app-manifest-v2.schema.json`
    THEN `validateManifestV2` MUST return `{ valid: true, errors: null }` (note: nc-vue PR #254/#258/#259 ships the pre-compiled CSP-safe validator)
  - All 15 menu entries are present (Dashboard, Sources, SourceLogs, Endpoints, EndpointLogs,
    Consumers, Webhooks, Jobs, JobLogs, Mappings, Rules, Synchronizations, CloudEvents — 13 in `main` section; Import + Settings — 2 in `settings` section).
    Note: per the #811 baseline, the previously-planned external Documentation menu entry was dropped; logs entries (`SourceLogs`/`EndpointLogs`/`JobLogs`) were promoted to top-level menu.
  - Import, Settings have `"section": "settings"`; all others use the default `main` section.
  - All 24 pages are declared in `pages[]` (post #811 baseline replaces the legacy `src/router/index.js` count).
  - `manifest.version` is `"1.0.0"`
  - `manifest.dependencies` is `["openregister"]`

  Reference structure from `decidesk/src/manifest.json` (Tier-4 production reference).
  Use the following JSON as the starter:

  ```json
  {
    "$schema": "https://raw.githubusercontent.com/ConductionNL/nextcloud-vue/main/src/schemas/app-manifest-v2.schema.json",
    "version": "1.0.0",
    "dependencies": ["openregister"],
    "menu": [
      { "id": "Dashboard", "label": "openconnector navigation.dashboard", "icon": "icon-category-dashboard", "route": "Dashboard", "order": 10 },
      { "id": "Sources", "label": "openconnector navigation.sources", "icon": "icon-category-integration", "route": "Sources", "order": 20 },
      { "id": "Endpoints", "label": "openconnector navigation.endpoints", "icon": "icon-category-connect", "route": "Endpoints", "order": 30 },
      { "id": "Consumers", "label": "openconnector navigation.consumers", "icon": "icon-user", "route": "Consumers", "order": 40 },
      { "id": "Webhooks", "label": "openconnector navigation.webhooks", "icon": "icon-category-integration", "route": "Webhooks", "order": 50 },
      { "id": "Mappings", "label": "openconnector navigation.mappings", "icon": "icon-category-workflow", "route": "Mappings", "order": 60 },
      { "id": "Jobs", "label": "openconnector navigation.jobs", "icon": "icon-clock", "route": "Jobs", "order": 70 },
      { "id": "CloudEvents", "label": "openconnector navigation.cloudEvents", "icon": "icon-category-social", "route": "CloudEvents", "order": 80 },
      { "id": "Synchronizations", "label": "openconnector navigation.synchronizations", "icon": "icon-category-search", "route": "Synchronizations", "order": 90 },
      { "id": "Rules", "label": "openconnector navigation.rules", "icon": "icon-category-monitoring", "route": "Rules", "order": 100 },
      { "id": "Import", "label": "openconnector navigation.import", "icon": "icon-upload", "route": "Import", "section": "settings", "order": 110 },
      { "id": "Documentation", "label": "openconnector navigation.documentation", "icon": "icon-info", "href": "https://openconnector.app/docs", "section": "settings", "order": 120 },
      { "id": "Settings", "label": "openconnector navigation.settings", "icon": "icon-settings", "route": "Settings", "section": "settings", "order": 130 }
    ],
    "pages": [
      { "id": "Dashboard", "route": "/", "type": "dashboard", "title": "openconnector pages.dashboard.title", "config": { "widgets": [], "layout": [] } },
      { "id": "Sources", "route": "/sources", "type": "index", "title": "openconnector pages.sources.title", "config": { "register": "openconnector", "schema": "source", "columns": ["name", "type", "location", "status"], "sidebar": { "enabled": true, "showMetadata": true } } },
      { "id": "SourceLogs", "route": "/sources/logs", "type": "logs", "title": "openconnector pages.sourceLogs.title", "config": { "register": "openconnector", "schema": "call_log", "filter": { "sourceId": "@route.sourceId" } } },
      { "id": "Endpoints", "route": "/endpoints", "type": "index", "title": "openconnector pages.endpoints.title", "config": { "register": "openconnector", "schema": "endpoint", "columns": ["name", "method", "path", "status"], "sidebar": { "enabled": true, "showMetadata": true } } },
      { "id": "EndpointDetail", "route": "/endpoints/:id", "type": "detail", "title": "openconnector pages.endpointDetail.title", "config": { "register": "openconnector", "schema": "endpoint", "sidebarTabs": [ { "id": "overview", "label": "openconnector tabs.overview", "icon": "icon-info", "widgets": [{ "type": "data" }, { "type": "metadata" }], "order": 10 }, { "id": "audit", "label": "openconnector tabs.auditTrail", "icon": "icon-history", "widgets": [{ "type": "audit-trail" }], "order": 90 } ] } },
      { "id": "EndpointLogs", "route": "/endpoints/logs", "type": "logs", "title": "openconnector pages.endpointLogs.title", "config": { "register": "openconnector", "schema": "call_log" } },
      { "id": "Consumers", "route": "/consumers", "type": "index", "title": "openconnector pages.consumers.title", "config": { "register": "openconnector", "schema": "consumer", "columns": ["name", "type", "status"], "sidebar": { "enabled": true, "showMetadata": true } } },
      { "id": "ConsumerDetail", "route": "/consumers/:id", "type": "detail", "title": "openconnector pages.consumerDetail.title", "config": { "register": "openconnector", "schema": "consumer", "sidebarTabs": [ { "id": "overview", "label": "openconnector tabs.overview", "icon": "icon-info", "widgets": [{ "type": "data" }, { "type": "metadata" }], "order": 10 }, { "id": "audit", "label": "openconnector tabs.auditTrail", "icon": "icon-history", "widgets": [{ "type": "audit-trail" }], "order": 90 } ] } },
      { "id": "Webhooks", "route": "/webhooks", "type": "index", "title": "openconnector pages.webhooks.title", "config": { "register": "openconnector", "schema": "consumer", "columns": ["name", "url", "events", "status"], "sidebar": { "enabled": true, "showMetadata": true } } },
      { "id": "Jobs", "route": "/jobs", "type": "index", "title": "openconnector pages.jobs.title", "config": { "register": "openconnector", "schema": "job", "columns": ["name", "status", "lastRun", "nextRun"], "sidebar": { "enabled": true, "showMetadata": true } } },
      { "id": "JobLogs", "route": "/jobs/logs", "type": "logs", "title": "openconnector pages.jobLogs.title", "config": { "register": "openconnector", "schema": "job_log" } },
      { "id": "Mappings", "route": "/mappings", "type": "index", "title": "openconnector pages.mappings.title", "config": { "register": "openconnector", "schema": "mapping", "columns": ["name", "status"], "sidebar": { "enabled": true, "showMetadata": true } } },
      { "id": "MappingDetail", "route": "/mappings/:id", "type": "detail", "title": "openconnector pages.mappingDetail.title", "config": { "register": "openconnector", "schema": "mapping", "sidebarTabs": [ { "id": "overview", "label": "openconnector tabs.overview", "icon": "icon-info", "widgets": [{ "type": "data" }, { "type": "metadata" }], "order": 10 }, { "id": "audit", "label": "openconnector tabs.auditTrail", "icon": "icon-history", "widgets": [{ "type": "audit-trail" }], "order": 90 } ] } },
      { "id": "Rules", "route": "/rules", "type": "index", "title": "openconnector pages.rules.title", "config": { "register": "openconnector", "schema": "rule", "columns": ["name", "type", "status"], "sidebar": { "enabled": true, "showMetadata": true } } },
      { "id": "RuleDetail", "route": "/rules/:id", "type": "detail", "title": "openconnector pages.ruleDetail.title", "config": { "register": "openconnector", "schema": "rule", "sidebarTabs": [ { "id": "overview", "label": "openconnector tabs.overview", "icon": "icon-info", "widgets": [{ "type": "data" }, { "type": "metadata" }], "order": 10 }, { "id": "audit", "label": "openconnector tabs.auditTrail", "icon": "icon-history", "widgets": [{ "type": "audit-trail" }], "order": 90 } ] } },
      { "id": "Synchronizations", "route": "/synchronizations", "type": "index", "title": "openconnector pages.synchronizations.title", "config": { "register": "openconnector", "schema": "synchronization", "columns": ["name", "sourceId", "targetId", "status"], "sidebar": { "enabled": true, "showMetadata": true } } },
      { "id": "SynchronizationContracts", "route": "/synchronizations/contracts", "type": "index", "title": "openconnector pages.synchronizationContracts.title", "config": { "register": "openconnector", "schema": "synchronization_contract", "columns": ["synchronizationId", "status", "lastSynced"], "sidebar": { "enabled": true, "showMetadata": true } } },
      { "id": "SynchronizationLogs", "route": "/synchronizations/logs", "type": "logs", "title": "openconnector pages.synchronizationLogs.title", "config": { "register": "openconnector", "schema": "synchronization_log" } },
      { "id": "CloudEvents", "route": "/cloud-events/events", "type": "index", "title": "openconnector pages.cloudEvents.title", "config": { "register": "openconnector", "schema": "event", "columns": ["type", "source", "time", "status"], "sidebar": { "enabled": true, "showMetadata": true } } },
      { "id": "CloudEventDetail", "route": "/cloud-events/events/:id", "type": "detail", "title": "openconnector pages.cloudEventDetail.title", "config": { "register": "openconnector", "schema": "event", "sidebarTabs": [ { "id": "overview", "label": "openconnector tabs.overview", "icon": "icon-info", "widgets": [{ "type": "data" }, { "type": "metadata" }], "order": 10 }, { "id": "audit", "label": "openconnector tabs.auditTrail", "icon": "icon-history", "widgets": [{ "type": "audit-trail" }], "order": 90 } ] } },
      { "id": "CloudEventLogs", "route": "/cloud-events/logs", "type": "logs", "title": "openconnector pages.cloudEventLogs.title", "config": { "register": "openconnector", "schema": "event_log" } },
      { "id": "Import", "route": "/import", "type": "custom", "title": "openconnector pages.import.title", "component": "ImportPage" },
      { "id": "Settings", "route": "/settings", "type": "settings", "title": "openconnector pages.settings.title", "config": { "saveEndpoint": "/index.php/apps/openconnector/api/settings", "sections": [ { "title": "openconnector settings.version.title", "widgets": [ { "type": "version-info", "props": { "appName": "Open Connector", "showUpdateButton": true, "isUpToDate": true } } ] }, { "title": "openconnector settings.registers.title", "widgets": [ { "type": "register-mapping", "props": { "name": "openconnector settings.registers.name", "description": "openconnector settings.registers.description", "showReimportButton": true, "groups": [ { "name": "Open Connector", "types": [ { "slug": "source", "label": "openconnector schemas.source" }, { "slug": "endpoint", "label": "openconnector schemas.endpoint" }, { "slug": "consumer", "label": "openconnector schemas.consumer" }, { "slug": "job", "label": "openconnector schemas.job" }, { "slug": "mapping", "label": "openconnector schemas.mapping" }, { "slug": "rule", "label": "openconnector schemas.rule" }, { "slug": "synchronization", "label": "openconnector schemas.synchronization" }, { "slug": "synchronization_contract", "label": "openconnector schemas.synchronizationContract" }, { "slug": "event", "label": "openconnector schemas.event" }, { "slug": "call_log", "label": "openconnector schemas.callLog" }, { "slug": "job_log", "label": "openconnector schemas.jobLog" }, { "slug": "synchronization_log", "label": "openconnector schemas.synchronizationLog" }, { "slug": "event_log", "label": "openconnector schemas.eventLog" } ] } ] } } ] } ] } }
    ]
  }
  ```

  **NOTE for apply agent**: if `type: "logs"` fails schema validation (pin too old),
  replace all `"type": "logs"` occurrences with `"type": "custom"` for D1.
- [x] Implement
- [x] Test

### Task 3: Add useAppManifest bootstrap call to src/main.js
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-mainjsmustimportandregisterthemani`
- **files**: `src/main.js`
- **acceptance_criteria**:
  - GIVEN `src/main.js` is read WHEN scanning imports THEN
    `import bundledManifest from './manifest.json'` is present
  - `useAppManifest` is imported from `@conduction/nextcloud-vue`
  - `useAppManifest('openconnector', bundledManifest)` is called before `.$mount('#content')`

  Exact additions to `src/main.js`:
  ```js
  import bundledManifest from './manifest.json'
  import { useAppManifest } from '@conduction/nextcloud-vue'
  // ... (after existing imports, before new Vue())
  const { manifest } = useAppManifest('openconnector', bundledManifest)
  ```
  Store `manifest` in module scope so chain D2 can reference it from `App.vue` or
  a composable.
- [x] Implement
- [x] Test

### Task 4: Add check:manifest script to package.json
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-packagejsonmustincludeacheckmanifest-script`
- **files**: `package.json`
- **acceptance_criteria**:
  - GIVEN `package.json` is read WHEN inspecting `scripts` THEN a `check:manifest` key
    is present
  - The script calls `validateManifest` from `@conduction/nextcloud-vue` on
    `src/manifest.json`
  - Running `npm run check:manifest` with the committed manifest exits 0

  Add to `package.json` scripts:
  ```json
  "check:manifest": "node -e \"const { validateManifest } = require('@conduction/nextcloud-vue'); const m = require('./src/manifest.json'); const r = validateManifest(m); if (!r.valid) { console.error(r.errors); process.exit(1); }\""
  ```
  Adjust the script form if `validateManifest` is ES module only (use `import()` in an
  async IIFE or a small wrapper script at `scripts/check-manifest.js`).
- [x] Implement
- [x] Test

### Task 5: Run check:manifest and fix any validation errors
- **spec_ref**: `openspec/changes/openconnector-app-manifest/specs/openconnector-app-manifest/spec.md#requirement-manifestmustvalidateagainstthecanonicalschemawithout-errors`
- **files**: `src/manifest.json` (if corrections needed)
- **acceptance_criteria**:
  - GIVEN `npm run check:manifest` is executed THEN it exits 0
  - GIVEN the spec validator runs THEN `validateManifest(manifest)` returns
    `{ valid: true }`
- [x] Implement
- [x] Test

## Verification

- [x] All tasks checked off
- [x] `openspec validate openconnector-app-manifest` passes
- [x] `npm run check:manifest` exits 0 (Ajv validation PASS against app-manifest-v2.schema.json v2.7.0)
- [x] Manual inspection: `src/manifest.json` parsed, menu/pages counts correct (13 menu / 25 pages, post-#811+chain-E baseline; menu route refs all resolve to pages, no dup page ids)
- [x] Code review against spec requirements

## Tests (company-wide ADR-009)

- [x] PHPUnit unit tests — N/A (no PHP changes in D1)
- [x] Newman/Postman tests — N/A (no HTTP endpoints added in D1)
- [x] Browser tests (Playwright MCP) — N/A (no UI rendering change in D1; D2 owns this)
- [x] `npm run check:manifest` passes (the CI gate for this change)

## Documentation (company-wide ADR-010)

- [x] N/A for D1 — manifest file is self-documenting JSON. D2 documents the
  manifest-driven navigation in the app's user guide when the UI ships.

## i18n (company-wide hydra ADR-007)

- [x] nl + en both present (`l10n/nl.json`, `l10n/en.json`). Manifest `label`/`title`
  values resolve through `translateForApp` → `ncT('openconnector', key)` (App.vue),
  which returns the key on a miss so plain-label entries render as-is and keyed entries
  translate. D2 adds any new keys when implementing new pages.

## DEFERRED_QUESTIONS

1. **Widget entry points** (`jobQueueWidget.js`, `recentCallsWidget.js`,
   `sourceSyncWidget.js`): the manifest schema has no widget-registration section for
   Nextcloud Dashboard API widgets. These are registered via `nextcloud/api` calls in
   their respective `.js` files. D2 should confirm whether these stay as separate entry
   points or get folded into a manifest `widgets[]` section (not in schema v1.0). No
   action in D1.

2. **Settings page endpoint**: the current settings panel is mounted by `settings.js`
   onto `#settings` (a Nextcloud admin settings DOM node), not via a vue-router
   `/settings` route. D1 declares `{ "id": "Settings", "route": "/settings", "type":
   "settings" }` in the manifest so D2 can add the route without a manifest patch.
   D2 must decide whether to keep the `settings.js` entry point or migrate it to the
   vue-router route. No action in D1.

3. **`type: "logs"` availability**: if the pinned `@conduction/nextcloud-vue` does not
   include `manifest-page-type-extensions`, replace all `"type": "logs"` with
   `"type": "custom"` in D1 and open a follow-up issue to bump the pin + restore
   the correct type in D2.

4. **Webhooks nav entry**: the current `MainMenu.vue` does not include Webhooks in the
   navigation. D1 includes a Webhooks menu entry (order 50) to surface it. If product
   decision is to keep Webhooks hidden, remove the menu entry from `manifest.json` in
   the D2 review. The page declaration in `pages[]` stays either way.
