# http-call-engine Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- sources-outbound-rate-limit-pacing

## Purpose

Calls to a source are spaced to stay under its rate limit instead of being refused when the budget runs out. Row `integriq:src-ratelimit-out`.

## ADDED Requirements

### Requirement: Calls to a source are spaced to stay under its limit (REQ-RLPC-001)

Integriq MUST pace calls to a source by a configured rate, held in a budget shared by all workers, and by the remaining calls and reset time the source announces, using whichever gives the larger gap. It MUST record the waiting time on each call log and MUST show the pace, the remaining budget and the waiting time on the source page.

#### Scenario: a nightly synchronization no longer fails halfway
- GIVEN a source that allows 100 calls a minute and a synchronization that needs 5,000 calls
- WHEN the synchronization runs
- THEN the calls are spread over about fifty minutes, none is refused by integriq, and the source page shows the waiting time
- @e2e exclude timing behaviour; covered by PHPUnit with a fake clock

### Requirement: A live call waits briefly, background work waits for the window (REQ-RLPC-002)

Integriq MUST let a live gateway call wait at most a configured short time for its turn and otherwise answer 429 with `Retry-After`, without sending the call. Synchronizations, jobs and flows MUST wait as long as the window requires up to a configured maximum, after which a synchronization MUST stop and resume from its cursor on its next run.

#### Scenario: a busy gateway call is told when to come back
- GIVEN a paced source whose next turn is five seconds away and a live wait limit of two seconds
- WHEN a consumer calls an endpoint proxied to that source
- THEN the answer is 429 with `Retry-After: 5`, and the source received nothing
- @e2e exclude covered by PHPUnit and Newman
