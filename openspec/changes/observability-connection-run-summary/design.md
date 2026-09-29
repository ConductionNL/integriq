# Design: observability-connection-run-summary

Kind: code. The run record learns its source. A summary route groups runs per
day. A failed run gets a direct rerun. Thresholds are counted by a background
job, and the warning itself is a declared notification on an alert object.

## Where it fits

- Run record: `lib/Settings/register.d/sync-run-progress.json` gains
  `sourceId` (a string: a synchronization's `sourceId` is polymorphic, a uuid,
  a legacy number or a `register/schema` pair) and `triggeredBy` (`cron`,
  `manual`, `rerun`). `SynchronizationService::synchronize()` takes an
  optional `triggeredBy`; without one it reads the execution trace (`cron`
  from JobService, `manual` otherwise).
  `lib/Service/SynchronizationRunProgressService.php:176` (`start()`) takes
  both and writes them with the first counters.
- Summary: `sources#runSummary` (GET `/api/sources/{id}/run-summary?from=&to=`)
  on `lib/Controller/SourcesController.php`, registered with the source routes
  at `appinfo/routes.php:375`, guarded by the existing action `source.logs`
  (`lib/actions.seed.json:4`). A new `lib/Service/RunSummaryService.php` reads
  `synchronization_run` for the source in the window, at most 31 days, in
  bounded pages (ADR-058), and sums per day.
- Source page: `SourceDetail` gets a body widget `SourceRunSummaryWidget`
  (`src/components/`, registered in `src/registry.js`) for the per-day table
  and the source's latest runs with Run again on a failed one. The route
  answers both, so the page makes one request behind one action guard
  instead of a second `object-list` widget.
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
  `threshold-passed` with trigger `created` and one `expression` recipient,
  `OCA\\Integriq\\Notification\\ConnectionAlertRecipientResolver` (D5).
- Job: `lib/BackgroundJob/ConnectionThresholdJob.php`, a `TimedJob` every 300
  seconds (ADR-069), counts failed calls (`call_log`, `source`, `statusCode`
  400 or more), failed runs (`synchronization_run`, `status: failed`) and
  invalid objects (sum of `invalid`) in each window, and opens or clears
  alerts.
- Recipient setting: `ConnectionAlertSettingsController` (GET and PUT
  `/api/admin/connection-alert-group`, admin only) and
  `src/views/admin/ConnectionAlertSettings.vue` on the admin settings page.
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

## D5. The recipient group is an app setting with no default

The change first named `openconnector-ops`, the group the older rules in
`integriq_register.json` notify. That group exists on no instance, and which
group looks after an organisation's connections is that organisation's call.
So the rule names an `expression` recipient, `ConnectionAlertRecipientResolver`,
which returns the members of the group in the app setting
`connection_alert_group`. Nothing is set by default: an alert then notifies
nobody and shows on the alerts page only. An administrator names the group on
the admin settings page, which refuses a group that does not exist. The
alternative, a `groups` recipient with a fixed name, would ship a rule that
notifies nobody on every install without saying so.

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
