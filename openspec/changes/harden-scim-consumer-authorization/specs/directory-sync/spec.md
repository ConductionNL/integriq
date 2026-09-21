# directory-sync Specification

**Status**: in-progress
**Scope**: integriq
**OpenSpec changes**:
- directory-and-group-sync
- harden-scim-consumer-authorization

## Purpose

Integriq keeps Nextcloud's users and groups in step with the customer's directory.
This delta closes the gap between authenticating a SCIM caller and authorising one:
the endpoint proved a caller held a valid credential but never constrained what that
caller could do with it, so any consumer API key could write the `admin` group.

## ADDED Requirements

### Requirement: A SCIM call is answered as a named consumer (REQ-DS-007)

Integriq MUST resolve the presented SCIM credential to a specific consumer and MUST
make that identity available to the route handling the call. A call that cannot be
resolved to exactly one consumer MUST be refused. Every accepted call and every
refusal MUST be logged naming the resolved consumer, so that an operator can answer
"who did this" from the log alone.

The resolution MUST NOT be reported to the caller in any form that distinguishes one
failure from another — a refusal remains undifferentiated to the caller while being
fully attributed in the log.

#### Scenario: an accepted call names its consumer in the log
- GIVEN a SCIM request presenting the API key of a registered consumer
- WHEN the call is authorised
- THEN the resolved consumer is available to the route
- AND the log line for the call names that consumer
- @e2e exclude a SCIM client cannot be staged in the browser; covered by PHPUnit on the controller

#### Scenario: an unresolvable credential is refused without hinting why
- GIVEN a SCIM request presenting a credential matching no consumer
- WHEN it arrives
- THEN it is refused before any account is read
- AND the response body is identical to the body returned for a missing credential
- AND the refusal is logged
- @e2e exclude covered by PHPUnit on the controller

### Requirement: SCIM MUST NOT write the administrator group (REQ-DS-008)

Integriq MUST refuse any SCIM membership write targeting the Nextcloud `admin`
group, whatever credential the caller presents and whatever the instance's
configuration says. This refusal MUST NOT be configurable, MUST be enforced in the
provisioning service rather than in the controller, and MUST take effect before any
membership is read or written.

The refusal MUST cover both directions: adding a member to `admin`, and removing
members from `admin` by omitting them from a reconciling write.

Scope, stated because the implementation's comment previously overstated it: this
requirement governs the SCIM route. The directory-sync route
(`DirectorySyncService::write()`, driven by an admin-gated controller and a system
background job) writes group membership through `IGroupManager` directly and is not
covered by this assertion. That is a narrower exposure — it needs an administrator
or the system — but it is not closed, and a shared assertion both routes call is
tracked separately rather than assumed here.

#### Scenario: a consumer cannot make itself an administrator
- GIVEN a SCIM request presenting a valid consumer API key
- WHEN it sends a membership write naming the `admin` group and listing a user
- THEN the write is refused before any membership is changed
- AND the user is not added to `admin`
- AND the refusal is logged naming the consumer and the group
- @e2e exclude covered by PHPUnit on the provisioning service

#### Scenario: a consumer cannot empty the administrator group
- GIVEN a SCIM request presenting a valid consumer API key
- GIVEN an `admin` group with at least one member
- WHEN it sends a reconciling membership write naming `admin` with an empty member list
- THEN the write is refused
- AND every existing member of `admin` remains a member
- @e2e exclude covered by PHPUnit on the provisioning service

### Requirement: SCIM writes only the groups a connection declares it manages (REQ-DS-009)

Integriq MUST confine a SCIM membership write to the groups declared as managed by
the directory connections configured on the instance — the union of
`managedGroups()` across all directory connections. A write naming a group outside
that set MUST be refused before any membership is changed, and the refusal MUST name
the group so that an operator can see which mapping to extend.

This requirement constrains what a valid caller may do; it does not distinguish
between callers. Confining an individual consumer to a subset of the managed set is
out of scope and requires a permission property on the `consumer` schema.

#### Scenario: an unmanaged group is refused and names itself
- GIVEN a directory connection whose mapping declares `vergunningen` as managed
- GIVEN a SCIM request presenting a valid consumer API key
- WHEN it sends a membership write naming the group `finance`
- THEN the write is refused before any membership is changed
- AND the refusal names `finance`
- AND the refusal is logged naming the consumer
- @e2e exclude covered by PHPUnit on the provisioning service

#### Scenario: a managed group is written normally
- GIVEN a directory connection whose mapping declares `vergunningen` as managed
- GIVEN a SCIM request presenting a valid consumer API key
- WHEN it sends a membership write naming `vergunningen`
- THEN the membership is reconciled as before this change
- @e2e exclude covered by PHPUnit on the provisioning service

#### Scenario: no configured connection means no writable group
- GIVEN an instance with no directory connection configured
- GIVEN a SCIM request presenting a valid consumer API key
- WHEN it sends any membership write
- THEN the write is refused
- AND no group is created or modified
- @e2e exclude covered by PHPUnit on the provisioning service

## MODIFIED Requirements

### Requirement: SCIM provisioning creates, changes and deactivates accounts (REQ-DS-003)

Integriq MUST expose a SCIM 2.0 endpoint for `Users` and `Groups` that an
identity system calls to create, change and deactivate accounts, gated by its
own credential and rejecting an unauthenticated call before any read. An
authenticated call MUST additionally be authorised for what it writes: holding a
valid credential MUST NOT by itself permit a membership write, and the constraints
of REQ-DS-008 and REQ-DS-009 apply to every such write. A deactivation MUST disable
the Nextcloud account and MUST NOT delete it.

<!-- Previous behavior: the requirement asked only that the endpoint be "gated by its
     own credential and rejecting an unauthenticated call before any read". It
     required authentication and never required authorisation, so an implementation
     that let any valid credential write any group satisfied it. -->

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

## Non-Functional Requirements

- **Performance:** The managed-group lookup MUST NOT add a per-member query. It is
  resolved once per membership write, not once per user in the incoming list.
- **Accessibility:** Not applicable — this capability has no user interface surface.
- **Internationalization:** Refusal text returned to a machine caller is a SCIM
  protocol string and is not translated. Any refusal surfaced in the run report UI
  MUST be available in Dutch and English (hydra ADR-007).

## Acceptance Criteria

- A membership write naming `admin` is refused for every caller, including one whose credential is valid
- A membership write naming `admin` with an empty member list leaves existing administrators in place
- A membership write naming a group outside the managed set is refused and the refusal names the group
- A membership write naming a managed group behaves exactly as it did before this change
- An accepted SCIM call and a refused one are both attributable to a named consumer in the log
- A refused credential and a refused authorisation are indistinguishable to the caller and distinguishable in the log

## Notes

- The `admin` refusal is deliberately placed in `ScimProvisioningService` rather than
  in `ScimController`, so that it holds for every caller of the service rather than
  for the routes that remember to ask. The controller gate is convenience; the
  service refusal is the guarantee.
- Nextcloud admin-delegation groups beyond the literal `admin` group are not covered.
  `IGroupManager` exposes `isAdmin()` and `isDelegatedAdmin()` for a user, but
  enumerating delegated-admin groups requires another app's private API
  (`OCA\Settings\Service\AuthorizedGroupService`). A delegated-admin group remains
  reachable only if an operator has declared it managed by a directory connection.
- REQ-DS-009 constrains what any valid caller may do, not what a particular consumer
  may do. Per-consumer scoping needs a permission property on the `consumer` schema,
  which does not exist today — tracked as a separate change.
