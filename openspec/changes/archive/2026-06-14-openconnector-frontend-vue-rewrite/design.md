# Design: openconnector-frontend-vue-rewrite

## Context

OpenConnector's frontend was assembled before `@conduction/nextcloud-vue` introduced
manifest-driven shell components. Chain D1 added `src/manifest.json` at Tier 1
(manifest available, not yet consumed by `CnAppRoot`). Chain D2 completes the Tier-4
upgrade and integrates Thijn's 9-PR resource-page refactor.

Current state:
- `src/main.js` — imperative bootstrap: VueRouter + hand-wired App.vue.
- `src/navigation/MainMenu.vue` — hardcoded nav items.
- `src/router/index.js` — 20+ hand-declared routes.
- `src/modals/Modals.vue` — aggregator mounting 30+ modals simultaneously.
- `src/store/modules/*.ts` — 16+ stores with hand-rolled `fetch()` calls.
- `src/Controller/`, `src/Mapper/` — dead PHP fragments (per ADR-006).
- `src/settings.js` — separate webpack entry for admin settings.
- `src/jobQueueWidget.js`, `src/recentCallsWidget.js`, `src/sourceSyncWidget.js` — 
  Dashboard widget entry points in `src/` root.

Target state:
- `src/main.js` — Tier-4 bootstrap: `CnAppRoot` + `routesFromManifest()` (decidesk pattern).
- `src/navigation/` — deleted.
- `src/router/index.js` — deleted (routes built from manifest at boot).
- `src/modals/Modals.vue` — deleted; each resource page uses `CnIndexPage` slot injection.
- `src/store/modules/*.ts` — all migrated to `createCrudStore`.
- `src/Controller/`, `src/Mapper/` — deleted.
- `src/settings.js` — deleted; Settings route declared in manifest, rendered by `CnPageRenderer`.
- `src/widgets/jobQueueWidget.js` etc. — relocated, same webpack entry names.

## Goals / Non-Goals

**Goals:**
- Tier-4 manifest adoption (ADR-024).
- Zero orphaned entry points after D2.
- All 16+ stores on `createCrudStore` (unified HTTP-layer pattern within ADR-001).
- All 10 resource pages on `CnIndexPage`.
- Dead code deleted (ADR-006).

**Non-Goals:**
- No PHP changes.
- No new nc-vue components authored in openconnector.
- No E2E test coverage (chain E).
- No Dashboard widget manifest registration (Nextcloud Dashboard API does not use
  `manifest.json`; widgets register via PHP).

## ADR-031: Declarative vs Imperative

D2 is `kind: code` (per ADR-032): the Vue components implementing each resource page
are imperative TypeScript/Vue code. However, the manifest-driven approach introduces
a declarative meta-layer: the _routing_, _navigation ordering_, and _page type_ for
each resource are expressed as data in `src/manifest.json` (added in D1, `kind: config`).

The apply agent should note this nuance: adding a new resource in future requires
updating `manifest.json` (declarative, D1 convention) AND writing the Vue page
component (imperative, D2 convention). The two concerns are cleanly separated:
the manifest declares _what exists_; the components define _how it renders_.

## Decisions

### Decision 1: Bootstrap pattern — decidesk `routesFromManifest()` approach

The decidesk `src/main.js` establishes the canonical Tier-4 bootstrap:

```js
function routesFromManifest(manifest) {
  const routes = manifest.pages.map((page) => ({
    name: page.id,
    path: page.route,
    component: RoutePageRenderer,
    props: page.route.includes(':'),
  }))
  routes.push({ path: '*', redirect: '/' })
  return routes
}
const router = new VueRouter({
  mode: 'history',
  base: generateUrl('/apps/openconnector'),
  routes: routesFromManifest(bundledManifest),
})
```

OpenConnector D2 uses the same pattern verbatim. `CnPageRenderer` (from nc-vue) renders
each page by looking up the manifest page's `type` in the `pageTypes` registry. Custom
pages (Import, Settings) are registered as `customComponents` entries.

**Alternative considered:** Keeping the existing `src/router/index.js` and just adding
`CnAppRoot` to `App.vue`. Rejected because it would leave two route definitions in sync
(manifest `pages[]` and router) — maintenance burden and source of drift.

### Decision 2: Per-resource stores remain per-resource (ADR-001)

ADR-001 explicitly states: "Keep the 16+ resource-specific Pinia stores under
`src/store/modules/` as-is. Do NOT migrate them to `createObjectStore`."

D2's store migration changes the _implementation_ (from hand-rolled `fetch()` to
`createCrudStore`) but keeps the _per-resource granularity_. Each store retains its
domain-specific extensions (e.g. `testSource()` on the source store, `runJob()` on the
job store). `createCrudStore` provides the base CRUD surface; extra actions are appended
on top.

