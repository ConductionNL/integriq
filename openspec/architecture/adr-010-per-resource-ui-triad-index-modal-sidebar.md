# ADR-010: Every resource has a per-resource UI triad — index view, modals (edit/delete/view/test), and a detail sidebar

## Status
Partially superseded by `openconnector-frontend-vue-rewrite` (chain D2): the per-resource `{Resource}sIndex.vue` + `Modals.vue` aggregator pattern is replaced by `CnIndexPage` driven by the D1 manifest; the per-resource detail sidebars remain unchanged. See README status legend.

## Date
2026-05-20

## Context

Integriq's frontend is organised around a per-resource UI convention.
For each resource domain (Source, Endpoint, Job, Synchronization, Mapping,
Rule, Consumer, Contract, Log) the current code ships:

**1. Index view** under `src/views/{Resource}/`  
  - E.g. `src/views/Source/SourcesIndex.vue` (primary list/table view)
  - E.g. `src/views/Endpoint/EndpointsIndex.vue`
  - May also include sub-pages: `SourceLogIndex.vue`, `SynchronizationLogIndex.vue`

**2. Modals** under `src/modals/{Resource}/`  
  - Edit/create: `EditSource.vue`, `EditJob.vue`, …
  - Delete: `DeleteSource.vue`, `DeleteJob.vue`, …
  - View/detail: `ViewSource.vue`, `ViewJob.vue`, …
  - Domain-specific: `TestSource.vue`, `TestMapping.vue`, `RunJob.vue`,
    `RunSynchronization.vue`
  - All wired through a single aggregator `src/modals/Modals.vue` (100+ lines)
    which mounts all modals simultaneously and shows/hides via
    `navigationStore.modal` string comparison.

**3. Sidebars** under `src/sidebars/{Resource}/`  
  - E.g. `src/sidebars/Source/SourceLogSideBar.vue`
  - E.g. `src/sidebars/Job/JobDetails.vue`, `JobLogSideBar.vue`
  - Used for per-item detail panels in the App Content right panel

**Navigation** is driven by `navigationStore.setModal(name)` and
`navigationStore.setDialog(name)` — string-keyed flags stored in the Pinia
navigation store. `Modals.vue` renders all modals on every page load; only the
one matching `navigationStore.modal` is visible.

The `src/dialogs/Dialogs.vue` file exists as a stub (empty component) — the
"dialog" surface is currently served by modals for destructive operations
(Delete) where `navigationStore.setDialog()` is called instead of `setModal()`.

The convention predates `@conduction/nextcloud-vue` and was established before
`CnIndexPage` existed. Chain D2 (`openconnector-frontend-vue-rewrite`)
introduces `CnIndexPage` + `createCrudStore` which consolidate the index view
and modal wiring into a schema-driven single component.

## Decision

The current per-resource UI triad (index view + modals + sidebar) is the
accepted convention for all 10+ resources in integriq's current codebase.
New resources added before chain D2 lands MUST follow the same structure:

- Add an `{Resource}sIndex.vue` under `src/views/{Resource}/`.
- Add `Edit{Resource}.vue` and `Delete{Resource}.vue` under
  `src/modals/{Resource}/`.
- Register both in `src/modals/Modals.vue`.
- Add a `{Resource}Details.vue` or `{Resource}SideBar.vue` if the resource
  has a detail panel.

Once chain D2 lands, new resources SHOULD use `CnIndexPage` + `createCrudStore`
instead of the manual triad. The existing 10+ resources will be migrated
resource-by-resource during D2.

## Consequences

- The `Modals.vue` aggregator pattern means all modal components are
  instantiated on every page, even if the user never opens them. This has
  a non-trivial bundle-size cost for large modals with deep sub-components
  (e.g. `EditSynchronization`, `EditMapping`). Chain D2 mitigates this
  by using lazy slot injection in `CnIndexPage`.
- Adding a new resource modal requires two edits: the modal file AND the
  registration in `Modals.vue`. Missing the `Modals.vue` registration is
  the most common cause of "modal never opens" bugs.
- The `navigationStore.modal` string is a global singleton; only one modal
  can be open at a time. Multi-step workflows that need two simultaneous
  modals must use the sidebar as the second surface (see JobLogSideBar.vue).
- `src/dialogs/Dialogs.vue` is a stub that does nothing; destructive
  operations use `navigationStore.setDialog()` checked inside `Modals.vue`
  (see `src/modals/Modals.vue:46` for `navigationStore.dialog === 'deleteRule'`).
  New code SHOULD NOT add real content to `Dialogs.vue`; use the modal pattern.
- Cross-reference: ADR-001 (per-resource Pinia stores) — each triad resource
  has a matching store module.
- Cross-reference: `openspec/changes/openconnector-frontend-vue-rewrite/README.md`
  — chain D2 that replaces the manual triad with `CnIndexPage`.
- Cross-reference: hydra ADR-022 (`Tier 4` manifest-driven UI) — chain D2
  targets Tier 4; this ADR captures the current Tier 0 convention.

## Evidence

- `src/views/Source/SourcesIndex.vue:1-50` — index view with card/table toggle,
  NcActions action bar, and `navigationStore.setModal('editSource')` calls.
- `src/modals/Modals.vue:6-53` — aggregator template mounting 30+ modal
  components simultaneously.
- `src/modals/Modals.vue:56-100` — import list confirming per-resource modal
  files for every domain.
- `src/sidebars/Job/` — `JobDetails.vue`, `JobLogIndex.vue`,
  `JobLogSideBar.vue` — three sidebar surfaces for the Job resource.
- `src/sidebars/Endpoint/EndpointDetails.vue` — detail sidebar for Endpoint.
- `src/views/Source/SourcesIndex.vue:55,138` —
  `navigationStore.setModal('editSource')` for create,
  `navigationStore.setDialog('deleteSource')` for delete — the modal/dialog
  split.
- `src/dialogs/Dialogs.vue:1-13` — empty stub confirming the dialog surface
  is not actually used for rendered content.
