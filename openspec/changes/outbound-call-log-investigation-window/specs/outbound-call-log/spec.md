# outbound-call-log Specification (delta)

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- outbound-call-log-investigation-window

## Purpose

Bodies of outgoing calls are stored only during an investigation window an
administrator opens per source, and they age out after it. Woo row 13.23,
decision D5 (2026-10-05).

## MODIFIED Requirements

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

## ADDED Requirements

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
