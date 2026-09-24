# message-recipient-selection Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- one-off-and-suppressed-recipients

## Purpose

Integriq resolves the recipients of one outgoing message from the standing list
the calling app passes, the recipients somebody added for this message only, and
the recipients somebody suppressed for this message only. The decision and its
reasons are part of the message record, so the message can say who did not
receive it and why. Row 6.23 of the competitor gap register, "One-off recipient
added to a single message, or a standing one suppressed", rated `no` for dossiq.

## ADDED Requirements

### Requirement: The recipient list of a message is a recorded decision (REQ-MRS-001)

Integriq MUST record, on every outbound message, the standing recipients it was
given, the recipients added for that message, and the recipients suppressed for
that message, each as its own part of the record. The transport MUST receive the
standing recipients minus the suppressed ones plus the added ones. Integriq MUST
NOT store the resolved list alone, and MUST NOT drop a suppressed recipient from
the record.

#### Scenario: the record shows who was meant to receive it and did not
- GIVEN a message with three standing recipients, one addition and one suppression
- WHEN the message is recorded
- THEN the record carries all three parts, and the suppressed recipient is present and marked suppressed
- e2e: `tests/e2e/message-recipient-selection.spec.ts`

#### Scenario: the transport receives the resolved list
- GIVEN the same message
- WHEN it is handed to the transport
- THEN the transport receives three addresses: the two remaining standing ones and the added one
- @e2e exclude a resolution step; covered by PHPUnit on the resolver

#### Scenario: a suppressed recipient has no delivery state
- GIVEN a suppressed recipient on a sent message
- WHEN the record is read
- THEN that recipient reads `suppressed` and carries no delivery state, and it is not reported as `not reported`
- @e2e exclude a record shape; covered by PHPUnit on the recorder

### Requirement: An addition applies to one message only (REQ-MRS-002)

A recipient added to a message MUST apply to that message alone. Integriq MUST
NOT write it to the subject, to a standing list, or to any store that a later
message reads. A later message for the same subject MUST resolve without it
unless the caller adds it again.

#### Scenario: a gemachtigde receives this letter and not the next one
- GIVEN a message to which an external address was added
- WHEN a second message is sent for the same subject with no addition
- THEN the second message goes to the standing recipients only
- e2e: `tests/e2e/message-recipient-selection.spec.ts`

#### Scenario: nothing outside the message is written
- GIVEN a message with an addition
- WHEN the send completes
- THEN no standing list, party record or subject is changed by integriq
- @e2e exclude a write-scope assertion; covered by PHPUnit on the resolver

### Requirement: A suppression carries a reason and is refused without one (REQ-MRS-003)

A suppression MUST carry a reason typed by a person, the user who made it and
the timestamp, all stored on the message record and readable afterwards. A
suppression with an empty or absent reason MUST be refused and the message MUST
NOT be sent until the request is corrected.

#### Scenario: a year later the record says why
- GIVEN a message with one recipient suppressed with a typed reason
- WHEN the record is opened
- THEN the reason, the user and the timestamp are shown beside that recipient
- e2e: `tests/e2e/message-recipient-selection.spec.ts`

#### Scenario: a suppression without a reason stops the send
- GIVEN a delivery request suppressing a recipient with no reason
- WHEN it is handled
- THEN it is refused, nothing is sent, no record of a send is written, and the refusal says a reason is required
- @e2e exclude a validation path; covered by PHPUnit on the resolver

### Requirement: A required recipient cannot be suppressed (REQ-MRS-004)

A caller MAY mark a standing recipient as required and MUST name what requires
it. Integriq MUST refuse a suppression of a recipient marked required, MUST
repeat the caller's stated requirement in the refusal, and MUST send nothing for
that request. Integriq MUST NOT decide on its own which recipients are required.

#### Scenario: the applicant cannot be dropped from a beschikking
- GIVEN a standing recipient marked required with the text the caller supplied
- WHEN somebody suppresses it
- THEN the suppression is refused, the refusal repeats that text, and nothing is sent
- e2e: `tests/e2e/message-recipient-selection.spec.ts`

#### Scenario: an unmarked recipient may be suppressed
- GIVEN a standing recipient the caller did not mark required
- WHEN it is suppressed with a reason
- THEN the suppression is accepted and recorded
- @e2e exclude a validation path; covered by PHPUnit on the resolver

### Requirement: The resolved list is shown before the send (REQ-MRS-005)

Integriq MUST offer a resolve that writes no record and sends nothing, returning
the same three parts the send would record. The send surface MUST show that
answer before the message leaves, including additions and suppressions with
their reasons. The preview MUST run the same resolution as the send.

#### Scenario: a handler sees who will receive it
- GIVEN a message with one addition and one suppression
- WHEN the handler opens the send screen
- THEN the standing, added and suppressed recipients are listed with the suppression's reason, before anything is sent
- e2e: `tests/e2e/message-recipient-selection.spec.ts`

#### Scenario: a preview leaves no trace
- GIVEN a preview of a message
- WHEN it returns
- THEN no message record exists and no transport was called
- @e2e exclude a no-write assertion; covered by PHPUnit on the resolver

### Requirement: The recipient decision travels on the delivery request (REQ-MRS-006)

The additions, the suppressions with their reasons, and the required markers
MUST be carried on the delivery request event of `delivery-intake`, and integriq
MUST resolve them in its own listener. A calling app MUST NOT need a second call
or a second channel to express them, and MUST NOT resolve recipients itself.

#### Scenario: a sibling app hands over the whole decision at once
- GIVEN a delivery request carrying standing recipients, one addition, one suppression with a reason and one required marker
- WHEN the listener handles it
- THEN the resolution runs in integriq and the result slot reports the recorded message
- @e2e exclude event handling; covered by PHPUnit on the listener

#### Scenario: a malformed recipient decision fails closed
- GIVEN a delivery request whose suppression names a recipient that is not in the standing list
- WHEN the listener handles it
- THEN the request is refused naming the unknown recipient, and nothing is sent
- @e2e exclude a validation path; covered by PHPUnit on the listener
