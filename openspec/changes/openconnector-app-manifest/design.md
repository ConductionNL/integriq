# Design: openconnector-app-manifest

## Architecture Overview

This is a **pure config change** under ADR-032. No new service classes are introduced.
No PHP is touched. The sole deliverable is one JSON file (`src/manifest.json`) plus a
two-line glue change in `src/main.js` and a script addition in `package.json`.

```
src/
  manifest.json          ← NEW: declarative app shell descriptor
  main.js                ← MODIFIED: import + useAppManifest call (2 LOC)
package.json             ← MODIFIED: add "check:manifest" script
```

The manifest renderer — `CnAppRoot`, `CnAppNav`, `CnPageRenderer`, `useAppManifest` —
lives entirely in `@conduction/nextcloud-vue`. OpenConnector provides the data file;
the library provides the engine. This separation is the core principle of ADR-024 and
ADR-032: declarative configuration, not imperative service classes.

### How the manifest is consumed (chain D2, not D1)

D1 loads the composable so it is available; D2 actually wires `CnAppRoot`:

```
src/main.js (D1)
  import bundledManifest from './manifest.json'
  import { useAppManifest } from '@conduction/nextcloud-vue'
  const { manifest } = useAppManifest('openconnector', bundledManifest)
  // manifest ref is available; D2 passes it to CnAppRoot

src/App.vue (D2)
  <CnAppRoot :manifest="manifest" appId="openconnector" :t="t" />
```

The three-phase orchestration (loading → dependency-check → shell) is entirely inside
`CnAppRoot`; no per-app service logic is needed.

## Decisions

### Decision 1: Tier choice — Tier 1 now, Tier 4 in D2

ADR-024 §8 defines four tiers:
- Tier 1: `useAppManifest` only (manifest validated, available, not yet driving UI)
- Tier 4: full `CnAppRoot` shell (nav, pages, dependency guard all manifest-driven)

D1 lands at **Tier 1**. D2 completes the Tier-4 adoption. This split keeps D1 as pure
config (no UI behaviour changes, no risk of rendering regressions) while making the
manifest available for the CI gate and for early App Builder tooling.

Alternatives considered:
- *Land Tier 4 in D1*: would couple the config file to untested Vue component wiring;
  Thijn's D2 PR stack (#719-#810) is not yet merged, so `CnAppRoot` would have nothing
  to render against known-good routes. Risk too high.
- *Skip Tier 1 entirely, go straight to Tier 4 in D2*: loses the value of D1's CI gate
  and the manifest-as-source-of-truth for the App Builder before D2 ships.

### Decision 2: Page coverage — index + detail for every resource

The manifest declares both an `index` page and a `detail` page (route `/resource/:id`)
for every CRUD resource. Thijn's D2 PRs use this pattern. Declaring both now means D2
only needs to implement the components; no manifest edits are needed in D2 for standard
pages.

Custom pages that don't fit `index`/`detail` (Import, Settings) use `type: "custom"`.

### Decision 3: `icon-*` class strings copied from decidesk reference

ADR-024 does not mandate MDI icon names in the manifest. The decidesk reference uses
Nextcloud `icon-*` CSS classes (`icon-category-dashboard`, `icon-file`, etc.). This
change matches that convention exactly rather than inventing MDI names, keeping the
manifest compatible with `CnAppNav`'s current rendering logic.

### Decision 4: `section: "settings"` for nav items in NcAppNavigationSettings

`MainMenu.vue` currently places Import and Settings inside `<NcAppNavigationSettings>`.
The manifest replicates this by annotating those menu items with `"section": "settings"`.
This matches the decidesk pattern (`SettingsMenu`, `Documentation` both have
`"section": "settings"`).

### Decision 5: ADR-031 — all behaviour declared, no service classes added

Per ADR-031, the manifest's business logic (page routing, nav ordering, dependency
checking) is expressed as data in `manifest.json`. The renderer (`CnAppRoot`) is in the
library, not in openconnector. This change adds zero service classes, zero controllers,
and zero PHP logic.

## ADR-032: Declarative-vs-Imperative

This change is `kind: config`. The manifest renderer lives in `@conduction/nextcloud-vue`,
not in openconnector. D1 provides only the declarative data; D2 will provide the
consumption wiring via library components. No service classes are added in D1.

## Security Considerations

No security impact. The manifest is a static JSON file bundled at webpack build time.
It is not fetched from a user-controlled source. It does not contain executable code.
The `check:manifest` gate rejects malformed JSON before it reaches the browser.

The `dependencies` field causes `CnAppRoot` to check whether `openregister` is installed
(at D2 time) via `useAppStatus`, which reads `@nextcloud/capabilities` — a safe,
read-only, authenticated Nextcloud API call.

## NL Design System

No new components are introduced in D1. `src/manifest.json` references icon class strings
(`icon-category-dashboard`, etc.) that map to Nextcloud's built-in icon CSS classes.
NL Design token compliance is D2's concern when `CnAppNav` renders the menu.

## File Structure

```
src/
  manifest.json              ← NEW (~200 LOC JSON)
  main.js                    ← MODIFIED (2 LOC: import + useAppManifest call)
package.json                 ← MODIFIED (1 LOC: check:manifest script)
```

Files explicitly NOT changed:
```
src/navigation/MainMenu.vue  ← untouched (D2 replaces)
src/settings.js              ← untouched (D2 replaces)
src/router/index.js          ← untouched (D2 replaces)
src/App.vue                  ← untouched (D2 wires CnAppRoot)
src/jobQueueWidget.js        ← untouched
src/recentCallsWidget.js     ← untouched
src/sourceSyncWidget.js      ← untouched
lib/**                       ← untouched (all PHP)
```

## Seed Data

Not applicable — this change introduces no new database schema or data entities.

## Trade-offs

| Concern | Decision | Rationale |
|---------|----------|-----------|
| Ship manifest before D2 routes are final | Use Thijn's PR list as the source of truth for D2 pages; any new routes in D2 require a manifest patch | D1's CI gate catches drift at D2 merge time |
| Backend `/api/manifest` endpoint | Out of scope | ADR-024 §4 is opt-in; silent 404 fallback is the contract |
| `manifest.version` = `1.0.0` vs `0.1.0` | Use `1.0.0` | Resource inventory is complete and stable; the manifest content stabilises with D1 |
| `check:manifest` in CI vs just dev tooling | Add to `package.json` scripts; CI picks it up via `npm test` / quality gate | ADR-024 §5 mandates the CI gate |

## Open Questions

None blocking D1. See `DEFERRED_QUESTIONS` in the tasks file for D2-facing items
(widget entry point handling, Settings page endpoint).
