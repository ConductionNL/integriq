## ADDED Requirements

### Requirement: Verzoeken are open to the intake account, the handlers and administrators only (REQ-DSO-072)

`dso_verzoek` holds BSNs. Its OpenRegister authorization block MUST grant `create` and `update` to the group `dso-intake`, `read` and `update` to the group `dso-behandelaars`, and nothing to anyone else. `delete` MUST be granted to nobody. Administrators keep every action. The account of the DSO connection MUST be a member of `dso-intake`: choosing it in the DSO connection settings MUST add it and remove the previous account, and a repair step MUST add the current account on upgrade. The group names are fixed.

#### Scenario: An ordinary account gets no verzoeken

- GIVEN an account that is in neither group and is not an administrator
- WHEN it reads, creates or updates a `dso_verzoek` through the OpenRegister API
- THEN every request is refused
- @e2e exclude backend RBAC: covered by the register test and the live proof per role

#### Scenario: A DSO handler reads and updates

- GIVEN an account in `dso-behandelaars`
- WHEN it reads and updates a verzoek
- THEN both succeed
- AND it cannot create one
- @e2e exclude backend RBAC: covered by the live proof per role

#### Scenario: The chosen intake account joins the intake group

- GIVEN an administrator in the DSO connection section
- WHEN they choose an account and save
- THEN the account is a member of `dso-intake`
- AND the account chosen before is no longer a member
- AND an account the save refuses is not left in the group
- @e2e exclude group membership: covered by PHPUnit
