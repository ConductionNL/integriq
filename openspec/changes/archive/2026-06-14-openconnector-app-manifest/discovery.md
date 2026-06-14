# Discovery: openconnector-app-manifest

## Question

Is `useAppManifest` available in the currently pinned version of
`@conduction/nextcloud-vue`, and does OpenConnector's existing route/nav inventory map
cleanly onto the manifest schema without ambiguity?

## Approach Taken

1. Read `src/router/index.js` to enumerate all declared routes (22 route entries).
2. Read `src/navigation/MainMenu.vue` to enumerate all nav items and their section
   placement (11 primary nav entries + Import + Settings in NcAppNavigationSettings).
3. Read `decidesk/src/manifest.json` (Tier-4 production reference) to confirm the
   schema shape, icon naming convention, `section: "settings"` pattern, and
   `$schema` URL.
4. Read `hydra/openspec/architecture/adr-024-app-manifest.md` for the fleet mandate
   and tier definitions.
5. Read `nextcloud-vue/openspec/changes/add-json-manifest-renderer/specs/json-manifest-renderer/spec.md`
   (17 REQ-JMR-* requirements) to confirm the page type closed enum and composable signature.
6. Read `nextcloud-vue/CLAUDE.md` to confirm `useAppManifest`, `validateManifest`, and
   `CnAppRoot`/`CnAppNav`/`CnPageRenderer` are all documented as available exports.
7. Inspected `appinfo/info.xml` to confirm `openregister` is the correct dependency id.

## Findings

### Route inventory (from `src/router/index.js`)

| Route path | Vue component | Manifest page type |
|-----------|--------------|-------------------|
| `/` | DashboardIndex | `dashboard` |
| `/sources` | SourcesIndex | `index` |
| `/sources/logs` | SourceLogIndex | `logs` (type: `logs`) |
| `/endpoints` | EndpointsIndex | `index` |
| `/endpoints/logs` | EndpointLogIndex | `logs` |
| `/endpoints/:id` | EndpointsIndex | `detail` |
| `/consumers` | ConsumersIndex | `index` |
| `/consumers/:id` | ConsumersIndex | `detail` |
| `/webhooks` | WebhooksIndex | `index` |
| `/jobs` | JobsIndex | `index` |
| `/jobs/logs` | JobLogIndex | `logs` |
| `/mappings` | MappingsIndex | `index` |
| `/mappings/:id` | MappingsIndex | `detail` |
| `/rules` | RulesIndex | `index` |
| `/rules/:id` | RulesIndex | `detail` |
| `/synchronizations` | SynchronizationsIndex | `index` |
| `/synchronizations/contracts` | ContractsIndex | `index` |
| `/synchronizations/logs` | SynchronizationLogIndex | `logs` |
| `/cloud-events` | redirect → `/cloud-events/events` | — |
| `/cloud-events/events` | EventsIndex | `index` |
| `/cloud-events/events/:id` | EventsIndex | `detail` |
| `/cloud-events/logs` | EventLogIndex | `logs` |
| `/import` | ImportIndex | `custom` |
| `*` | redirect → `/` | — |

### Nav inventory (from `src/navigation/MainMenu.vue`)

Primary section (NcAppNavigationList):
- Dashboard, Sources (+Logs child), Endpoints (+Logs child), Consumers, Mappings,
  Jobs (+Logs child), Cloud Events (Events + Logs children), Synchronizations
  (Contracts + Logs children), Rules

Settings section (NcAppNavigationSettings):
- Import, Settings (emits `open-settings` — no route), Documentation (implicit, not
  in current nav but present in decidesk reference)

### Key findings

1. **`useAppManifest` and `validateManifest` confirmed available** — both appear in
   `nextcloud-vue/CLAUDE.md` under "Available Composables" and "Available Utilities"
   respectively. The manifest renderer is a shipped feature of the library.

2. **`logs` type is in the extended enum** — `manifest-page-type-extensions` added
   `logs`, `settings`, `chat`, `files` to the closed enum. Confirmed in spec.md:
   "Subsequent extensions: `manifest-page-type-extensions` (schema v1.1) added `logs`,
   `settings`, `chat`, `files` to the enum". Use `type: "logs"` for log pages.

3. **Settings nav item has no route** — `MainMenu.vue` emits `open-settings` rather than
   navigating to a route. In the manifest this becomes `type: "settings"` with a custom
   component reference, or a `section: "settings"` item pointing to a dedicated
   `/settings` route. The decidesk reference uses a `/settings` route with
   `type: "settings"`. Adopt the same pattern; D2 will implement the route.

4. **Webhooks present in router but absent from nav** — `MainMenu.vue` does not include
   a Webhooks nav entry, but the router has `/webhooks`. Include it in the manifest
   pages array (for D2 reachability) but optionally omit from the menu or include with
   low order.

5. **`appinfo/info.xml` has no `<app>` dependency on openregister** — the XML
   `<dependencies>` block lists only `nextcloud`, `php`, and `database`. The
   openregister runtime dependency is managed at the service level (DI injection), not
   declared in info.xml. `manifest.dependencies: ["openregister"]` is still correct
   because the manifest dependency check is a runtime UX guard (shows
   `CnDependencyMissing` if not installed), independent of the PHP app dependency
   declaration.

6. **`check:manifest` script pattern** — the decidesk reference does not yet have this
   script, but `nextcloud-vue/CLAUDE.md` documents `validateManifest` as the build-time
   validator function. The script will call it via a small JS wrapper (similar to
   `check:docs` in the library).

## Recommendation

Proceed to specs and manifest authoring. The route/nav inventory is complete; no
ambiguity blocks D1. Use `type: "logs"` for log pages (extended enum). Use a dedicated
`/settings` route with `type: "settings"` for the admin settings panel. Include
Webhooks in `pages[]` even if omitted from `menu[]`.

The thin-glue change to `main.js` is straightforward and carries no risk under ADR-032.

## Risks Uncovered

- **`logs` type requires `manifest-page-type-extensions`** to be shipped in the pinned
  `@conduction/nextcloud-vue` version. If the pin pre-dates that extension, `type: "logs"`
  will fail schema validation. Mitigation: check the pin in tasks.md; use `type: "custom"`
  as a fallback if needed (D2 can upgrade the pin when wiring components).
- **Webhooks nav absence is intentional or an oversight** — unclear from the nav code
  alone. Flag in `DEFERRED_QUESTIONS` for D2 to confirm.

## Next Steps

Proceed to:
1. `specs` — author the formal requirements for the manifest file and glue changes.
2. `migration` — not applicable (no DB schema change); write minimal note.
3. `tasks` — enumerate implementation steps for the apply agent.
4. `test-plan` — CI gate validation and route-coverage check.
