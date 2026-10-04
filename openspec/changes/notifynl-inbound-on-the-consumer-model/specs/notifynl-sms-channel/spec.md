## ADDED Requirements

### Requirement: The status callback acts as the NotifyNL connection's account (REQ-020)

`POST /api/notifynl/inbound` MUST authenticate a delivery against the `notifynl-webhook` consumer, as REQ-CM-020 of `consumer-management` describes, and MUST run every OpenRegister write as that consumer's account. A bad signature MUST answer 401. A missing connection, account or right MUST answer 503 and write nothing. A write OpenRegister refuses MUST answer 503, never 200. Ruben approved this model on 2026-10-04.

#### Scenario: A signed status callback without a session is stored as the connection's account

- GIVEN a `notifynl-webhook` consumer whose account may write `sms_message`
- AND a sent message
- WHEN NotifyNL posts a correctly signed status callback without a session
- THEN the answer is 200
- AND the message is updated by that account
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A missing connection answers 503

- GIVEN no `notifynl-webhook` consumer, or one whose account lacks `update` on `sms_message`
- WHEN a correctly signed callback arrives
- THEN the answer is 503 and nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A wrong signature answers 401

- GIVEN a `notifynl-webhook` consumer
- WHEN a callback arrives signed with another secret
- THEN the answer is 401 and nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof
