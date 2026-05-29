# openconnector-app-manifest Specification

**Status**: planned
**Scope**: openconnector
**OpenSpec changes**:
- openconnector-app-manifest (this change)

## Purpose

Declare OpenConnector's frontend shell shape — navigation entries, page routes, runtime
dependencies, and settings configuration — in a single `src/manifest.json` file
conforming to the canonical `app-manifest-v2.schema.json` from `@conduction/nextcloud-vue`.

This spec covers the D1 config deliverables: the manifest file itself, the thin-glue
bootstrap call in `main.js`, and the CI validation gate. It does NOT cover the Vue
component wiring (chain D2).

Aligns with: ADR-024 (app manifest fleet-wide adoption), ADR-031
(declarative-over-imperative), ADR-032 (config/code chain split; this change is
`kind: config`). Cross-references `nextcloud-vue/openspec/changes/add-json-manifest-renderer/specs/json-manifest-renderer/spec.md`
(17 REQ-JMR-* requirements defining the renderer contract).

## ADDED Requirements

### Requirement: Manifest file MUST exist at canonical path

The openconnector app MUST ship a file at `src/manifest.json` adjacent to `src/main.js`
and `src/App.vue`. The file MUST be valid JSON parseable without errors.

#### Scenario: Manifest file present at canonical path
- GIVEN a checkout of the openconnector repo
- WHEN inspecting `openconnector/src/`
- THEN `manifest.json` MUST exist as a regular file
- AND it MUST parse as valid JSON with no syntax errors

#### Scenario: Manifest file is adjacent to main.js
- GIVEN a checkout of the openconnector repo
- WHEN listing `openconnector/src/`
- THEN `manifest.json`, `main.js`, and `App.vue` MUST all be present in the same directory

---

### Requirement: Manifest MUST declare a valid schema reference

The manifest file MUST contain a `$schema` field set to the published URL of the
canonical schema:
`"https://codeberg.org/Conduction/nextcloud-vue/raw/branch/main/src/schemas/app-manifest-v2.schema.json"`

#### Scenario: $schema field is present and correct
- GIVEN the manifest file is loaded
- WHEN inspecting the `$schema` top-level key
- THEN it MUST equal `"https://codeberg.org/Conduction/nextcloud-vue/raw/branch/main/src/schemas/app-manifest-v2.schema.json"`

---

### Requirement: Manifest version MUST follow semver

The manifest file MUST contain a `version` field matching the semver pattern
`^\d+\.\d+\.\d+`. The initial value for D1 MUST be `"1.0.0"`.

#### Scenario: version field is valid semver
- GIVEN the manifest file is loaded
- WHEN validating `version` against `/^\d+\.\d+\.\d+$/`
- THEN the match MUST succeed

#### Scenario: version is 1.0.0 for D1
- GIVEN the D1 manifest
- WHEN reading `manifest.version`
- THEN the value MUST be `"1.0.0"`

---

### Requirement: Manifest MUST declare openregister as a runtime dependency

The manifest `dependencies` array MUST contain the string `"openregister"`. No other
apps need to be listed in D1.

#### Scenario: dependencies contains openregister
- GIVEN the manifest file is loaded
- WHEN inspecting `manifest.dependencies`
- THEN the array MUST contain exactly the string `"openregister"`

#### Scenario: dependencies is a JSON array
- GIVEN the manifest file is loaded
- WHEN inspecting `manifest.dependencies`
- THEN it MUST be a JSON array (not null, not an object, not a string)

---

### Requirement: Manifest MUST declare a menu entry for every primary navigation item

The manifest `menu` array MUST contain one entry for each of the 15 navigation items
currently rendered by `src/navigation/MainMenu.vue` plus the external Documentation
link. Each entry MUST have: `id` (unique string), `label` (i18n key string), `icon`
(Nextcloud icon class string), `route` OR `href` (but not both), and `order` (integer).
Items in `NcAppNavigationSettings` MUST additionally have `"section": "settings"`.

Required menu entries (confirmed from `src/navigation/MainMenu.vue`):

| id | label | section | order |
|----|-------|---------|-------|
| Dashboard | openconnector navigation.dashboard | — | 10 |
| Sources | openconnector navigation.sources | — | 20 |
| Endpoints | openconnector navigation.endpoints | — | 30 |
| Consumers | openconnector navigation.consumers | — | 40 |
| Webhooks | openconnector navigation.webhooks | — | 50 |
| Mappings | openconnector navigation.mappings | — | 60 |
| Jobs | openconnector navigation.jobs | — | 70 |
| CloudEvents | openconnector navigation.cloudEvents | — | 80 |
| Synchronizations | openconnector navigation.synchronizations | — | 90 |
| Rules | openconnector navigation.rules | — | 100 |
| Import | openconnector navigation.import | settings | 110 |
| Documentation | openconnector navigation.documentation | settings | 120 |
| Settings | openconnector navigation.settings | settings | 130 |

