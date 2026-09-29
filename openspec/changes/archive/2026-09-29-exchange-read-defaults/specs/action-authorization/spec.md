# action-authorization Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- exchange-read-defaults

## Purpose

Reading exchange jobs is granted by default to the groups that read learniq's exchange gates
(D33), on fresh installs and on upgraded instances that never changed the value.

## ADDED Requirements

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

## Non-Functional Requirements

- **Performance:** one config read and at most one write per upgrade.
- **Accessibility:** no UI change.
- **Internationalization:** no new strings.

## Acceptance Criteria

- Unit tests cover the broadened, added, kept and empty cases.
