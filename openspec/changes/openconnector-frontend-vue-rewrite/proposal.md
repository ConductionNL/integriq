---
kind: code
depends_on:
  - openconnector-app-manifest
  - openconnector-services-direct-or-usage
---

# Proposal: openconnector-frontend-vue-rewrite

## Why

OpenConnector's frontend was built incrementally over multiple sprints. The result is
a hand-rolled UI convention (per ADR-010) that pre-dates the `@conduction/nextcloud-vue`
library: 10+ index views under `src/views/`, 30+ modal components aggregated by a single
`Modals.vue`, and 16+ per-resource Pinia stores that each hand-wire their own fetch
calls. Thijn has already done the per-resource refactor work across 9 pull requests
(#718-#810) on the `feature/nextcloud-vue` stacked branch tree, but those PRs cannot
ship until the manifest (chain D1) and the backend cleanup (chain C) are both merged.

Chain D2 is the integration step: apply Thijn's PR stack in merge order, bootstrap the
manifest-driven app shell (Tier 4), delete the dead-code PHP fragments from `src/`
(per ADR-006), and remove the hand-rolled navigation layer that the manifest now replaces.

## What Changes

- `src/main.js` is rewritten to bootstrap `CnAppRoot` from `@conduction/nextcloud-vue`
  using the manifest authored in D1, replacing the imperative router + nav wiring.
- `src/Controller/` and `src/Mapper/` PHP fragments are deleted (per ADR-006).
- `src/navigation/` is deleted (manifest-driven nav replaces it).
- `src/settings.js` is removed as a separate entry point; Settings becomes a vue-router
  route declared in the manifest and rendered by `CnPageRenderer`.
- Three widget entry points (`src/jobQueueWidget.js`, `src/recentCallsWidget.js`,
  `src/sourceSyncWidget.js`) are relocated from `src/` to `src/widgets/` and kept as
  separate webpack entries (Nextcloud Dashboard API requires per-widget bundles).
- 10 resource pages (Sources, Endpoints, Consumers, Mappings, CloudEvents,
  Synchronizations, SyncContracts, Rules, Import, Dashboard) are each migrated to
  `CnIndexPage` + `createCrudStore` by applying Thijn's PRs in order (#718 → #810).
- 16+ Pinia store modules are migrated to `createCrudStore({apiEndpoint, schemaSlug})`
  (per ADR-001, stores remain per-resource; only the implementation changes).
- Per-resource dialogs and Modals.vue aggregator are deleted where CnIndexPage handles
  them; sidebars are preserved (per ADR-010 and product convention).

## Impact

- **Frontend only** — no PHP services, controllers, or DB changes.
- 10 resource index views replaced by `CnIndexPage`-based pages.
- 30+ modal components replaced by `CnIndexPage`'s built-in schema-driven forms.
- 16+ Pinia stores unified under `createCrudStore`.
- `src/navigation/`, `src/Controller/`, `src/Mapper/` directories deleted.
- `src/settings.js` entry point deleted; settings mounts via router.
- Widget entry points moved to `src/widgets/`; webpack config updated.
- `npm run lint`, `npm run build` and Playwright E2E tests must pass post-merge.

## Summary

Applies Thijn's 9-PR stack (#718-#810) onto `development`, bootstraps the
manifest-driven Tier-4 app shell, deletes dead-code PHP fragments and the hand-rolled
nav layer, and migrates all 16+ Pinia stores to `createCrudStore`. The result is a
fully manifest-driven OpenConnector frontend with no orphaned entry points and a
schema-driven CnIndexPage for every resource.

## Motivation

- **Thijn's work is ready**: PRs #719-#810 are on stacked branches off `feature/nextcloud-vue`.
  D1 and C are the only blockers. Merging them now prevents the branch tree from
  diverging further from `development`.
- **ADR-006 compliance**: `src/Controller/JobLogController.php` and
  `src/Mapper/JobLogMapper.php` are dead code (no PHP class declarations, no Vue imports)
  that should be deleted before onboarding new contributors.
- **Tier-4 completion**: D1 landed `src/manifest.json` at Tier 1. D2 wires `CnAppRoot`
  to reach Tier 4, aligning OpenConnector with the fleet-wide ADR-024 convention.
- **Bundle size**: the `Modals.vue` aggregator mounts all 30+ modals on every page load.
  CnIndexPage uses lazy slot injection, reducing the initial bundle.

## Affected Projects

- [x] Project: `openconnector` — rewrite of `src/main.js`, deletion of orphaned
  directories, migration of 10 resource pages and 16+ stores, widget relocation.
- [ ] Project: `@conduction/nextcloud-vue` — passive; D2 consumes the existing
  `CnAppRoot`, `CnIndexPage`, `CnStatsPanel`, `createCrudStore` exports.
  A version bump may be required if D1's `check:manifest` gate revealed a missing
  page type (see D1 DEFERRED_QUESTIONS item 3).

## Scope

### In Scope

- Bootstrap `CnAppRoot` in `src/main.js` using `src/manifest.json` (Tier 4, per ADR-024).
- Delete `src/Controller/`, `src/Mapper/` (per ADR-006).
- Delete `src/navigation/` (manifest replaces it).
- Delete `src/settings.js`; migrate Settings to a vue-router route.
- Relocate 3 widget JS files to `src/widgets/`; update `webpack.config.js`.
- Apply Thijn PRs #718-#810 in merge order (exact order in tasks.md).
- Migrate each of the 16+ Pinia stores to `createCrudStore` (per ADR-001 — stores
  remain per-resource, only the implementation pattern changes).
- Delete `src/modals/Modals.vue` aggregator and per-resource dialog components once
  `CnIndexPage` handles them; keep sidebars.
- CI gate: `npm run lint:fix && npm run lint && npm run build` must pass.
- Bundle-size gate: ≤10% regression vs `feature/nextcloud-vue` baseline.
- All 23 manifest pages must resolve their components.

### Out of Scope

- No PHP changes (backend is stable after chain C).
- No new API endpoints.
- No DB schema changes.
- No new `@conduction/nextcloud-vue` components (D2 consumes existing ones).
- Chain E (`openconnector-comprehensive-tests`) — E2E + PHPUnit coverage is a separate
  chain link.
- Nextcloud Dashboard widget manifest registration (schema v1.0 has no `widgets[]`
  section; widget discovery is via the Nextcloud Dashboard API, not `manifest.json`).
- `type: "logs"` availability in the nc-vue pin — if the pin is too old, `type:
  "custom"` was already substituted in D1; D2 carries that forward and a separate bump
  PR restores the correct type.

## Approach

1. Verify `@conduction/nextcloud-vue` pin has `CnAppRoot`, `CnIndexPage`,
   `CnStatsPanel`, `createCrudStore` (versions released in the `add-json-manifest-renderer`
   and `crud-store` milestones).
2. Apply Thijn's PRs in dependency order using `gh pr merge` or `git merge` (no
   re-writing Thijn's commits).
3. Rewrite `src/main.js` to bootstrap `CnAppRoot` with `bundledManifest`, routing built
   from `manifest.pages[]` via `routesFromManifest()` (decidesk precedent).
4. Delete orphaned directories and entry points.
5. Migrate remaining stores not covered by Thijn's PRs to `createCrudStore`.
6. Run `npm run lint:fix && npm run build`; fix any orphaned imports.
7. Playwright smoke test: all 13 nav items render; add/edit/delete flows work for
   Sources and Mappings (highest-risk resources).

## New Dependencies

No new npm packages. `createCrudStore` and `CnIndexPage` already ship in the pinned
`@conduction/nextcloud-vue` from chain D1. A pin bump may be needed if the pin did not
yet include those exports at the time D1 merged.

## Cross-Project Dependencies

- **Depends on D1** (`openconnector-app-manifest`): `src/manifest.json` must exist and
  pass `check:manifest` before D2 touches `src/main.js`.
- **Depends on C** (`openconnector-services-direct-or-usage`): the backend REST API
  surface must be stable before D2's stores are rewritten; the Pinia stores front the
  REST layer, not the mappers.
- **Upstream**: `@conduction/nextcloud-vue` must export `CnAppRoot`, `CnIndexPage`,
  `CnStatsPanel`, `createCrudStore`; confirm pin before applying Thijn's PRs.

## Risks

### Risk 1: Thijn's stacked PRs have merge conflicts after C and D1

**Severity:** High — **Mitigation:** tasks.md specifies exact merge order. The apply
agent runs `gh pr diff` on each PR before merging, resolves conflicts locally, then
creates a squash merge commit. Each resource page is a separate commit so conflicts are
isolated to one resource at a time.

### Risk 2: Deleting `src/Controller/` and `src/Mapper/` breaks unexpected callers

**Severity:** High — **Mitigation:** pre-deletion grep confirms zero Vue/TS/JS imports
from those paths (`grep -r "Controller\|Mapper" src/ --include="*.vue" --include="*.ts" --include="*.js"`). Only if grep returns no output do we proceed with deletion.

### Risk 3: `@conduction/nextcloud-vue` pin too old for `CnIndexPage` / `createCrudStore`

**Severity:** Medium — **Mitigation:** Task 1 in tasks.md is an explicit pin check.
If the pin is below the required version, a separate bump PR is opened before any
page migration starts.

### Risk 4: Widget entry points break after relocation to `src/widgets/`

**Severity:** Medium — **Mitigation:** `webpack.config.js` entry map is updated in the
same commit that moves the files. Nextcloud app developer tooling resolves entries by
file path, not by convention, so the rename is safe as long as the entry map is updated.

### Risk 5: Bundle size regression >10% from `CnIndexPage` + `createCrudStore`

**Severity:** Medium — **Mitigation:** webpack code-splitting per page (each resource
page is a lazy route); `npm run build -- --analyze` during verification to spot
unexpected chunks.

### Risk 6: i18n orphan keys after modal/view deletion

**Severity:** Low — **Mitigation:** `npm run lint` catches orphan keys if the l10n
tooling from Thijn's #743 (Mappings + l10n tooling) is applied first.

## Rollback Strategy

All D2 changes live on a feature branch (`feature/D2-frontend-vue-rewrite`) off
`development`. If post-merge issues are found, revert the merge commit on `development`.
The manifest (`src/manifest.json`) is preserved (D1 owns it) — D2 only adds the
`CnAppRoot` consumer. If `CnAppRoot` must be removed, revert `src/main.js` to the D1
state (Tier 1: `useAppManifest` only, no `CnAppRoot`). The old `src/navigation/` and
`src/router/` are recoverable from git history.

## Open Questions

1. **`createCrudStore` signature**: Thijn's PRs use `createCrudStore({apiEndpoint,
   schemaSlug})` — confirm the exact function signature against the current nc-vue pin
   before migrating stores not covered by Thijn's PRs.
2. **Settings admin panel**: current `settings.js` mounts onto `#settings` (Nextcloud
   admin settings DOM). If `CnPageRenderer` cannot target an arbitrary DOM id, the
   admin settings panel may need a separate mount point preserved. Investigate during
   Task 4 (settings migration).
3. **Flow token plumbing in stores** (ADR-011): the `synchronization.ts` store triggers
   `synchronizeContract()` calls that may inject a flow token. Confirm the store
   migration preserves any flow-token headers passed by the frontend.