#### Scenario: All 15 menu entries are present
- GIVEN the manifest file is loaded
- WHEN inspecting `manifest.menu`
- THEN the array MUST contain entries with ids: Dashboard, Sources, Endpoints,
  Consumers, Webhooks, Mappings, Jobs, CloudEvents, Synchronizations, Rules, Import,
  Documentation, Settings

#### Scenario: Settings section entries carry section field
- GIVEN the manifest file is loaded
- WHEN inspecting menu entries with ids Import, Documentation, Settings
- THEN each MUST have `"section": "settings"`

#### Scenario: Documentation entry uses href not route
- GIVEN the manifest file is loaded
- WHEN inspecting the Documentation menu entry
- THEN it MUST have an `href` field pointing to `"https://openconnector.app/docs"`
- AND it MUST NOT have a `route` field

#### Scenario: Primary nav entries have route not href
- GIVEN the manifest file is loaded
- WHEN inspecting menu entries with ids Dashboard, Sources, Endpoints, Consumers,
  Mappings, Jobs, CloudEvents, Synchronizations, Rules, Import
- THEN each MUST have a `route` field
- AND the `route` value MUST match a `pages[].id` in the same manifest

---

### Requirement: Manifest MUST declare a page entry for every route

The manifest `pages` array MUST contain one entry for every distinct page component
registered in `src/router/index.js` (excluding redirects). Each page entry MUST have:
`id` (unique, used as vue-router route name), `route` (path pattern string), `type`
(one of: `dashboard`, `index`, `detail`, `logs`, `custom`, `settings`), `title`
(i18n key string).

Redirect-only routes (`/cloud-events` → `/cloud-events/events`, `*` → `/`) MUST NOT
produce manifest page entries.

Required pages (minimum 23 entries):

| id | route | type |
|----|-------|------|
| Dashboard | / | dashboard |
| Sources | /sources | index |
| SourceLogs | /sources/logs | logs |
| Endpoints | /endpoints | index |
| EndpointDetail | /endpoints/:id | detail |
| EndpointLogs | /endpoints/logs | logs |
| Consumers | /consumers | index |
| ConsumerDetail | /consumers/:id | detail |
| Webhooks | /webhooks | index |
| Jobs | /jobs | index |
| JobLogs | /jobs/logs | logs |
| Mappings | /mappings | index |
| MappingDetail | /mappings/:id | detail |
| Rules | /rules | index |
| RuleDetail | /rules/:id | detail |
| Synchronizations | /synchronizations | index |
| SynchronizationContracts | /synchronizations/contracts | index |
| SynchronizationLogs | /synchronizations/logs | logs |
| CloudEvents | /cloud-events/events | index |
| CloudEventDetail | /cloud-events/events/:id | detail |
| CloudEventLogs | /cloud-events/logs | logs |
| Import | /import | custom |
| Settings | /settings | settings |

#### Scenario: All routes from router are declared as pages
- GIVEN the manifest file is loaded
- WHEN inspecting `manifest.pages` ids
- THEN an entry MUST exist for each of the 23 routes listed in the table above
- AND no page id MUST be duplicated

#### Scenario: Dashboard page type is dashboard
- GIVEN the manifest file is loaded
- WHEN inspecting the page with id `"Dashboard"`
- THEN its `type` field MUST be `"dashboard"`
- AND its `route` MUST be `"/"`

#### Scenario: Log pages use type logs
- GIVEN the manifest file is loaded
- WHEN inspecting pages with ids SourceLogs, EndpointLogs, JobLogs, SynchronizationLogs, CloudEventLogs
- THEN each MUST have `"type": "logs"`

#### Scenario: Import page is type custom
- GIVEN the manifest file is loaded
- WHEN inspecting the page with id `"Import"`
- THEN its `type` MUST be `"custom"`

#### Scenario: Settings page is type settings
- GIVEN the manifest file is loaded
- WHEN inspecting the page with id `"Settings"`
- THEN its `type` MUST be `"settings"`

#### Scenario: Detail pages carry :id parameter in route
- GIVEN the manifest file is loaded
- WHEN inspecting pages with ids EndpointDetail, ConsumerDetail, MappingDetail, RuleDetail, CloudEventDetail
- THEN each MUST have a `route` field ending in `/:id`

---

### Requirement: Manifest MUST validate against the canonical schema without errors

The file `src/manifest.json` MUST pass validation against
`app-manifest-v2.schema.json` (from `@conduction/nextcloud-vue`) with zero errors when
checked by the `validateManifest` utility function.

#### Scenario: validateManifest returns valid
- GIVEN `validateManifest(manifest)` is called with the contents of `src/manifest.json`
- WHEN the function executes
- THEN it MUST return `{ valid: true, errors: null }`

#### Scenario: Unknown page type fails validation
- GIVEN a modified manifest with `pages[0].type = "wizard"`
- WHEN `validateManifest(manifest)` is called
- THEN it MUST return `{ valid: false, errors: <non-empty array> }`

