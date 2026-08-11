---
kind: fix
depends_on: []
---

## Why

`/jobs/logs`, reached from the Jobs index "View logs" row action, was unusable in
two independent ways at once: it listed **every** `job_log` row in the register
rather than the selected job's, and its table rendered five entirely blank
columns even though the API returned data.

Three distinct defects, all verified against HEAD:

1. **Blank cells.** The page is manifest-only — `src/manifest.json` declared
   `{ id: "JobLogs", type: "logs", config: { register, schema } }` with **no
   `columns`**. `CnLogsPage.resolvedColumns` therefore fell back to a hardcoded
   audit-log default of `timestamp / actor / action / target / details`. Not one
   of those five properties exists on `job_log` (whose real fields are `level`,
   `message`, `jobId`, `jobClass`, `arguments`, `executionTime`, `stackTrace`,
   `expires`, `lastRun`, `nextRun`, `created`, …), so every cell resolved to
   `undefined` and rendered empty.

2. **No filtering, paging, or sorting.** `CnLogsPage.fetch()` called
   `objectStore.fetchCollection(this.objectType)` with **zero params** and never
   read `$route.query`. The `?job=<uuid>` the row action pushed was dead on
   arrival. The component's own header comment claimed "pagination + filtering";
   it had neither. `CnIndexPage` already had exactly this feature — the
   `resolveQueryFilters` deep-link path in `useSelfFetchList.js` — and
   `CnLogsPage` simply did not use it.

3. **Wrong query-param name.** `src/handlers/logTargets.js` mapped
   `'view-job-logs' → { queryParam: 'job' }`, but `JobService::saveJobLog()`
   persists the FK as **`jobId`**. The `job_log` schema declares both and labels
   `jobId` "Legacy string FK to job (kept during transition)" — but `jobId` is
   the one that is actually written, and the schema's own
   `x-openregister-mcp.tools.search.filters` lists `jobId`. Verified in
   OpenRegister's `ObjectsController::getConfig()`: every non-`_`-prefixed query
   param is passed through as a property filter, so `?job=` would have matched
   nothing even once filtering worked.

`app-shell-and-logs-ui` REQ-SHELLUI-003 forbids an OpenConnector-owned log-index
component, so defects 1 and 2 had to be fixed upstream in the shared
`@conduction/nextcloud-vue` `CnLogsPage` rather than worked around here.

## What Changes

### `@conduction/nextcloud-vue` (shared, backwards-compatible)

- `CnLogsPage` now drives its store-backed list through the existing
  `useListView` composable, so `_limit` / `_page` / `_order` and the merged
  filter map reach every request. Six new props, each defaulting to today's
  behaviour: `filter`, `pagination`, `sortKey`, `sortOrder`, `sortKeys`,
  `rowDetail`.
- Filters merge two sources, `filter` winning a collision: every
  non-`_`-prefixed `$route.query` entry, then the manifest's own `filter` with
  its `@route.<param>` / `@me` / `@today±Nd` tokens resolved at fetch time. A
  same-path query change re-fetches.
- `CnPagination` is rendered when the result spans more than one page, and
  column headers now sort server-side (client-side in `source` mode, which has
  no server to ask).
- The hardcoded default columns are now a last resort. A store-backed page with
  a loaded schema forwards it to `CnDataTable`, which derives the columns —
  giving type-aware cells and, notably, ending the five-blank-columns bug for
  **every** `type: "logs"` page rather than just this one. `source` mode and a
  failed schema load still get the legacy default, unchanged.
- `rowDetail` adds an opt-in read-only `NcDialog` showing the entry's fields.
  A nested bag whose values are all primitive (a stack trace's frames, an
  argument map) renders as its own labelled `CnDetailGrid`; anything deeper
  falls back to pretty-printed JSON. Overridable via a `#row-detail` slot.
- Two supporting primitives: a `count` built-in formatter (renders a
  collection-valued cell as "5 frames" instead of a truncated JSON blob) and a
  `milliseconds` unit for the declarative `duration` format (sub-second values
  render as `245ms` rather than flooring to `0s`).
- Root padding on `.cn-logs-page`, matching the
  `calc(5 * var(--default-grid-baseline))` that `.cn-index-page` and
  `.cn-detail-page` have always set (with the same `calc(3 * …)` step under
  768px). The logs page type had none, so its content ran flush into both edges
  of the app content area.
- An opt-in `fixedLayout` on `CnDataTable` (forwarded by `CnLogsPage`) switching
  the table to `table-layout: fixed`, so declared column widths bind instead of
  being hints the browser overrides from cell content. `.cn-data-table td` had
  no overflow handling at all and the inline `max-width` on a sized cell is
  inert under auto layout, so the single unsized column absorbed all slack while
  a long unbreakable value — the 45-char FQCN
  `OCA\OpenConnector\Action\SynchronizationAction` — painted past its cell into
  the next column. Fixed layout plus `overflow-wrap: anywhere` on its cells
  fixes both.
- `resolveQueryFilters` / `resolveFilterMap` moved out of
  `CnIndexPage/useSelfFetchList.js` into a shared `utils/routeFilters.js` so
  both page types apply one implementation. Behaviour-neutral.
- Also fixed while in the file: the `#error` slot was **dead** in store mode
  (`fetchCollection` records failures on the store rather than throwing, and the
  component only ever read a thrown error), and a `v-if` tested
  `$slots.actions || $slots.actions`.

### OpenConnector

- `src/handlers/logTargets.js`: `view-job-logs` now sends `?jobId=`.
- `src/manifest.json`: the `JobLogs` page gains six curated `columns` (Time,
  Level as a coloured badge, Message, Duration, Job class, Stack-trace frame
  count) at percentage widths summing to 100 — 14 / 9 / 38 / 9 / 19 / 11 —
  plus `sortKey: created` / `sortOrder: desc`, `pagination.limit: 50`,
  `rowDetail: true` and `fixedLayout: true`.

## Deliberately not in scope

- **Curated `columns` for the other four log pages** (`SourceLogs`,
  `EndpointLogs`, `SynchronizationLogs`, `CloudEventLogs`) and `Traces`. They
  inherit the schema-derived-columns fix, so they stop showing blank cells and
  gain paging and sorting, but they get no hand-picked column set.
- **The `?endpoint=` and `?event=` mismatches.** Both point at `call_log`, which
  has neither an `endpoint` nor an `event` property (its FKs are
  `sourceId`/`source`, `actionId`, `synchronizationId`/`synchronization`), so
  both still filter to nothing. Documented in `logTargets.js` as a known
  mismatch; picking the right field for each needs its own investigation into
  what writes those rows.
- **`Traces`' dead `config.detailRoute`.** `CnLogsPage` has no prop for it (the
  library's established name is `rowRoute`). Adding navigate-instead-of-dialog
  support to `CnLogsPage` is the natural companion change.
- A filter bar (level / date / free-text search) on logs pages.

## Impact

REQ-SHELLUI-003 is unaffected and still passes: the route stays a manifest
`type: "logs"` page resolved by the shared `CnLogsPage`, and nothing was added
to `src/views/`.

Library blast radius is limited to OpenConnector. OpenConnector is the only app
in the workspace that declares `type: "logs"` pages — OpenRegister, OpenCatalogi,
LaunchPad and Pipelinq declare none, so no other consumer can regress. Every new
prop defaults to the prior behaviour, and a hand-rolled `store` prop that
`useListView` cannot drive is duck-typed and routed to the legacy no-params
fetch instead of throwing.
