# intake-channels Specification

## Purpose
Integriq turns a channel into a declared adapter with a routing rule, so a
message that is not an e-mail can open a case without a release. The contract
itself (REQ-IC-001..REQ-IC-005) arrives with `intake-channels-beyond-mail`, still
an open change; this spec starts with the Microsoft Teams adapter that builds
on it (REQ-IC-006, archived from `teams-messages-open-cases`).

## Requirements

### Requirement: A Teams message arrives as an intake channel (REQ-IC-006)

Integriq MUST ship a `teams` adapter implementing `IntakeChannelAdapter`.
`receive()` MUST normalise a Microsoft Teams activity into the inbound shape:
the author as the correspondent, the message text stripped of its markup, the
attachments the activity carries, the conversation and message identifiers,
and the raw payload kept whole. `describe()` MUST say the channel can reply.
`reply()` MUST answer in the conversation the message came from, not by any
other route.

The adapter MUST NOT decide what a case is, MUST NOT name a case type, and
MUST NOT write in a consuming app. A Teams message MUST go through the same
routing rules, the same review inbox and the same case-number detection as
every other channel.

#### Scenario: A message naming a case number links to it

- GIVEN a routing rule for the `teams` channel and a message whose text names an existing case number
- WHEN the message arrives on the inbound endpoint
- THEN it is normalised, the reference is detected, and the owning app is offered the link
- @e2e exclude channel dispatch; covered by PHPUnit on the adapter and the detector

#### Scenario: A message naming nothing is offered as a new case

- GIVEN a routing rule mapping the `teams` channel onto a case type
- WHEN a message naming no case number arrives
- THEN the owning app is offered the message as the start of a case, with the author as correspondent
- @e2e exclude covered by PHPUnit on the routing service

#### Scenario: A message matching no rule is held with its reason

- GIVEN a Teams message that matches no routing rule
- WHEN it arrives
- THEN it is held in the review inbox with the reason, no case is opened, and nothing is dropped
- @e2e exclude covered by PHPUnit on the routing service

#### Scenario: The answer goes back into the thread

- GIVEN a case opened from a Teams message
- WHEN the owning app answers with a case number
- THEN the reply is posted into the same Teams conversation
- AND a channel that refuses the post reports the refusal rather than reporting success
- @e2e exclude an outbound post to a third party; covered by PHPUnit in mock mode

### Requirement: A channel acts as its connection's account (REQ-IC-020)

`POST /api/intake/channels/{channel}/inbound and POST /api/verdicts/inbound` MUST authenticate a delivery against the `intake-channel-<channelId> (verdicts: intake-channel-verdicts)` consumer, as REQ-CM-020 of `consumer-management` describes, and MUST run every OpenRegister write as that consumer's account. A bad signature MUST answer 401. A missing connection, account or right MUST answer 503 and write nothing. A write OpenRegister refuses MUST answer 503, never 200. Ruben approved this model on 2026-10-04.

#### Scenario: A signed delivery without a session is stored as the channel's account

- GIVEN an `intake-channel-form-submission` consumer whose account may create `intake_message`
- WHEN a correctly signed form submission arrives without a session
- THEN the answer is 202
- AND the intake message is written by that account
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: An account without the create right answers 503

- GIVEN an `intake-channel-verdicts` consumer whose account may not create `verdict`
- WHEN a correctly signed verdict arrives
- THEN the answer is 503 `intakeverdicts_account_lacks_rights` and nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: Each channel has its own connection

- GIVEN only an `intake-channel-verdicts` consumer
- WHEN a correctly signed delivery arrives on the form-submission channel
- THEN the answer is 503 `intakeformsubmission_connection_not_configured`
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A wrong signature answers 401

- GIVEN an `intake-channel-form-submission` consumer
- WHEN a delivery arrives signed with another secret
- THEN the answer is 401 and nothing is stored
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof
