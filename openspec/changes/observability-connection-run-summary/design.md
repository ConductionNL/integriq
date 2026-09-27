# Design: observability-connection-run-summary

Kind: code. The run record learns its source. A summary route groups runs per
day. A failed run gets a direct rerun. Thresholds are counted by a background
job, and the warning itself is a declared notification on an alert object.

## Where it fits

- Run record: `lib/Settings/register.d/sync-run-progress.json` gains
  `sourceId` (uuid) and `triggeredBy` (`cron`, `manual`, `rerun`).
  `lib/Service/SynchronizationRunProgressService.php:176` (`start()`) takes
  both and writes them with the first counters.
- Summary: `sources#runSummary` (GET `/api/sources/{id}/run-summary?from=&to=`)
  on `lib/Controller/SourcesController.php`, registered with the source routes
  at `appinfo/routes.php:375`, guarded by the existing action `source.logs`
  (`lib/actions.seed.json:4`). A new `lib/Service/RunSummaryService.php` reads
  `synchronization_run` for the source in the window, at most 31 days, in
  bounded pages (ADR-058), and sums per day.
- Source page: `SourceDetail` (`src/manifest.json:998`) gets a body widget
  `SourceRunSummaryWidget` (registered in `src/registry.js`) for the per-day
  table, and an `object-list` widget over `synchronization_run` filtered by
  `sourceId: @objectId`, the filter token the page's existing widgets use.
- Rerun: a new `rerunFailedRunHandler` in `src/handlers/actionHandlers.js`
  posts to `synchronizations#run` (`appinfo/routes.php:405`) with the run's
  `synchronizationId` and shows a toast linking the new run. It is offered on
  rows whose `status` is `failed`, on the source page list and on the
  `SynchronizationRuns` page. The run is started with `triggeredBy: rerun`.
- Thresholds: a fragment `lib/Settings/register.d/observability-connection-run-summary.json`
  merges `alertThresholds` onto `source` and `synchronization`, and declares
  `connection_alert` (`subjectType`, `subject`, `rule`, `count`, `threshold`,
  `windowMinutes`, `state` open or cleared, `openedAt`, `clearedAt`). It
  declares on `connection_alert` an `x-openregister-notifications` rule
  `threshold-passed` with trigger `created` and recipients the
  `openconnector-ops` group, the group the existing rules on
  `lib/Settings/integriq_register.json` already notify.
- Job: `lib/BackgroundJob/ConnectionThresholdJob.php`, a `TimedJob` every 300
  seconds (ADR-069), counts failed calls (`call_log`, `source`, `statusCode`
  400 or more), failed runs (`synchronization_run`, `status: failed`) and
  invalid objects (sum of `invalid`) in each window, and opens or clears
  alerts.
- Alerts page: a manifest fragment `src/manifest.d/observability-connection-run-summary.json`
  adds a `logs` page `ConnectionAlerts` over `connection_alert`.

## D1. The run carries its source

Grouping runs by source needs the source on the run. The alternative was to
join through the synchronization at read time. Rejected: a synchronization can
be edited to point at another source, and the summary would then move past
runs to the new source. Writing `sourceId` at start records the fact as it was.

## D2. A summary route, because the widget dialect has no sums per day

The manifest widget dialect has `bucket.interval: day` with a count
(`src/manifest.json:595`, widget `calls-daily`) and `metric: sum` without a bucket
(`src/manifest.json:1062`, widget `src-kpis-objects`), but not several sums per day in
one table. The route computes them. When OpenRegister's aggregate can return
sums per bucket, the route is replaced by a widget declaration, which ADR-031
prefers.

## D3. One click means no dialog

The existing "Run now" opens a dialog with options (`runSynchronizationHandler()`
at `src/handlers/actionHandlers.js:94`). A failed pull restarted by an
administrator on a Monday morning needs no options: it is the same run again.
"Run again" posts directly. The alternative was to reuse the dialog. Rejected:
the row asks for one click, and CKAN's "Reharvest" is one.

## D4. Count imperatively, notify declaratively

The notification dialect's `threshold` trigger compares one aggregated field on
a schema, as the `event_message` rule does with `retryCount`, and has no time
window or grouping per source; the `call_log` rule's note records that a
created trigger has no numeric operator. So integriq counts. The warning is
still declarative: the job creates a `connection_alert`, and a `created` rule
on that schema sends the notification through OpenRegister's engine, with no
`INotificationManager` call in integriq. An alert stays open until the count
falls back, so a source failing all night notifies once, not every five
minutes.

## Declarative versus imperative

Declarative: the thresholds on the source and the synchronization, the
notification rule on `connection_alert`, the alerts page, and the runs list on
the source page. Imperative: counting over a window per source, and the per-day
sums, for the reasons in D2 and D4.

## Seed data

No seeded source gets thresholds, so nothing alerts on upgrade.
`connection_alert` seeds nothing. The fragment's schema version bump on
`synchronization_run` leaves existing runs without `sourceId`; the summary
shows them under "source unknown" rather than guessing.

## Risks

- Counting every five minutes over many sources costs queries. The job skips
  sources with no thresholds, and each count is one aggregate query.
- A threshold set too low floods nobody but opens alerts often. The alerts
  page shows how often each rule opened in the last week.
