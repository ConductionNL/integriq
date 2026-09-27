---
kind: code
depends_on: []
---

# Proposal: automation-job-calendar-schedules

## Summary

A job in integriq runs every N seconds and nothing else. An administrator who
needs a pull to run at seven on working days, or an export on the last day of
the month, has no way to say so. This change adds a calendar schedule to a
job: chosen weekdays, a day-of-month rule, times of day, a time zone and dates
to skip.

## Why

Matrix row `integriq:auto-working-days`, "Schedule a job on working days only,
or on the first or last day of the month." The matrix rates integriq `no` with
`built.state` `none`.

Demand:

- featureRequest, https://community.n8n.io/t/228418. The matrix note reads:
  "n8n community feature request 2025-11-29, advanced scheduler and cron
  enhancements."

Competitors rated `yes`:

- MuleSoft Anypoint (`mulesoft`), evidence
  https://docs.mulesoft.com/mule-runtime/latest/scheduler-concept.md: "The
  Scheduler supports Quartz Cron expressions" with "L: Last day of the week
  or month" and "W: Weekday", plus day-of-week ranges and a timeZone setting.
- Frank!Framework (`frank`), source read at v10.2.0:
  "core/src/main/java/org/frankframework/scheduler/AbstractJobDef.java:491
  setCronExpression is passed to Quartz cronSchedule", "whose cron syntax
  supports MON-FRI, the nearest working day (W) and the last day of the month
  (L)". No evidence URL is recorded for this cell; the evidence is the source
  path.

n8n is rated `partial`: it has a weekdays trigger but no last day of the month.

This change covers one row: `integriq:auto-working-days`. Jobs stay integriq's
under ADR-065; only flows move to OpenRegister's engine, so the calendar is
built on the job.

## What integriq already has

- A job stores `interval` in seconds (`lib/Settings/integriq_register.json:1291`)
  and an optional `scheduleAfter` start time.
- `lib/Service/JobService.php:511` sets the next run to `now + interval`
  after a success, and `:528` does the same after a failure.
- `lib/Service/JobService.php:745` (`run()`) runs every enabled job whose
  `nextRun` has passed or is empty.
- `lib/BackgroundJob/JobTask.php:63` ticks every 300 seconds.
- `lib/Service/JobIntervalCron.php:51` translates an interval into a
  five-field cron only for the flow generator, and
  `lib/Service/JobToFlowGenerator.php:364` refuses intervals a cron cannot
  express.

There is no weekday, month-day or time-of-day schedule anywhere.

## What this change builds

1. A `schedule` object on the job with `type` `interval` (the default, today's
   behaviour) or `calendar`, and for a calendar: weekdays, a day-of-month rule
   (`first`, `last`, `firstWorkingDay`, `lastWorkingDay` or a number), one or
   more times of day, an IANA time zone and a list of dates to skip.
2. A pure next-run calculator used by `JobService` after every run, and when a
   calendar job has no next run yet.
3. A schedule section in the job form, with a preview of the next five run
   times computed by the same calculator.
4. The flow generator translates a weekday and time calendar into a five-field
   cron, and refuses by name what a cron cannot say.

## Out of scope

- A national holiday feed. The administrator enters dates to skip; a feed of
  Dutch public holidays can fill that list later.
- Raw Quartz or cron expressions typed by hand.
- Schedules for flows. Those belong to OpenRegister's flow engine (ADR-065).
