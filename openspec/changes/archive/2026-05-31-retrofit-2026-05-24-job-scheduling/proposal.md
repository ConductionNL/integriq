# Retrofit — job-scheduling

Describes observed behavior of 13 methods under `job-scheduling` as 5 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Controller/JobsController.php::logs()`
- `lib/Controller/JobsController.php::run()`
- `lib/Controller/JobsController.php::test()`
- `lib/Cron/JobTask.php::run()`
- `lib/Cron/LogCleanUpTask.php::run()`
- `lib/Cron/LogCleanUpTask.php::cleanupSchema()`
- `lib/Service/JobService.php::scheduleJob()`
- `lib/Service/JobService.php::executeJob()`
- `lib/Service/JobService.php::getJobListId()`
- `lib/Service/JobService.php::saveJobLog()`
- `lib/Service/JobService.php::truncateMessage()`
- `lib/Service/JobService.php::calculateExpires()`
- `lib/Service/JobService.php::run()`

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section + design.md surface observed-but-suspicious behaviour
- Cap REQs at 5: fold private helpers (`saveJobLog`, `truncateMessage`, `calculateExpires`, `getJobListId`) under the REQs of their callers

## Major security finding flagged

- **HIGH (IDOR — OWASP A01:2021 / ADR-005 Rule 3)** — `JobsController::run()` and `JobsController::test()` carry `@NoAdminRequired` / `@NoCSRFRequired` and accept any job UUID. Any authenticated NC user can trigger arbitrary background job execution (including jobs configured to run as a privileged user). The controller has no per-object authorization guard. Triggers `hydra-gate-no-admin-idor`.

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
