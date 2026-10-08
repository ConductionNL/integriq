# intake-access Specification

## Purpose
Intake messages and verdicts hold what citizens send in, so only the account that stores them, the handlers who work on them and administrators may read or change them. This spec sets the OpenRegister authorization blocks on `intake_message` and `verdict`, the groups that carry those rights, and how webhook accounts join and leave them.

## Requirements

### Requirement: Intake messages and verdicts are open to the intake account, the handlers and administrators only (REQ-IAC-002)

`intake_message` MUST carry an OpenRegister authorization block that grants `create` and `update` to `intakekanalen-intake`, `read` and `update` to `intakekanalen-behandelaars`, and `delete` to nobody. `verdict` MUST carry the same block with `verdicts-intake` and `verdicts-behandelaars`. Administrators keep every action. The account of every intake channel webhook MUST be a member of `intakekanalen-intake`, and the account of the verdicts webhook of `verdicts-intake`: choosing it in the webhook settings MUST add it, an account the save refuses MUST NOT stay in the group, and the previous account MUST be removed unless another webhook of the same group still acts as it. A repair step MUST create the four groups and enrol the current accounts on upgrade. It MUST NOT add anyone to a handler group. Approved by Ruben on 2026-10-05.

#### Scenario: An ordinary account gets nothing

- GIVEN an account in none of the four groups that is not an administrator
- WHEN it reads, creates or updates an `intake_message` or a `verdict` through the OpenRegister API
- THEN every request is refused
- @e2e exclude backend RBAC: covered by the register test and the live proof per role

#### Scenario: A handler reads and updates

- GIVEN an account in `intakekanalen-behandelaars` and `verdicts-behandelaars`
- WHEN it reads and updates an intake message and a verdict
- THEN both succeed
- AND it cannot create either
- @e2e exclude backend RBAC: covered by the live proof per role

#### Scenario: The chosen account joins the intake group

- GIVEN an administrator in the webhook settings
- WHEN they choose an ordinary account for the form-submission channel and save
- THEN the save succeeds and the account is a member of `intakekanalen-intake`
- AND a signed delivery is stored as that account
- AND once the account is removed from the group a delivery answers 503
- @e2e exclude group membership: covered by PHPUnit and the live proof

#### Scenario: An upgraded instance keeps its webhook accounts

- GIVEN an instance whose verdicts webhook already names an account
- WHEN the upgrade runs the repair step
- THEN the account is a member of `verdicts-intake`
- AND nobody was added to `verdicts-behandelaars`
- @e2e exclude repair step: covered by PHPUnit and the live proof

### Requirement: The webhook settings say when nobody can read what a webhook stores (REQ-IAC-003)

The webhook settings MUST return `handlerGroup: {id, empty}` for every webhook whose schema names a handler group, and `null` for the others. The row of such a webhook MUST show a warning that names the group while it has no members, as REQ-IAC-001 does for DSO and Open Formulieren.

#### Scenario: An empty handler group is announced on the webhook

- GIVEN `intakekanalen-behandelaars` has no members
- WHEN an administrator opens the webhook settings
- THEN the form-submission row warns and names `intakekanalen-behandelaars`
- AND the ROD row carries `handlerGroup: null` and no warning
- @e2e exclude settings payload: covered by PHPUnit and the live render on the throwaway instance
