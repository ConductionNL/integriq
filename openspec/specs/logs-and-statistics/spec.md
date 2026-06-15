# logs-and-statistics Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-24-logs-and-statistics. Update Purpose after archive.

@e2e exclude backend logs/statistics API (LogsController + StatisticsService aggregation + CSV export, no browser UI) — covered by Newman/PHPUnit; the log sub-pages render is covered by manifest-pages e2e

## Requirements
### Requirement: Synchronization log listing, retrieval and deletion (REQ-001)

`LogsController::index(?int $limit = 20, ?int $offset = 0, ?string
$level = null, ?string $message = null, ?string $synchronizationId =
null, ?string $dateFrom = null, ?string $dateTo = null): JSONResponse`
MUST query OR for `register=openconnector, schema=synchronization_log`
merged with the supplied filters, paginate with the given limit and
offset, and return:

```json
{
  "results": [<logs>],
  "pagination": { "page": <currentPage>, "pages": <totalPages>, "results": <countOnPage>, "total": <total> }
}
```

`LogsController::show(string $id): JSONResponse` MUST `find` by id
under the same register/schema and return the log's `getObject()` array
on success, or `404 { error: 'Log not found' }` on missing.

`LogsController::destroy(string $id): JSONResponse` MUST resolve the
log by id and call `deleteObject($log->getUuid())`. On success it MUST
return `{ message: 'Log deleted successfully' }`; on missing,
`404 { error: 'Log not found or could not be deleted' }`.

All three carry `@NoAdminRequired` + `@NoCSRFRequired`.

#### Scenario: list with level filter

- **GIVEN** `?level=error&limit=10&offset=0`
- **WHEN** `index(...)` is called
- **THEN** OR is queried with `filters: { register, schema, level: 'error' }, limit: 10, offset: 0`
- **AND** the response carries `pagination.page == 1`

#### Scenario: show non-existent log

- **WHEN** `show('does-not-exist')` is called
- **THEN** the response status is 404
- **AND** the body is `{ error: 'Log not found' }`

#### Scenario: destroy removes the log

- **GIVEN** a log with uuid `<id>` exists
- **WHEN** `destroy('<id>')` is called
- **THEN** `deleteObject('<id>')` is invoked on OR
- **AND** the response is `{ message: 'Log deleted successfully' }`

#### Notes

- **HIGH (IDOR / OWASP A01:2021):** all three endpoints are
  `@NoAdminRequired` + `@NoCSRFRequired` and accept arbitrary log
  UUIDs. Any authed user can list / read / delete every log on the
  instance. `destroy` further enables audit-trail tampering — users
  can delete logs of their own activity.
- The retrofit documents the surface but does not patch it. Hardening
  is a focused change with admin-only annotations + per-object scope
  checks.

---

### Requirement: Synchronization log level statistics and CSV export (REQ-002)

`LogsController::statistics(): JSONResponse` MUST issue five separate
OR `findAll` queries — one per level
(`error` / `warning` / `info` / `success` / `debug`) — under the
`synchronization_log` schema, compose the per-level counts, and return:

```json
{
  "errorCount": N, "warningCount": N, "infoCount": N, "successCount": N, "debugCount": N,
  "levelDistribution": { "error": N, "warning": N, "info": N, "success": N, "debug": N }
}
```

On any `\Exception` the method MUST return `500 { error: 'Could not fetch statistics' }`.

`LogsController::export(?string $level = null, ?string $message =
null, ?string $synchronizationId = null, ?string $dateFrom = null,
?string $dateTo = null): JSONResponse` MUST run the same filter
composition as REQ-001 `index`, query OR WITHOUT pagination, and
build a CSV string in-memory with the header
`UUID,Level,Message,Synchronization ID,User ID,Session ID,Created,Expires`
and one row per log. The response MUST be:

```json
{ "filename": "synchronization_logs_<Y-m-d_H-i-s>.csv", "content": "<csv>", "contentType": "text/csv" }
```

On any `\Exception`: `500 { error: 'Could not export logs' }`.

Both endpoints carry `@NoAdminRequired` + `@NoCSRFRequired`.

#### Scenario: statistics aggregates 5 levels

- **WHEN** `statistics()` is called
- **THEN** five OR `findAll` queries fire (one per level)
- **AND** the response contains both per-level keys (`errorCount`, etc) and a `levelDistribution` map

#### Scenario: export builds a single CSV string

- **GIVEN** 3 logs match `level=error`
- **WHEN** `export(level: 'error')` is called
- **THEN** the response `content` is a CSV string with 1 header line + 3 data lines
- **AND** message fields are double-quoted with internal `"` doubled (RFC4180-ish)

