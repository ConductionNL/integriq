## Deep Linking and SPOT (Single Point Of Truth) Architecture

This document explains how deep linking works in Integriq and how SPOT is achieved via the URL, ensuring a consistent, reliable, and shareable application state across backend and frontend.

### Goals
- **Deep linking**: Directly navigate to any app view via URL, including first load and refresh, without backend errors.
- **Single Point Of Truth (SPOT)**: Treat the browser URL (path + query) as the authoritative state for view selection and filters. All UI state that should be shareable/bookmarkable lives in the URL.
- **Consistency**: Backend page routes and frontend SPA routes must stay in sync.

## Backend Page Routes (Server-Side Deeplinking)

All page-level routes must exist in the backend to serve the SPA entry for history-mode routing. These routes map to controllers and a `page()` action which returns the app template.

Key page routes are defined here:

```php
		// UI page routes for SPA deep links
		['name' => 'ui#sources', 'url' => '/sources', 'verb' => 'GET'],
		['name' => 'ui#sourcesLogs', 'url' => '/sources/logs', 'verb' => 'GET'],
		['name' => 'ui#endpoints', 'url' => '/endpoints', 'verb' => 'GET'],
		['name' => 'ui#endpointsLogs', 'url' => '/endpoints/logs', 'verb' => 'GET'],
		['name' => 'ui#consumers', 'url' => '/consumers', 'verb' => 'GET'],
		...
```

For each page route, there must be a corresponding controller under `lib/Controller/` with a `page()` action that returns a `TemplateResponse`. A minimal controller looks like:

```php
// 21:38:lib/Controller/TablesController.php
    /**
     * This returns the template of the main app's page
     * It adds some data to the template (app version)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return TemplateResponse
     */
    public function page(): TemplateResponse
    {
        return new TemplateResponse(
            'integriq',
            'index',
            []
        );
    }
```

Notes:
- If a backend page route lacks a controller `page()` action, history-mode deep linking will 500 on first-load.
- The `name` in routes must be consistent: `dashboard#page` means `DashboardController::page()` and so on.

## Frontend SPA Router (Client-Side Deeplinking)

The Vue Router runs in history mode with a base path that matches the app entry. All SPA routes must mirror the backend page routes above.

```js
// 1:999:src/router/index.js
const router = new Router({
    mode: 'history',
    base: '/index.php/apps/integriq/',
    routes: [
        { path: '/', components: { default: Dashboard } },
        { path: '/sources', components: { default: SourcesIndex } },
        { path: '/sources/logs', components: { default: SourceLogIndex, sidebar: SourceLogSideBar } },
        { path: '/endpoints', components: { default: EndpointsIndex } },
        { path: '/endpoints/logs', components: { default: EndpointLogIndex, sidebar: EndpointLogSideBar } },
        { path: '/consumers', components: { default: ConsumersIndex } },
        { path: '/webhooks', components: { default: WebhooksIndex } },
        { path: '/jobs', components: { default: JobsIndex } },
        { path: '/jobs/logs', components: { default: JobLogIndex, sidebar: JobLogSideBar } },
        { path: '/mappings', components: { default: MappingsIndex } },
        { path: '/rules', components: { default: RulesIndex } },
        { path: '/synchronizations', components: { default: SynchronizationsIndex } },
        { path: '/synchronizations/contracts', components: { default: ContractsIndex, sidebar: ContractsSideBar } },
        { path: '/synchronizations/logs', components: { default: SynchronizationLogIndex, sidebar: LogsSideBar } },
        { path: '/cloud-events', redirect: '/cloud-events/events' },
        { path: '/cloud-events/events', components: { default: EventsIndex } },
        { path: '/cloud-events/logs', components: { default: EventLogIndex, sidebar: EventLogSideBar } },
        { path: '/import', components: { default: ImportIndex } },
        { path: '*', redirect: '/' },
    ],
})
```

The SPA router is installed in the Vue root instance:

```js
// 12:18:src/main.js
new Vue(
    {
        pinia,
        router,
        render: h => h(App),
    },
).$mount('#content')
```

## Views Composition

The app host renders the active route via `<router-view />` (default view) and exposes a named view for sidebars (see next section)

Thanks to this both the Views.vue and Sidebars.vue can be deleted as they have become redundant.

```html
// 1:999:src/App.vue
    <NcContent app-name="integriq">
        <MainMenu />
        <NcAppContent>
            <template #default>
                <router-view />
            </template>
        </NcAppContent>
        <router-view name="sidebar" />
        <Modals />
        <Dialogs />
    </NcContent>
```

## Navigation Without `navigationStore`

Navigation is now entirely route-driven. The main menu sets `active` based on `$route.path` and navigates via `$router.push()`.

```html
// 4:9:src/navigation/MainMenu.vue
            <NcAppNavigationItem :active="$route.path === '/'" :name="t('integriq', 'Dashboard')" @click="handleNavigate('/')">
                <template #icon>
                    <Finance :size="20" />
                </template>
            </NcAppNavigationItem>
```