#### Scenario: Missing required page field fails validation
- GIVEN a modified manifest with a page entry missing the `id` field
- WHEN `validateManifest(manifest)` is called
- THEN it MUST return `{ valid: false, errors: <non-empty array> }` referencing the missing `id`

---

### Requirement: main.js MUST import and register the manifest composable

`src/main.js` MUST import `manifest.json` as a bundled static import and pass it to
`useAppManifest('openconnector', bundledManifest)` from `@conduction/nextcloud-vue`
before `new Vue().$mount('#content')`. The composable call returns `{ manifest,
isLoading, validationErrors }` which MUST be stored in a variable accessible for
chain D2's `CnAppRoot` wiring.

#### Scenario: main.js contains manifest import
- GIVEN the file `src/main.js` is read
- WHEN scanning its import statements
- THEN `import bundledManifest from './manifest.json'` MUST be present

#### Scenario: main.js calls useAppManifest
- GIVEN the file `src/main.js` is read
- WHEN scanning its body
- THEN `useAppManifest('openconnector', bundledManifest)` MUST be called
- AND `useAppManifest` MUST be imported from `@conduction/nextcloud-vue`

#### Scenario: useAppManifest call is before Vue mount
- GIVEN the file `src/main.js` is read
- WHEN checking line order
- THEN the `useAppManifest` call MUST appear before `.$mount('#content')`

---

### Requirement: package.json MUST include a check:manifest script

The `package.json` file MUST include a `"check:manifest"` script that invokes
`validateManifest` on `src/manifest.json`. The script MUST exit with code 0 on
validation success and non-zero on validation failure.

#### Scenario: check:manifest script is present in package.json
- GIVEN `package.json` is loaded
- WHEN inspecting the `scripts` object
- THEN a key `"check:manifest"` MUST be present

#### Scenario: check:manifest script validates the manifest
- GIVEN the check:manifest script is executed in CI
- WHEN `src/manifest.json` is valid
- THEN the script MUST exit with code 0

#### Scenario: check:manifest fails on invalid manifest
- GIVEN the check:manifest script is executed
- WHEN `src/manifest.json` contains an invalid page type
- THEN the script MUST exit with a non-zero code
- AND MUST print the validation error to stdout or stderr

---

## ADR-031 — Declarative-vs-Imperative

All behaviour declared in `manifest.json`. No service classes are added by this change.
The routing, navigation, and dependency-check logic lives in `@conduction/nextcloud-vue`
components (`CnAppRoot`, `CnAppNav`, `CnPageRenderer`); openconnector provides only the
data file.

## Non-Functional Requirements

- **Performance:** `manifest.json` is bundled statically by webpack; no HTTP request is
  incurred for the manifest itself. Build size impact is negligible (< 5 KB minified).
- **Accessibility:** Not applicable to a JSON data file. Accessibility is enforced in D2
  via `CnAppNav`'s Nextcloud component usage.
- **Internationalization:** All `label` and `title` values in the manifest MUST be i18n
  key strings (not translated strings). The translator function in the consuming app
  resolves them at render time (ADR-007, ADR-025). Dutch and English MUST be supported.

## Acceptance Criteria

- [ ] `src/manifest.json` exists and parses as valid JSON
- [ ] `validateManifest(manifest)` returns `{ valid: true }` with zero errors
- [ ] All 15 menu entries are present with correct `section` placement (13 in `main` + 2 in `settings`)
- [ ] All 24 page routes are represented in `pages[]` (#811 shipped manifest-v2 baseline — `src/router/index.js` is deprecated)
  (excluding redirect-only routes)
- [ ] `src/main.js` contains `import bundledManifest from './manifest.json'` and
  calls `useAppManifest('openconnector', bundledManifest)`
- [ ] `package.json` has a `check:manifest` script that passes on the committed manifest

## Notes

- Chain D2 (`openconnector-frontend-vue-rewrite`) wires `CnAppRoot` and `CnPageRenderer`
  to consume the manifest. D1 is Tier 1 adoption (composable called, manifest available)
  per ADR-024 §8.
- `type: "logs"` requires the `manifest-page-type-extensions` schema in
  `@conduction/nextcloud-vue`. If the pinned version pre-dates this extension, fall back
  to `type: "custom"` for log pages in D1; D2 can upgrade the pin.
- The Webhooks resource is present in `src/router/index.js` but absent from
  `src/navigation/MainMenu.vue`. It is included in `pages[]` (for D2 reachability) and
  in `menu[]` with order 50 (between Endpoints and Mappings) to match the resource
  importance.
- The `/settings` route with `type: "settings"` is a new route not present in the
  current router — the current settings panel is mounted by `settings.js` onto a
  separate `#settings` DOM node. D2 will implement the vue-router `/settings` route.
  The manifest declares it now so D2 can add the route without a manifest patch.
- See `DEFERRED_QUESTIONS` in `tasks.md` for open items about the widget entry points
  and the Settings page endpoint.
