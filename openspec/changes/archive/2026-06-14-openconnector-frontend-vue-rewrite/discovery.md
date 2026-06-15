# Discovery: openconnector-frontend-vue-rewrite

## Question

Can the current `@conduction/nextcloud-vue` pin provide all the components and
composables that D2 requires — specifically `CnAppRoot`, `CnIndexPage`, `CnStatsPanel`,
and `createCrudStore` — and do Thijn's stacked PRs (#718-#810) integrate cleanly with
the manifest shape declared by D1?

---

## Approach Taken

1. Read the existing D1 artifacts (`proposal.md`, `design.md`, `tasks.md`) to understand
   what `src/manifest.json` declares and what nc-vue version was required.
2. Inspected `decidesk/src/main.js` as the canonical Tier-4 production reference for the
   `routesFromManifest()` + `CnAppRoot` bootstrap pattern.
3. Read all 11 repo-local ADRs to understand constraints on the store layer (ADR-001),
   dead-code deletion (ADR-006), UI convention (ADR-010), and flow-token plumbing
   (ADR-011).
4. Listed `src/store/modules/` (27 files), `src/views/`, `src/modals/`, `src/sidebars/`,
   `src/navigation/`, `src/router/` to understand the full scope of the migration.
5. Reviewed C's spec to confirm the REST API surface is stable (no mapper facade
   surviving into D2).
6. Cross-referenced the PR descriptions for #718-#810 in the task scope above to
   understand what each PR covers.

---

## Findings

### nc-vue exports are sufficient

The decidesk `src/main.js` already imports `CnPageRenderer`, `defaultPageTypes`,
`registerIcons`, `registerTranslations`, `installIntegrationRegistry`,
`registerBuiltinIntegrations`, and `registerXwikiIntegration` from the pinned
`@conduction/nextcloud-vue`. `CnAppRoot` is passed to `App.vue` as a prop-driven
component (not a registered global). The bootstrap pattern is well-established.

`createCrudStore` was introduced alongside the Thijn PR stack. D1's `check:manifest`
gate will fail if the pin predates it — Task 0 in tasks.md must verify the pin before
any migration starts.

### Decidesk pattern is a direct template

`decidesk/src/main.js:93-109` shows `routesFromManifest()` building vue-router 3 routes
from `manifest.pages[]`. OpenConnector can use an identical function with only the
`base` URL changed to `generateUrl('/apps/openconnector')`.

### `src/Controller/` and `src/Mapper/` are confirmed dead

ADR-006 documents that `src/Controller/JobLogController.php` and
`src/Mapper/JobLogMapper.php` have no PHP class declarations and are imported by zero
Vue/TS/JS files. The pre-deletion grep is a formality; deletion is safe.

### 27 store modules, 16 are CRUD candidates

`src/store/modules/` contains 27 files. Of these:
- `navigation.js` and `navigation.spec.js` — navigation state store; NOT a `createCrudStore`
  candidate (no CRUD resource). Migrate to a simple Pinia store or delete if manifest
  replaces the navigation state.
- `search.ts` and `search.spec.ts` — search state store; NOT a `createCrudStore` candidate.
- `importExport.js` — import/export orchestration; NOT a `createCrudStore` candidate.
- `settings.js` — app settings key/value store; NOT a `createCrudStore` candidate.
- Remaining 16 files (source, endpoints, consumer, contract, event, job, log, mapping,
  rule, synchronization, webhooks + their spec files) — all CRUD candidates.

### Modals.vue aggregator mounts 30+ modals

`src/modals/Modals.vue` is confirmed to mount all resource modals simultaneously.
Deletion is safe once all resource pages are on `CnIndexPage`, but the apply agent must
grep `src/sidebars/` for any modal imports before deleting modal files.

### Settings entry point — `appinfo/` check needed

`src/settings.js` is a separate webpack entry. Whether it corresponds to an
`appinfo/settings.xml` admin-settings registration is not visible from `src/`
inspection alone. The apply agent must check `appinfo/` before deleting `settings.js`.

### Flow token plumbing in synchronization store

ADR-011 confirms `SynchronizationService` accepts a `FlowToken` parameter for sync
calls. The frontend `synchronization.ts` store may set custom HTTP headers (e.g.
`X-Flow-Token`) on sync trigger calls. `createCrudStore`'s base `update()` action does
not forward custom headers. The synchronization store migration requires a custom
`triggerSync()` action that wraps the base store's HTTP client with the token header.

---

## Recommendation

**Proceed to specs and tasks.** The Tier-4 bootstrap pattern from decidesk is a direct
template with minimal adaptation. The main risks (pin version, conflict resolution on
Thijn's PRs, flow token preservation) are all mitigatable in tasks.md. No additional
discovery sprint is needed.

---

## Risks Uncovered

1. **`navigation.js` store fate** — the Pinia navigation store manages modal/dialog
   visibility (`navigationStore.setModal()`, `navigationStore.setDialog()`). Once
   `CnIndexPage` handles modal state internally, `navigation.js` may become a stub.
   The apply agent should leave `navigation.js` intact and delete only the
   modal-visibility state after confirming `CnIndexPage` manages it.

2. **`appinfo/settings.xml`** — if present, deleting `settings.js` without updating
   `appinfo/` would leave a broken admin-settings registration. Treat as a blocker for
   the settings migration task.

---

## Next Steps

Proceed to `specs/openconnector-frontend-vue-rewrite/spec.md`, `design.md`,
`migration.md` (N/A — no DB changes), `tasks.md`, and `test-plan.md`.