```js
// 116:121:src/navigation/MainMenu.vue
        methods: {
            t,
            handleNavigate(path) {
                this.$router.push(path)
            },
        }
```

The legacy `navigationStore` is no longer used.

## Sidebars via Named Router Views

Sidebars are mounted per route using a named view. Each route can define `components: { default, sidebar }`, and the app template contains `<router-view name="sidebar" />`.

```js
// 1:999:src/router/index.js
{ path: '/jobs/logs', components: { default: JobLogIndex, sidebar: JobLogSideBar } }
{ path: '/synchronizations/contracts', components: { default: ContractsIndex, sidebar: ContractsSideBar } }
{ path: '/cloud-events/logs', components: { default: EventLogIndex, sidebar: EventLogSideBar } }
```

This keeps sidebar state aligned with navigation without conditional rendering components.

## SPOT: URL As The Single Source of Truth

In Integriq, log-related sidebars and contracts use SPOT. Filters are synchronized with the URL query so links are shareable and back/forward works.

Key principles:
- URL query is authoritative. Component/store state is derived from it.
- Changes to filters update the URL via a debounced writer.
- On route changes, components parse the query and update stores/state.
- Use shallow-equality to avoid write loops; use `router.replace` to avoid history noise.
- Normalize dates to ISO strings on write and validate on read.

Implemented in:
- `src/sidebars/Source/SourceLogSideBar.vue`
- `src/sidebars/Job/JobLogSideBar.vue`
- `src/sidebars/logs/LogsSideBar.vue` (synchronizations logs)
- `src/sidebars/event/EventLogSideBar.vue`
- `src/sidebars/contracts/ContractsSideBar.vue`

Build a query from current state (only include active filters):

```js
        // Build URL query from current component/store state
        buildQueryFromState() {
            const query = {}
            // Filters
            if (registerStore.registerItem) query.register = String(registerStore.registerItem.id)
            if (schemaStore.schemaItem) query.schema = String(schemaStore.schemaItem.id)
            if (this.selectedSuccessStatus && this.selectedSuccessStatus.value) query.success = String(this.selectedSuccessStatus.value)
            if (Array.isArray(this.selectedUsers) && this.selectedUsers.length > 0) query.user = this.selectedUsers.map(u => u.value || u).join(',')
            // JS dates are awful, so we first check if its a valid date and then get the ISO string.
            if (this.dateFrom) query.dateFrom = new Date(this.dateFrom).getDate() ? new Date(this.dateFrom).toISOString() : null
            if (this.dateTo) query.dateTo = new Date(this.dateTo).getDate() ? new Date(this.dateTo).toISOString() : null
            if (this.searchTermFilter) query.searchTerm = this.searchTermFilter
            if (this.executionTimeFrom) query.executionTimeFrom = String(this.executionTimeFrom)
            if (this.executionTimeTo) query.executionTimeTo = String(this.executionTimeTo)
            if (this.resultCountFrom) query.resultCountFrom = String(this.resultCountFrom)
            if (this.resultCountTo) query.resultCountTo = String(this.resultCountTo)
            return query
        },
```

Write query to the URL only when it changes, using shallow comparison and `replace`:

```js
        // Write current state into URL
        updateRouteQueryFromState() {
            if (this.$route.path !== '/search-trails') return
            const nextQuery = this.buildQueryFromState()
            if (this.queriesEqual(nextQuery, this.$route.query)) return
            this.$router.replace({ path: this.$route.path, query: nextQuery })
        },
```

Read query from the URL and apply to component/store, being robust to async list loading (retry) and validating dates:

```js
        // Read URL query and apply to component/store
        applyQueryParamsFromRoute() {
            if (this.$route.path !== '/search-trails') return
            const q = this.$route.query || {}
            // Success status
            if (typeof q.success !== 'undefined') {
                const val = String(q.success)
                const opt = this.successOptions.find(o => String(o.value) === val)
                this.selectedSuccessStatus = opt || null
            }
            // Users
            if (typeof q.user === 'string') {
                const users = q.user.split(',').map(s => s.trim()).filter(Boolean)
                this.selectedUsers = users.map(u => ({ label: u, value: u }))
            }
            // Dates and fields
            // JS dates are awful, so we first check if its a valid date and then create the date. (q.dateFrom is a ISO string)
            this.dateFrom = q.dateFrom && new Date(q.dateFrom).getDate() ? new Date(q.dateFrom) : null
            this.dateTo = q.dateTo && new Date(q.dateTo).getDate() ? new Date(q.dateTo) : null
            this.searchTermFilter = q.searchTerm || ''
            this.executionTimeFrom = q.executionTimeFrom || ''
            this.executionTimeTo = q.executionTimeTo || ''
            this.resultCountFrom = q.resultCountFrom || ''
            this.resultCountTo = q.resultCountTo || ''
            // Registers & schemas depend on lists
            const applyRegister = () => {
                if (!q.register) return true
                if (!registerStore.registerList.length) return false
                const reg = registerStore.registerList.find(r => String(r.id) === String(q.register))
                if (reg) registerStore.setRegisterItem(reg)
                return true
            }
            const applySchema = () => {
                if (!q.schema) return true
                if (!schemaStore.schemaList.length) return false
                const sch = schemaStore.schemaList.find(s => String(s.id) === String(q.schema))
                if (sch) schemaStore.setSchemaItem(sch)
                return true
            }
            const tryApply = (attempt = 0) => {
                const rOk = applyRegister()
                const sOk = applySchema()
                // Apply store filters once selection ready
                if (rOk && sOk) {
                    this.applyFilters()
                    this.loadActivityData()
                    return
                }
                if (attempt < 10) setTimeout(() => tryApply(attempt + 1), 200)
            }
            tryApply()
        },
```
```js
// write
if (this.dateFrom) query.dateFrom = getValidISOstring(this.dateFrom)
// read
this.dateFrom = q.dateFrom && new Date(q.dateFrom).getDate() ? new Date(q.dateFrom) : null
```

