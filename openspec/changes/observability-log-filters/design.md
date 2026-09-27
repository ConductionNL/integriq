# Design: observability-log-filters

Kind: config. Integriq declares which filters each log page has; the shared
`CnLogsPage` renders them. Integriq writes manifest configuration only; the
component work is in nextcloud-vue.

## Where it fits

- Pages: `src/manifest.json`, the six `logs` pages `SourceLogs` (`:1338`),
  `EndpointLogs` (`:1589`), `JobLogs` (`:2156`), `SynchronizationLogs`
  (`:2462`), `CloudEventLogs` (`:2714`) and `Traces` (`:3168`). Each gets a
  `filterControls` array. The pages already exist in the main manifest, so the
  edit is there rather than in a `src/manifest.d/` fragment, which adds pages.
- Filter values: OpenRegister takes a property filter per non-underscore query
  key, and a range as `field[gte]` and `field[lte]`, the shape
  `lib/Service/MappingService.php:763` produces for VNG operators. A date range
  on `created` becomes `created[gte]` and `created[lte]`.
- Status options per schema, from the schemas in
  `lib/Settings/integriq_register.json`:
  - `call_log.statusCode` is an integer, so the options are ranges: success
    (`statusCode[gte]=200`, `statusCode[lt]=400`), client error (400 to 499),
    server error (500 and up).
  - `job_log.level` is a string (info, warning, error).
  - `execution_trace.status` and `entryPoint` are enums in
    `lib/Settings/register.d/execution-trace-observability.json:31` and `:42`.
  - `synchronization_log` has no status; its filters are the synchronization,
    the `test` flag and the time.
- Subject pickers: `source` and `endpoint` on `call_log`, `jobId` on `job_log`
  and `synchronizationId` on `synchronization_log`, the same keys
  `src/handlers/logTargets.js:56` already sends, so a row action and a picker
  set the same filter.
- Scoping: `SourceLogs` gets `filter: {direction: outbound}` and
  `EndpointLogs` gets `filter: {direction: inbound}`. `call_log.direction` is
  an enum of `inbound` and `outbound`.

## D1. Declared in the manifest, rendered by the shared page

REQ-SHELLUI-003 makes `CnLogsPage` the only log index component. A filter bar
built in integriq would be the bespoke wrapper that requirement forbids, and
every other app's log pages would still lack one. So integriq declares, and the
shared page renders. The alternative was to switch the log pages to `index`
pages, whose `CnIndexPage` already renders `quickFilters`. Rejected: it throws
away the log page's paging, sort and row detail fixed by
`job-logs-filtering-and-columns`, and breaks REQ-SHELLUI-003.

## D2. One declaration shape for every control

A control is `{key, label, type, options?, optionsFrom?, range?}`, with `type`
`select`, `reference` (a picker over another schema, such as sources) or
`dateRange`. A status option carries the filter map it applies, so a range
over `statusCode` and a plain enum use the same shape. The alternative was one
bespoke prop per filter kind. Rejected: every new filter would need a
component release.

## D3. The address bar holds the filter

Filter changes are written to `$route.query`. `CnLogsPage` already reads it, so
a filtered view reloads the same, can be shared as a link, and a row action
from another page lands with the picker already set. The alternative was
component state. Rejected: a refresh would lose the filter, and a link would
not carry it.

## D4. Source and endpoint logs stop showing each other's rows

Both pages list `call_log` with no page filter, so the source log shows inbound
endpoint calls and the endpoint log shows outbound calls. Scoping each by
`direction` makes the pages mean what their titles say. `CloudEventLogs` keeps
the full list and gains a direction control, because event deliveries are
outbound calls with no field of their own.

## Declarative versus imperative

All of it is declarative on integriq's side: manifest configuration read by a
shared component, and OpenRegister's existing property and range filters. No
controller or service is added.

## Risks

- Until nextcloud-vue ships the rendering, the declarations are inert. The
  page-level `filter` on `SourceLogs` and `EndpointLogs` works today, because
  `CnLogsPage` already honours `filter`.
- A range filter on a large `call_log` table without an index on `created` is
  slow. OpenRegister's table indexing is its concern; the date range defaults to
  the last seven days to keep the first load small.
