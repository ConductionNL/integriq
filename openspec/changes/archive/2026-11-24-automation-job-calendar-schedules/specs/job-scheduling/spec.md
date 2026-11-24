# job-scheduling Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- automation-job-calendar-schedules

## Purpose

An administrator schedules a job on chosen weekdays, on a day-of-month rule
such as the last working day, at set times in a set time zone, and skips
listed dates. Matrix row `integriq:auto-working-days`.

## ADDED Requirements

### Requirement: A job can carry a calendar schedule instead of an interval (REQ-JCAL-001)

The `job` schema MUST accept a `schedule` object whose `type` is `interval` or
`calendar`. A calendar MUST carry weekdays, an optional day-of-month rule
(`first`, `last`, `firstWorkingDay`, `lastWorkingDay` or a day number from 1
to 31), one or more times of day, an IANA time zone and an optional list of
dates to skip. A job without `schedule` MUST behave as `type: interval`.

#### Scenario: a pull runs at seven on working days
- GIVEN an administrator on the Jobs page editing a synchronization job
- WHEN they choose "On a calendar", tick Monday to Friday, set 07:00 and time zone Europe/Amsterdam, and save
- THEN the job's next run is the next weekday at 07:00 Amsterdam time
- e2e: tests/e2e/job-calendar-schedule.spec.ts

#### Scenario: an existing interval job is unchanged
- GIVEN a job saved before this change with `interval` 3600 and no `schedule`
- WHEN it runs
- THEN its next run is one hour later, as before
- @e2e exclude a regression on the run loop; covered by PHPUnit on JobService

### Requirement: The next run follows the calendar after success and after failure (REQ-JCAL-002)

After a calendar job runs, `JobService` MUST set `nextRun` to the first
calendar slot after the current time, whether the run succeeded or failed. A
calendar job with no `nextRun`, or whose schedule changed since `nextRun` was
computed, MUST get its next run computed and MUST NOT run on that tick.

#### Scenario: the last working day of the month
- GIVEN a job with `dayOfMonth: lastWorkingDay` at 18:00 and 31 October 2026 falling on a Saturday
- WHEN the calculator computes the next run from 1 October 2026
- THEN the result is Friday 30 October 2026 at 18:00
- @e2e exclude a date calculation; covered by PHPUnit on JobCalendarSchedule

#### Scenario: a skipped date is skipped
- GIVEN a working-day job at 07:00 with 25 December 2026 in its skipped dates
- WHEN the calculator computes the next run from 24 December 2026 at 08:00
- THEN the result is Monday 28 December 2026 at 07:00
- @e2e exclude a date calculation; covered by PHPUnit on JobCalendarSchedule

#### Scenario: a failure waits for the next slot
- GIVEN a calendar job at 07:00 on working days whose run fails on Tuesday
- WHEN `JobService` advances the timeline
- THEN the next run is Wednesday at 07:00, not the next tick
- @e2e exclude a failure path; covered by PHPUnit on JobService

#### Scenario: an edited calendar is picked up
- GIVEN a calendar job whose next run is Monday at 07:00
- WHEN an administrator changes its time to 09:00
- THEN on the next tick the next run becomes Monday at 09:00 and the job does not run early
- @e2e exclude a cron tick; covered by PHPUnit on JobService::run

### Requirement: The job form previews the next five run times (REQ-JCAL-003)

The job form MUST show the next five run times of a calendar as the
administrator edits it, computed by the same calculator the run loop uses,
through `POST /api/jobs/schedule-preview`. The route MUST require the action
`job.schedule-preview`.

#### Scenario: the preview answers "which days"
- GIVEN an administrator editing a calendar with `dayOfMonth: first` at 06:00
- WHEN they change the rule to `firstWorkingDay`
- THEN the preview lists the next five first working days at 06:00
- e2e: tests/e2e/job-calendar-schedule.spec.ts

### Requirement: A calendar job migrates to a flow only when a cron can say it (REQ-JCAL-004)

The job to flow generator MUST translate a calendar with weekdays and whole
times, and no day-of-month rule and no skipped dates, into a five-field cron
per time of day. It MUST refuse any other calendar with a reason that names the
field a cron cannot express, and MUST NOT round or drop it.

#### Scenario: a weekday calendar becomes a cron
- GIVEN a job at 07:00 on Monday to Friday
- WHEN the generator runs
- THEN the generated flow's cron is `0 7 * * 1-5`
- @e2e exclude a document generator; covered by PHPUnit on JobToFlowGenerator

#### Scenario: the last day of the month is refused
- GIVEN a job with `dayOfMonth: last`
- WHEN the generator runs
- THEN it refuses with a reason naming `dayOfMonth`, and no flow document is produced
- @e2e exclude a refusal; covered by PHPUnit on JobToFlowGenerator
