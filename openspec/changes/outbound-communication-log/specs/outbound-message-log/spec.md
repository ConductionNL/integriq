# outbound-message-log Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- outbound-communication-log

## Purpose

Integriq records every message the product sends to a person: its recipients,
its steps, the point at which it failed, and the exact text that left. An
administrator reads it, retries from it, and forwards from it, and Awb 3:41
and Awb 2:3 become questions with an answer. Round 4 discovery cluster 23,
candidates C-communication-33, 57, 5, 58, 56, 39, 10 and 40, rows 6.11, 6.20
and 6.23, three matrix holes.

## ADDED Requirements

### Requirement: Every outbound message is recorded per recipient and per step (REQ-OCL-001)

Integriq MUST write a message record for every outbound message it sends,
carrying the subject reference, the channel, each recipient with its own
status, and an ordered list of steps with their outcomes. A failure MUST name
the step it happened in and the reason the transport gave. A record MUST be
written even when the first step fails.

#### Scenario: an administrator finds where a send failed
- GIVEN a message whose delivery failed at the transport step for one of three recipients
- WHEN the administrator opens the record
- THEN the two delivered recipients and the failed one are shown, and the failed one names the step and the transport's reason
- e2e: `tests/e2e/outbound-message-log.spec.ts`

#### Scenario: a send that never left is still recorded
- GIVEN a message whose rendering fails before any transport is called
- WHEN the attempt ends
- THEN a record exists with the failed step and no recipient marked delivered
- @e2e exclude a pre-transport failure path; covered by PHPUnit on the recorder

#### Scenario: the queue is readable as a queue
- GIVEN twelve messages, four of them failed
- WHEN the administrator filters the log by status
- THEN the four failed ones are listed with their subject reference and their step
- e2e: `tests/e2e/outbound-message-log.spec.ts`

### Requirement: The sent message and its real recipients are readable behind their own permission (REQ-OCL-002)

The stored message body and the resolved recipient list MUST be readable only
to a principal holding a named permission for that, distinct from the
permission to see that a message was sent. Redaction MUST run before the body
is stored, per `execution-trace` REQ-003. A read of a body MUST itself be
recorded.

#### Scenario: a handler sees that a letter went out and not what it said
- GIVEN a handler without the read-body permission
- WHEN they open the message record
- THEN the recipients, the status and the steps are shown and the body is not
- e2e: `tests/e2e/outbound-message-log.spec.ts`

#### Scenario: reading a body is itself recorded
- GIVEN a principal holding the read-body permission
- WHEN they open the stored body
- THEN the read is recorded with the principal and the message id
- @e2e exclude audit write on read; covered by PHPUnit on the reader

#### Scenario: secrets never reach the stored body
- GIVEN a message whose render context carries a credential
- WHEN the body is stored
- THEN the credential is redacted before the write, not after
- @e2e exclude redaction is the trace spec's; covered by PHPUnit on the recorder

### Requirement: A failed send is retried from the screen, and the retry is recorded (REQ-OCL-003)

An administrator holding the retry permission MUST be able to retry a failed
message, singly or in bulk, from the log. The retry MUST reuse the audited
replay act of `dead-letter-replay` REQ-DLR-003 rather than a second
mechanism, MUST create a new attempt on the same record, and MUST report a
per-item outcome for a bulk retry.

#### Scenario: a message that failed during an outage goes out afterwards
- GIVEN a message that failed because the transport was unavailable
- WHEN an administrator retries it
- THEN a new attempt is appended to the same record and its outcome is shown
- e2e: `tests/e2e/outbound-message-log.spec.ts`

#### Scenario: a bulk retry reports each item
- GIVEN nine failed messages selected, two of which fail again
- WHEN the bulk retry runs
- THEN seven succeed, two report their reason, and no item is silently skipped
- @e2e exclude bulk outcome aggregation; covered by PHPUnit on the replay service

### Requirement: A message is forwarded onward and the forwarding is a record (REQ-OCL-004)

Integriq MUST support forwarding a recorded message to a recipient outside
the system, carrying the original body and attachments, and MUST write the
forward as its own record linked to the original. The link MUST be readable
from both sides. A forward MUST NOT alter the original record.

#### Scenario: a misdirected request is passed on provably
- GIVEN a received message recorded against a case and a forward to another bestuursorgaan
- WHEN the forward is sent
- THEN a linked forward record exists naming the new recipient and the original, and the original is unchanged
- e2e: `tests/e2e/outbound-message-log.spec.ts`

#### Scenario: the link reads from both ends
- GIVEN a forwarded message
- WHEN either record is opened
- THEN it names the other
- @e2e exclude record linkage; covered by PHPUnit

### Requirement: Delivery and read status are recorded where the transport reports them, and absent where it does not (REQ-OCL-005)

A recipient's record MUST carry a delivery state and a read state, each one
of `reported`, `not reported` or `unsupported by this channel`. Integriq MUST
NOT infer a delivery from the absence of a failure and MUST NOT infer a read
from a delivery. A channel that cannot report a state MUST render
`unsupported by this channel`.

#### Scenario: a channel that reports delivery says so
- GIVEN a channel that returns a delivery receipt
- WHEN the receipt arrives
- THEN the recipient's delivery state reads `reported` with its timestamp
- @e2e exclude an inbound receipt callback; covered by PHPUnit on the channel

#### Scenario: silence is not delivery
- GIVEN a channel that reports nothing after a successful handover
- WHEN the record is read
- THEN the delivery state reads `not reported`, never `delivered`
- @e2e exclude an absence claim; covered by PHPUnit on the recorder

#### Scenario: a channel without read receipts says so
- GIVEN a channel with no read reporting at all
- WHEN the record is read
- THEN the read state reads `unsupported by this channel`
- e2e: `tests/e2e/outbound-message-log.spec.ts`

### Requirement: The log answers when a recipient was last told anything (REQ-OCL-006)

Integriq MUST answer, for a subject reference and a recipient, the timestamp
of the most recent message that reached at least the transport step, and MUST
say when there is none. The answer MUST be a query over the log, not a field
integriq stores on anyone else's record.

#### Scenario: nobody has written to this citizen in six weeks
- GIVEN a case with three messages to the requester, the latest six weeks ago
- WHEN the last-contact query runs for that requester
- THEN it answers that timestamp
- @e2e exclude a query contract; covered by PHPUnit on the log service

#### Scenario: never contacted answers never, not now
- GIVEN a case with no message to a given recipient
- WHEN the query runs
- THEN it answers that there is none, and does not answer with the case's own dates
- @e2e exclude covered by PHPUnit on the log service

### Requirement: An address outside the instance is a first-class recipient (REQ-OCL-007)

A recipient MUST be storable as a plain external address with no Nextcloud
account, carrying the same per-recipient status, steps and states as any
other. Integriq MUST NOT create an account for it and MUST NOT silently drop
it from a send.

#### Scenario: a gemachtigde with no account is written to and recorded
- GIVEN a message addressed to an external address beside two accounts
- WHEN it is sent
- THEN the external recipient has its own status and steps in the record
- e2e: `tests/e2e/outbound-message-log.spec.ts`

#### Scenario: an unsendable external address fails visibly
- GIVEN an external address the transport refuses
- WHEN the send runs
- THEN that recipient is marked failed with the transport's reason and the other recipients are unaffected
- @e2e exclude per-recipient isolation; covered by PHPUnit on the recorder
