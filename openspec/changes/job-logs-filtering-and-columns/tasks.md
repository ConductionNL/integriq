## 1. Verify the three defects against HEAD

- [x] 1.1 Confirm `JobLogs` in `src/manifest.json` declares no `config.columns`,
      and that `CnLogsPage.resolvedColumns` falls back to
      `timestamp / actor / action / target / details`.
- [x] 1.2 Confirm none of those five properties exists on `job_log`
      (`lib/Settings/openconnector_register.json`, `components.schemas.job_log`).
- [x] 1.3 Confirm `CnLogsPage.fetch()` calls `fetchCollection(type)` with no
      second argument and that the component never reads `$route.query`.
- [x] 1.4 Confirm `JobService::saveJobLog()` writes `jobId`, while
      `logTargets.js` pushes `?job=`.
- [x] 1.5 Confirm OpenRegister passes non-`_`-prefixed query params through as
      property filters (`ObjectsController::getConfig()` → `'filters' => $params`),
      so `?jobId=` filters and `?job=` matches nothing.
- [x] 1.6 Confirm OpenConnector is the only workspace app declaring
      `type: "logs"` pages, bounding the library blast radius.

## 2. Shared library — extract the filter resolvers (behaviour-neutral)

- [x] 2.1 Create `nextcloud-vue/src/utils/routeFilters.js` exporting
      `resolveQueryFilters` and `resolveFilterMap`, moved verbatim out of
      `CnIndexPage/useSelfFetchList.js`.
- [x] 2.2 Rewire `useSelfFetchList.js` to import them; delete the local copies.
- [x] 2.3 Keep both module-private (not exported from `src/index.js`), per the
      `resolveFilterTokens.js` / `multiColumnSort.js` precedent.
- [x] 2.4 Prove neutrality: `CnIndexPageSelfFetch` + `CnIndexPageQuickFilters`
      stay green (22 tests).

## 3. Shared library — supporting primitives

- [x] 3.1 Add a `count` formatter to `builtInFormatters.js` + register it in
      `BUILT_IN_FORMATTERS`. Counts array entries / object keys, scalar as 1,
      with `{ singular, plural, zero }` phrases and `{n}` substitution.
- [x] 3.2 Add a `milliseconds` branch to `CnCellRenderer.formatDuration`,
      rendering sub-second values as `245ms` instead of flooring to `0s`.
- [x] 3.3 Add `"milliseconds"` to the `column.format.unit` enum in
      `app-manifest.schema.json`, and add the missing `formatterOptions` key to
      its `$defs.column` (which is `additionalProperties: false`, so the key was
      unusable in v1 manifests despite the code supporting it).

## 4. Shared library — rework `CnLogsPage`

- [x] 4.1 Drive the store-backed list through `useListView` in `setup()`,
      registering the object type synchronously first (its `onMounted` calls
      `fetchSchema`, which throws on an unregistered type).
- [x] 4.2 Duck-type the `store` prop: route a partial store that `useListView`
      cannot drive to the legacy no-params fetch rather than throwing.
- [x] 4.3 Add `filter`, `pagination`, `sortKey`, `sortOrder`, `sortKeys`,
      `rowDetail` — all defaulting to the prior behaviour.
- [x] 4.4 Merge `resolveQueryFilters($route.query)` under
      `resolveFilterMap(props.filter, $route.params)` as the `fixedFilters`
      getter, so it is re-read on every fetch.
- [x] 4.5 Return `[]` from `resolvedColumns` when a schema is loaded and forward
      it to `CnDataTable`; keep the legacy default for `source` mode and for a
      failed schema load (turn it into a function so `t()` runs after
      `registerTranslations()`).
- [x] 4.6 Add `$route.query` / `$route.params` watchers with no `immediate`, and
      gate `mounted()` behind `if (!this.list)` — one fetch on mount, not two.
- [x] 4.7 Render `CnPagination` when `pages > 1`; wire `@sort` to the composable
      (and to `multiKeySort` over `localRows` in `source` mode, since
      `CnDataTable` never sorts its own rows).
- [x] 4.8 Add the opt-in row-detail `NcDialog` inline (a sibling SFC would be
      picked up by `docgen.config.js`'s `Cn*/Cn*.vue` glob and orphan a generated
      partial). Body via `CnDetailGrid`; `<pre>` for non-flat bags rather than
      `CnJsonViewer`, which would drag CodeMirror into this async chunk.