#### Notes

- **MEDIUM (soft DoS):** `export` materialises the entire matching
  dataset in PHP memory and returns it in the JSON body. Large
  datasets OOM the worker.
- **HIGH (IDOR):** like REQ-001, both endpoints are `@NoAdminRequired`
  — any user can extract every log on the instance via CSV.

---

### Requirement: Per-source call log listing and outbound test call (REQ-003)

`SourcesController::logs(SearchService $searchService): JSONResponse`
MUST list `call_log` objects from OR with pagination via `_page` /
`_limit`, support special filter parameters (`date_from`, `date_to`,
`endpoint`, `status_code`, `slow_requests`), and validate sort fields
against the allow-list `['created', 'status_code', 'endpoint']` before
forwarding to OR.

`SourcesController::test(CallService $callService, string $id):
JSONResponse` MUST resolve the source by `$id` (404 on missing) and
then dispatch an outbound HTTP call via `CallService` using the
request body's `query` / `headers` / `method` / `endpoint` / `type` /
`body` fields. The response is the call result (status / headers /
body) as JSON.

Both endpoints carry `@NoAdminRequired` + `@NoCSRFRequired`.

#### Scenario: logs with status_code range

- **GIVEN** `?status_code=400,500`
- **WHEN** `logs(...)` is called
- **THEN** the search condition `status_code >= 400 AND status_code <= 500` is appended to `searchParams`

#### Scenario: test fires a CallService call

- **GIVEN** a source uuid `src-1` and a body `{ method: 'POST', endpoint: '/foo', body: 'x' }`
- **WHEN** `test($callService, 'src-1')` is called
- **THEN** `CallService` dispatches a POST to the source's resolved URL + `/foo`
- **AND** the response carries the upstream response shape

#### Notes

- **HIGH (SSRF / IDOR):** `test` is `@NoAdminRequired` and accepts
  any source UUID. Even though the URL is admin-configured, source
  UUIDs are guessable and the test action can be used as a blind
  SSRF probe (sources pointing at internal IPs), a credential probe
  (sources with stored auth), or an outbound DoS vector. Triggers
  `hydra-gate-no-admin-idor`.
- **HIGH (info disclosure):** `logs` exposes every source's call
  logs (URLs, status codes, response bodies) to every authed user.

---

### Requirement: App-wide retention settings read and update (REQ-004)

`SettingsService::getSettings(): array` MUST return an array shaped:

```php
[
  'version' => ['appName' => 'Open Connector', 'appVersion' => '0.2.0'],
  'retention' => [
    'successLogRetention'      => <int ms, default 3_600_000>,         // 1h
    'callLogRetention'         => <int ms, default 2_592_000_000>,      // 30d
    'eventMessageRetention'    => <int ms, default 604_800_000>,        // 7d
    'jobLogRetention'          => <int ms, default 2_592_000_000>,      // 30d
    'syncContractLogRetention' => <int ms, default 7_776_000_000>,      // 90d
    'syncLogRetention'         => <int ms, default 2_592_000_000>,      // 30d
  ],
]
```

When the `openconnector / retention` IAppConfig key exists, the method
MUST JSON-decode it and override the per-key defaults via `?? <default>`.

`SettingsService::updateSettings(array $data): array` MUST accept a
shape containing a `retention` key. When present, the method MUST
JSON-encode the retention payload and write it to `openconnector /
retention` via `setValueString`. The method MUST then return
`getSettings()` (the freshly read state).

Both methods throw `\RuntimeException` on `\Exception`, with the
original message wrapped.

#### Scenario: defaults applied when no config

- **WHEN** `getSettings()` is called with no `retention` key in IAppConfig
- **THEN** the response carries all 6 default retention values

#### Scenario: update writes JSON and returns freshly read state

- **GIVEN** `data = { retention: { callLogRetention: 86_400_000 } }`
- **WHEN** `updateSettings($data)` is called
- **THEN** `openconnector / retention` is set to a JSON string with the new value
- **AND** the return is `getSettings()` reflecting the updated config

#### Notes

- **LOW (drift):** `appVersion: '0.2.0'` is hard-coded and drifts
  from `appinfo/info.xml`. Documented as observed; fix is to source
  from `IAppManager::getAppVersion`.

---

### Requirement: App-wide stats and DB-vendor-aware retention rebase (REQ-005)

