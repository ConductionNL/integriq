# Design — Retrofit job-scheduling

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

Integriq exposes a job-scheduling subsystem with three layers:

1. **`JobService`** (`lib/Service/JobService.php`) — the business-logic core.
   `scheduleJob` registers an OR `job` object with NC's `IJobList`,
   `executeJob` runs one job and persists a `job_log` entry, `run()` is the
   batch dispatcher that walks all enabled, due jobs.
2. **`JobTask`** (`lib/Cron/JobTask.php`) — a NC `TimedJob` that runs every
   5 minutes and delegates to `JobService::run()`. Wired by `JobService::scheduleJob`.
3. **`LogCleanUpTask`** (`lib/Cron/LogCleanUpTask.php`) — a NC `TimedJob`
   that runs every minute (sic, see notes) and deletes expired log objects
   across four log schemas.
4. **`JobsController`** (`lib/Controller/JobsController.php`) — HTTP entry
   point for `GET /jobs/logs`, `POST /jobs/{id}/run`, `POST /jobs/{id}/test`.

## Observed-but-suspicious behaviour (flagged, not fixed)

| Site | Issue | Severity |
|---|---|---|
| `JobsController::run` / `::test` | both methods carry `@NoAdminRequired` + `@NoCSRFRequired` and accept arbitrary job UUIDs. No per-object authorization guard. Any authenticated NC user can trigger arbitrary background job execution, including jobs configured with `userId` to run as a privileged user. Triggers `hydra-gate-no-admin-idor` (ADR-005 Rule 3 / OWASP A01:2021). | **HIGH — IDOR / privilege escalation** |
| `JobsController::logs` | also `@NoAdminRequired` + `@NoCSRFRequired`. Job logs may contain stack traces and result messages that leak information about internal services and configurations. No `userId` filter on the OR query — every authed user sees every job's logs. | **MEDIUM — info disclosure** |
| `JobTask::run` | ignores its `$argument` parameter (which can carry a specific `jobId` from the queue) and ALWAYS calls `jobService->run()` (process all due jobs). This means: every queued JobTask instance triggers a full sweep, regardless of which specific job was scheduled. Highly redundant; turns an O(N) queue into O(N²) sweeps. | medium — performance / correctness drift |
| `LogCleanUpTask` | `setInterval(60)` (every minute, with a `@todo change to hour`) plus `setAllowParallelRuns(true)` and the `setTimeSensitivity(IJob::TIME_INSENSITIVE)` flag — these combine into "run every minute, allow concurrent sweeps." On a busy instance with slow OR queries, multiple cleanup sweeps stack. | medium — soft DoS via cron-storm |
| `LogCleanUpTask::cleanupSchema` | `catch (\Exception $e) {}` per object — single-object delete failures are swallowed with no logging. A persistent malformed log object will be retried every minute forever with no operator visibility. | medium — silent failure mode |
| `JobService::scheduleJob` | the disable-path (`isEnabled === false` OR `jobListId !== null`) clears `jobListId` but the comment says `// @todo fix this (call to protected method) // $this->jobList->removeById(...)` — **the actual NC job entry is never removed.** A disabled job stays scheduled in NC's `oc_jobs` table even though the integriq-side record claims it's disabled. The next `JobTask` sweep will still run it. | **HIGH — disable doesn't actually disable** |
| `JobService::scheduleJob` | the "already scheduled, don't update" guard prevents re-scheduling — there is NO way through this service to change a job's schedule once it's set. Comment says `// @todo we should`. | medium — incomplete UX |
| `JobService::executeJob` | sets a user session via `$userSession->setUser($user)` BUT never resets it on completion. Subsequent code on the same PHP worker process inherits the job's user. In cron context this is fine (worker exits); in HTTP context (via JobsController::run from a browser) it's a session-clobber. | **HIGH — session-clobber via HTTP run** |
| `JobService::executeJob` | the rate-limit branch uses `DateTime::createFromFormat('U', $result['nextRun'], $nextRun->getTimezone())` — `'U'` is a Unix timestamp; timezone is ignored for `'U'` and `$result['nextRun']` from the action is trusted to be a valid integer string. Malformed input from the action makes the next-run calculation NaN; PHP's `DateTime->modify` then no-ops silently. | low — input-trust |
| `JobService::executeJob` | retention math: `$logRetention * 1000` happens at every save; the per-job `logRetention` field is in seconds, the JobService internal in milliseconds. Consistent within file but easy to misuse if another consumer reads `logRetention` directly. | low — unit hazard |
| `JobService::getJobListId` | "get the most recent job of this class" via `ORDER BY id DESC LIMIT 1`. If two schedules of the same `JobTask` class race, both win the same id — they share state. The comment cites NC's API limitation; the pattern is best-effort. | low — known NC API gap |
| `JobService::run::findAll filter` | `'isEnabled' => true` is filter-by-equality, but the OR object stores arbitrary JSON — if the field is missing or null on legacy records, they are silently excluded. | low — silent-skip |
| `JobsController::logs` `searchConditions` / `searchParams` arrays | built and then NEVER USED (no `where`/`whereRaw` call before the OR query). Dead code: the date_from / date_to / status / slow_executions filters from the docstring don't actually work. | medium — silently broken filter |

## REQ → method map

| REQ | Methods |
|---|---|
| REQ-001 | `JobsController::logs` |
| REQ-002 | `JobsController::run` + `JobsController::test` (both invoke `executeJob` with different `forceRun` semantics) |
| REQ-003 | `JobTask::run` |
| REQ-004 | `JobService::scheduleJob` + `JobService::executeJob` + `JobService::run` + private helpers `saveJobLog`, `truncateMessage`, `calculateExpires`, `getJobListId` |
| REQ-005 | `LogCleanUpTask::run` + `LogCleanUpTask::cleanupSchema` |

REQ-004 deliberately folds the JobService methods together — they are a single
unit (scheduleJob and run both call executeJob, and the four private helpers
are only reachable from executeJob/saveJobLog).

## What the spec deliberately does NOT cover

- The OR `job` and `job_log` schemas — that's data-model territory.
- The actual job-action class implementations (`jobClass`) — each is its own
  capability (e.g. sync-related action classes belong to the
  synchronization-engine cluster, deferred).
- `JobsController::page` — the index template route, covered by
  `frontend-vue-spa`.

## Validation

After archive, `openspec validate job-scheduling --strict` MUST pass.
