# Test plan — job-logs filtering and columns

## Build prerequisite

OpenConnector imports the **built** library (`module: dist/esm/index.js`) through
the `node_modules/@conduction/nextcloud-vue → ../../../nextcloud-vue` symlink;
`webpack.config.js` deliberately removed the `src` alias. So both watchers must
run, the library first:

```
cd ../nextcloud-vue && npm run dev     # rollup -c -w → dist/esm
cd ../openconnector && npm run dev     # webpack
```

## Automated

### `nextcloud-vue`

| Command | Expectation |
|---|---|
| `npm test` | 6115 pass. `tests/packaging/subpath-exports.spec.js` fails **pre-existing** ("not installed — licence unverifiable" for optional deps absent from the dev environment); it fails identically on a stashed tree. |
| `npm run lint` | clean |
| `npm run check:jsdoc` | all 250 components meet baseline; `CnLogsPage` 96% (baseline bumped from 0.63) |
| `npm run check:docs` | 433 exports, 233 component docs, all accuracy checks pass |
| `cd docusaurus && npm run prebuild:docs` | regenerates `_generated/CnLogsPage.md` + `_generated/CnCellRenderer.md`; commit the diff or the freshness gate fails |

Specs that carry this change:

- `tests/utils/routeFilters.spec.js` — the extracted resolvers
- `tests/components/CnLogsPageSelfFetch.spec.js` — filtering, paging, sorting, single-fetch-on-mount, column resolution, the partial-store fallback
- `tests/components/CnLogsPageRowDetail.spec.js` — the row-detail dialog
- `tests/utils/builtInFormatters.spec.js` — `count`
- `tests/components/CnCellRendererFormat.spec.js` — `duration` + `milliseconds`
- `tests/components/CnIndexPageSelfFetch.spec.js`, `CnIndexPageQuickFilters.spec.js` — regression proof that the resolver extraction was behaviour-neutral

### `openconnector`

| Command | Expectation |
|---|---|
| `npm test` | 231 pass (14 files) |
| `npm run check:specs` | json-strict + manifest (Ajv, 0 errors, 35 pages) + register all PASS |
| `npm run lint` | clean |

## Manual (browser)

1. **Jobs index → row `…` → "View logs".** URL becomes `#/jobs/logs?jobId=<uuid>`.
   - Six populated columns: **Time** (absolute date + time), **Level** (coloured
     pill — blue INFO, amber WARNING, red ERROR), **Message**, **Duration**
     (`6s` for 6245 ms; a sub-second value as `245ms`), **Job class**,
     **Stack trace** (`5 frames`, or `—` when absent).
   - Only that job's entries. Cross-check the row count against the same URL with
     `?jobId=` removed.
   - **Padding**: the page has the same side padding as the Jobs index and a
     detail page — content no longer touches either edge. Re-check under 768px,
     where all three step down together.
   - **Widths**: the six columns sit at 14 / 9 / 38 / 9 / 19 / 11 % of the table.
     `Message` no longer sprawls across the spare width, and the `Job class`
     FQCN wraps inside its own cell rather than overlapping `Stack trace`.
     Re-check at a narrow window and at full width.
   - Network tab: exactly **one**
     `GET …/api/objects/openconnector/job_log?jobId=<uuid>&_limit=50&_page=1&_order={"created":"desc"}`.
2. **Change `?jobId=` in the address bar to another job's uuid** (same path).
   Exactly one new request, new row set — this exercises the `$route.query` watcher.
3. **Click the Time header.** The ▲/▼ indicator flips, one request with the
   inverted `_order`, page resets to 1. Shift-click a second sortable header → a
   `2` priority badge appears and `_order` carries both keys in order.
4. **Pagination** renders only when `pages > 1`. Page 2 → `_page=2` with `jobId`
   still present. Change the page size → `_limit` changes, back to page 1.
5. **Click a row.** The detail dialog opens, titled with the message. Scalars as a
   horizontal grid; `arguments` as a labelled block
   (`synchronizationId: australia-austender-…`); `stackTrace` as labelled
   `frame_0…frame_n` rows. Closes via the button and via Esc.
6. **`/jobs/logs` with no query.** All `job_log` rows, same columns, pagination
   active.
7. **Regression check for the inherited library fix** — `/sources/logs`,
   `/endpoints/logs`, `/synchronizations/logs`, `/cloud-events/logs`, `/traces`:
   no more blank columns (schema-derived now), working sort and pagination, and
   **no** row dialog (`rowDetail` defaults false). Note that `?endpoint=` and
   `?event=` still filter to nothing — a known, documented out-of-scope gap.
8. **Console clean on all six pages.** `tests/e2e/regression/manifest-pages.spec.ts`
   gates on `console.error`, so any new error fails CI.

## Risk notes

- Library changes reach only OpenConnector: it is the sole workspace app
  declaring `type: "logs"` pages.
- Every new `CnLogsPage` prop defaults to the previous behaviour, so the four
  other log pages change only in the ways step 7 checks for — schema-derived
  columns, paging and sorting.
- The `register` / `schema` props no longer re-fetch on change in store mode
  (`useListView` captures the object type at setup). Safe because
  `CnPageRenderer` keys the dispatched page on register + schema, so a manifest
  page swap remounts. Documented in the prop JSDoc.
