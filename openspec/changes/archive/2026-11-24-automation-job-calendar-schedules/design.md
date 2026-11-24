# Design: automation-job-calendar-schedules

Kind: code. The calendar lives on the job, the calculation lives in one pure
class, and `JobService` asks that class for the next run instead of adding
`interval` seconds.

## Where it fits

- Schema: a fragment `lib/Settings/register.d/job-calendar-schedule.json`
  (ADR-037) adds a `schedule` object to the `job` schema declared at
  `lib/Settings/integriq_register.json:1206`, and bumps the job schema version
  the way `lib/Settings/register.d/job-form-fields.json` does. `interval`
  stays; it is what `schedule.type: interval` reads.
- Service: a new final class `lib/Service/JobCalendarSchedule.php` with
  `nextRunAfter(DateTimeImmutable $after, array $calendar): ?DateTimeImmutable`
  and `preview(array $calendar, int $count): array`. It has no dependencies,
  in the shape of `lib/Service/JobIntervalCron.php:44`.
- `lib/Service/JobService.php:511` and `:528` call it when
  `schedule.type` is `calendar`. `lib/Service/JobService.php:763`, the "skip
  jobs that are not yet due" check inside `run()`, gains one rule: a calendar
  job whose `nextRun` is empty, or whose stored `scheduleFingerprint` differs
  from its current schedule, gets its next run computed and saved and is not
  run on that tick.
- Controller and route: `jobs#schedulePreview` (POST
  `/api/jobs/schedule-preview`) on `lib/Controller/JobsController.php`, next
  to `jobs#run` at `appinfo/routes.php:393`. It takes a calendar and returns
  the next five run times. It needs the action `job.schedule-preview` in
  `lib/actions.seed.json`, default admin, so a preview does not open a
  read of the job list to everyone.
- Page: the Jobs index page at `src/manifest.json` (page id `Jobs`) keeps its
  form; `src/modals/v2/JobFormFields.vue` renders the schedule section and
  calls the preview route. The Jobs columns gain `nextRun`.
- Flow generator: `lib/Service/JobToFlowGenerator.php:364`
  (`scheduleRefusals()`) accepts a calendar with weekdays and whole times and
  no day-of-month rule or skipped dates, and emits `m h * * d` for it; the rest
  is refused by name, as intervals are today at `:376`.

## D1. A structured calendar, not a cron string

The calendar is fields an administrator picks: weekdays, a day-of-month rule,
times, a time zone, dates to skip. The alternative was a Quartz cron field,
which is what MuleSoft and Frank!Framework expose. Rejected for two reasons.
An administrator in a municipality does not read `0 0 7 ? * MON-FRI`, and a
typo in one is a job that silently never runs. And integriq ships no cron
library (`composer.json` requires none), so a Quartz dialect would be new
parsing code to own. The structured shape covers the row: working days, first
and last day of the month.

## D2. Working day means Monday to Friday minus skipped dates

`firstWorkingDay` and `lastWorkingDay` count Monday to Friday, skipping the
job's `skipDates`. Weekdays chosen on the calendar filter the same way. The
alternative was a built-in Dutch holiday table. Rejected for now: holidays
differ per sector and per year, and a wrong built-in table is worse than an
empty list the administrator can see. A holiday feed is named in the proposal
as out of scope.

## D3. The run loop computes the first run time, not the save

A job is created and edited through OpenRegister's objects endpoint, not an
integriq controller, so integriq has no hook at the moment of save. `run()`
already visits every enabled job each tick, so it computes the first slot for
a calendar job whose `nextRun` is empty and does not run it. The alternative
was a listener on OpenRegister's object-saved event. Rejected: it adds a
second place that writes `nextRun`, and the run loop must handle an empty
`nextRun` anyway, because a job imported through a configuration arrives
without one. The fingerprint (a hash of the `schedule` object) catches an edit
to the calendar of a job that already has a next run.

## D4. After a failure the next slot is the next calendar slot

Today a failure advances by `interval` (`JobService.php:528`) so it does not
block the next tick. A calendar job advances to its next calendar slot. The
alternative, retrying on the next tick, turns a job meant for seven o'clock
into one that runs every five minutes all day against a partner that is down.

## Declarative versus imperative

The next run time is a calculation over the calendar and the clock, done by
`JobService` on the cron worker; no `x-openregister-*` annotation computes a
future date. The schema change itself is declarative: `schedule` and its enums
are declared in the fragment and validated by OpenRegister on save.

## Seed data

The fragment seeds nothing new into existing jobs: `schedule` is absent on the
three seeded jobs (`example-cron-sync`, `example-cleanup`,
`example-health-check`, `lib/Settings/integriq_seed_data.json:245`), and an
absent `schedule` means `interval`, today's behaviour. One disabled example
job, `example-weekday-morning`, is added with a calendar of Monday to Friday at
07:00 Europe/Amsterdam so the form has something to show.

## Risks

- `JobTask` ticks every 300 seconds (`lib/BackgroundJob/JobTask.php:63`), so a
  07:00 run starts between 07:00 and 07:05. The form says so.
- A time that does not exist on a daylight saving day (02:30 on the last
  Sunday of March in Europe/Amsterdam) runs at the next valid minute. A time
  that exists twice runs once. The calculator's tests cover both.
- `dayOfMonth: 31` in a 30-day month: the rule skips that month. The form
  offers `last` next to the number so nobody needs 31 for "end of month".
