## ADDED Requirements

### Requirement: The retour acts as the ROD connection's account (REQ-020)

`POST /api/rod/retour` MUST authenticate a delivery against the `rod-webhook` consumer, as REQ-CM-020 of `consumer-management` describes, and MUST run every OpenRegister write as that consumer's account. A bad signature MUST answer 401. A missing connection, account or right MUST answer 503 and write nothing. A write OpenRegister refuses MUST answer 503, never 200. Ruben approved this model on 2026-10-04.

#### Scenario: A signed delivery without a session is stored as the connection's account

- GIVEN a `rod-webhook` consumer whose account may write `rod_message`
- WHEN DUO (ROD) posts a correctly signed delivery without a session
- THEN it is stored
- AND every write runs as that account
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A connection without a usable account answers 503

- GIVEN a `rod-webhook` consumer without an account
- WHEN a correctly signed delivery arrives
- THEN the answer is 503 and nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A wrong signature answers 401

- GIVEN a `rod-webhook` consumer
- WHEN a delivery arrives signed with another secret
- THEN the answer is 401 and nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof
