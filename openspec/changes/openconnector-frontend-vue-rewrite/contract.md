# Contract: openconnector-frontend-vue-rewrite

## Overview

Chain D2 is a **frontend-only** change. No new API endpoints are introduced, and no
existing API endpoints are modified, removed, or have their response shapes changed.
The Pinia stores in `src/store/modules/` continue to front the same REST surface they
front today — D2 changes the store _implementation_ (to `createCrudStore`) but not the
URL map or HTTP contract.

This contract document is therefore brief by design: it records what other projects
that consume OpenConnector's REST API can expect to remain stable across D2, and what
internal store-level contracts the migrated `createCrudStore` modules must honour.

---

## Consumers

| Consumer | Consumes |
|----------|----------|
| `openconnector` frontend (self) | 16+ Pinia stores → REST API at `/index.php/apps/openconnector/api/*` |
| Any external Nextcloud app | The same REST API surface — D2 does not change it |
| Nextcloud Dashboard widgets | `jobQueueWidget`, `recentCallsWidget`, `sourceSyncWidget` entry points |

---

## Endpoints (unchanged)

D2 makes **zero** changes to HTTP endpoints. All endpoints remain at their existing
paths, accept the same request bodies, and return the same response shapes defined by
chain C (`openconnector-services-direct-or-usage`).

For the canonical REST endpoint list, see chain C's spec:
`openspec/changes/openconnector-services-direct-or-usage/specs/openconnector-direct-or-usage/spec.md`

---

## Internal Store Contract

Each Pinia store migrated to `createCrudStore` MUST preserve the following exported
surface so that Vue components that call the store directly do not break:

| Export | Type | Notes |
|--------|------|-------|
| `list` | `Ref<object[]>` | All loaded items |
| `current` | `Ref<object \| null>` | Selected item |
| `loading` | `Ref<boolean>` | Request in-flight flag |
| `error` | `Ref<string \| null>` | Last error message |
| `fetchAll()` | `() => Promise<void>` | Load full list |
| `fetchOne(id)` | `(id: string) => Promise<void>` | Load single item |
| `create(data)` | `(data: object) => Promise<object>` | POST |
| `update(id, data)` | `(id, data) => Promise<object>` | PUT/PATCH |
| `delete(id)` | `(id: string) => Promise<void>` | DELETE |

Resource-specific extensions (e.g. `testSource()`, `runJob()`, `triggerSync()`) are
preserved as additional exports alongside the base `createCrudStore` surface.

---

## Versioning

API version: unchanged (no version field added or removed).

D2 introduces no new HTTP endpoints and no new response fields. The `createCrudStore`
migration is a frontend-internal change; the HTTP contract is identical to the state
after chain C.

---

## Breaking Change Policy

D2 contains no breaking API changes for external consumers. Any future breaking change
to the REST API requires a separate chain with its own contract document, advance notice
to consuming projects, and a version bump in the API path.

---

## SLA

Unchanged from pre-D2 baseline. OpenConnector's REST endpoints target:
- Response time: < 500ms for list endpoints with ≤ 100 items
- Availability: same as Nextcloud instance uptime

---

## Widget Entry Points

The three Nextcloud Dashboard widgets are **relocated** from `src/` to `src/widgets/`
but remain separate webpack entry points in `webpack.config.js`. Their Nextcloud
Dashboard API registration (PHP side, in `appinfo/`) is unchanged. External consumers
(Nextcloud Dashboard) continue to load the same widget bundle names; only the source
file path changes.

| Widget | Old source path | New source path | Bundle name |
|--------|----------------|-----------------|-------------|
| Job Queue | `src/jobQueueWidget.js` | `src/widgets/jobQueueWidget.js` | `jobQueueWidget` |
| Recent Calls | `src/recentCallsWidget.js` | `src/widgets/recentCallsWidget.js` | `recentCallsWidget` |
| Source Sync | `src/sourceSyncWidget.js` | `src/widgets/sourceSyncWidget.js` | `sourceSyncWidget` |
