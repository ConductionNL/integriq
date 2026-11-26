# action-authorization Specification

## Purpose

Integriq's adoption of ADR-023 (action-level authorization via
admin-configured action/group mappings), which is owned company-wide in
`hydra/openspec/architecture/adr-023-action-authorization.md`.

The ADR states the rule; this spec states what THIS app does about it: which
actions exist, where the matrix lives, how `ActionAuthService` decides, and what
the admin sees. Twenty-two `@spec` tags across `ActionAuthService`,
`ActionMatrixController`, `Repair\InitializeActions`, `ActionAuthMatrix.vue` and
`AdminSettings.vue` pointed at the hydra ADR by path, which does not exist in
this repository — every one of them was an unresolvable target. A company-wide
ADR has one canonical home and is *adopted* from there, not path-referenced
across repos.

Data RBAC is out of scope here: who may read or write which objects is
OpenRegister's job (ADR-022), and this app does not re-implement it.

## Requirements

### Requirement: The action registry is declared, seeded admin-only, and stored in IAppConfig

Integriq SHALL declare its actions in `lib/actions.seed.json` as
dot-separated names (`source.test`, `synchronization.run`, `mapping.test`, …),
each seeded with `["admin"]`, and SHALL store the live matrix as JSON in
`IAppConfig` under app id `openconnector`, key `actions`.

The seed is the first-install posture and it is deliberately closed: nothing is
reachable by a non-admin until an admin broadens it in the settings UI. A code
change MUST NOT relax that default.

#### Scenario: A fresh install starts admin-only

- **GIVEN** an instance where Integriq has just been installed
- **WHEN** the `InitializeActions` repair step runs
- **THEN** every action named in `lib/actions.seed.json` MUST be present in the
  stored matrix with `["admin"]` as its group list
- @e2e exclude repair-step seeding runs at install time, before any browser
  session exists — covered by PHPUnit

#### Scenario: An upgrade does not reopen a narrowed action

- **GIVEN** an admin has narrowed or broadened an action in the matrix
- **WHEN** the repair step runs again on upgrade
- **THEN** the admin's stored value for that action MUST survive, and only
  actions absent from the stored matrix MUST be seeded
- @e2e exclude same — repair steps run outside a session

### Requirement: Enforcement is centralised in ActionAuthService

Every controller method that performs a named action SHALL delegate its
authorization decision to `ActionAuthService::requireAction()`, which throws
`OCSForbiddenException` when the caller may not perform it. Controllers MUST NOT
carry their own group checks for these actions.

Admin-only operations that are not actions — editing the matrix, app config,
rebase — SHALL instead be declared at the route layer with
`#[AuthorizedAdminSetting(IntegriqAdmin::class)]` and bypass this service.

#### Scenario: A Nextcloud admin always passes

- **GIVEN** a user in the `admin` group
- **WHEN** `requireAction()` is called for any action
- **THEN** it MUST return without throwing, whatever the matrix says
- **AND** this break-glass path MUST be checked before the matrix is read
- @e2e exclude service-level decision with no UI surface — covered by PHPUnit

#### Scenario: An admin-only entry denies every non-admin

- **GIVEN** an action whose matrix entry is `["admin"]` or is empty
- **WHEN** a non-admin calls it
- **THEN** `requireAction()` MUST throw `OCSForbiddenException`
- **AND** the message MUST name the action
- @e2e exclude service-level decision with no UI surface — covered by PHPUnit

#### Scenario: Group membership is intersected, with "admin" excluded from the intersection

- **GIVEN** an action allowed for `["admin", "editors"]` and a non-admin user in
  `editors`
- **WHEN** the user calls it
- **THEN** `requireAction()` MUST return without throwing
- **AND** the `admin` entry MUST NOT participate in the intersection — it is a
  display hint in the matrix, not a group to match against, and the admin case
  was already decided above
- @e2e exclude service-level decision with no UI surface — covered by PHPUnit

#### Scenario: A corrupt or partially-typed matrix fails closed

- **GIVEN** a stored matrix value that is not valid JSON, or one whose entries
  hold non-array values or non-string group names
