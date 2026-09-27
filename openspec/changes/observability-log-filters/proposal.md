---
kind: config
depends_on: [job-logs-filtering-and-columns]
---

# Proposal: observability-log-filters

## Summary

Integriq's log pages filter correctly when a filter arrives in the address
bar, for example from a "View logs" row action. A person standing on a log page
cannot filter at all: there is no status select, no source or endpoint picker
and no date range. This change declares filter controls on the six log pages
and asks the shared logs page component to render them, so an administrator
narrows a log by status, source, endpoint and time without editing a URL.

## Why

Matrix row `integriq:obs-filter-logs`, "Filter logs by status, source,
endpoint and time." The matrix rates integriq `partial` with `built.state`
`built`. Its note: "Filtering works as deep-link query params ... and via
row-action links from other pages, but none of the logs pages (SourceLogs,
EndpointLogs, Traces, JobLogs) has an on-page filter bar or date-range picker a
staff member can use directly."

There is no demand row. Competitors rated `yes`:

- n8n (`n8n`), source read at n8n@2.40.7:
  "packages/cli/src/executions/execution.service.ts:83 status, :88 workflowId,
  :90 startedAfter and :91 startedBefore filters ... drive
  packages/frontend/editor-ui/src/features/execution/executions/components/ExecutionsFilter.vue".
  No evidence URL is recorded for this cell.
- MuleSoft Anypoint (`mulesoft`), evidence
  https://docs.mulesoft.com/monitoring/logs-search-hf.md, which lets you
  "manage, search for, filter, and analyze your logs" and "Set a date and time
  range".
- Frank!Framework (`frank`), source read at v10.2.0:
  "console/backend/src/main/java/org/frankframework/console/controllers/TransactionalStorage.java:132
  browses a message log or error store filtered by type, host, messageId,
  correlationId, label, comment and start and end date". No evidence URL is
  recorded for this cell.

This change covers one row: `integriq:obs-filter-logs`.

## What integriq already has

- Six `logs` pages in `src/manifest.json`: `SourceLogs` (`:1338`),
  `EndpointLogs`, `JobLogs`, `SynchronizationLogs`, `CloudEventLogs` and
  `Traces` (`:3168`). None declares a filter control.
- Row actions that land on a log page pre-filtered:
  `src/handlers/logTargets.js:56` (`VIEW_LOGS_TARGETS`) maps source, endpoint,
  job and synchronization to a query parameter. `view-cloud-event-logs` has no
  parameter, because `call_log` has no event field (the comment above it).
- `job-logs-filtering-and-columns` made the shared `CnLogsPage` read
  `$route.query` and a manifest `filter`, with server-side paging and sorting.
- `SourceLogs`, `EndpointLogs` and `CloudEventLogs` all list `call_log` with no
  page-level filter, so each shows every call, inbound and outbound.
- `app-shell-and-logs-ui` REQ-SHELLUI-003 forbids an integriq-owned log index
  component: the log pages are rendered by `@conduction/nextcloud-vue`'s
  `CnLogsPage`. The version integriq locks, 2.39.0 (`package-lock.json`),
  renders no filter bar in `CnLogsPage`, while the same library ships
  `CnFilterBar` and `CnDateRangePicker`.

## What this change builds

1. A `filterControls` declaration on each of the six log pages: status, the
   subject (source, endpoint, job, synchronization or entry point) and a date
   range on the page's time column; `direction` on the three `call_log` pages.
2. A page-level `filter` that scopes `SourceLogs` to `direction: outbound` and
   `EndpointLogs` to `direction: inbound`, so the two pages stop showing each
   other's rows.
3. The status options as ranges over `statusCode` for `call_log` (success,
   client error, server error) and as the enum for `job_log.level` and
   `execution_trace.status`.
4. Filter changes written to the address bar, so a filtered view is a link
   another administrator can open.

## Nextcloud-vue's half, not built here

`CnLogsPage` rendering `filterControls` with `CnFilterBar` and
`CnDateRangePicker`, folding their values into the filter map it already sends,
and writing them to `$route.query`. That is a change to
`ConductionNL/nextcloud-vue`, released in a 2.x minor. Integriq takes it by
moving its lockfile within the existing `^2.37.0` range, not by widening the
range.

## Out of scope

- Free-text search over log messages. OpenRegister's search over large log
  tables is a separate question.
- Saved searches.
- Retention and export of logs.
