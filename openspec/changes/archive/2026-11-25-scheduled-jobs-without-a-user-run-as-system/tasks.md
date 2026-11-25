# Tasks: scheduled-jobs-without-a-user-run-as-system

- [x] 1.1 `JobRun` carries `scheduled`; `JobService::executeJob()` takes it and `run()` passes `scheduled: true`
- [x] 1.2 `JobService::callJobAction()` runs a scheduled action without `userId` through `SystemWrite::run()`
- [x] 1.3 PHPUnit: scheduled without user acts as the system; with user acts as that user; manual and event runs act as the caller; a throwing scheduled action is logged and leaves no context; the cron loop passes the flag
- [x] 1.4 Requirement REQ-JSYS-001 synced into `openspec/specs/job-scheduling/spec.md`
