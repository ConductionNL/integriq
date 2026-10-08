## ADDED Requirements

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
