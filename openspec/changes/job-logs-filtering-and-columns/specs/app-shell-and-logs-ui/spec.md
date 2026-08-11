# Capability: app-shell-and-logs-ui

## ADDED Requirements

### Requirement: A logs page reached from a parent row action SHALL be scoped to that parent (REQ-SHELLUI-005)

A "View logs" affordance that carries a parent's identity MUST land the
destination logs page filtered to that parent, and the destination MUST render
the log schema's own fields rather than a generic default.

Concretely:

1. The query-param key a `VIEW_LOGS_TARGETS` entry pushes MUST name the property
   the log rows are actually **written** with, not merely a property the schema
   declares. `CnLogsPage` forwards every non-`_`-prefixed `$route.query` entry to
   OpenRegister as a property filter, so a key no writer sets matches nothing.
2. The destination page MUST apply that query param as a filter on its first
   fetch, and MUST re-apply it when the query changes on the same path.
3. A `type: "logs"` page MUST NOT render columns that do not exist on its
   schema. It satisfies this either by declaring `config.columns`, or by relying
   on the shared component deriving them from the loaded schema.
4. Log listing MUST be paginated and sortable server-side, so a job with a large
   log history does not render every row in one table.

#### Scenario: The row action pushes the field the writer persists

- **GIVEN** `JobService::saveJobLog()` persists the executing job's uuid as
  `jobId`
- **WHEN** the Jobs index "View logs" row action builds its route
- **THEN** it pushes `?jobId=<uuid>`, not `?job=<uuid>`
- **AND** the run/test modal's "View full log" link pushes the same key, both
  being built from the one `VIEW_LOGS_TARGETS` entry

#### Scenario: The logs page lands scoped to one job

- **GIVEN** a register holding `job_log` rows for several jobs
- **WHEN** the user opens `/jobs/logs?jobId=<uuid>`
- **THEN** the request carries `jobId=<uuid>` as a filter
- **AND** only that job's entries are listed

#### Scenario: A query change on the same path re-scopes the list

- **GIVEN** `/jobs/logs?jobId=<a>` is already mounted
- **WHEN** a navigation replaces the query with `?jobId=<b>`
- **THEN** the list re-fetches once with the new filter, without a remount

#### Scenario: Reserved list params are not mistaken for filters

- **WHEN** the query contains `_page`, `_limit`, `_search` or `_order`
- **THEN** those are consumed as list params and MUST NOT be forwarded as
  property filters

#### Scenario: Every rendered column exists on the schema

- **WHEN** any `type: "logs"` page renders
- **THEN** each column resolves against a real property of its schema (or its
  `@self` block)
- **AND** no column renders empty for every row because the property does not
  exist — specifically, the generic `timestamp` / `actor` / `action` / `target` /
  `details` set MUST NOT be used for an OpenRegister-backed log schema that
  declares none of them

#### Scenario: An unfilterable log page lists everything rather than nothing

- **GIVEN** a log schema whose stored rows carry no field linking back to the
  parent (synchronization logs persist no synchronization FK; `call_log`
  declares no event FK)
- **WHEN** the "View logs" affordance for that parent is used
- **THEN** it navigates to the UNFILTERED page
- **AND** it MUST NOT push a query param that matches nothing, which would
  render an empty table — strictly worse than listing everything
- **AND** the reason is recorded alongside the route table, so the param can be
  restored when the writer starts persisting the field

#### Scenario: A log whose detail surface is a real page navigates to it

- **GIVEN** a log type with a purpose-built detail page (`execution_trace` →
  `TraceDetail`, which renders a step timeline and owns a Replay action)
- **WHEN** a row is clicked
- **THEN** the page opens, rather than a generic read-only dialog
- **AND** a declarative key naming that page MUST be one the component actually
  reads — a config key no prop consumes is dead config, not configuration

#### Scenario: A nested log payload is reachable, not truncated into a cell

- **GIVEN** a `job_log` entry whose `stackTrace` holds several frames and whose
  `arguments` holds an argument map
- **WHEN** the row is rendered
- **THEN** the table shows a summary (a frame count), not a truncated JSON blob
- **AND** the full frame list and argument map are reachable from the row

Notes: `src/handlers/logTargets.js` (the one route/param table, read by both
`handlers/actionHandlers.js::viewLogsHandler` and `modals/v2/runTargets.js`), the
`JobLogs` `config` in `src/manifest.json`, and — for the shared behaviour —
`@conduction/nextcloud-vue`'s `CnLogsPage` plus `utils/routeFilters.js`.

Requirement 3 is met for `JobLogs` by explicit `config.columns` and for the
remaining log pages by `CnLogsPage` forwarding the loaded schema to
`CnDataTable`, which derives columns from it.

Every param was verified against a populated instance rather than inferred from
the schema — `total` per filter: `source` 11 of 15, `endpoint` 0 of 0 (correct;
no inbound rows exist yet), `jobId` 3 of 5, `synchronization` 0 of 10 (broken),
`event` 0 of 15 (broken). The two broken ones now carry `queryParam: null`.

Both are blocked backend-side, not in this layer:
`SynchronizationLogService::normalize()` drops `synchronizationId` before the
insert, so no `synchronization_log` row links to its synchronization; and
`call_log` declares no event property at all. Requirement 1 is satisfied by
navigating unfiltered until each writer persists a usable field.
