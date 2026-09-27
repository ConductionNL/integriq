# action-authorization Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- platform-action-rights-coverage

## Purpose

Every action integriq enforces can be delegated from the action authorization
screen, the seed cannot drift from the code again, and an administrator sets
who may create, read, update and delete each configuration object on the same
screen. Matrix rows `integriq:plt-action-matrix` and `integriq:plt-roles`.

## ADDED Requirements

### Requirement: Every enforced action is in the seed (REQ-ARC-001)

Every action name passed to `ActionAuthService::requireAction()` or `can()`
anywhere in `lib/` MUST be present in `lib/actions.seed.json` with `admin` as
its default, a label and an area. A unit test MUST fail, naming the action,
when an enforced action is missing from the seed.

#### Scenario: an adapter action can be delegated
- GIVEN an administrator on the action authorization screen
- WHEN they look for `sms.send`
- THEN it is listed under its area with a label and can be granted to a group
- e2e: tests/e2e/action-rights-coverage.spec.ts

#### Scenario: a new unseeded action fails the build
- GIVEN a controller calling `requireAction(action: 'example.new')` with no seed entry
- WHEN the unit suite runs
- THEN `ActionSeedCoverageTest` fails naming `example.new`
- @e2e exclude a build guard; covered by PHPUnit ActionSeedCoverageTest

### Requirement: The screen groups actions by area with labels (REQ-ARC-002)

The action authorization screen MUST show one section per area, each action
with its label and its name, and a checkbox per group, and MUST save all
sections with one save.

#### Scenario: an administrator delegates adapter pushes
- GIVEN an administrator on the screen
- WHEN they open the "Adapters" section, tick group `integratie-beheer` for `stuf-zkn.push` and save
- THEN a member of `integratie-beheer` can push through the StUF-ZKN bridge and a non-member gets 403
- e2e: tests/e2e/action-rights-coverage.spec.ts

### Requirement: Object rights per configuration schema are set on the screen (REQ-ARC-003)

The matrix MUST hold `object.<schema>.<verb>` actions for `source`, `mapping`,
`synchronization`, `job`, `endpoint`, `rule`, `consumer`,
`event_subscription` and `api_product`, and the verbs `create`, `read`,
`update` and `delete`. Saving MUST write the chosen groups, always with
`admin`, into that schema's OpenRegister `authorization` block. The seeded
value of each object action MUST equal the schema's authorization in force
before this change.

#### Scenario: a team edits mappings but not sources
- GIVEN an administrator who grants `object.mapping.update` to `integratie-beheer` and leaves `object.source.update` at admin
- WHEN a member of `integratie-beheer` edits a mapping and then a source through the OpenRegister object API
- THEN the mapping edit is saved and the source edit is refused
- e2e: tests/e2e/action-rights-coverage.spec.ts

#### Scenario: applying the seed changes nothing
- GIVEN an upgraded instance where no administrator changed an object action
- WHEN the object rights are applied
- THEN every schema's authorization answers the same requests as before
- @e2e exclude a before and after comparison; covered by an integration test against OpenRegister

### Requirement: Object rights survive a register import (REQ-ARC-004)

After every register import integriq MUST apply the stored object rights to the
schemas again, so an upgrade does not reset an administrator's choice.

#### Scenario: an upgrade keeps delegated mapping edits
- GIVEN `object.mapping.update` granted to `integratie-beheer`
- WHEN integriq is upgraded and the register is imported again
- THEN a member of `integratie-beheer` can still edit a mapping
- @e2e exclude an upgrade path; covered by PHPUnit on the ApplyObjectRights repair step