- [x] 4.9 Read the store's recorded error in store mode — `fetchCollection`
      never throws, so the `#error` slot was dead there.
- [x] 4.10 Return every setup key even when the composable is unused; Vue warns
      on a computed reading a key `setup()` omitted.
- [x] 4.11 Fix the duplicated `$slots.actions || $slots.actions` condition.

## 5. Shared library — tests

- [x] 5.1 `tests/utils/routeFilters.spec.js` — the two extracted pure functions.
- [x] 5.2 `tests/components/CnLogsPageSelfFetch.spec.js` (17) — `?jobId=` reaches
      the fetch; `_`-prefixed keys are not forwarded; `filter` wins a collision;
      `@route.<param>` resolves; `sortKey`/`sortOrder` → `_order`;
      `pagination.limit` → `_limit`; sort and page change re-fetch keeping the
      filter; exactly one fetch on mount; a query change adds exactly one more;
      schema-derived vs. configured vs. legacy columns; the partial-store
      fallback; the store-recorded error.
- [x] 5.3 `tests/components/CnLogsPageRowDetail.spec.js` (8) — default-off; the
      re-emitted `row-click`; scalar items with `@self` dropped; a flat bag as a
      labelled grid titled from the schema; JSON fallback for a non-flat bag.
- [x] 5.4 Extend `builtInFormatters.spec.js` for `count` and
      `CnCellRendererFormat.spec.js` for `milliseconds`.
