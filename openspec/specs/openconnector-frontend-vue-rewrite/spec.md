# openconnector-frontend-vue-rewrite Specification

## Purpose
TBD - created by archiving change openconnector-frontend-vue-rewrite. Update Purpose after archive.
## Requirements
### Requirement: The app shell MUST boot via CnAppRoot using the D1 manifest

`src/main.js` MUST import `bundledManifest` from `./manifest.json` and build vue-router
routes from `manifest.pages[]` via a `routesFromManifest()` function. The Vue root
MUST render `App.vue` with the `manifest`, `customComponents`, and `pageTypes` props
consumed by `CnAppRoot`. The imperative route declarations in `src/router/index.js`
MUST be deleted. The hardcoded nav items in `src/navigation/` MUST be deleted.

#### Scenario: All 13 manifest menu items render in the nav

GIVEN the app is loaded at `/apps/integriq` in a Nextcloud instance with OR installed
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

### Requirement: All 24 manifest pages MUST use a standard page type — `type: "custom"` is reserved for genuine exceptions only

Every manifest page MUST use one of the 11 standard v2 page types (`index`/`detail`/`dashboard`/`logs`/`settings`/`chat`/`files`/`form`/`wiki`/`map`/`custom`) and MUST NOT use `type: "custom"` unless a `_note` field documents a specific capability gap in `@conduction/nextcloud-vue` that prevents using a standard type.

**Baseline reality (2026-05-20):** post-merge with `origin/development`, all 24 entries in `src/manifest.json` currently use `type: "custom"` and are mapped through `src/registry.js`'s customComponents. The registry.js comment frames this as "genuine exceptions, not deferred migrations". **This spec deliberately overrides that framing.** Per the nc-vue capability surface shipped in PRs #254/#257/#258/#259 (manifest-v2 schema + renderer + codemod + CSP-safe validator), the v2 page-type enum (`index`, `detail`, `dashboard`, `logs`, `settings`, `chat`, `files`, `form`, `wiki`, `map`, `custom`) plus the universal widget+slot grid system CAN express every integriq page — including the "complex interactive surfaces" the dev team flagged (mapping editor, cron builder, rule conditions, CloudEvent management).

Every manifest page MUST use one of the standard v2 page types unless its `_note` field documents a specific capability gap in nc-vue that prevents it. `registry.js` MUST shrink as pages migrate; the long-term target is a registry containing ONLY genuinely-custom widgets registered against specific page slots (NOT entire pages).

#### Type assignment table (24 pages)

| Page id | Current `type` | Required post-D2 `type` | Implementation notes |
|---|---|---|---|
| Dashboard | `custom` | `dashboard` | Built-in `dashboard` type + CnDashboardGrid + widget array; existing customComponents.DashboardView is decomposed into 3 widgets via `widgetKey` references |
| Sources | `custom` | `index` | CnIndexPage with schema-driven CRUD; `testSource` becomes a row-action widget `widgetKey: "SourceTestActionWidget"` (genuinely custom, registers in customComponents) |
| SourceDetail | `custom` | `detail` | CnDetailPage with sidebarTabs: overview + audit + connection-test |
| SourceLogs | `custom` | `logs` | Built-in `logs` type with filter `sourceId=@route.sourceId` |
| Endpoints | `custom` | `index` | CnIndexPage standard CRUD |
| EndpointDetail | `custom` | `detail` | CnDetailPage with sidebarTabs: overview + audit + request-tester |
| EndpointLogs | `custom` | `logs` | Built-in `logs` type |
| Consumers | `custom` | `index` | CnIndexPage standard CRUD |
| ConsumerDetail | `custom` | `detail` | CnDetailPage standard |
| Webhooks | `custom` | `index` | CnIndexPage on `consumer` schema with filter on `type=webhook` |
| Jobs | `custom` | `index` | CnIndexPage standard CRUD; `runJob` becomes a row-action widget `widgetKey: "JobRunActionWidget"` |
| JobLogs | `custom` | `logs` | Built-in `logs` type |
| Mappings | `custom` | `index` | CnIndexPage list; the drag-drop **MappingEditorWidget** is a custom widget registered via `widgetKey` for the detail page's body slot |
| MappingDetail | `custom` | `detail` | CnDetailPage with body slot widget `widgetKey: "MappingEditorWidget"` (drag-drop UX) |
| Rules | `custom` | `index` | CnIndexPage list; **RuleConditionsWidget** (visual conditions editor) is a custom widget for the detail page |
| RuleDetail | `custom` | `detail` | CnDetailPage with body slot widget `widgetKey: "RuleConditionsWidget"` |
| Synchronizations | `custom` | `index` | CnIndexPage list + sidebar slot CnStatsBlockWidget |
| SynchronizationContracts | `custom` | `index` | CnIndexPage standard CRUD on contracts |
| SynchronizationLogs | `custom` | `logs` | Built-in `logs` type |
| CloudEvents | `custom` | `index` | CnIndexPage on `event` schema |
| CloudEventDetail | `custom` | `detail` | CnDetailPage with **EventSubscriptionsWidget** in tab: subscriptions slot |
| CloudEventLogs | `custom` | `logs` | Built-in `logs` type |
| Import | `custom` | `custom` | **STAYS custom** — multi-step file upload UX exceeds form/wizard built-ins. `_note` field MUST be added: `"Multi-step file-upload + dry-run preview UX exceeds nc-vue v1.x form/wizard capability — revisit after nc-vue ships CnWizardPage."` |
| AppSettings | `custom` | `settings` | Built-in `settings` type |

