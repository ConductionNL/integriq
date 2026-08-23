---
kind: code
depends_on: []
---

## Why

Three `package.json` dependency issues inflate integriq's install size and
`node_modules` footprint with weight the bundle never uses or duplicates what
`@conduction/nextcloud-vue` already ships:

- **`apexcharts` (`^3.50.0`) and `vue-apexcharts` (`~1.6.2`) are declared dependencies
  with zero usage anywhere in `src/`** — `grep -rln "apexcharts" openconnector/src
  --include=*.js --include=*.vue` returns no matches. Per fleet convention
  (`feedback_shared-deps.md`: "apexcharts from nc-vue, fortawesome NOT"), charting
  should come from `@conduction/nextcloud-vue`, which already bundles `apexcharts@^4.7.0`
  / `vue-apexcharts@^1.7.0` (`nextcloud-vue/package.json:80,90`) — integriq's own
  copies are pure dead weight in `npm install` and the dependency tree, and if anyone
  ever does start using charts here without noticing the nc-vue copy, webpack would
  bundle **two different major versions of apexcharts** (3.x app-local + 4.x via
  nc-vue's aliasing) rather than one shared instance.
- **`@fortawesome/fontawesome-svg-core` and `@fortawesome/free-solid-svg-icons` are
  declared dependencies with zero usage anywhere in `src/`** —
  `grep -rln "fortawesome" openconnector/src --include=*.js --include=*.vue` returns no
  matches. Unlike apexcharts, fortawesome is explicitly NOT supposed to come from nc-vue
  (per the same fleet convention), so this isn't a duplication issue — it's simply an
  unused dependency that should be dropped.
- **`lodash` (full package, `^4.17.21`) is imported wholesale for two call sites that use
  a single function each**: `src/modals/Endpoint/EditEndpoint.vue:122` does
  `import _ from 'lodash'` and only calls `_.toString()` (lines 311, 414);
  `src/views/wrappers/MappingDetailPage.vue:170` already does the right thing
  (`import debounce from 'lodash/debounce.js'`). The `EditEndpoint.vue` full import
  pulls in lodash's entire namespace for one three-line utility function that has a
  trivial inline replacement (`String(x)`), unlike `debounce` which is legitimately
  worth importing from the submodule.

None of these three are new regressions from a recent PR — they are accumulated
dependency drift the 2026-07-07 code review's correctness-focused pass did not check
bundle composition for.

## What Changes

- Remove `apexcharts` and `vue-apexcharts` from `package.json` `dependencies` (zero
  usage; nc-vue already provides both, one version, for any future chart need).
- Remove `@fortawesome/fontawesome-svg-core` and `@fortawesome/free-solid-svg-icons`
  from `package.json` `dependencies` (zero usage).
- Replace `import _ from 'lodash'` in `src/modals/Endpoint/EditEndpoint.vue:122` with an
  inline `String(value)` (or, if a team prefers keeping the lodash call site,
  `import toString from 'lodash/toString.js'`) at both call sites
  (`EditEndpoint.vue:311,414`) — do not import the full package for a single function.
- No behavior change: none of the removed packages are imported, so removing them is a
  pure dependency-hygiene change with zero runtime risk.

## Impact

- **`package.json`** — 4 dependencies removed.
- **`package-lock.json`** — regenerated (`npm install`); expect the lockfile's
  `node_modules` tree to shrink (apexcharts + vue-apexcharts + 2 fortawesome packages
  and their transitive deps).
- **`src/modals/Endpoint/EditEndpoint.vue`** — `import _ from 'lodash'` replaced; two
  call sites updated.
- No webpack config change needed — nothing in `webpack.config.js` special-cases these
  packages.
- No test/spec impact — these packages have no scenario coverage because they have no
  behavior in this app.
