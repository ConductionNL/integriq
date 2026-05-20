# openconnector-frontend-vue-rewrite Specification

**Status**: in-progress

## Overview

This specification covers the requirements for the D2 frontend rewrite of OpenConnector.
It defines what the Tier-4 manifest-driven app shell, the per-resource page migrations,
and the dead-code deletions MUST look like after the change ships.

All requirements use RFC 2119 keywords (MUST, SHALL, SHOULD, MAY).

---

## ADDED Requirements

### Requirement: The app shell MUST boot via CnAppRoot using the D1 manifest

`src/main.js` MUST import `bundledManifest` from `./manifest.json` and build vue-router
routes from `manifest.pages[]` via a `routesFromManifest()` function. The Vue root
MUST render `App.vue` with the `manifest`, `customComponents`, and `pageTypes` props
consumed by `CnAppRoot`. The imperative route declarations in `src/router/index.js`
MUST be deleted. The hardcoded nav items in `src/navigation/` MUST be deleted.

#### Scenario: All 13 manifest menu items render in the nav

GIVEN the app is loaded at `/apps/openconnector` in a Nextcloud instance with OR installed
WHEN the user opens the left navigation panel
THEN all 13 menu items declared in `src/manifest.json` MUST be visible (Dashboard, Sources,
Endpoints, Consumers, Webhooks, Mappings, Jobs, CloudEvents, Synchronizations, Rules,
Import, Documentation, Settings)

#### Scenario: Navigation routes to the correct page

GIVEN the app shell is rendered via CnAppRoot
WHEN the user clicks the "Sources" menu item
THEN the router MUST navigate to `/sources` and the Sources index page MUST render

#### Scenario: Legacy router file is absent

GIVEN the D2 branch is merged
WHEN `find src/router -name "*.js"` is run
THEN the command MUST produce zero output (router is driven from manifest)

---

### Requirement: src/Controller/ and src/Mapper/ dead-code directories MUST be deleted

All files under `src/Controller/` and `src/Mapper/` MUST be deleted from the repository per ADR-006. No Vue, TypeScript, or JavaScript file under `src/` SHALL import from either directory. A pre-deletion grep MUST confirm zero imports before the delete commit is made.

#### Scenario: No PHP files remain under src/

GIVEN the D2 merge commit is applied
WHEN `find src/ -name "*.php"` is run
THEN the command MUST return zero results

#### Scenario: Pre-deletion grep finds no callers

GIVEN the D2 apply agent is running the Controller/Mapper deletion task
WHEN `grep -r "Controller\|Mapper" src/ --include="*.vue" --include="*.ts" --include="*.js"` is run
THEN the command MUST return zero matches before the delete proceeds

---

### Requirement: src/navigation/ MUST be deleted

The entire `src/navigation/` directory MUST be deleted. Navigation MUST be driven exclusively by `CnAppRoot` consuming the `manifest.json` menu array. No component or composable SHALL import from `src/navigation/` after D2 ships.

#### Scenario: Navigation directory is absent post-merge

GIVEN the D2 merge commit is applied
WHEN `find src/navigation -type f` is run
THEN the command MUST return no results

---

### Requirement: Each of the 10 resource pages MUST use CnIndexPage

Every resource index page MUST be rendered via `CnIndexPage` from `@conduction/nextcloud-vue`. This covers Sources, Endpoints, Consumers, Mappings, CloudEvents, Synchronizations, SyncContracts, Rules, Import, and Dashboard. Hand-rolled index view components in `src/views/{Resource}/{Resource}sIndex.vue` MUST be replaced. The CnIndexPage MUST receive the `register`, `schema`, `columns`, and `sidebar` configuration from the manifest page `config` object.

D1's manifest declares 23 pages total. The 10 index pages above use `CnIndexPage`; the remaining 13 pages MUST use the post-D2 component types declared in this table:

