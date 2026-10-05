## ADDED Requirements

### Requirement: Routing rules are read as administrator configuration (REQ-IC-007)

The routing of an intake message MUST read the routing rules as the engine, with RBAC and multitenancy off, so that a message arriving on a non-administrator intake account is matched against every enabled rule. That read MUST be a read only. Creating, changing and deleting a rule MUST stay an administrator action, and an ordinary account MUST NOT be able to read a rule. Approved by Ruben on 2026-10-05.

#### Scenario: An enabled rule routes a message from the intake account

- **GIVEN** an enabled rule for the form-submission channel and an app that opens its target
- **WHEN** a signed submission arrives on the connection's ordinary intake account
- **THEN** the message is routed, not held
- @e2e exclude signed webhook: covered by PHPUnit and the live proof

#### Scenario: Without an enabled rule the message is held

- **GIVEN** the rule is disabled or deleted
- **WHEN** a signed submission arrives
- **THEN** the message is held with "No routing rule matched"
- @e2e exclude signed webhook: covered by the live proof

#### Scenario: An ordinary account still cannot touch the rules

- **GIVEN** an account that is not an administrator, the intake account included
- **WHEN** it lists, reads, creates, updates or deletes a rule
- **THEN** it sees none and every write is refused
- @e2e exclude backend RBAC: covered by the live proof per role
