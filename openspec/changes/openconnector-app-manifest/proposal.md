---
kind: config
depends_on:
  - openconnector-register-storage
---

# Proposal: openconnector-app-manifest

## Why

OpenConnector's frontend shell is currently assembled imperatively from four separate
entry points: `main.js` (mounts the Vue app), `navigation/MainMenu.vue` (hardcodes the
nav items), `settings.js` (mounts the admin settings panel), plus three widget entry
points (`jobQueueWidget.js`, `recentCallsWidget.js`, `sourceSyncWidget.js`). This
duplicates the boilerplate that `@conduction/nextcloud-vue`'s `CnAppRoot` / `CnAppNav` /
`CnPageRenderer` was built to eliminate, and leaves the admin App Builder with nothing to
inspect, reorder, or hide — because the nav lives only in Vue component code.

ADR-024 mandates that every Conduction app ship a `src/manifest.json` validated against
the canonical schema. As the integration bus at the heart of the Conduction stack,
OpenConnector should lead the fleet adoption rather than lag it.

## What Changes

- A single new file — `src/manifest.json` — declares OpenConnector's navigation menu,
  all page routes, dependency list (`openregister`), and settings configuration.
- One thin-glue line in `src/main.js` imports the manifest and passes it to
  `useAppManifest('openconnector', bundledManifest)` (per ADR-024 §3).
- `package.json` gains a `check:manifest` script that calls `validateManifest` from
  `@conduction/nextcloud-vue` at build time; CI fails on schema errors.

No existing files are removed in this change. `navigation/MainMenu.vue`, `settings.js`,
and the widget entry points are **preserved** until chain D2
(`openconnector-frontend-vue-rewrite`) replaces them with manifest-driven components.

## Impact

Chain D1 is frontend-only and config-only. No PHP is touched. No routes change.
No API endpoints are added or removed. The manifest file is bundled by webpack as a
static JSON import; it does not add an HTTP endpoint or affect the Nextcloud router.

## Summary

Adds `src/manifest.json` — OpenConnector's declarative frontend manifest — listing 13
navigation entries and 23 page definitions (index + detail pairs for all resources,
plus dashboard, settings, and a documentation external link). Pairs with a
`check:manifest` CI gate and a single `useAppManifest` bootstrap call in `main.js`.
The manifest renderer itself lives in `@conduction/nextcloud-vue` (ADR-032: this
change is `kind: config`, not `kind: code`); chain D2 will wire up `CnAppRoot` and
`CnPageRenderer` to consume it.

## Motivation

- **Admin App Builder needs a manifest to inspect.** The planned cross-app App Builder
  (`/api/manifest` consumers, ADR-024 §4) cannot discover or override nav entries that
  live only in Vue component code. D1 lays the foundation.
