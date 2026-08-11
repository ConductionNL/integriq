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

- `src/handlers/logTargets.js`: `view-job-logs` now sends `?jobId=`. Every other
  entry was then checked against a populated instance, since applying a filter
  that matches nothing renders an EMPTY page — strictly worse than the
  unfiltered listing those pages showed before. `source` (11 of 15 rows) and
  `endpoint` (a declared property `recordInboundCallLog()` writes; 0 rows only
  because no inbound logs exist yet) are correct. The other two now carry
  `queryParam: null` and navigate unfiltered, both blocked backend-side:
  `SynchronizationLogService::normalize()` drops `synchronizationId` before the
  insert so no row links to its synchronization (0 of 10 for either candidate
  key), and `call_log` declares no event FK at all (0 of 15).
- `viewLogsHandler` now builds its location through `logsLocation()` rather than
  assembling the query itself — it was the one caller bypassing the shared
  builder, which the module's own docblock claims is the single source of truth.
- All five remaining log pages get curated `columns`, sorting, pagination and
  `fixedLayout`, authored from **observed rows** rather than from the schema:
  `call_log` keeps url/method/duration nested inside its `request`/`response`
  bags (dot paths), and `synchronization_log` rows carry no `created` at all
  (resolved via CnDataTable's `@self` fallback) and nest their counters under
  `result.objects`.
- `Traces`' `detailRoute` turned out NOT to be dead config: `TraceDetail` is a
  real `type: "custom"` page (`/traces/:id`, `TraceDetailPage.vue`) rendering a
  step timeline and owning a Replay action. `CnLogsPage` gains a `rowRoute` prop
  — the library's established name, matching CnIndexPage's `open-page` shape —
  and the key is renamed to it, so the row click finally reaches the page it
  always named. No row-detail dialog there: a dialog cannot host Replay.
- `src/manifest.json`: the `JobLogs` page gains six curated `columns` (Time,
  Level as a coloured badge, Message, Duration, Job class, Stack-trace frame
  count) at percentage widths summing to 100 — 14 / 9 / 38 / 9 / 19 / 11 —
  plus `sortKey: created` / `sortOrder: desc`, `pagination.limit: 50`,
  `rowDetail: true` and `fixedLayout: true`.

## Deliberately not in scope — needs a backend change

Two log surfaces cannot be scoped to their parent from the UI at all, and both
are recorded in `handlers/logTargets.js` with the evidence:

- **Synchronization logs carry no FK.** `SynchronizationRunLog::toArray()`
  includes `synchronizationId`, but it is null by the time
  `SynchronizationLogService::normalize()` runs and is dropped before the
  insert. Every one of the 10 stored rows on the reference instance is
  unattributable. Restore `queryParam: 'synchronization'` once the writer
  persists it.
- **`call_log` has no event FK.** Nothing declares or writes one, so cloud-event
  logging needs a field — or its own schema, `event_message` being the likely
  candidate — before `CloudEventLogs` can be scoped.

Also out of scope:

- **Endpoint logs have no data.** `EndpointsController::logs()` still returns a
  hard-coded empty result and is not wired to `call_log`; only
  `EndpointService::recordInboundCallLog()` writes rows. That page's columns are
  therefore authored from the writer rather than validated against real rows.
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
