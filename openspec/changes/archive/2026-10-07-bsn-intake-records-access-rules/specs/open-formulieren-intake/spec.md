## ADDED Requirements

### Requirement: Submissions are open to the intake account, the handlers and administrators only (REQ-008)

`openformulieren_submission` holds BSNs. Its OpenRegister authorization block MUST grant `create` and `update` to the group `openformulieren-intake`, `read` and `update` to the group `openformulieren-behandelaars`, and nothing to anyone else. `delete` MUST be granted to nobody. Administrators keep every action. The account of the Open Formulieren connection MUST be a member of `openformulieren-intake`: choosing it in the connection settings MUST add it and remove the previous account, and a repair step MUST add the current account on upgrade. The group names are fixed.

#### Scenario: An ordinary account gets nothing

- GIVEN an account that is in neither group and is not an administrator
- WHEN it reads, creates or updates an `openformulieren_submission` through the OpenRegister API
- THEN every request is refused
- @e2e exclude backend RBAC: covered by the register test and the live proof per role

#### Scenario: A handler reads and updates

- GIVEN an account in `openformulieren-behandelaars`
- WHEN it reads and updates a submission
- THEN both succeed
- AND it cannot create one
- @e2e exclude backend RBAC: covered by the live proof per role

#### Scenario: The intake account creates

- GIVEN the Open Formulieren connection account, a member of `openformulieren-intake`
- WHEN a signed submission arrives
- THEN the submission is created as that account
- @e2e exclude backend RBAC: covered by PHPUnit and the live proof

#### Scenario: The chosen intake account joins the intake group

- GIVEN an administrator in the Open Formulieren connection section
- WHEN they choose an account and save
- THEN the account is a member of `openformulieren-intake`
- AND the account chosen before is no longer a member
- AND an account the save refuses is not left in the group
- @e2e exclude group membership: covered by PHPUnit and the live proof

#### Scenario: An upgraded instance keeps its intake account

- GIVEN an instance whose Open Formulieren connection already names an account
- WHEN the upgrade runs the repair step
- THEN the four intake and handler groups exist
- AND the account is a member of `openformulieren-intake`
- AND nobody was added to `openformulieren-behandelaars`
- @e2e exclude repair step: covered by PHPUnit and the live proof