**Alternative considered:** Replacing all stores with a single generic `createObjectStore`
(the `openregister` fleet pattern). Rejected by ADR-001 — connector-domain stores encode
integration-specific UX that does not fit the generic object-store shape.

### Decision 3: Settings migration — router route, not separate entry point

D1's DEFERRED_QUESTIONS item 2 asked whether `settings.js` should stay as a separate
entry point (mounting onto `#settings` in the Nextcloud admin settings DOM) or be
migrated to a vue-router `/settings` route.

**Decision: migrate to vue-router route.** The manifest declares
`{ "id": "Settings", "route": "/settings", "type": "settings" }`. D2 implements the
Settings page as a `CnPageRenderer`-rendered route, rendered inside the standard
`CnAppRoot` shell. The `#settings` DOM node in Nextcloud admin is a Nextcloud-native
entry point for system-wide admin settings; OpenConnector's app-level settings belong
in the app shell, not the system admin panel.

**Risk:** If any Nextcloud admin menu link points to the `#settings` fragment, it will
break. The apply agent must check `appinfo/settings.xml` and any
`INavigationManager::add()` calls that reference the `settings` entry point.

### Decision 4: Modals.vue aggregator deletion

`src/modals/Modals.vue` mounts all 30+ modals on every page load. CnIndexPage provides
built-in create/edit/delete modal slots, injected lazily when the page opens. After
each resource page is migrated to `CnIndexPage`, the corresponding modals in
`src/modals/{Resource}/` become unused.

**Deletion strategy:** modals are deleted resource-by-resource as part of each Thijn PR
merge task. `Modals.vue` itself is deleted in the final cleanup task after all 10
resource pages are migrated.

**Sidebars are kept.** Per ADR-010 and product convention, `src/sidebars/{Resource}/`
detail panels are preserved. They are rendered by the manifest `sidebarTabs[]` config
inside `CnPageRenderer` detail pages.

### Decision 5: Widget relocation to `src/widgets/`

D1's DEFERRED_QUESTIONS item 1 asked whether widgets should stay as separate entry
points or get a manifest `widgets[]` section.

**Decision: keep as separate webpack entry points, relocate to `src/widgets/`.** The
Nextcloud Dashboard API requires each widget to be a separate JS bundle registered
by the PHP backend. The manifest schema v1.0 has no `widgets[]` section. Moving the
files to `src/widgets/` is a housekeeping improvement that does not change the entry
names in `webpack.config.js`.

### Decision 6: Thijn's PRs applied via `git merge` (no re-writing commits)

The 9 PRs (#718-#810) are stacked on `feature/nextcloud-vue`. Each PR's commits are
applied by running `gh pr merge --merge` (no squash) so that Thijn's authorship is
preserved. The apply agent resolves any conflicts locally before merging. The exact
merge order is defined in tasks.md.

## Risks / Trade-offs

- [`CnIndexPage` bundle inflation] → Mitigated by per-page lazy routes (vue-router
  code-splitting). CI gate: `npm run build -- --analyze` to verify chunk sizes.
- [Settings admin panel DOM] → The existing `settings.js` Nextcloud admin entry
  (`#settings` fragment) may have an `appinfo/settings.xml` registration. If found,
  the admin settings panel must be split: app-level settings go in the router route;
  any true system-admin settings (if they exist) keep the `settings.js` entry point.
- [Flow token plumbing] → ADR-011: `synchronization.ts` store triggers
  `synchronizeContract()` with a flow-token header. `createCrudStore`'s base `update()`
  action does not know about flow tokens. The store migration for `synchronization.ts`
  must preserve the custom `triggerSync()` action that sets the `X-Flow-Token` header.
- [l10n orphan keys] → Deleting 30+ modal components may leave orphan keys in
  `l10n/en.json`. Thijn's #743 adds the l10n tooling; apply it before the modal cleanup
  task.

## Migration Plan

D2 is a frontend-only change with no DB schema changes and no Nextcloud migration
classes. A separate `migration.md` file is not required.

The "migration" in the human sense is the per-resource page transition:

1. Merge Thijn PRs in order (tasks 1-10).
2. Delete dead-code directories (task 11).
3. Rewrite `src/main.js` bootstrap (task 12).
4. Migrate remaining stores (task 13).
5. Delete `Modals.vue` aggregator (task 14).
6. Run lint + build + bundle-size check (task 15).
7. Playwright smoke test (task 16).

Each task is independently revertable via `git revert`.

## Open Questions

1. Does `appinfo/settings.xml` register a system-admin settings entry that points to
   the `settings.js` bundle? If yes, the Settings migration (Decision 3) needs a split.
2. Does the currently pinned `@conduction/nextcloud-vue` export `createCrudStore`?
   (Check during Task 0 — pin verification.)
3. Are any of the 30+ modals in `src/modals/` imported by sidebars (not just by
   `Modals.vue`)? A pre-deletion grep must check `src/sidebars/` imports.
