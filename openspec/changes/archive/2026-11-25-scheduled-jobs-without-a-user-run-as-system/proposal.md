# Proposal: scheduled jobs without a user run as the system

## Why

A live run on Nextcloud 34 with OpenRegister main showed that `JobService` runs
a job without `userId` under whatever session user the cron process holds,
which on a clean cron is none. `SynchronizationService::persistSynchronization`
saves with RBAC, so every shipped synchronization job (the endoflife.date jobs,
the opt-in gathering jobs) failed with "User 'Anonymous' does not have
permission to 'update' objects in schema 'Synchronization'". It only worked
where another job had left an admin behind as the session user.

## Decision

Ruben, 25 Nov 2026: integriq jobs without `userId` run in OpenRegister's
`SystemOperationContext` (option (a) of the question raised by the live lane:
integriq's own bookkeeping and its target writes alike).

Applied to the cron loop only. The run and test endpoints are
`#[NoAdminRequired]`; elevating a run a person starts would let any signed-in
user act as the system, so a manual or event-delivered run keeps acting as its
caller.

## What changes

- `JobService::run()` (the cron loop) marks its runs as scheduled.
- A scheduled run of a job without `userId` calls its action through
  `SystemWrite::run()`. When OpenRegister cannot grant the context the run is
  logged as an ERROR, never run as nobody.
- A job with `userId` keeps acting as that user.
