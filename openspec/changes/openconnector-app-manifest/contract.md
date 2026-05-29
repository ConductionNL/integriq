# Contract: openconnector-app-manifest

## Overview

This change is `kind: config` — it introduces a static JSON manifest file bundled into
the webpack build. It does **not** introduce, modify, or remove any HTTP endpoints.
All API contracts for OpenConnector's REST resources are unchanged.

## Manifest File as a Contract Surface

The file `src/manifest.json` is the sole deliverable of this change. Its schema is
governed by the canonical `app-manifest.schema.json` in `@conduction/nextcloud-vue`.
Two parties consume it:

## Consumers

- **`@conduction/nextcloud-vue` `CnAppRoot` / `CnAppNav` / `CnPageRenderer`** (chain D2):
  reads `manifest.menu[]` to render nav items and `manifest.pages[]` to dispatch page
  components. Consumes via `useAppManifest('openconnector', bundledManifest)` composable
  passed from `src/main.js`.
- **Admin App Builder** (future): reads `GET /index.php/apps/openconnector/api/manifest`
  (ADR-024 §4 opt-in endpoint, not implemented in D1) to offer menu-order and
  page-visibility overrides. Until D1's successor implements this endpoint, the App
  Builder receives a 404 and falls back to the bundled manifest silently (per ADR-024).
- **CI validation gate** (`check:manifest` script): calls `validateManifest` from
  `@conduction/nextcloud-vue` at build time against the schema. No HTTP involved.

## Manifest JSON Shape (canonical schema reference)

The manifest must conform to:
```
https://codeberg.org/Conduction/nextcloud-vue/raw/branch/main/src/schemas/app-manifest.schema.json
```

Top-level structure:
```json
{
  "$schema": "<schema URL>",
  "version": "<semver>",
  "dependencies": ["<nextcloud-app-id>"],
  "menu": [{ "id": "...", "label": "...", "route": "...", "order": 0 }],
  "pages": [{ "id": "...", "route": "...", "type": "...", "title": "...", "config": {} }]
}
```

Key constraints (from ADR-024 and REQ-JMR-001):
- `version` MUST follow semver `^\d+\.\d+\.\d+`
- `pages[].id` MUST be unique within the manifest (also serves as vue-router route name)
- `pages[].type` is a closed enum: `index | detail | dashboard | settings | custom | logs | chat | files`
- `menu[].route` references a `pages[].id` (not a URL path)
- `menu[].href` is used for external links (no `route` in that case)
- `menu[].section: "settings"` places the item in `NcAppNavigationSettings`

## Versioning

`manifest.version` in this change is set to `"1.0.0"` — the manifest content stabilises
with the full resource inventory from `src/router/index.js`. Bump to `"1.1.0"` when
chain D2 adds or removes pages.

The schema version (governed by `@conduction/nextcloud-vue`) is independent; consuming
apps reference the schema URL, not a pinned schema version.

## Breaking Change Policy

The manifest is consumed by the rendering library (`@conduction/nextcloud-vue`) and by
chain D2. Changes that remove or rename a `pages[].id` that D2 hardcodes as a route
name are breaking. The procedure:

1. File a GitHub issue on `openconnector` documenting the rename.
2. Coordinate with D2 branch — update route name in manifest and router atomically.
3. Bump `manifest.version` minor (e.g. `"1.0.0"` → `"1.1.0"`).

Adding new pages/menu entries is non-breaking (additive).

## SLA

Not applicable — this is a static bundled file, served as part of the webpack build
output with zero additional HTTP latency. Runtime availability equals Nextcloud's
own availability.

## No New HTTP Endpoints

D1 explicitly does NOT implement `GET /index.php/apps/openconnector/api/manifest`.
That endpoint is out of scope per the proposal. If requested, the Nextcloud router
will return a 404, which `useAppManifest` handles gracefully (silent fallback to
bundled manifest per REQ-JMR-002).
