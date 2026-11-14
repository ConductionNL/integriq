# tenant-scoping Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- integriq-tenant-scoping

## Purpose

One installation can host several organisations, and integriq keeps their API setups apart: a user only ever sees and acts on their own organisation's sources, endpoints, mappings, jobs, synchronisations and logs. Row `integriq:acc-tenants`.

## ADDED Requirements

### Requirement: A user only reaches their own organisation's integriq objects (REQ-TEN-001)

Integriq MUST leave OpenRegister's organisation filter on for every read, list, run, change or delete a user requests on a source, endpoint, mapping, job, synchronisation, synchronisation contract, dead letter, event or log. An object of another organisation MUST answer as not found and MUST NOT appear in any list, count or export.

#### Scenario: a user of organisation A cannot read organisation B's source
- GIVEN a source owned by organisation B
- WHEN a user whose active organisation is A requests that source, tests it, or trips or resets its circuit breaker
- THEN integriq answers 404 and does not call the source
- @e2e exclude covered by PHPUnit per controller

#### Scenario: a user of organisation A cannot run or change organisation B's synchronisation
- GIVEN a synchronisation and a job owned by organisation B
- WHEN a user of organisation A reruns the synchronisation, resets its cursor, deletes its logs, replays its dead letters, or runs the job
- THEN integriq answers 404 and nothing runs or changes
- @e2e exclude covered by PHPUnit per controller

#### Scenario: logs and events stay within the organisation
- GIVEN call logs, synchronisation logs and events of organisations A and B
- WHEN a user of organisation A lists or opens them
- THEN only organisation A's entries are listed, and opening one of B's answers 404
- @e2e exclude covered by PHPUnit per controller

### Requirement: Admins see across organisations only as OpenRegister defines (REQ-TEN-002)

Integriq MUST NOT widen an admin's view beyond what OpenRegister grants. An admin MUST see other organisations' integriq objects only when OpenRegister's admin override is on.

#### Scenario: an admin without the override stays in their organisation
- GIVEN OpenRegister's admin override is off and an admin whose active organisation is A
- WHEN the admin opens the Sources page
- THEN only organisation A's sources are listed
- @e2e exclude covered by OpenRegister's multitenancy tests; integriq passes the default flags

### Requirement: System paths run unscoped only with a stated reason (REQ-TEN-003)

A path that runs without a user, or that resolves an object after a request was already authorised and scoped, MAY switch the organisation filter off. Each such call MUST carry a one-line comment saying why it runs unscoped.

#### Scenario: the hourly health job still probes every source
- GIVEN sources of organisations A and B and no user session
- WHEN the connection health job runs
- THEN it probes both sources, and the call site says it runs as the system
- @e2e exclude background job, covered by PHPUnit
