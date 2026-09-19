# outbound-call-log Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- outbound-call-delivery-and-replay

## Purpose

Integriq records every call it makes to an external system with its request
and its response, lets an administrator replay a failed one, and takes its
retry schedule from configuration rather than from a constant. Round 4
discovery cluster 27, candidates C-integrations-7 (matrix hole),
C-integrations-31 (matrix hole), C-integrations-45 (matrix hole),
C-integrations-12, 6, 16, 20 and 32, row 6.11, number 8 of the twenty-five
loudest.

## ADDED Requirements

### Requirement: Every outbound call is a record with its request and its response (REQ-OCD-001)

Integriq MUST write a call record for every outbound call, carrying the
target, the trace id from `execution-trace` REQ-001, the request as sent, the
response as received, the status and the duration. Secrets MUST be redacted
before the record is written. The log MUST filter by target, status and time,
and MUST be readable only to a principal holding a named permission.

#### Scenario: a failed StUF call is found without a container log
- GIVEN a StUF call that returned a fault
- WHEN an administrator filters the call log by failed status
- THEN the call is listed, and opening it shows the request sent and the fault returned
- e2e: `tests/e2e/outbound-call-log.spec.ts`

#### Scenario: a credential never reaches the record
- GIVEN a call whose headers carry an authorization token
- WHEN the record is written
- THEN the token is redacted before the write
- @e2e exclude redaction runs before buffering; covered by PHPUnit on the recorder

#### Scenario: the log is permissioned
- GIVEN a principal without the call-log permission
- WHEN they request the log
- THEN the request is refused and no call is disclosed
- @e2e exclude an authorization refusal; covered by PHPUnit on the controller

### Requirement: A failed call is replayed from the screen, singly and in bulk (REQ-OCD-002)

An administrator holding the replay permission MUST be able to replay a
failed call from its record, and to select several and replay them together
with a per-item outcome. The replay MUST use the audited act of
`dead-letter-replay` REQ-DLR-003 and the original dispatch path of
`execution-trace` REQ-006. A replay MUST append a new attempt to the same
record and MUST NOT overwrite the first.

#### Scenario: a notification lost during an outage is sent after the receiver returns
- GIVEN a ZGW Notificaties delivery that failed while the receiver was down
- WHEN an administrator replays it
- THEN a new attempt is appended with its own request, response and outcome, and the original attempt is still readable
- e2e: `tests/e2e/outbound-call-log.spec.ts`

#### Scenario: a replayed delivery is signed like a first one
- GIVEN a subscription holding a signing secret
- WHEN a failed delivery is replayed
- THEN the replayed request carries a valid signature under the subscription's current secret
- @e2e exclude signature verification; covered by PHPUnit against `webhook-signing` REQ-WHS-001

#### Scenario: a dry run changes nothing
- GIVEN a failed call
- WHEN an administrator runs the dry run
- THEN the request that would be sent is shown and no call is made and no record is written
- e2e: `tests/e2e/outbound-call-log.spec.ts`

### Requirement: A call can be fired by hand (REQ-OCD-003)

An administrator holding the replay permission MUST be able to fire a call
for a chosen subscription or target without waiting for the event that would
have triggered it. A hand-fired call MUST be recorded as such, naming the
principal who fired it, and MUST be indistinguishable to the receiver from a
triggered one.

#### Scenario: a partner asks for a message to be sent again today
- GIVEN a subscription and a chosen object
- WHEN an administrator fires the delivery by hand
- THEN the receiver gets the same shape it would have got, and the record names the principal who fired it
- e2e: `tests/e2e/outbound-call-log.spec.ts`

#### Scenario: firing by hand needs the permission
- GIVEN a principal without the replay permission
- WHEN they attempt to fire a call
- THEN the attempt is refused and nothing is sent
- @e2e exclude an authorization refusal; covered by PHPUnit on the controller

### Requirement: The retry schedule is configuration, per connection (REQ-OCD-004)

The number of retries, the interval and the backoff MUST be configuration on
a connection, with an instance default. Changing them MUST NOT require a
release. A call that exhausts its retries MUST land in the dead-letter list
of `dead-letter-replay` rather than disappearing. The policy in force MUST be
recorded on the call.

#### Scenario: a Digikoppeling connection gets its own schedule
- GIVEN a connection whose partner asks for six attempts over a day
- WHEN an administrator sets the policy and a call fails
- THEN the attempts follow that schedule and the record names the policy that governed them
- e2e: `tests/e2e/outbound-call-retry-policy.spec.ts`

#### Scenario: an exhausted call is dead-lettered, not lost
- GIVEN a call that exhausts its configured retries
- WHEN the last attempt fails
- THEN the call appears in the dead-letter list and stays replayable
- @e2e exclude the dead-letter hand-off; covered by PHPUnit

### Requirement: A replay names the mapping version it ran under (REQ-OCD-005)

A call record MUST name the mapping and its version at the time of the call.
A replay MUST offer the original version and the current one, MUST state
which it used, and MUST NOT silently switch. Editing a mapping MUST create a
new version rather than mutating the one past calls ran under.

#### Scenario: a mapping changed between the failure and the replay
- GIVEN a call recorded under mapping version 3 and a mapping now at version 4
- WHEN an administrator replays it
- THEN both versions are offered, the chosen one is recorded on the new attempt, and neither is applied silently
- e2e: `tests/e2e/outbound-call-log.spec.ts`

#### Scenario: an edit versions rather than mutates
- GIVEN a mapping past calls ran under
- WHEN an administrator edits it in the mapping editor
- THEN a new version is created and the recorded calls still name the version they ran under
- @e2e exclude version creation; covered by PHPUnit on the mapping service

### Requirement: An external verdict is recorded against the record it judges (REQ-OCD-006)

Integriq MUST accept a verdict from an external checker for a named object,
carrying a state of `pass`, `fail` or `pending`, a source and a reason, and
MUST make it readable by the owning app. Integriq MUST NOT act on a verdict
and MUST NOT change the object it judges.

#### Scenario: a ketenpartner returns a machine verdict
- GIVEN an external checker posting a `fail` with a reason for a case
- WHEN the verdict arrives
- THEN it is stored against that case, readable by dossiq with its source and reason
- @e2e exclude an inbound verdict callback; covered by Newman against the endpoint

#### Scenario: a verdict changes nothing by itself
- GIVEN a stored `fail` verdict
- WHEN the object is read
- THEN the object is unchanged and the verdict sits beside it
- @e2e exclude an absence claim; covered by PHPUnit

### Requirement: A blocking pre-check asks an outside system and reports the answer (REQ-OCD-007)

Integriq MUST support a pre-check that calls a configured external system
before a declared act, waits for its answer within a configured timeout, and
reports `allow`, `refuse` or `no answer` to the caller with the reason given.
A timeout MUST report `no answer` and MUST NOT report `allow`. What a refusal
means to the caller's act is the caller's decision.

#### Scenario: an outside process refuses and the reason travels
- GIVEN a pre-check configured on an act and an external system answering `refuse` with a reason
- WHEN the pre-check runs
- THEN the caller receives `refuse` with that reason
- @e2e exclude an external decision endpoint; covered by PHPUnit against a mock-mode fixture

#### Scenario: a timeout is not permission
- GIVEN an external system that does not answer within the timeout
- WHEN the pre-check gives up
- THEN it reports `no answer`, never `allow`, and the attempt is recorded
- @e2e exclude a timeout path; covered by PHPUnit
