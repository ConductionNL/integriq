# synchronization-engine Specification

## ADDED Requirements

### Requirement: A synchronization runs on its job's schedule (REQ-SCB-012)

A job whose `jobClass` is `SynchronizationAction` MUST run the synchronization named by its `synchronizationId` argument each time the job is due. The job's interval decides when it is due. A disabled job MUST NOT run unless the run is forced. A job without a `synchronizationId` MUST fail with a job log that says so, and run nothing.

#### Scenario: a nightly job copies new records
- GIVEN a synchronization from a source into a register schema
- AND an enabled job with `jobClass` `SynchronizationAction` and that `synchronizationId`
- WHEN the cron pass reaches the job's next run
- THEN the synchronization runs and its new records are written to the register
- AND the job log records the run
- @e2e exclude cron-driven; covered by PHPUnit on JobService and SynchronizationAction

#### Scenario: a disabled job leaves the source alone
- GIVEN the same job with `isEnabled` false
- WHEN the cron pass runs
- THEN the synchronization does not run

#### Scenario: a job without a synchronization says so
- GIVEN a `SynchronizationAction` job without a `synchronizationId` argument
- WHEN the job runs
- THEN the job log reads "No synchronization ID provided" and nothing is synchronised