- **WHEN** the matrix is read
- **THEN** unreadable JSON MUST yield an empty matrix rather than an exception,
  and unusable entries MUST be discarded rather than coerced
- **AND** an action with no usable entry MUST deny every non-admin
- @e2e exclude storage normalisation with no UI surface — covered by PHPUnit

### Requirement: The matrix is editable by an administrator, and only by one

The admin settings panel SHALL present every declared action with its group
list, and SHALL persist edits through `GET`/`PUT
/apps/integriq/api/admin/action-matrix`. Both routes SHALL be declared
`#[AuthorizedAdminSetting(IntegriqAdmin::class)]`, so the framework rejects
a non-admin before the controller body runs.

Editing the matrix is the only supported way to broaden an action.

#### Scenario: An administrator sees the action matrix in settings

- **GIVEN** an authenticated administrator
- **WHEN** they open Administration settings → Integriq
- **THEN** the Action authorization panel MUST render, listing the declared
  actions with their currently allowed groups
- @e2e action-authorization::an-administrator-sees-the-action-matrix-in-settings

#### Scenario: A non-admin cannot read or write the matrix

- **GIVEN** an authenticated non-admin user
- **WHEN** either action-matrix route is called
- **THEN** the framework MUST reject the request on the attribute, before the
  controller body runs
- @e2e exclude requires a second, non-admin session — covered by
  `tests/integration/integriq.postman_collection.json`

### Requirement: REQ-001: exchange.read defaults to admin, coordinators and compliance officers
The seeded action matrix MUST grant `exchange.read` to `admin`, `coordinators` and
`compliance-officers`. `exchange.resubmit` and `exchange.waive` MUST stay `["admin"]`.

#### Scenario: fresh install
- GIVEN an instance with an empty action matrix
- WHEN the app is installed
- THEN `exchange.read` is `["admin", "coordinators", "compliance-officers"]`
- AND `exchange.waive` is `["admin"]`

### Requirement: REQ-002: an upgrade broadens only the untouched default
On upgrade a repair step MUST set `exchange.read` to the new default when the stored entry
equals `["admin"]` or is absent from a non-empty matrix. It MUST leave any other stored value
unchanged, MUST leave every other action unchanged, and MUST do nothing on an empty matrix.
It MUST run at most once per instance (a marker in IAppConfig), so an administrator who sets
`["admin"]` back after the upgrade keeps it on every later upgrade.

#### Scenario: untouched default is broadened
- GIVEN a stored matrix where `exchange.read` is `["admin"]`
- WHEN the repair step runs
- THEN `exchange.read` is `["admin", "coordinators", "compliance-officers"]`
- AND every other entry is unchanged

#### Scenario: absent entry is added
- GIVEN a non-empty stored matrix without an `exchange.read` entry
- WHEN the repair step runs
- THEN `exchange.read` is `["admin", "coordinators", "compliance-officers"]`

#### Scenario: an administrator's value is kept
- GIVEN a stored matrix where `exchange.read` is `["admin", "team-leads"]`
- WHEN the repair step runs
- THEN the matrix is not written

#### Scenario: a later upgrade does not broaden again
- GIVEN the step already ran and an administrator set `exchange.read` back to `["admin"]`
- WHEN the repair step runs on the next upgrade
- THEN the matrix is not written

#### Scenario: empty matrix is left to the seeding step
- GIVEN an empty stored matrix
- WHEN the repair step runs
- THEN the matrix is not written

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
before this change. Granting one verb MUST NOT narrow the verbs left at their
seed: once a schema's block names any verb, a verb still open to every
signed-in user MUST be written out as `authenticated`, because OpenRegister
reads a verb missing from a block as admin only.

#### Scenario: a team edits mappings but not sources
- GIVEN an administrator who grants `object.mapping.update` to `integratie-beheer` and leaves `object.source.update` at admin
- WHEN a member of `integratie-beheer` edits a mapping and then a source through the OpenRegister object API
- THEN the mapping edit is saved and the source edit is refused, and a non-member can still read the mapping but not edit it
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