**Net**: 23 of 24 pages move from `custom` to a standard type. Only `Import` stays custom (with a documented `_note`). Of the 23 standard pages, 6 pages additionally reference **custom widgets** registered in `customComponents` for body/sidebar/tab slots (the "genuinely custom" interactive UX bits). The remaining customComponents page entries are deleted as their pages migrate.

#### Scenario: registry.js shrinks as standard types are adopted

GIVEN the baseline state has `src/registry.js` exporting 18 entries (full hand-rolled custom-page components)
WHEN this change is applied
THEN `src/registry.js` MUST contain only widget-component exports (e.g. `MappingEditorWidget`, `RuleConditionsWidget`, `EventSubscriptionsWidget`, `SourceTestActionWidget`, `JobRunActionWidget`, plus the Import multi-step page component)
AND `src/registry.js` MUST NOT export any entry whose corresponding manifest page entry uses a standard v2 type (`index`/`detail`/`logs`/`dashboard`/`settings`)

#### Scenario: Sources index page renders via CnIndexPage

GIVEN the manifest page `Sources` has `type: "index"` post-migration
WHEN the user navigates to `/sources`
THEN CnIndexPage MUST render the sources list from `GET /index.php/apps/integriq/api/sources` AND the existing hand-rolled `src/views/Source/SourcesIndex.vue` MUST be deleted

#### Scenario: Mapping detail page renders the MappingEditor as a widget

GIVEN the manifest page `MappingDetail` has `type: "detail"` AND `widgets: [{ widgetKey: "MappingEditorWidget", slot: "body", gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 8 }]`
WHEN the user navigates to `/mappings/:id`
THEN CnDetailPage MUST render with the MappingEditorWidget filling the body slot
AND `src/registry.js` MUST export `MappingEditorWidget` (as a widget, NOT a page-component entry)

#### Scenario: Import page retains custom type with documented _note

GIVEN the manifest page `Import` has `type: "custom"` AND `_note: "Multi-step file-upload + dry-run preview UX exceeds nc-vue v1.x form/wizard capability — revisit after nc-vue ships CnWizardPage."`
WHEN the manifest is validated against `app-manifest-v2.schema.json`
THEN the validator MUST accept the entry (since `_note` is present)

#### Scenario: customComponents registry size shrinks

GIVEN the baseline export count of `src/registry.js` is 18
WHEN this change is applied
THEN the export count MUST be reduced by at least 12 entries (those whose pages moved to standard types) AND the remaining entries MUST all be widget components, not full-page components

