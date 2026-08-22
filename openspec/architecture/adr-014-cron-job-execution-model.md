# ADR-014: Cron / job execution model (JobService + JobTask + LogCleanUpTask)

## Status
Accepted (capturing existing decision)

## Date
2026-05-20

## Context

Integriq exposes a `Job` entity that lets administrators schedule arbitrary
action classes to run on a recurring interval. No ADR has documented how jobs
are discovered, scheduled, executed, retried, or expired, nor how the
`LogCleanUpTask` retention sweep interacts with the log-retention windows
described in ADR-004.

Two Nextcloud `TimedJob` subclasses ship in `lib/Cron/`:

- `JobTask.php` — drives execution of user-defined `Job` records.
- `LogCleanUpTask.php` — sweeps expired log rows from four mapper tables.

Both are registered as Nextcloud background jobs, meaning Nextcloud's cron
runner (`occ background:cron` or a system cron pointing at `cron.php`) calls
their `run()` method on their configured interval. Nextcloud guarantees at-most-
once execution per `IJob` class per interval; it does NOT guarantee wall-clock
precision — the actual invocation time depends on how often Nextcloud's cron
runner itself fires (typically every 5 minutes on a properly configured server).

## Decision

**Job discovery — `JobTask` and `JobMapper::findRunnable()`**

`JobTask` extends `\OCP\BackgroundJob\TimedJob` with a 300-second (5-minute)
interval (`setInterval(300)` at `lib/Cron/JobTask.php:61`). On each Nextcloud
cron tick it calls `JobService::run()`, which calls
`JobMapper::findRunnable()`. `findRunnable()` issues a query against
`oc_openconnector_jobs` selecting rows where `is_enabled = true` AND
`next_run IS NOT NULL` AND `next_run <= now` (`lib/Db/JobMapper.php:343-349`).
"Due" therefore means: enabled, has an initial `nextRun` set, and that timestamp
is in the past.

**Job execution — `JobService::executeJob()`**

For each runnable `Job`, `JobService::executeJob()` (`lib/Service/JobService.php:269`):

1. Skips execution if `isEnabled = false` (unless `$forceRun = true`).
2. Skips (without logging) if `nextRun > now` (race guard; the mapper query
   should have filtered these out already).
3. Resolves the job action class from the DI container via
   `$this->containerInterface->get($job->getJobClass())` and calls
   `$action->run($arguments)`.
4. After a successful run, computes `nextRun` as
   `now + $job->getInterval() seconds` (`lib/Service/JobService.php:320`).
   If the action result contains a `nextRun` epoch timestamp, and that timestamp
   is later than the interval-based value, the rate-limit value wins
   (lines 323–331). The final `nextRun` is truncated to minute precision
   (seconds zeroed out) at line 335.
5. Sets `lastRun = now` and persists the updated `Job` via `JobMapper::update()`.
6. Creates a `JobLog` row via `JobLogMapper::createForJob()`, with `level =
   SUCCESS` or an error level from the action's result array. The `expires`
   field is computed from the maximum of `job.logRetention * 1000` and the
   global `JobService.successRetention` / `errorRetention` constants
   (`DEFAULT_SUCCESS_LOG_RETENTION = 3600000 ms`,
   `DEFAULT_ERROR_LOG_RETENTION = 2592000000 ms`).

**Single-run jobs**: if `Job::isSingleRun()` is true, the job is disabled
(`setIsEnabled(false)`) after the first non-force execution (line 313–315). It
remains in the table but `findRunnable()` will never return it again.

**Retry semantics**: there are no automatic retries at the engine level. A
failed job produces a `JobLog` with an error level but leaves `nextRun` set
to the next scheduled interval — the job WILL run again at the next due time.
Retry/backoff is the responsibility of the action class (which may return a
future `nextRun` epoch to defer its next run).

**LogCleanUpTask**

`LogCleanUpTask` extends `TimedJob` with a 60-second interval
(`setInterval(60)` at `lib/Cron/LogCleanUpTask.php:75`; a TODO comment
acknowledges this should be raised to hourly). It calls four mapper cleanup
methods in sequence:

