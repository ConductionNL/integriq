## ADDED Requirements

### Requirement: The inbound webhook acts as the Peppol connection's account (REQ-020)

`POST /api/peppol/inbound` MUST authenticate a delivery against the `peppol-webhook` consumer, as REQ-CM-020 of `consumer-management` describes, and MUST run every OpenRegister write as that consumer's account. A bad signature MUST answer 401. A missing connection, account or right MUST answer 503 and write nothing. A write OpenRegister refuses MUST answer 503, never 200. Ruben approved this model on 2026-10-04.

#### Scenario: A signed callback without a session is stored as the connection's account

- GIVEN a `peppol-webhook` consumer whose account may write `peppol_transmission`
- AND a sent transmission
- WHEN the access point posts a correctly signed delivery callback without a session
- THEN the answer is 200
- AND the transmission is updated by that account
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A connection without a usable account answers 503

- GIVEN a `peppol-webhook` consumer without an account
- WHEN a correctly signed callback arrives
- THEN the answer is 503 `peppol_account_unavailable`
- AND nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof

#### Scenario: A wrong signature answers 401

- GIVEN a `peppol-webhook` consumer
- WHEN a callback arrives signed with another secret
- THEN the answer is 401 and nothing is written
- @e2e exclude server-to-server webhook: covered by PHPUnit and the live proof