#### Scenario: Sources index page renders via CnIndexPage

GIVEN the app is running and the user navigates to `/sources`
WHEN the Sources page mounts
THEN `CnIndexPage` MUST render a list of source objects returned by
`GET /index.php/apps/integriq/api/sources`

#### Scenario: Create source form opens from CnIndexPage

GIVEN the user is on the Sources index page
WHEN the user clicks the "Add source" action in CnIndexPage
THEN a schema-driven create form MUST open without navigating away from the page

#### Scenario: Rules page uses schema-driven UI

GIVEN the Rules page is rendered via CnIndexPage (per Thijn PR #809)
WHEN the user opens the create/edit form for a Rule
THEN the form fields MUST be driven by the `rule` schema from OpenRegister

---

### Requirement: All per-schema CRUD Pinia stores MUST be deleted; only connector-action stores remain

Per the architecture pivot of 2026-05-20 (chain C proposal § "Delete the per-schema CRUD layer"), OR + nc-vue already deliver per-schema CRUD state generically — `CnIndexPage`/`CnDetailPage` consume OR's `/api/objects/{register}/{schema}/*` directly and manage their own list/detail state. The hand-rolled per-resource Pinia stores under `src/store/modules/` (source.ts, endpoints.ts, consumer.ts, contract.ts, event.ts, job.ts, mapping.ts, rule.ts, synchronization.ts, importExport.js, plus the `webhooks` alias) MUST be deleted.

Connector-specific **action** stores MUST be created where a connector action has non-trivial UI state (a multi-step run dialog, a long-running poll, a flow-token correlation tracker). Examples that may need a small dedicated store: `useJobRunner` (for `runJob` modal polling), `useSyncTrigger` (for flow-token-aware trigger UX), `useSourceTester` (for connection-test result panel). These are NOT CRUD stores — each has at most one or two actions and no `list`/`fetchAll` surface. Non-CRUD generic stores (`navigation.js`, `search.ts`, `settings.js`) MAY stay as-is or be subsumed by nc-vue.

#### Scenario: per-schema CRUD store file is absent post-merge

GIVEN the chain D2 cutover is applied
WHEN `ls src/store/modules/source.ts src/store/modules/endpoints.ts src/store/modules/consumer.ts src/store/modules/job.ts src/store/modules/mapping.ts src/store/modules/rule.ts src/store/modules/synchronization.ts src/store/modules/event.ts src/store/modules/contract.ts 2>/dev/null` runs
THEN no file MUST be reported (all 9 + the importExport.js variants are deleted)

#### Scenario: connector-specific action survives in a dedicated store

GIVEN a `useJobRunner` store exists under `src/store/actions/` (new directory pattern)
WHEN a component calls `jobRunnerStore.run(jobId)`
THEN it MUST POST to `/index.php/apps/integriq/api/jobs/{jobId}/run` (a connector-specific action endpoint preserved in chain C) and MUST track the run state (`status: 'running' | 'completed' | 'failed'`, `lastResult`)

#### Scenario: Synchronization trigger preserves the flow-token

GIVEN a `useSyncTrigger` store exists
WHEN `syncTriggerStore.trigger(contractId, flowToken)` is called with a non-null flowToken
THEN the HTTP request MUST include the `X-Flow-Token` header value per local ADR-011

---

### Requirement: src/settings.js entry point MUST be deleted; Settings MUST become a router route

The `src/settings.js` standalone webpack entry MUST be deleted. The Integriq
settings panel MUST be accessible as the `/settings` vue-router route rendered by
`CnPageRenderer` inside the `CnAppRoot` shell. The manifest page
`{ "id": "Settings", "route": "/settings", "type": "settings" }` declared in D1 is the
authoritative definition of this page.

If `appinfo/settings.xml` registers a system-admin settings entry pointing to the
`settings.js` bundle, that registration MUST be updated or removed before the file is
deleted.

#### Scenario: Settings page is reachable via nav

GIVEN the user is logged in as admin
WHEN they click the "Settings" menu item in the Integriq navigation
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