- `CallLogMapper::clearLogs()` — deletes `CallLog` rows past their `expires`
  timestamp (per ADR-003 / ADR-004).
- `JobLogMapper::clearLogs()` — deletes `JobLog` rows past their `expires`
  timestamp.
- `SynchronizationContractLogMapper::clearLogs()` — deletes
  `SynchronizationContractLog` rows past their `expires` timestamp.
- `SynchronizationLogMapper::cleanupExpired()` — deletes `SynchronizationLog`
  rows past their `expires` timestamp.

The `expires` field on each log row is set at creation time using the
`calculateExpires()` method in the relevant service; `LogCleanUpTask` does NOT
recompute retention windows — it only acts on rows whose `expires` has passed.
This design means retention policy is enforced at write time (ADR-004) and sweep
time (this cron) independently.

## Consequences

- **Scheduling precision is bounded by Nextcloud's cron cadence**, not by the
  `Job.interval` value. A job with `interval = 60` seconds will NOT run every
  minute unless Nextcloud's cron runner fires every minute. On a default 5-minute
  cron, the effective minimum job interval is 5 minutes regardless of the
  configured value.
- **Long-running jobs risk Nextcloud cron timeout**. Nextcloud may kill
  background jobs that exceed its configured execution limit. Action classes
  should implement their own timeout guard for operations expected to exceed 2
  minutes.
- **`nextRun` truncation to minute precision** (line 335) means jobs scheduled
  less than 1 minute apart will appear to collide. This is intentional:
  sub-minute scheduling is not supported.
- **`LogCleanUpTask` runs more frequently than needed** (every 60 seconds; the
  TODO comment acknowledges this). After chain B migrates logs to OR archival
  annotations (ADR-004), the sweep frequency should be reduced and the task
  may be replaced by OR's built-in archival sweep.
- **No cross-job isolation**: `JobTask` processes all runnable jobs serially in
  a single `TimedJob` invocation. A hanging action will prevent subsequent jobs
  in the same batch from running until Nextcloud's cron timeout fires.
- **After chain B**: `findRunnable()` and the `JobMapper` will be rewritten as
  thin facades over OR `ObjectService` (local ADR-012). The scheduling logic in
  `JobService` is unaffected; only the storage backend changes.

## Evidence

- `lib/Cron/JobTask.php:34` — `class JobTask extends TimedJob`.
- `lib/Cron/JobTask.php:61` — `$this->setInterval(300)` (5-minute interval).
- `lib/Cron/JobTask.php:84-88` — `run()` delegates to `$this->jobService->run()`.
- `lib/Cron/LogCleanUpTask.php:37` — `class LogCleanUpTask extends TimedJob`.
- `lib/Cron/LogCleanUpTask.php:75` — `$this->setInterval(60)` (60-second
  interval; TODO: change to hour).
- `lib/Cron/LogCleanUpTask.php:97-108` — `run()` calls four mapper cleanup
  methods: `callLogMapper->clearLogs()`, `jobLogMapper->clearLogs()`,
  `syncContractLogMapper->clearLogs()`, `syncLogMapper->cleanupExpired()`.
- `lib/Db/JobMapper.php:337-350` — `findRunnable()`: SQL query selecting
  `is_enabled = true AND next_run IS NOT NULL AND next_run <= now`.
- `lib/Service/JobService.php:269-374` — `executeJob()`: full execution flow
  including guard checks, DI resolution, `nextRun` computation with rate-limit
  override (lines 320–336), single-run disabling (lines 313–315), and `JobLog`
  creation with expiry calculation (lines 343–357).
- `lib/Service/JobService.php:377-394` — `run()`: iterates `findRunnable()`
  and calls `executeJob()` per job.
- `lib/Service/JobService.php:60-61` — retention constants:
  `DEFAULT_SUCCESS_LOG_RETENTION = 3600000` ms (1 hour),
  `DEFAULT_ERROR_LOG_RETENTION = 2592000000` ms (30 days).
