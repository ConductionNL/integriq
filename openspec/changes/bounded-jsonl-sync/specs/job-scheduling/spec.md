# job-scheduling (delta)

@e2e exclude Scheduler timing with no browser surface; covered by PHPUnit (JobServiceTest, SynchronizationActionTest).

## ADDED Requirements

### Requirement: A job with work left runs again on the next tick

When a job's action returns `resumeSoon: true`, the scheduler SHALL set the
job's `nextRun` to now, so the next cron tick runs it again, instead of
advancing it by the job's interval. `SynchronizationAction` SHALL return
`resumeSoon: true` when the synchronization run's result carries `resume`
(a batched source with batches left; a batch whose download failed does not
carry it). A job that does not return the flag SHALL keep its interval.

#### Scenario: a batched synchronization continues on the next tick

- **GIVEN** a synchronization job with a weekly interval whose run read one
  batch of a larger JSONL file
- **WHEN** the scheduler records the run
- **THEN** the job's `nextRun` is no later than the next minute

#### Scenario: a finished run keeps the interval

- **GIVEN** the same job whose run read the last batch
- **WHEN** the scheduler records the run
- **THEN** the job's `nextRun` is the run time plus its interval
