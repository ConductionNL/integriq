# action-authorization Specification

## Purpose

OpenConnector's adoption of ADR-023 (action-level authorization via
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

OpenConnector SHALL declare its actions in `lib/actions.seed.json` as
dot-separated names (`source.test`, `synchronization.run`, `mapping.test`, …),
each seeded with `["admin"]`, and SHALL store the live matrix as JSON in
`IAppConfig` under app id `openconnector`, key `actions`.

The seed is the first-install posture and it is deliberately closed: nothing is
reachable by a non-admin until an admin broadens it in the settings UI. A code
change MUST NOT relax that default.

#### Scenario: A fresh install starts admin-only

- **GIVEN** an instance where OpenConnector has just been installed
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
`#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]` and bypass this service.

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
/apps/openconnector/api/admin/action-matrix`. Both routes SHALL be declared
`#[AuthorizedAdminSetting(OpenConnectorAdmin::class)]`, so the framework rejects
a non-admin before the controller body runs.

Editing the matrix is the only supported way to broaden an action.

#### Scenario: An administrator sees the action matrix in settings

- **GIVEN** an authenticated administrator
- **WHEN** they open Administration settings → OpenConnector
- **THEN** the Action authorization panel MUST render, listing the declared
  actions with their currently allowed groups
- @e2e action-authorization::admin-sees-the-action-matrix

#### Scenario: A non-admin cannot read or write the matrix

- **GIVEN** an authenticated non-admin user
- **WHEN** either action-matrix route is called
- **THEN** the framework MUST reject the request on the attribute, before the
  controller body runs
- @e2e exclude requires a second, non-admin session — covered by
  `tests/integration/openconnector.postman_collection.json`
