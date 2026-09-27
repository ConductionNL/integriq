# synchronization-engine Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- synchronisation-source-destruction-purge

## Purpose

When a source destroys a record, the object synchronized from it is removed
with its files, at once on a destruction notice or on the next complete run,
and the removal is recorded where it outlives the object. Row
`opencatalogi:lc-source-destroyed`.

## ADDED Requirements

### Requirement: A synchronization can purge a vanished record and its files (REQ-SDP-001)

The disappearance policy MUST accept `purge`. Under `purge`, a target object
whose source record is gone MUST be deleted permanently, including its
files, and MUST keep every guard a delete has: no purge in incremental mode,
on an incomplete fetch, or past the deletion ratio.

#### Scenario: a destroyed Woo document leaves no file behind
- GIVEN a publication synchronization with `disappearancePolicy` `purge` and a published document with an attached PDF
- WHEN the source no longer lists the document and a complete full run finishes
- THEN the publication object and its PDF are gone from OpenRegister and from Nextcloud Files, and the run reports one purged object
- e2e: `tests/e2e/source-destruction-purge.spec.ts`

#### Scenario: an incomplete run purges nothing
- GIVEN a `purge` synchronization whose fetch stopped halfway
- WHEN the run finishes
- THEN no object is purged, and the run log gives the incomplete fetch as the reason
- @e2e exclude a fetch completeness branch; covered by PHPUnit on SynchronizationService

### Requirement: A destruction notice purges one object without a full run (REQ-SDP-002)

A ZGW notification with `actie` `destroy`, or a signed call to
`POST /api/synchronizations/{id}/destroyed`, MUST resolve the one
synchronization contract whose `originId` names the destroyed record and,
when the synchronization's `onSourceDestroyed` is `purge`, purge that object
at once. Otherwise it MUST apply the synchronization's disappearance policy
to that object. It MUST NOT run a full synchronization and MUST NOT touch an
object without a contract. An unsigned or wrongly signed call MUST be refused
before its body is read.

#### Scenario: the DMS destroys a document and the publication follows
- GIVEN a publication synchronization with `onSourceDestroyed` `purge` and a ZGW abonnement on the source
- WHEN the source's Notificaties component sends `actie` `destroy` for the document
- THEN the publication made from it is purged with its files within the same request, and no other publication changes
- e2e: `tests/e2e/source-destruction-purge.spec.ts`

#### Scenario: a forged destruction call is refused
- GIVEN a call to the destroyed route with a bad signature
- WHEN it arrives
- THEN it is refused before the body is read, and nothing is deleted
- @e2e exclude a refused public request; covered by PHPUnit on the controller

### Requirement: Every purge is recorded and a refused purge stays visible (REQ-SDP-003)

Each purge MUST write a contract log entry naming the synchronization, the
source record, the purged object and the trigger, and MUST keep the contract
with `targetLastAction` `purge`. When OpenRegister refuses the permanent
delete, the engine MUST record the refusal and leave the object as it was,
and MUST NOT fall back to a soft delete.

#### Scenario: an auditor finds what was purged
- GIVEN a purge on a destruction notice last week
- WHEN an administrator opens the synchronization's contract logs
- THEN the entry shows the source record, the purged object id, the notice and the time
- e2e: `tests/e2e/source-destruction-purge.spec.ts`

#### Scenario: a restricted object is not quietly soft deleted
- GIVEN a publication another object restricts
- WHEN a purge is attempted
- THEN the publication stays with its files, and the run lists the refusal with OpenRegister's reason
- @e2e exclude a referential integrity branch; covered by an integration test against OpenRegister