| Page id | Manifest `type` | Post-D2 component | Notes |
|---|---|---|---|
| Dashboard | `dashboard` | `CnDashboard` | D1 Thijn #718 (already merged on `feature/nextcloud-vue`) |
| SourceLogs | `logs` | `CnLogsPage` | filter `sourceId=@route.sourceId` |
| EndpointDetail | `detail` | `CnDetailPage` | sidebarTabs: overview + audit |
| EndpointLogs | `logs` | `CnLogsPage` | |
| ConsumerDetail | `detail` | `CnDetailPage` | sidebarTabs: overview + audit |
| Webhooks | `index` | `CnIndexPage` | shares `consumer` schema with Consumers; filtered list — distinct page id, same store via filter param |
| Jobs | `index` | `CnIndexPage` | NOT one of Thijn's 10 PRs; covered by Task 13 (createCrudStore migration of `job.ts` store) |
| JobLogs | `logs` | `CnLogsPage` | |
| MappingDetail | `detail` | `CnDetailPage` | |
| RuleDetail | `detail` | `CnDetailPage` | |
| SynchronizationLogs | `logs` | `CnLogsPage` | |
| CloudEventDetail | `detail` | `CnDetailPage` | |
| CloudEventLogs | `logs` | `CnLogsPage` | |
| Settings | `settings` | `CnSettingsPage` | covered by Task 15 (settings.js migration) |

`CnIndexPage`, `CnDetailPage`, `CnLogsPage`, `CnSettingsPage`, and `CnDashboard` MUST all be exported by the pinned `@conduction/nextcloud-vue` version (verified in Task 0). If the pin does not export one of these component types, the pin MUST be bumped BEFORE this change ships.

#### Scenario: Sources index page renders via CnIndexPage

GIVEN the app is running and the user navigates to `/sources`
WHEN the Sources page mounts
THEN `CnIndexPage` MUST render a list of source objects returned by
`GET /index.php/apps/openconnector/api/sources`

#### Scenario: Create source form opens from CnIndexPage

GIVEN the user is on the Sources index page
WHEN the user clicks the "Add source" action in CnIndexPage
THEN a schema-driven create form MUST open without navigating away from the page

#### Scenario: Rules page uses schema-driven UI