`SettingsService::getStats(): array` MUST return a fixed-shape array
with three sections: `warnings` (counts of records that need
attention — without-expiry / expired across 5 schemas), `totals`
(15 `total<Table>` counts), `sizes` (10 size counters), plus a
`lastUpdated` ISO-8601 timestamp.

Per-table counts MUST be obtained via direct
`SELECT COUNT(*) FROM <table>` queries (one per table). Per-table
`\Exception` failures MUST default the count to `0` and log a `debug`
entry. Any other top-level `\Exception` MUST be wrapped in
`\RuntimeException`.

`SettingsService::rebase(): array` MUST iterate over 6 retention
buckets (success-log, call-log, event-message, job-log, sync-contract-log,
sync-log) and for each non-zero retention value run an SQL `UPDATE`
that sets `expires` on every row where the current value is `NULL` or
empty (and for sync-contract / sync logs also where the value is
`'0000-00-00 00:00:00'` or `created IS NOT NULL`). The
`expires` expression MUST be DB-vendor-portable via
`expiresExpression` (REQ-005 helpers).

Per-bucket exceptions MUST be appended to `results.errors` and logged
via `LoggerInterface::error`. The method MUST always return a result
dict with `startTime`, `endTime`, `duration`, `success`,
`retentionResults`, `errors`.

The two private helpers:

- `expiresExpression(string $createdColumn): string` MUST return a
  parametrised SQL fragment that adds a microsecond interval. The
  expression branches on the active `Doctrine\DBAL\Platforms`
  vendor: PostgreSQL gets
  `<col> + (? || ' microseconds')::interval`; everything else gets
  `DATE_ADD(<col>, INTERVAL ? MICROSECOND)`.
- `columnExists(string $unprefixedTable, string $column): bool` MUST
  return `true` iff the named column exists on the named table. The
  query branches on vendor: PostgreSQL uses
  `information_schema.columns`; others use `SHOW COLUMNS FROM
  \`*PREFIX*<table>\` LIKE <quoted-column>`. Any `\Throwable`
  (e.g. legacy table dropped) MUST return `false`.

`SettingsController::rebase(): JSONResponse` is a thin HTTP wrapper
that calls `SettingsService::rebase()` and returns its result as JSON.
On `\Exception` it returns `500 { error: 'Failed to perform rebase
operation', message: <e->getMessage()> }`. Carries `@NoAdminRequired`
+ `@NoCSRFRequired`.

#### Scenario: getStats aggregates 15 totals

- **WHEN** `getStats()` is called
- **THEN** 15 `SELECT COUNT(*)` queries fire, one per registered table
- **AND** the response carries `totals.totalCallLogs`, `totals.totalJobs`, etc

#### Scenario: rebase on PostgreSQL uses interval syntax

- **GIVEN** the DB platform is `PostgreSQLPlatform`
- **AND** `retention.callLogRetention = 86_400_000` (1 day in ms)
- **WHEN** `rebase()` is called
- **THEN** the call-logs UPDATE uses `expires = created + (? || ' microseconds')::interval` with `86_400_000_000` (microseconds) as the bound parameter

#### Scenario: rebase on MySQL uses DATE_ADD

- **GIVEN** the DB platform is MySQL (default branch)
- **WHEN** `rebase()` runs the same retention
- **THEN** the UPDATE uses `expires = DATE_ADD(created, INTERVAL ? MICROSECOND)`

#### Scenario: missing legacy table is silently skipped

- **GIVEN** the `event_messages.expires` column does NOT exist (legacy table dropped post #820)
- **WHEN** `rebase()` reaches the event-messages bucket
- **THEN** `columnExists` returns `false` (the `\Throwable` catch fires)
- **AND** the result dict contains `retentionResults.eventMessagesUpdated = 'Column expires not found - skipped'`

#### Notes

- **HIGH (privilege escalation / data destruction):** the HTTP
  wrapper `SettingsController::rebase` is `@NoAdminRequired` —
  combined with REQ-004's `updateSettings` (also @NoAdminRequired)
  any authed user can set retention to `1ms` and trigger `rebase`,
  effectively wiping every log on the instance.
- **MEDIUM (bucket clobber):** the first UPDATE branch (success-log
  retention) writes to `*PREFIX*openconnector_call_logs` AND records
  the row count under the key `callLogsUpdated`; the second branch
  (call-log retention) does the same. The second clobbers the first
  in the response dict. Two of the six rebase passes target the
  same table with the same write key — observable bug.
- **MEDIUM (DB portability):** the sources-controller log query
  (REQ-003) uses MySQL-specific `JSON_EXTRACT(response,
  '$.responseTime')` — would fail under Postgres.