Additional details:
- Debounce user input before writing to URL to reduce churn.
- Arrays become comma-separated lists, booleans are stringified (`"true"|"false"`), numbers are stringified.
- Use shallow equality (`queriesEqual`) to prevent write loops.

## Modal State and SPOT

Current behavior:
- Modal visibility/state is not encoded in the URL query. Modals (e.g. entries in `src/modals/Modals.vue` such as `src/modals/configuration/DeleteConfiguration.vue`) are controlled by local/component or store state triggered by in-app actions. Refreshing or sharing a URL does not reopen a modal.

Why not in URL today:
- Avoids noisy history and accidental deep links into destructive flows.
- Keeps URLs stable while a user is mid-flow.

Possible future enhancement:
- Encode modal intent in the query, for example: `?modal=deleteConfiguration&configurationId=123`.
- Guidelines if adopted:
  - Read `modal` (and any related identifiers) on mount/route changes; validate identifiers and permissions before opening.
  - Only open the modal if the base view (route + required data) is ready.
  - Write modal params with `router.replace` on open; remove them on close to avoid history spam.
  - Preserve SPOT precedence: page and filters remain the authoritative state; modal is an optional overlay.
  - Prefer non-destructive modals for deep links; keep destructive actions gated by explicit user confirmation.

## Adding A New Page With Deeplinking & (Optional) SPOT

1. **Backend route**: Add a page route in `appinfo/routes.php` (`name` = `YourController#page`, `url` = `/your-path`). Ensure a matching controller exists in `lib/Controller/YourController.php` with `page(): TemplateResponse` returning the SPA template.
2. **Frontend route**: Add a Vue route in `src/router/index.js` with `path: '/your-path'` and a component. Optionally add a `sidebar` component via named views.
3. **Navigation**: Add an entry in `src/navigation/MainMenu.vue` and set `:active` using `$route.path` or `$route.path.startsWith('/your-path')`. Use `this.$router.push('/your-path')` on click.
4. **Sidebars**: Define `components: { default, sidebar }` for the route and let `<router-view name="sidebar" />` mount it.
5. **SPOT (optional)**: If the page has filters/state that should be shareable, implement the SPOT pattern:
   - Read from `$route.query` on mount/route change and update component/store state.
   - Write to `$router.replace({ query })` when state changes, with debounce and equality checks.
   - Ensure robust handling of async data dependencies (e.g., retry until lists are loaded).

## Example Deep Links

- Job logs filtered by job and level in a time window:
  - `/index.php/apps/integriq/jobs/logs?job_id=12&level=ERROR,WARNING&dateFrom=2024-01-01T00:00:00.000Z&dateTo=2024-12-31T23:59:59.999Z`
- Contracts page: `/index.php/apps/integriq/synchronizations/contracts`

## Potential Enhancement: Dynamic Main Menu

`src/navigation/MainMenu.vue` can be generated dynamically from routes (e.g. using `this.$router.options.routes`). This would keep navigation in sync with route definitions.

## Pitfalls and Best Practices

- **Backend route parity**: Every SPA page path must have a backend page route/controller to avoid 404 on refresh.
- **History base**: Keep `router.base` in sync with the app mount path.
- **No `navigationStore`**: Use `$route` and `$router` exclusively for navigation and active state.
- **Debounce & replace**: Debounce URL writes and use `router.replace` to avoid noisy history.
- **Validate inputs**: Validate/normalize dates and parse primitives from strings when reading from the URL.

## Quick Checklist

- Backend `appinfo/routes.php` updated with page route and controller exists.
- Frontend `src/router/index.js` has the corresponding route (and optional named `sidebar`).
- `src/App.vue` renders `<router-view />` and `<router-view name="sidebar" />`.
- `src/navigation/MainMenu.vue` uses `$router.push` and `$route.path` for active state.
- SPOT implemented where needed: read URL -> state, state -> URL with debounce and equality checks.