GIVEN the Rules page is rendered via CnIndexPage (per Thijn PR #809)
WHEN the user opens the create/edit form for a Rule
THEN the form fields MUST be driven by the `rule` schema from OpenRegister

---

### Requirement: All 16 CRUD Pinia stores MUST be migrated to createCrudStore

All 16 per-resource CRUD store modules MUST be rewritten to use `createCrudStore({apiEndpoint, schemaSlug})` from `@conduction/nextcloud-vue`. Per ADR-001, stores MUST remain per-resource; the migration changes the implementation pattern only, not the store granularity. The 16 modules are: source, endpoints, consumer, contract, event, job, log, mapping, rule, synchronization, and webhooks.

Domain-specific store actions (e.g. `testSource()`, `runJob()`, `triggerSync()`,
flow-token-aware `synchronizeContract()`) MUST be preserved as extensions on top of
the `createCrudStore` base.

#### Scenario: Source store provides createCrudStore base surface

GIVEN the source store is imported in a Vue component
WHEN `sourceStore.list`, `sourceStore.fetchAll()`, `sourceStore.create(data)`,
`sourceStore.update(id, data)`, and `sourceStore.delete(id)` are called
THEN each MUST perform the corresponding HTTP operation against
`/index.php/apps/openconnector/api/sources`

#### Scenario: Domain-specific store actions survive migration

GIVEN the source store is migrated to createCrudStore
WHEN `sourceStore.testSource(id)` is called (domain-specific action)
THEN it MUST POST to the test endpoint and return the test result without error

#### Scenario: Synchronization store preserves flow-token header

GIVEN the synchronization store is migrated to createCrudStore
WHEN `syncStore.triggerSync(contractId, flowToken)` is called with a non-null flowToken
THEN the HTTP request MUST include the `X-Flow-Token` header value per ADR-011

---

### Requirement: src/settings.js entry point MUST be deleted; Settings MUST become a router route

The `src/settings.js` standalone webpack entry MUST be deleted. The OpenConnector
settings panel MUST be accessible as the `/settings` vue-router route rendered by
`CnPageRenderer` inside the `CnAppRoot` shell. The manifest page
`{ "id": "Settings", "route": "/settings", "type": "settings" }` declared in D1 is the
authoritative definition of this page.

If `appinfo/settings.xml` registers a system-admin settings entry pointing to the
`settings.js` bundle, that registration MUST be updated or removed before the file is
deleted.

#### Scenario: Settings page is reachable via nav

GIVEN the user is logged in as admin
WHEN they click the "Settings" menu item in the OpenConnector navigation
THEN the router MUST navigate to `/settings` and the settings page content MUST render
inside the CnAppRoot shell

#### Scenario: settings.js webpack entry is absent

GIVEN the D2 merge commit is applied
WHEN `webpack.config.js` is inspected
THEN no entry named `settings` pointing to `src/settings.js` SHALL be present

---

### Requirement: Widget JS files MUST be relocated to src/widgets/

The three Dashboard widget entry point files MUST be moved from `src/` to `src/widgets/`.
The webpack entry names (`jobQueueWidget`, `recentCallsWidget`, `sourceSyncWidget`)
MUST remain unchanged. `webpack.config.js` MUST be updated to point to the new paths.
The Nextcloud Dashboard API registration in `appinfo/` MUST NOT be modified.

#### Scenario: Widget bundles still load in Nextcloud Dashboard

GIVEN the widget files have been moved to src/widgets/
WHEN `webpack.config.js` is read
THEN each widget entry MUST point to `src/widgets/{name}.js`
AND the bundle output names MUST match the names registered in `appinfo/`

#### Scenario: No widget JS files remain under src/ root

GIVEN the D2 merge commit is applied
WHEN `find src/ -maxdepth 1 -name "*.js"` is run (excluding src/ subdirectories)
THEN no widget JS files SHALL be listed (only `src/main.js` and `src/pinia.js` may remain)

---

### Requirement: Modals.vue aggregator MUST be deleted after all resource pages migrate

The `src/modals/Modals.vue` aggregator MUST be deleted once all 10 resource pages have been migrated to `CnIndexPage`. Per-resource modal components MUST also be deleted once CnIndexPage handles them. Sidebars under `src/sidebars/{Resource}/` MUST be preserved. Any remaining modal that is still imported by a sidebar MUST NOT be deleted until the sidebar is updated.

#### Scenario: Modals.vue is absent post-migration

GIVEN all 10 resource pages are on CnIndexPage
WHEN `find src/modals -name "Modals.vue"` is run
THEN the command MUST return no results

#### Scenario: Sidebars are preserved

GIVEN the Modals.vue deletion has been committed
WHEN `find src/sidebars -type f -name "*.vue"` is run
THEN the command MUST return at least one sidebar component per resource family
(Source, Endpoint, Job, Synchronization, Mapping, Rule)

---

### Requirement: npm run lint and npm run build MUST pass cleanly after D2

After all D2 tasks are applied, `npm run lint` MUST exit 0 and `npm run build` MUST
produce a clean bundle with no orphan import errors. Bundle size MUST NOT increase by
more than 10% relative to the `feature/nextcloud-vue` baseline (pre-D2 state with
Thijn's PRs applied but before legacy code deletion).

#### Scenario: Lint passes after migration

GIVEN all D2 commits are applied
WHEN `npm run lint` is executed
THEN it MUST exit with code 0 and report zero errors

#### Scenario: Build produces no orphan import errors

GIVEN all D2 commits are applied
WHEN `npm run build` is executed
THEN it MUST exit with code 0 and the output MUST contain no "Module not found" or
"Cannot find module" errors

#### Scenario: Bundle size is within threshold

GIVEN the D2 build has completed
WHEN the total main bundle size is compared to the feature/nextcloud-vue baseline
THEN the size MUST NOT exceed the baseline by more than 10%
