# Design — Retrofit logs-and-statistics

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

Three controllers + one service make up the openconnector logs / stats /
retention surface:

- `LogsController` — CRUD + stats + CSV export over synchronization logs
  (OR-backed `synchronization_log` schema).
- `SourcesController::logs` + `::test` — per-source call-log listing and
  a "fire a test call" outbound dispatch.
- `SettingsController::rebase` — admin action that bulk-updates the
  `expires` column across every log table to the current retention
  window.
- `SettingsService` — backs the rebase action, provides
  `getStats` / `getSettings` / `updateSettings` for the dashboard, and
  defines `expiresExpression` + `columnExists` for DB-portable rebase
  SQL (GH #822).

## Observed-but-suspicious behaviour (flagged, not fixed)

| Site | Issue | Severity |
|---|---|---|
| `LogsController::index` / `::show` / `::destroy` / `::statistics` / `::export` | every method is `@NoAdminRequired` + `@NoCSRFRequired` with no per-object guard. Any authenticated user can list, read, delete, count, and export the FULL synchronization-log dataset across the instance. Triggers `hydra-gate-no-admin-idor`. | **HIGH — IDOR / data exfiltration** |
| `SourcesController::logs` | same — `@NoAdminRequired`. Any user reads every source's call logs (including request URLs, status codes, response bodies). | **HIGH — info disclosure / IDOR** |
| `SourcesController::test` | `@NoAdminRequired` — any user can fire an arbitrary outbound HTTP call by providing the source UUID + request params. Even though the URL itself is admin-configured, source UUIDs are guessable; the test action can be used for: (a) blind SSRF if any source points at internal infrastructure, (b) credential probing if sources carry stored auth, (c) outbound DoS via flood. | **HIGH — SSRF / IDOR** |
| `SettingsController::rebase` | `@NoAdminRequired` + `@NoCSRFRequired` — any authed user can trigger a global `UPDATE` across every log table, rewriting `expires` for every log row instance-wide. Combined with an `updateSettings` call that drops the retention to `1ms` (also @NoAdminRequired — REQ-004) this lets a user effectively wipe every log on the instance. | **HIGH — privilege escalation / data destruction** |
| `LogsController::destroy` | individual-log deletion with no authz beyond "is logged in." Logs are evidence of activity — users can delete logs that incriminate them. | **MEDIUM — audit trail tampering** |
| `LogsController::statistics` | runs 5 separate `findAll(... level: X)` queries per call. With no caching, this is a soft DoS vector (admin dashboards usually invoke it on load). | low — performance |
| `LogsController::export` | iterates all matching logs into one PHP string for CSV emission. No pagination, no streaming. Large datasets OOM the worker. Returns the entire CSV in the JSON `content` field — base64'd through transport. | medium — soft DoS |
| `SettingsService::rebase` | the first two `UPDATE` branches (success-log retention at index 0, call-log retention at index 1) both target `openconnector_call_logs` writing to `callLogsUpdated` — they clobber each other's results in the response dict. The first key is overwritten by the second; observable bug. | medium — drift, log loss |
| `SettingsService::rebase` | uses `*PREFIX*<table>` interpolation inside SQL UPDATE strings. The table names are hard-coded constants so this is not user-controlled — but the `WHERE expires IS NULL OR expires = ''` is a tautology shape that touches every NULL/empty row each call. Repeated `rebase` invocations are O(N) over the log table; a malicious user can DoS the DB by spamming. | medium — soft DoS via rebase loop |
| `SettingsService::getSettings` | hard-codes `appVersion: '0.2.0'` — drift from `appinfo/info.xml`. Stale info-disclosure surface. | low — drift |
| `SettingsService::getStats` | every per-table query has its own `try/catch(\Exception)` that defaults the count to `0` and logs `debug`. Missing tables → zero counts. Operators can't tell "fresh install" from "table broken." | low — silent skip |
| `LogsController::export` `JSON_EXTRACT` reference | the SourcesController equivalent uses `JSON_EXTRACT(response, '$.responseTime')` which is MySQL-specific; would fail under Postgres. Pinned for portability follow-up. | low — DB portability |
| `LogsController::index` `filters` | `level` / `message` / `synchronizationId` / `dateFrom` / `dateTo` are forwarded into OR's `filters` array verbatim. OR is the gatekeeper for safe interpolation but the SearchService / SQL layer downstream is what would matter for injection. Documented as observation; OR contract is trusted. | informational |

## REQ → method map

| REQ | Methods |
|---|---|
| REQ-001 | `LogsController::index` + `::show` + `::destroy` |
| REQ-002 | `LogsController::statistics` + `::export` |
| REQ-003 | `SourcesController::logs` + `::test` |
| REQ-004 | `SettingsService::getSettings` + `::updateSettings` |
| REQ-005 | `SettingsService::getStats` + `::rebase` + private helpers `expiresExpression` + `columnExists`; `SettingsController::rebase` (the thin HTTP wrapper) is folded under the same REQ-005 |

## What the spec deliberately does NOT cover

- The OR log schemas themselves (`synchronization_log` / `call_log` / `job_log`)
  — that's data-model territory.
- The CallService that backs `SourcesController::test` — its outbound
  HTTP behaviour is covered by the `http-call-engine` cluster (PR #942).
- The CSV format — caller contract, not a behaviour worth pinning.

## Validation

After archive, `openspec validate logs-and-statistics --strict` MUST pass.
