# Delta: job-scheduling

## ADDED Requirements

### Requirement: A scheduled job without a user acts as the system (REQ-JSYS-001)

A job run started by the cron loop (`JobService::run()`) for a job without
`userId` MUST call its action inside OpenRegister's `SystemOperationContext`
(through `SystemWrite::run()`), for integriq's own bookkeeping and its target
writes alike. A job with `userId` MUST keep acting as that user. A run started
by a person (the run and test endpoints) or by an event delivery MUST NOT act as
the system, whatever the job carries. When OpenRegister cannot grant the
context, the run MUST be logged as an ERROR rather than run without it.

#### Scenario: a shipped synchronization job runs on a clean cron
- GIVEN an enabled, due job without `userId` and a cron process with no session user
- WHEN the cron loop runs it
- THEN its action runs inside the system-operation context and its writes are not refused as Anonymous
- AND the context has ended when the action returns or throws

#### Scenario: a job with a user keeps that user
- GIVEN an enabled, due job with `userId: alice`
- WHEN the cron loop runs it
- THEN its action runs as alice, not as the system

#### Scenario: a person cannot run a job as the system
- GIVEN a job without `userId`
- WHEN a signed-in user runs it through the run or test endpoint
- THEN its action runs as that user, not as the system
