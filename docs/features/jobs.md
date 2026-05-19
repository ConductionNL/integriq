# Jobs and Scheduling

## Overview

**Jobs** enable scheduled execution of synchronizations and other tasks within OpenConnector. Each job is configured with a cron expression that determines when it runs. The Nextcloud background job system (`IJobList`) drives execution. All job runs are logged with their outcome, duration, and any errors.

## Job Configuration

| Field | Description |
|-------|-------------|
| `name` | Human-readable job name |
| `slug` | URL-friendly unique identifier |
| `synchronizationId` | Synchronization to run (required for sync jobs) |
| `schedule` | Cron expression (e.g. `0 * * * *` for hourly) |
| `isEnabled` | Whether the job is active |
| `force` | If `true`, skip change detection on each run |
| `maxRetries` | Number of retry attempts on failure |
| `nextRun` | Timestamp of the next scheduled execution |
| `lastRun` | Timestamp of the last execution |

## Cron Expressions

Jobs use standard 5-field cron syntax:

```
┌───────────── minute (0–59)
│ ┌─────────── hour (0–23)
│ │ ┌───────── day of month (1–31)
│ │ │ ┌─────── month (1–12)
│ │ │ │ ┌───── day of week (0–7, 0 and 7 = Sunday)
│ │ │ │ │
* * * * *
```

Common schedules:

| Expression | Frequency |
|-----------|-----------|
| `* * * * *` | Every minute |
| `0 * * * *` | Every hour |
| `0 0 * * *` | Daily at midnight |
| `0 6 * * 1` | Weekly on Monday at 06:00 |
| `*/15 * * * *` | Every 15 minutes |

## Job Execution

When a job fires, `JobService` resolves the associated synchronization and delegates to `SynchronizationService.synchronize()`. The `force` flag on the job is passed through, overriding change detection if set.

## Job Logging

Every job execution produces a log entry with:

- Start and end timestamps
- Execution duration
- Result (`success`, `error`, `skipped`)
- Number of objects processed
- Error message and stack trace (on failure)

Logs are accessible in the OpenConnector UI under the Logs section and via `GET /api/logs?jobId={id}`.

## Log Cleanup

OpenConnector automatically purges old job log entries to prevent unbounded storage growth. Retention periods are configurable:

| Setting | Default | Description |
|---------|---------|-------------|
| Success log retention | 30 days | Keep successful run logs |
| Error log retention | 90 days | Keep failed run logs |

Cleanup runs as part of the background job cycle.

## Implementation

- `lib/Service/JobService.php` — Job execution and log writing
- `lib/Controller/JobsController.php` — REST CRUD API
- `lib/Db/Job.php` — Entity
- `lib/Db/JobMapper.php` — Database mapper
- `lib/Db/JobLog.php` — Log entity
- `lib/Db/JobLogMapper.php` — Log mapper
