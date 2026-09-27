# Tasks: automation-job-calendar-schedules

Kind: code. Matrix row `integriq:auto-working-days`.

### Task 1: The schedule object on the job schema
- **spec_ref**: openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-a-job-can-carry-a-calendar-schedule-instead-of-an-interval-req-jcal-001
- **files**: `lib/Settings/register.d/job-calendar-schedule.json`, `lib/Settings/integriq_seed_data.json`
- **acceptance_criteria**:
  - GIVEN the register is imported WHEN a job is saved with `schedule.type: calendar` and a weekday list THEN OpenRegister accepts it
  - GIVEN a calendar with an unknown `dayOfMonth` value WHEN it is saved THEN OpenRegister refuses it
  - GIVEN a fresh install WHEN the seed runs THEN `example-weekday-morning` exists and is disabled
- [ ] Implement
- [ ] Test (`node tests/validate-register.js` and a PHPUnit import test)

### Task 2: The calendar calculator
- **spec_ref**: openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-the-next-run-follows-the-calendar-after-success-and-after-failure-req-jcal-002
- **files**: `lib/Service/JobCalendarSchedule.php`, `tests/Unit/Service/JobCalendarScheduleTest.php`
- **acceptance_criteria**:
  - GIVEN `lastWorkingDay` at 18:00 WHEN computed from 1 October 2026 THEN the answer is 30 October 2026 18:00
  - GIVEN 25 December 2026 skipped WHEN computed from 24 December 2026 08:00 THEN the answer is 28 December 2026 07:00
  - GIVEN 02:30 on the last Sunday of March in Europe/Amsterdam WHEN computed THEN the answer is the next valid minute
- [ ] Implement
- [ ] Test (PHPUnit with fixed clocks, including both daylight saving transitions)

### Task 3: The run loop uses the calendar
- **spec_ref**: openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-the-next-run-follows-the-calendar-after-success-and-after-failure-req-jcal-002
- **files**: `lib/Service/JobService.php`, `tests/Unit/Service/JobServiceTest.php`
- **acceptance_criteria**:
  - GIVEN a calendar job that fails WHEN the timeline advances THEN `nextRun` is the next calendar slot
  - GIVEN a calendar job with an empty `nextRun` WHEN `run()` visits it THEN `nextRun` is saved and the job is not executed
  - GIVEN an interval job without `schedule` WHEN it runs THEN `nextRun` is `now + interval` as before
- [ ] Implement
- [ ] Test (PHPUnit on `executeJob()` and `run()` with a mocked ObjectService)

### Task 4: Schedule preview route and the job form
- **spec_ref**: openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-the-job-form-previews-the-next-five-run-times-req-jcal-003
- **files**: `lib/Controller/JobsController.php`, `appinfo/routes.php`, `lib/actions.seed.json`, `src/modals/v2/JobFormFields.vue`, `src/manifest.json`
- **acceptance_criteria**:
  - GIVEN an administrator editing a job WHEN they pick "On a calendar" THEN weekday, day-of-month, time, time zone and skipped date fields appear with a preview of five run times
  - GIVEN a user without `job.schedule-preview` WHEN they call the route THEN the answer is 403
- [ ] Implement
- [ ] Test (Playwright `tests/e2e/job-calendar-schedule.spec.ts`; PHPUnit on the 403)

### Task 5: Flow generator translation and refusal
- **spec_ref**: openspec/changes/automation-job-calendar-schedules/specs/job-scheduling/spec.md#requirement-a-calendar-job-migrates-to-a-flow-only-when-a-cron-can-say-it-req-jcal-004
- **files**: `lib/Service/JobToFlowGenerator.php`, `tests/Unit/Service/JobToFlowGeneratorTest.php`
- **acceptance_criteria**:
  - GIVEN Monday to Friday at 07:00 WHEN the generator runs THEN the cron is `0 7 * * 1-5`
  - GIVEN `dayOfMonth: last` WHEN the generator runs THEN it refuses and names `dayOfMonth`
- [ ] Implement
- [ ] Test (PHPUnit)

## Verification

- `openspec validate automation-job-calendar-schedules --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- `tests/e2e/job-calendar-schedule.spec.ts` green against a local instance
