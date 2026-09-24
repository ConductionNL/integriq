# directory-sync Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- directory-and-group-sync

## Purpose

Integriq keeps Nextcloud's users and groups in step with the customer's
directory, continuously rather than at login, so a membership that ends
somewhere else ends here. Round 4 discovery cluster 33, candidates
C-access-and-privacy-82 (matrix hole), C-integrations-34 and C-integrations-21,
number 7 of the twenty-five loudest.

## ADDED Requirements

### Requirement: A directory connection synchronises users and groups (REQ-DS-001)

Integriq MUST offer a directory connection as a source, with a scheduled run
and an on-demand run, that reads users and groups and writes the resulting
memberships through `IGroupManager`. A membership absent from the directory
MUST be removed. Integriq MUST NOT create a user store of its own and MUST
NOT hold a password.

#### Scenario: a membership that ended in the directory ends here
- GIVEN a user in the `behandelaars` group and a directory that no longer lists them in it
- WHEN the sync runs
- THEN the Nextcloud membership is removed and the removal is recorded with its run id
- e2e: `tests/e2e/directory-sync.spec.ts`

#### Scenario: a new member arrives without a manual step
- GIVEN a directory group that gains a member
- WHEN the scheduled run executes
- THEN the member is in the mapped Nextcloud group and no administrator acted
- e2e: `tests/e2e/directory-sync.spec.ts`

#### Scenario: no second account store
- GIVEN a synchronised user
- WHEN the user record is read
- THEN it is a Nextcloud account and integriq holds no credential for it
- @e2e exclude an absence claim about storage; covered by PHPUnit asserting no credential is persisted

### Requirement: The directory-to-group mapping is declared, not coded (REQ-DS-002)

The mapping from directory groups and attributes onto Nextcloud groups MUST
be configuration on the connection, editable without a release. A mapping
naming a Nextcloud group that does not exist MUST either create it or fail
naming it, according to a declared setting, and MUST NOT silently drop the
membership.

#### Scenario: an administrator maps two directory groups onto one Nextcloud group
- GIVEN `OU=Vergunningen` and `OU=Toezicht` mapped onto `behandelaars`
- WHEN the sync runs
- THEN members of both are in `behandelaars` and the mapping is readable on the connection
- e2e: `tests/e2e/directory-sync.spec.ts`

#### Scenario: an unknown target group never fails silently
- GIVEN a mapping onto a Nextcloud group that does not exist and creation turned off
- WHEN the sync runs
- THEN the run fails naming the group, and no membership is dropped
- @e2e exclude covered by PHPUnit on the mapping resolver

### Requirement: SCIM provisioning creates, changes and deactivates accounts (REQ-DS-003)

Integriq MUST expose a SCIM 2.0 endpoint for `Users` and `Groups` that an
identity system calls to create, change and deactivate accounts, gated by its
own credential and rejecting an unauthenticated call before any read. An
authenticated call MUST additionally be authorised for what it writes: holding a
valid credential MUST NOT by itself permit a membership write, and REQ-DS-008 and
REQ-DS-009 constrain every such write. A deactivation MUST disable the Nextcloud
account and MUST NOT delete it.

#### Scenario: a leaver is deactivated the same day
- GIVEN an identity system that sends a SCIM deactivation for a user
- WHEN the call is processed
- THEN the Nextcloud account is disabled, remains present, and the act is recorded
- @e2e exclude a SCIM client cannot be staged in the browser; covered by Newman against the SCIM endpoint

#### Scenario: an unauthenticated SCIM call reads nothing
- GIVEN a SCIM request with no valid credential
- WHEN it arrives
- THEN it is rejected before any user is read and the rejection is logged
- @e2e exclude covered by Newman and PHPUnit on the endpoint

#### Scenario: a valid credential is not by itself permission to write a group
- GIVEN a SCIM request presenting the valid API key of a registered consumer
- WHEN it sends a membership write that REQ-DS-008 or REQ-DS-009 refuses
- THEN the call is refused although the credential is valid
- AND the refusal is distinguishable in the log from an authentication failure
- @e2e exclude covered by PHPUnit on the provisioning service

### Requirement: A leaver's open work is reported, never silently dropped (REQ-DS-004)

When a synchronisation or a SCIM call deactivates an account or removes its
last mapped group, integriq MUST report what that account still holds, by
asking the registered consumers rather than by reading their data itself. The
report MUST be visible on the run and MUST name the consumer that answered.
Integriq MUST NOT reassign anything.

#### Scenario: a leaver with a live case list is named
- GIVEN a deactivated account that dossiq reports as holding open cases
- WHEN the run finishes
- THEN the run report names the account, the count and the consumer that answered
- e2e: `tests/e2e/directory-sync.spec.ts`

#### Scenario: a consumer that does not answer is recorded as unknown
- GIVEN a consumer that is absent or does not implement the query
- WHEN the run finishes
- THEN the report reads `unknown` for that consumer and does not read zero
- @e2e exclude an absent-consumer path; covered by PHPUnit on the reporter

### Requirement: A run can be previewed, and a large removal is guarded (REQ-DS-005)

A directory sync MUST support a test run that writes nothing and reports what
it would change, per `synchronization-engine` REQ-011. A run whose removals
exceed the configured deletion ratio MUST stop before writing and report why,
per REQ-010. A stopped run MUST be resumable after an explicit confirmation.

#### Scenario: an administrator sees the changes before they happen
- GIVEN a directory whose groups changed
- WHEN a test run executes
- THEN the additions and removals are listed and no membership changed
- e2e: `tests/e2e/directory-sync.spec.ts`

#### Scenario: a directory outage does not empty the groups
- GIVEN a directory answering with a fraction of its users
- WHEN the sync runs and the removals exceed the configured ratio
- THEN the run stops before writing, names the ratio it hit, and nothing is removed
- @e2e exclude the guard is the engine's; covered by PHPUnit with a truncated fixture

### Requirement: Every run says what it changed (REQ-DS-006)

Each run MUST write a record carrying its start, its end, the counts of users
and groups read, added, changed and removed, and the per-item failures the
engine isolated under `synchronization-engine` REQ-008. A failed item MUST
NOT abort the run, and MUST be readable afterwards with its reason.

#### Scenario: an administrator reads yesterday's run
- GIVEN a completed run with two failed items
- WHEN the run is opened
- THEN its counts and both failures with their reasons are shown
- e2e: `tests/e2e/directory-sync.spec.ts`

#### Scenario: one bad record does not stop the run
- GIVEN a directory entry integriq cannot map
- WHEN the sync runs
- THEN the remaining entries are processed and the bad one is captured with its reason
- @e2e exclude per-item isolation is the engine's; covered by PHPUnit
