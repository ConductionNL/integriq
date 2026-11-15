---
kind: code
depends_on: []
---

# Proposal: directory-and-group-sync

## Summary

Users and groups come from the customer's directory and stay in step with it,
so a lost group membership removes the access rather than leaving it behind.
Nextcloud keeps the accounts. Integriq keeps the connection, the mapping, the
schedule and the record of what each run changed.

## Motivation

Round 4 discovery, cluster 33, "Directory synchronisation and one place for
access" (`procest/_round4/discovery/build-plan.md` in
ConductionNL/market-intelligence, 2026-09-14). Three candidates, eight
passers, six driven and two documented, proving system glpi. Owner integriq,
size M, wave 3, no decision. One matrix hole.

| candidate | relevance | dossiq | what it asks |
|---|---|---|---|
| C-access-and-privacy-82 | must, matrix hole | no | users and groups taken from the directory and kept in step, so losing a group membership removes the access |
| C-integrations-34 | should | no | staff accounts created, changed and deactivated by the identity system over SCIM |
| C-integrations-21 | could | no | the product acts as an identity provider for another product |

Directory synchronisation is **number 7 of the twenty-five loudest** in
`_round4/discovery/found-and-lacking.md`, with four driven passers: "7.
Directory user and group synchronisation (4)". The list is ordered by `must`
first, then driven passers, and its own framing is "Each one is a question a
gemeente asks out loud".

The lane's evidence, verbatim from `_round4/discovery/candidates.json`,
C-access-and-privacy-82, `access-and-privacy.tsv:25`: "glpi: Directory import
(front/authldap.php, ldap.import.php, ldap.group.import.php,
ldap.group.php)". Its sources are D-glpi-43 for GLPI and **D-vikunja-6** for
Kanboard and Vikunja, with D-zammad-21 for Zammad. The note says what
separates a passer from an authenticator: "All three sync membership
continuously rather than only authenticating against the directory."

dossiq reads `no` three times, and the third is the one that matters:
"`roleType.ncGroupId` points at a Nextcloud group and nothing provisions it".
The second: "`lib/Repair/ProvisionAssignedGroups.php` provisions from local
config". Authorisation is already expressed in Nextcloud groups, and nothing
fills them from the customer's directory.

C-integrations-34's clause is the audit argument: "in- en uitdiensttreding is
where stale accounts come from, and a leaver with a live case list is the
finding an auditor writes up". Its passer: "openproject: Administration SCIM
clients, resources :scim_clients, namespace :scim_v, scimitar gem".

## What integriq does not hold

Nextcloud holds the accounts. The build plan says so in one line: "extend
integriq's SCIM and directory sync; Nextcloud holds the accounts". Integriq
creates no user store, no second password and no second group model. It moves
membership into the group model Nextcloud already has, and every consumer
keeps reading `IGroupManager`.

## What this change does not build, and why

**C-integrations-21, acting as an identity provider for another product.**
The lane admitted the candidate to record a distinction, not to ask for the
capability, and said so: "the direction a gemeente wants is the other one,
and it is worth a row so that the distinction is recorded rather than blurred
into 12.7". dossiq's own note answers it: "partial, Nextcloud is an OAuth
provider". Nextcloud issues the identity, and integriq's
`digid-eherkenning-auth-adapter` stays "the only component in the fleet" that
holds the government IdP conversation. Nothing is built, and the candidate is
recorded so it is not rediscovered.

## What integriq builds and what dossiq consumes

Integriq builds the connection and the run. dossiq consumes it without
knowing it exists: its `roleType.ncGroupId` points at a Nextcloud group, and
that group is now filled from the directory instead of from local
configuration. `lib/Repair/ProvisionAssignedGroups.php` keeps its job of
seeding, and stops being the only thing that ever writes a membership.

## The existing specs this extends

- `synchronization-engine`, REQ-001 orchestration and direction routing,
  REQ-008 per-item isolation and dead-letter capture, REQ-010 the
  deletion-ratio guard, REQ-011 test runs make no writes. A directory sync is
  a synchronisation, and it reuses all four rather than growing a second
  engine.
- `source-management`: the directory is a source, tested from the Sources
  screen like any other, with a mock-mode fixture.
- `organisation-bridge`: the OpenRegister organisation view a synced group
  may feed, through the existing soft-fail accessor.
- `logs-and-statistics`, REQ-001 and REQ-003: the run log and the per-source
  call log a sync run already writes to.
- `digid-eherkenning-auth-adapter`: the boundary this change does not cross.

## Size and dependencies

Size M, as the build plan rates it. It waits on nothing.

## Out of scope

- Authentication. Nextcloud's own LDAP and SSO backends authenticate; this
  change synchronises membership, which the lane's note calls the difference
  that separates a passer from an authenticator.
- Roles and their provenance, cluster 11, openregister. A synced group is an
  input to a grant, not a grant.
- Acting as an identity provider, C-integrations-21, recorded above.