- [x] 5.5 `npm test` — 6115 pass. The single failure
      (`tests/packaging/subpath-exports.spec.js`, "not installed — licence
      unverifiable") is pre-existing: it fails identically on a stashed tree,
      caused by optional deps absent from this environment.

## 6. Shared library — docs (CI-gated)

- [x] 6.1 Rewrite `docs/components/cn-logs-page.md` — the six new props, the
      `row-detail` slot, the `row-click` / `action` events, filtering, columns,
      a worked manifest example. Fixed the pre-existing `showTitle` default
      (documented `false`, actually `true`).
- [x] 6.2 Add `@slot` / `@binding` / `@event` JSDoc for the previously
      undocumented slots; `check:jsdoc` 63% → 96%, baseline bumped.
- [x] 6.3 `count` and `milliseconds` in `docs/migrating-to-manifest.md` and
      `docs/components/cn-cell-renderer.md`; document `widgetProps.colorMap` on
      the `badge` row, which the table omitted despite the code supporting it.
- [x] 6.4 Regenerate `docs/components/_generated/` and confirm `check:docs`
      passes (433 exports, 233 component docs).

## 7. OpenConnector

- [x] 7.1 `src/handlers/logTargets.js` — `view-job-logs` → `queryParam: 'jobId'`,
      with the `endpoint` / `event` mismatches recorded as a known gap.
- [x] 7.2 Update the two assertions that pinned the old key:
      `tests/vitest/runTargets.spec.js` (`logsLink`) and
      `tests/vitest/actionHandlers.spec.js` (the actionId → route table).
- [x] 7.3 `src/manifest.json` — the `JobLogs` `config`: six curated columns,
      `sortKey: created` / `sortOrder: desc`, `pagination.limit: 50`,
      `rowDetail: true`, plus a `_columnsNote` recording why `level` needs an
      explicit badge widget, why `executionTime` is in milliseconds, and why
      there is no `jobId` column.
- [x] 7.4 `npm test` (231), `npm run check:specs`, `npm run lint`.

## 8. Column-width follow-up (reported after the first pass)

Symptom: `message` rendered several times wider than it needed, while `jobClass`
and `stackTrace` were squeezed narrow enough that their text overlapped.

- [x] 8.1 Diagnose: `.cn-data-table td` had **no** overflow handling, and the
      inline `max-width` CnDataTable puts on sized cells is inert under
      `table-layout: auto` — the browser sizes columns from content. So
      `message`, the one unsized column, absorbed all slack, and the unbreakable
      45-char FQCN `OCA\OpenConnector\Action\SynchronizationAction` painted past
      its cell box into the next column.
- [x] 8.2 Add an opt-in `fixedLayout` prop to `CnDataTable` (default false)
      putting `cn-data-table--fixed` on the `<table>`.
- [x] 8.3 `src/css/table.css`: `.cn-data-table--fixed { table-layout: fixed }`
      plus `overflow-wrap: anywhere` on its cells, so a value that no longer
      gets to widen its column wraps instead of overflowing. Cells opting into
      `white-space: nowrap` (`cn-cell--end`) still win on specificity.
- [x] 8.4 Forward it through `CnLogsPage` as a `fixedLayout` prop (default
      false, so the other log pages are untouched).
- [x] 8.5 Re-express the `JobLogs` widths as percentages summing to 100 —
      14 / 9 / 38 / 9 / 19 / 11 — with `fixedLayout: true` so they bind exactly.
      Recorded as a `_widthsNote` in the manifest.
- [x] 8.6 `tests/components/CnDataTableFixedLayout.spec.js` (5) + a pass-through
      case in `CnLogsPageSelfFetch.spec.js`; docs for both new props (the props
      table, a "Column widths" section, and the colocated
      `src/components/CnDataTable/CnDataTable.md` the styleguide reads);
      `npx stylelint` clean.

## 9. Page padding follow-up (reported after the second pass)

Symptom: the logs page content ran flush into both edges of the app content area.

- [x] 9.1 Diagnose: `.cn-logs-page` set only `display/flex-direction/gap` and no
      padding, while `.cn-index-page` (`css/index-page.css`) and
      `.cn-detail-page` (`css/detail-page.css`) both set
      `padding: calc(5 * var(--default-grid-baseline))`. The logs page type had
      simply never picked up the shared convention.
- [x] 9.2 Apply the same root padding plus `box-sizing: border-box`, and the
      matching `calc(3 * …)` step at `max-width: 768px` that CnDetailPage uses,
      so all three page types agree on narrow viewports.
- [x] 9.3 Re-express the component's remaining raw-pixel gaps/margins in
      `--default-grid-baseline` multiples (same computed values: 16/8/12px).
- [x] 9.4 `npx stylelint` + `npx eslint` clean; 34 CnLogsPage tests green.

## 10. The remaining five log pages

Verified every filter against a populated instance before authoring anything —
`total` per query, not assumptions:

| action | schema | param | result |
|---|---|---|---|
| view-source-logs | call_log | `source` | 11 of 15 ✓ |
| view-endpoint-logs | call_log | `endpoint` | 0 of 0 ✓ (no inbound rows yet) |
| view-job-logs | job_log | `jobId` | 3 of 5 ✓ |
| view-synchronization-logs | synchronization_log | — | 0 of 10 ✗ |
| view-cloud-event-logs | call_log | — | 0 of 15 ✗ |

- [x] 10.1 Recognise the regression the filtering fix would otherwise cause:
      now that query params are actually applied, a param matching nothing turns
      a full page EMPTY. Before this change those two pages listed everything.
- [x] 10.2 `logTargets.js` — `queryParam: null` for `view-synchronization-logs`
      and `view-cloud-event-logs`, with `logsLocation()` returning a location
      with no query for them. Both blockers are backend-side and recorded:
      `SynchronizationLogService::normalize()` drops `synchronizationId` before
      the insert so no row links to its synchronization; `call_log` declares no
      event FK at all.
- [x] 10.3 Route `viewLogsHandler` through `logsLocation()` instead of building
      the query itself — it was the only caller not using the shared builder,
      and a null param became the literal query key `"null"`.
- [x] 10.4 Columns for all five, authored from observed rows rather than the
      schema: `call_log` keeps url/method/duration inside the `request` /
      `response` bags (dot paths), and `synchronization_log` rows carry no
      `created` (resolved via CnDataTable's `@self` fallback) and nest their
      counters under `result.objects`. HTTP status and the trace enums render as
      coloured badges; widths are percentages summing to 100 with `fixedLayout`.
- [x] 10.5 Traces: `detailRoute` was NOT dead config after all — `TraceDetail`
      is a real `type: "custom"` page (`/traces/:id`, `TraceDetailPage.vue`) with
      a step timeline and a Replay action. Added a `rowRoute` prop to
      `CnLogsPage` (the library's established name, matching CnIndexPage's
      `open-page` shape) and renamed the key, so the row click reaches the page
      it always named. No `rowDetail` there — a dialog cannot host Replay.
- [x] 10.6 Tests updated for the new navigation shape; three new `rowRoute`
      cases in `CnLogsPageRowDetail.spec.js`. Both suites green.

## 11. Inline-span overflow follow-up (reported after the third pass)

Symptom: the URL column on `/sources/logs` still ran over the Duration column,
despite `fixedLayout`. Root cause supplied by the user from DOM inspection —
the value's `span` measured wider than its `td`.

- [x] 11.1 Diagnose: step 8's `overflow-wrap: anywhere` on the cell was
      necessary but NOT sufficient. CnCellRenderer wraps every value in an
      **inline** span, whose box is sized by its own content rather than by the
      cell, so `overflow-wrap` had nothing to break against and the span simply
      grew past its column. Step 8's claim that fixed layout alone ended the
      overlap was therefore wrong — `jobClass` on the job logs page was affected
      by the same mechanism.
- [x] 11.2 `.cn-data-table--fixed td > .cn-cell-renderer` → `display: block`
      + `min-width: 0`, giving the wrapper the column's width so the inherited
      `overflow-wrap` can act. Fixes wrapping for every fixed-layout table.
- [x] 11.3 Add a `cn-cell--truncate` cell utility (one line, ellipsis, clipping
      on the promoted wrapper since `text-overflow` on a `display: table-cell`
      box is unreliable). For a single unbreakable token, wrapping mid-token
      over several lines makes every row that tall; the full value stays
      reachable through the `title` CnCellRenderer already sets.
- [x] 11.4 Apply `cellClass: "cn-cell--truncate"` to the URL column on
      SourceLogs and CloudEventLogs.
- [x] 11.5 Add `class` / `cellClass` to the v1 manifest schema's `$defs.column`
      (`additionalProperties: false`, so both were unusable there despite
      CnDataTable supporting them) and document the utility set.

## 12. Backend fix — the synchronization FK that was never persisted

Root cause, traced after the UI workaround shipped: **OpenRegister returns an
object's identifier as `id`** (mirrored on `@self.id`) and exposes **no**
top-level `uuid`. Verified against a live synchronization object — its keys are
`id, name, description, version, sourceId, …` with no `uuid`.

- [x] 12.1 `SynchronizationService.php:2345` built the run-log payload from
      `$synchronization['uuid'] ?? null` → always null →
      `SynchronizationLogService::normalize()` strips nulls → the FK never
      reached the row. Fixed to read `['id'] ?? ['uuid'] ?? null`.
- [x] 12.2 Same defect at `:2325`, where a sync-entryPoint `ExecutionTraceContext`
      took its `entryPointId` from the same expression. Confirmed against live
      data: all 5 `sync` traces stored **without** an entryPointId, all 5 `job`
      traces **with** one — which is the "some fields have data, others don't"
      the Traces page showed. Fixed identically.
- [x] 12.3 Same defect at `:1735`, feeding the batch HITL approval gate:
      `$synchronizationId` resolved to `''`, so
      `resolveApprovalForSynchronization()` / `suspendForSynchronization()` were
      keyed on an empty id. Fixed identically.
- [x] 12.4 `:475` inspected and deliberately NOT changed — it reads `['uuid']`
      but already falls back to `$object['id']` two lines down.
- [x] 12.5 Restore `queryParam: 'synchronizationId'` in `logTargets.js` (the key
      the writer sets, mirroring the job fix) and update both vitest assertions.
      `view-cloud-event-logs` stays null — `call_log` still has no event field.
- [x] 12.6 Two PHP regression tests in `SynchronizationServiceTest.php`: the
      run-log persists a supplied FK, and a null FK is DROPPED rather than
      stored as null — pinning the exact mechanism that made the defect silent.
      Note the existing fixtures set BOTH `id` and `uuid` on their synchronization
      payloads, which is why no test caught this.

**Not verifiable locally:** the PHP unit suite cannot run in this checkout —
`vendor/` was installed `--no-dev`, so the `OCA\OpenConnector\Tests\` namespace
is absent from the autoloader and every test in the file errors with
`Class "…\Helpers\ObjectServiceMockBuilder" not found`, the untouched
neighbouring test included. The new tests are written but unrun; CI (with dev
dependencies) is their first real execution. `php -l` passes and `phpcs` adds no
new violation class beyond the file's existing `createMock`/assert idiom.

**Existing rows stay unattributable.** The fix applies to newly written logs and
traces only; the 10 stored synchronization logs have no FK to recover, and
matching them to synchronizations by timestamp would be guesswork.

## 13. Manual verification (browser — user-driven)

See `test-plan.md`. Requires `npm run dev` in **both** repos, the library first:
OpenConnector imports `dist/esm` through the `node_modules` symlink, not `src`.
