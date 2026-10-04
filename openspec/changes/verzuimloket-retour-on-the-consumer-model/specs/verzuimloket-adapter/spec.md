## ADDED Requirements

### Requirement: The retour acts as the Verzuimloket connection's account (REQ-020)

`POST /api/verzuimloket/retour` MUST authenticate a delivery against the `verzuimloket-webhook` consumer, as REQ-CM-020 of `consumer-management` describes, and MUST run every OpenRegister write as that consumer's account. A bad signature MUST answer 401. A missing connection, account or right MUST answer 503 and write nothing. A write OpenRegister refuses MUST answer 503, never 200. Ruben approved this model on 2026-10-04.

#### Scenario: A signed delivery without a session is stored as the connection's account

- GIVEN a `verzuimloket-webhook` consumer whose account may write `verzuim_message`
- WHEN DUO Verzuimloket posts a correctly signed delivery without a session
- THEN it is stored
- AND every write runs as that account
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A connection without a usable account answers 503

- GIVEN a `verzuimloket-webhook` consumer without an account
- WHEN a correctly signed delivery arrives
- THEN the answer is 503 and nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A wrong signature answers 401

- GIVEN a `verzuimloket-webhook` consumer
- WHEN a delivery arrives signed with another secret
- THEN the answer is 401 and nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof
