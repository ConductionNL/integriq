---
kind: code
depends_on: []
---

# Proposal: observability-connection-run-summary

## Summary

An administrator who looks after a source system wants to see, per day, which
scheduled pulls ran and what they delivered, to restart a failed pull with one
click, and to be warned when a source starts failing more than usual. Integriq
lists every run and every call, but not per source per day, a restart goes
through the run dialog, and nothing warns on a threshold. This change adds a
per source, per day pull summary, a one-click "Run again" on a failed run, and
thresholds per source and per synchronization that open an alert and notify
the operations group.

## Why

Two rows.

`opencatalogi:int-connector-monitor`, from opencatalogi's matrix: "See per
source system which scheduled pulls ran each day and what they delivered, and
restart a failed pull with one click." Opencatalogi rates itself `partial`
with `built.state` `built` and names integriq as owner and provider. Demand:
tender, https://www.tenderned.nl/aankondigingen/overzicht/407973, origin note
"Drechtsteden WOO Publicatietool (TenderNed 407973, 2026-01-14) wishes DMK-05,
DMK-05-KW-01, DMK-06, DMK-06-KW-02". Competitor rated `yes`: CKAN (`ckan`),
source read at ckanext-harvest v1.6.2, "each harvest source has a jobs list
with per-job added, updated, deleted and errored counts
(ckanext/harvest/templates/snippets/job_details.html:4-30 ...) and a one-click
Reharvest button on the source admin page
(ckanext/harvest/templates/source/admin_base.html:16-19 ...)". No evidence URL
is recorded for this cell. The sibling matrix names the missing half: "the runs
list has no source-system column or per-day grouping ... and none of it is
reached from the opencatalogi app beyond the Add integration link".

`integriq:obs-threshold-counts`, "See per connection how many messages were
processed, delivered and refused, and warn the administrator when a threshold
is passed." Integriq rates it `partial` with `built.state` `built`. Demand:
tender, https://www.tenderned.nl/aankondigingen/overzicht/226100, note
"Gemeente Stein wens W3 (requirement 20243)". Competitor rated `yes`:
Frank!Framework (`frank`), source read at v10.2.0, "per adapter and receiver
the console counts received, processed and error messages ... and
core/src/main/java/org/frankframework/monitoring/Trigger.java:229 setThreshold
with :235 setPeriod raises an alarm". No evidence URL is recorded for this
cell.

This change covers `opencatalogi:int-connector-monitor` (integriq's half, from
a sibling matrix) and `integriq:obs-threshold-counts`.

## What integriq already has

- A run record per synchronization run with `status`, `found`, `processed`,
  `created`, `updated`, `deleted` and `invalid`
  (`lib/Settings/register.d/sync-run-progress.json`), started by
  `lib/Service/SynchronizationRunProgressService.php:176` (`start()`), which
  stores the `synchronizationId` but not the source.
- A runs page over it (`SynchronizationRuns` in `src/manifest.json`) with no
  source column and no grouping by day.
- A "Run now" row action that opens the run dialog:
  `src/handlers/actionHandlers.js:94` (`runSynchronizationHandler()`), which
  posts to `synchronizations#run` (`appinfo/routes.php:405`).
- Per source counts on the source detail page (`src/manifest.json:998`,
  `SourceDetail`): calls logged and errors with `statusCode` `>=400`.
- Declared notification rules through OpenRegister's engine
  (`integriq-notifications`), for example `job-error` on `job_log`. The
  `call-failed` rule on `call_log` is disabled; its own note says the created
  trigger has no numeric operator. No rule counts over a time window per
  source.

## What this change builds

1. `sourceId` and `triggeredBy` on every run record, set when the run starts.
2. `GET /api/sources/{id}/run-summary` with one row per day: runs, succeeded,
   failed, found, created, updated and invalid.
3. On the source detail page, a "Pulls per day" table from that route and a
   "Pull runs" list of the source's runs.
4. "Run again" on a failed run: one click, no dialog, the same synchronization
   run the scheduler would start, with a toast that links to the new run.
5. `alertThresholds` on a source and on a synchronization: failed calls, failed
   runs and invalid objects, each a count over a window in minutes.
6. A background job that counts against the thresholds every five minutes and
   opens a `connection_alert` when one is passed, and clears it when the count
   falls back.
7. A declared `created` notification on `connection_alert` to the members of
   the group an administrator names in the app setting
   `connection_alert_group` (none by default, design D5), and an alerts page.

## Opencatalogi's half, not built here

Opencatalogi's integrations page linking each catalog's feeding source to its
"Pulls per day" view in integriq, so the view is reached from opencatalogi
beyond the "Add integration" link. That is opencatalogi's work.

## Out of scope

- Alerting by e-mail or chat beyond what OpenRegister's notification engine
  sends.
- Thresholds on endpoints (inbound traffic). Rate limits cover inbound volume.
