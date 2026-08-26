---
status: implemented
retrofit: true
---

# Job Management

## Purpose

Integriq provides a Jobs section in its SPA where administrators can
configure scheduled background jobs — cron-like tasks that invoke a PHP
`jobClass` at a configured `interval`. Jobs can be enabled/disabled, run on
demand, and tested via dry runs. Their execution history is tracked in
`job_log` records. This spec covers the observable browser UI behaviour and
the backend job-execution internals (covered by PHPUnit/Newman). It is a
retrofit spec.
## Requirements

### REQ-JOB-UI-001: Job Management UI

Integriq MUST provide a Jobs section in its SPA where administrators can
browse, create, edit, enable/disable, and manually trigger background jobs.

#### Scenario: jobs list page mounts and shows content

- GIVEN an authenticated admin visits the integriq app
- WHEN they navigate to the Jobs section via the sidebar nav or direct URL `/apps/integriq/jobs`
- THEN the Jobs index page renders inside the main content area with content visible

#### Scenario: add job button opens the creation modal

- GIVEN the Jobs index page is loaded
- WHEN the user clicks the "Add Item" button
- THEN a modal or dialog opens containing the job creation form

#### Scenario: job logs sub-page mounts

- GIVEN an authenticated admin
- WHEN they navigate to the Job logs page at `/apps/integriq/jobs/logs`
- THEN the page mounts and renders the main content area

### REQ-JOB-001: Job execution runtime

The system SHALL execute registered jobs via the Nextcloud background-job
framework, resolving the `jobClass`, passing `arguments` (decoded from JSON),
and persisting a `job_log` on every run. A job disabled via `isEnabled: false`
SHALL be skipped by the scheduler. The run-on-demand endpoint `POST
/api/jobs/{id}/run` SHALL execute the job synchronously and return the log
entry.

@e2e exclude backend job-execution runtime — covered by PHPUnit/Newman, not browser UI

#### Scenario: disabled job is skipped by the scheduler

- **GIVEN** a job with `isEnabled: false`
- **WHEN** the scheduler tick runs
- **THEN** the job is not executed and no `job_log` is written

#### Scenario: scheduled job runs and persists a job_log

- **GIVEN** a job with a valid `jobClass` and `interval`
- **WHEN** its next execution time is reached
- **THEN** the job runs and a `job_log` record is persisted with start time,
  duration, and status

#### Scenario: run-on-demand returns the resulting log

- **GIVEN** `POST /api/jobs/{id}/run` is called by an admin
- **WHEN** the job completes
- **THEN** the response includes the resulting `job_log` entry

### REQ-JOB-002: Job dry-run (test mode)

The system SHALL provide a `POST /api/jobs/{id}/test` endpoint that executes
the job in dry-run mode (no side-effects committed) and returns a preview log.

@e2e exclude backend job dry-run — covered by PHPUnit/Newman, not browser UI

#### Scenario: dry-run writes no persistent changes

- **GIVEN** `POST /api/jobs/{id}/test` is called
- **WHEN** the job runs in test mode
- **THEN** no persistent changes are written to the data store and a preview
  result is returned

### Requirement: `FlowAction` runs a flow as a scheduled job (REQ-JOB-003)

The system MUST provide `OCA\Integriq\Action\FlowAction`
implementing the same duck-typed `run(array $arguments): array` contract
as the existing `SynchronizationAction`/`PingAction` (no new Action
interface is introduced — `jobClass` resolution via the DI container is
unchanged). A `job` OR object configured with `jobClass:
'OCA\Integriq\Action\FlowAction'` and `arguments: { flowId: '<uuid>'
}` MUST, when executed by `JobService::executeJob()` (on its normal cron
cadence, on-demand `run`, or forced `test`), resolve the referenced
`flow` OR object and call `FlowRunnerService::run($flow, triggerSource:
'cron')`. `FlowAction::run()` MUST return the same
`{ level, message, stackTrace, nextRun? }` shape `SynchronizationAction::run()`
already returns, deriving `level` from the resulting `flow_run.status`
(`SUCCESS` for `completed`, `WARNING` for `dead_letter`, `ERROR` for
`failed`/`stopped`-due-to-error) so `JobService::executeJob()`'s existing
`job_log` persistence (REQ-001 of this spec) requires no changes to
handle flow-backed jobs.

@e2e exclude backend job action dispatch — covered by PHPUnit/Newman, not browser UI

#### Scenario: a job configured with FlowAction runs the referenced flow

- **GIVEN** an enabled `job` OR object with `jobClass:
  'OCA\Integriq\Action\FlowAction'`, `arguments: { flowId: '<uuid>'
  }`, and `nextRun` in the past
- **WHEN** `JobService::run()` sweeps due jobs
- **THEN** `FlowAction::run({ flowId: '<uuid>' })` is invoked
- **AND** `FlowRunnerService::run()` executes the referenced flow with
  `triggerSource: 'cron'`
- **AND** a `job_log` entry is written with `level` derived from the
  flow run's resulting status

#### Scenario: forced test run executes the flow regardless of schedule

- **GIVEN** a `job` OR object configured with `FlowAction` and
  `isEnabled: false`
- **WHEN** `JobsController::test(id)` is called (always `forceRun: true`,
  per this spec's REQ-002)
- **THEN** `FlowAction::run()` is invoked and the flow executes anyway,
  matching the existing `forceRun` semantics for every other Action type

#### Scenario: a failing flow run is reflected as an ERROR-level job log

- **GIVEN** a `FlowAction`-backed job whose referenced flow run ends with
  `flow_run.status: failed` (an `onError: stop` step threw, per
  `flow-orchestration` REQ-006)
- **WHEN** the job executes
- **THEN** `FlowAction::run()` returns `level: 'ERROR'`
- **AND** the resulting `job_log` entry records `level: ERROR` with the
  flow's failure detail in `stackTrace`/`message`

#### Notes

- This requirement is purely additive: it registers one new concrete
  `Action` class following the existing `SynchronizationAction`/
  `PingAction` pattern. No change to `JobService::executeJob()`,
  `JobTask`, or the `job` schema is introduced — `jobClass` already
  accepts any DI-constructible class exposing `run(array): array`.
- See `job-scheduling` REQ-003/REQ-004 for the underlying cron-dispatch
  and retention mechanics `FlowAction`-backed jobs inherit unchanged
  (5-minute `JobTask` cadence, per-job/global log retention,
  session-scoped `userId` execution if configured on the job).