- **Eliminate duplication now, not later.** Every new resource Thijn adds in the D2 PR
  stack (#719–#810) would need to be registered in *both* the Vue nav component *and*
  the manifest if D1 ships after D2. Shipping D1 first means D2 only declares routes in
  one place.
- **Fleet convention.** ADR-024 is proposed; OpenConnector adopting the manifest before
  D2 ships makes the convention concrete for the rest of the fleet.

## Affected Projects

- [x] Project: `openconnector` — adds `src/manifest.json`; adds 1-2 LOC thin-glue
  to `src/main.js`; adds `check:manifest` script to `package.json`
- [ ] Project: `openregister` — passive; no code changes (manifest declares it as a
  dependency, which `CnAppRoot` will check at runtime once D2 ships)

## Scope

### In Scope

- `src/manifest.json` with `$schema`, `version`, `dependencies`, `menu[]`, and `pages[]`
  arrays covering all 13 nav entries and 23 pages currently exposed by the Vue router.
- Import and `useAppManifest` bootstrap call in `src/main.js` (1–2 LOC, qualifies as
  thin-glue under ADR-032 §thin-glue exception).
- `check:manifest` script in `package.json` wired to `validateManifest` from
  `@conduction/nextcloud-vue`.
- Verification that the manifest validates against the canonical schema
  (`app-manifest.schema.json`).

### Out of Scope

- `CnAppRoot`, `CnAppNav`, `CnPageRenderer` integration (chain D2 owns this).
- Deletion of `src/navigation/MainMenu.vue`, `src/settings.js`, `src/jobQueueWidget.js`,
  `src/recentCallsWidget.js`, `src/sourceSyncWidget.js` (chain D2 owns this).
- `createCrudStore` calls per resource (driven from manifest config in D2).
- The `GET /api/manifest` backend endpoint for admin overrides (ADR-024 §4 opt-in;
  out of scope for this config-only change).
- Any PHP changes.

## Approach

1. Read `decidesk/src/manifest.json` as the canonical Tier-4 reference.
2. Enumerate all routes from `src/router/index.js` and nav entries from
   `src/navigation/MainMenu.vue` to populate `menu[]` and `pages[]`.
3. Declare `dependencies: ["openregister"]` (confirmed by `appinfo/info.xml` — openconnector
   peer-depends on openregister at runtime).
4. Add `import bundledManifest from './manifest.json'` and
   `useAppManifest('openconnector', bundledManifest)` to `src/main.js` — the composable
   is exported from `@conduction/nextcloud-vue` (already a dependency).
5. Add `"check:manifest": "validateManifest src/manifest.json"` to `package.json`.

The manifest renderer is already shipped in `@conduction/nextcloud-vue`; no library
changes are required for D1.

## New Dependencies

None. `@conduction/nextcloud-vue` is already listed in `package.json` and already
exports `useAppManifest` and `validateManifest`.

## Impact

### New code
- `src/manifest.json` — ~200 LOC JSON config

### Modified code
- `src/main.js` — 1–2 LOC import + bootstrap call
- `package.json` — add `check:manifest` script

### Unchanged
- All PHP (`lib/`, `appinfo/`)
- All Vue components (`src/views/`, `src/navigation/`, `src/store/`, `src/components/`)
- All widget entry points
- `src/router/index.js`

## Cross-Project Dependencies

- **Depends on**: `openconnector-register-storage` (chain B) — manifest declares
  `openregister` as a runtime dependency; the register schemas that openconnector stores
  data in must be provisioned before `CnAppRoot`'s dependency check can pass. D1 itself
  is frontend-only and ships the manifest as a static file; the dependency check is
  enforced at runtime by `CnAppRoot` in D2.
- **No chain-C dependency**: this change is frontend-only config. Chain C
  (`openconnector-services-direct-or-usage`) refactors the PHP service layer and does not
  affect the manifest's content.
- **@conduction/nextcloud-vue**: `useAppManifest` and `validateManifest` must be present
  in the pinned version. Verify the current `package.json` pin is ≥ the version that
  ships the manifest renderer (shipped in `add-json-manifest-renderer`).

## Risks

### Risk 1: Manifest renderer not present in current nextcloud-vue pin
**Severity:** Medium — **Mitigation:** Read `package.json` before authoring D1; if the
pin pre-dates the manifest renderer, note in tasks.md but do NOT bump the pin in this
change (a version bump is a separate code change). The `check:manifest` script will
still validate the JSON statically even without the runtime composable.

### Risk 2: Manifest schema drifts from decidesk reference
**Severity:** Low — **Mitigation:** The `check:manifest` CI gate validates against the
published schema on every commit. Schema mismatch fails CI immediately; no silent drift.

### Risk 3: nav/page enumeration misses a resource (incomplete manifest)
**Severity:** Low — **Mitigation:** Cross-reference `src/router/index.js` and
`src/navigation/MainMenu.vue` exhaustively during authoring. Any route present in the
router but absent from the manifest is surfaced by the ADR-029 route-reachability gate
when D2 lands.

## Rollback Strategy

D1 adds only new files and 1–2 LOC to `main.js`. Rollback:
1. Delete `src/manifest.json`.
2. Revert the `useAppManifest` import/call in `src/main.js`.
3. Remove the `check:manifest` script from `package.json`.

No database, migration, or API change is required; rollback is instantaneous.
