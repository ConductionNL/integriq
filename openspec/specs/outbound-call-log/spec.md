# outbound-call-log Specification

## Purpose
Integriq records every call it makes to an external system with its request
and its response, lets an administrator replay a failed one, and takes its
retry schedule from configuration rather than from a constant. Round 4
discovery cluster 27, candidates C-integrations-7 (matrix hole),
C-integrations-31 (matrix hole), C-integrations-45 (matrix hole),
C-integrations-12, 6, 16, 20 and 32, row 6.11, number 8 of the twenty-five
loudest.

## Requirements

### Requirement: Every outbound call is a record with its request and its response (REQ-OCD-001)

Integriq MUST write a call record for every outbound call, carrying the
target, the trace id from `execution-trace` REQ-001, the method, the URL
without secrets, the redacted request and response headers, the status, the
size and the duration. The request body and the response body MUST be stored
only when the call's source had an open investigation window at the moment of
the call (REQ-OCD-008), or when the calling code passed `logBody` and is on
the allowlist of REQ-OCD-010. Outside those cases neither body is stored, for
a success or a failure. Secrets MUST be redacted before the record is
written. The log MUST filter by target, status and time, and MUST be readable
only to a principal holding a named permission.

#### Scenario: a failed StUF call is found without a container log
- GIVEN a StUF call that returned a fault while its source had an open investigation window
- WHEN an administrator filters the call log by failed status
- THEN the call is listed, and opening it shows the request sent and the fault returned
- e2e: `tests/e2e/outbound-call-log.spec.ts`

#### Scenario: a failure outside a window keeps no body
- GIVEN a source without an open window
- WHEN a call to it returns 500 with a body
- THEN the record carries the status, the timing and the redacted headers, `bodyCaptured` is false, and neither the request body nor the response body is stored
- @e2e exclude a storage absence claim; covered by PHPUnit `CallServiceBodyCaptureTest::testAFailureOutsideAWindowStoresNoBody`

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

### Requirement: An administrator opens a bounded investigation window per source (REQ-OCD-008)

An administrator MUST be able to open an investigation window on one source for
a number of hours between 1 and the instance maximum (default 72, setting
`call_log_body_capture_max_hours`), with a non-empty reason, and to close it
early. Opening MUST store `bodyCaptureUntil`, `bodyCaptureReason` and
`bodyCaptureBy` on the source. Opening and closing MUST each be recorded on the
source's audit trail with the principal and the reason. A request for more
hours than the maximum MUST be refused, not shortened. The window MUST close
by itself: a call made after `bodyCaptureUntil` stores no body, with no job or
person needed. A principal who only holds the call-log read permission MUST
NOT be able to open a window.

#### Scenario: an administrator investigates a partner for a day
- GIVEN the source `zgw-zaken` without a window, and an administrator
- WHEN she opens a window of 24 hours with the reason "melding 4711: verkeerde zaaktypen"
- THEN calls to `zgw-zaken` in the next 24 hours store their request and response bodies, the record says `bodyCaptured: true`, and the source's audit trail names her and the reason
- @e2e exclude the page that opens a window is built in outbound-call-log-investigation-window-page-and-allowlist; covered here by PHPUnit `BodyCaptureControllerTest::testAnAdministratorOpensAWindowWithAReason` and `CallServiceBodyCaptureTest::testASuccessInsideAWindowStoresBothBodies`

#### Scenario: the window switches itself off
- GIVEN a window on `zgw-zaken` that ended one minute ago
- WHEN integriq calls `zgw-zaken`
- THEN the record stores no body and says `bodyCaptured: false`
- @e2e exclude a time boundary; covered by PHPUnit `CallServiceBodyCaptureTest::testACallAfterTheWindowEndsStoresNoBody` with an injected clock

#### Scenario: a window longer than the maximum is refused
- GIVEN the instance maximum of 72 hours
- WHEN an administrator asks for 200 hours
- THEN the request is refused with the maximum in the message, and no window is opened
- @e2e exclude a server-side validation; covered by PHPUnit `BodyCaptureControllerTest::testMoreHoursThanTheMaximumIsRefused`

#### Scenario: a reader cannot open a window
- GIVEN a principal with only the call-log read permission
- WHEN they try to open a window
- THEN the request is refused with 403 and the source is unchanged
- @e2e exclude an authorization refusal; covered by PHPUnit `BodyCaptureControllerTest::testAReaderCannotOpenAWindow`

### Requirement: Captured bodies age out and the record stays (REQ-OCD-009)

A record written during a window MUST carry `bodyExpiresAt`, the window end plus
the body retention (default 7 days, setting `call_log_body_retention_days`).
The cleanup job MUST remove the request and response bodies, and
`replayRequest`, from every record past its `bodyExpiresAt`, set
`bodyCaptured` to false and `bodyExpiredAt` to the time it ran, and keep the
rest of the record until its own retention ends. A failed call MUST keep the
request it sent as `replayRequest` so REQ-OCD-002 can replay it. `replayRequest`
MUST be readable only with the replay permission, MUST NOT appear in the call
log detail outside a window, and MUST be removed when a replay of that call
succeeds or when the record's error retention ends.

#### Scenario: bodies captured last week are gone, the record is not
- GIVEN a record captured during a window, whose `bodyExpiresAt` passed yesterday
- WHEN the cleanup job runs
- THEN the record still shows the status, timing and headers, and no body
- @e2e exclude a background job; covered by PHPUnit `LogCleanUpTaskTest::testExpiredBodiesAreStrippedAndTheRecordKept`

#### Scenario: a failed delivery can still be replayed
- GIVEN a failed call outside any window
- WHEN an administrator with the replay permission replays it
- THEN the original request is sent again, and after the replay succeeds the record no longer holds `replayRequest`
- @e2e exclude a replay payload lifecycle; covered by PHPUnit `CallReplayServiceTest::testASuccessfulReplayRemovesTheReplayRequest`
