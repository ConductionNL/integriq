# job-management Specification (delta)

## ADDED Requirements

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
