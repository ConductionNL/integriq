# source-owned-records Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- records-owned-by-an-external-source

## Purpose

Integriq says who owns a record it maintains from an external source, what
happens to that record when the source stops carrying it, and what a person may
do to it locally. Row 5.19 of the competitor gap register, "Records owned by an
external source, with a policy for when they disappear", rated `no` for dossiq.

## ADDED Requirements

### Requirement: A record maintained from a source says who owns it (REQ-SOR-001)

Integriq MUST project, onto every object it maintains through a
synchronisation, the source it came from, its identifier at that source, the
ownership mode of that synchronisation, and the timestamp of the last complete
run that still saw it. The ownership mode MUST be one of `source`, `source with
local additions` or `local`. An object no synchronisation maintains MUST read
`local`. Integriq MUST NOT answer the ownership question by inference from the
presence of a contract alone.

#### Scenario: a person mirrored from the BRP says the BRP owns it
- GIVEN an object maintained by a synchronisation whose ownership mode is `source`
- WHEN the object is read
- THEN it carries the source, its identifier at that source, the mode `source` and the last-seen timestamp
- @e2e exclude a projected state on an object; covered by PHPUnit on the projection

#### Scenario: a hand-made record says nobody else owns it
- GIVEN an object no synchronisation maintains
- WHEN the object is read
- THEN it reads `local` and carries no source and no origin identifier
- @e2e exclude a projected state on an object; covered by PHPUnit on the projection

#### Scenario: before the first run the last-seen answer is unknown, not a guess
- GIVEN a contract written before this change and a synchronisation that has not run since
- WHEN the object is read
- THEN the last-seen state reads unknown and no timestamp is derived from the synchronisation's own `lastSync`
- @e2e exclude a migration-free default; covered by PHPUnit on the projection

### Requirement: What happens when a record disappears is declared, not hardcoded (REQ-SOR-002)

Each synchronisation MUST carry `sourceConfig.disappearancePolicy` with one of
`delete`, `markEnded` or `keepAndFlag`. The default MUST be `delete`, which is
the behaviour of `synchronization-engine` REQ-010 before this change. A value
the engine does not know MUST be refused when the synchronisation is saved,
naming the key and the values it accepts, and MUST NOT be treated as the
default.

#### Scenario: an existing synchronisation keeps deleting
- GIVEN a synchronisation that declares no policy
- WHEN a complete run finds a contract's object gone at the source
- THEN the object is deleted exactly as it was before this change, under the existing ratio guard
- @e2e exclude engine behaviour; covered by PHPUnit on the engine

#### Scenario: a misspelled policy is refused at save
- GIVEN an administrator saving a synchronisation with `disappearancePolicy` set to `remove`
- WHEN the save runs
- THEN it is refused, the message names the key and the three accepted values, and nothing is stored
- e2e: `tests/e2e/source-owned-records.spec.ts`

### Requirement: An ended record keeps its history and says when the source dropped it (REQ-SOR-003)

Under `markEnded` integriq MUST write an end date on the object, taken from the
run that first did not see it, and MUST NOT delete the object. Under
`keepAndFlag` integriq MUST leave the object's values untouched, MUST mark it as
no longer present at the source, and MUST record both the last run that saw it
and the run that did not. Under both policies the object MUST remain readable
and the count of affected objects MUST be reported on the run.

#### Scenario: an employee who left the directory is ended, not erased
- GIVEN a synchronisation with `markEnded` and an object the source no longer carries
- WHEN a complete run finishes
- THEN the object still exists, carries the end date of that run, and the run reports it in its counts
- e2e: `tests/e2e/source-owned-records.spec.ts`

#### Scenario: a person missing from the BRP is flagged for somebody to look at
- GIVEN a synchronisation with `keepAndFlag` and an object the source no longer carries
- WHEN a complete run finishes
- THEN the object's values are unchanged, it is marked as absent at the source with both timestamps, and the run's flagged count includes it
- e2e: `tests/e2e/source-owned-records.spec.ts`

#### Scenario: a record that comes back stops being flagged
- GIVEN a flagged object whose identifier appears again at the source
- WHEN the next complete run reads it
- THEN the flag is cleared, the last-seen timestamp advances, and the clearing is recorded on the run
- @e2e exclude engine behaviour; covered by PHPUnit on the engine

### Requirement: No policy runs on a fetch that was not complete (REQ-SOR-004)

Integriq MUST apply the disappearance policy only when the run's fetch was
complete under `synchronization-engine` REQ-009 and the deletion ratio guard of
REQ-010 did not stop the run. `markEnded` and `keepAndFlag` MUST be gated the
same way as `delete`. Integriq MUST NOT end, flag or delete an object because a
page was truncated, a source returned 429, or an incremental run did not carry
the whole set.

#### Scenario: a truncated page ends nobody
- GIVEN a synchronisation with `markEnded` whose fetch is marked incomplete
- WHEN the run finishes
- THEN no end date is written, no object is flagged, and the run records that the policy was skipped and why
- @e2e exclude a guarded path; covered by PHPUnit on the engine

#### Scenario: the ratio guard stops flagging too
- GIVEN a synchronisation with `keepAndFlag` and a complete fetch that would affect more objects than the configured ratio allows
- WHEN the run reaches the policy
- THEN nothing is flagged, the run names the ratio it hit, and an override is still required to proceed
- @e2e exclude a guarded path; covered by PHPUnit on the engine

### Requirement: A local delete of a source-owned record is refused unless somebody says why (REQ-SOR-005)

A delete of an object whose ownership mode is `source` or `source with local
additions` MUST be refused, and the refusal MUST name the synchronisation that
maintains it. The delete MUST be possible with an explicit override carrying a
reason, the user and the timestamp, all recorded on the object. An override
without a reason MUST be refused.

#### Scenario: a handler cannot quietly remove a BRP person
- GIVEN an object whose ownership mode is `source`
- WHEN a user deletes it
- THEN the delete is refused and the message names the synchronisation that maintains the object
- e2e: `tests/e2e/source-owned-records.spec.ts`

#### Scenario: an override is a written statement
- GIVEN the same object and a user who overrides the refusal with a typed reason
- WHEN the delete runs
- THEN the object is deleted and the override's reason, user and timestamp are recorded and readable afterwards
- e2e: `tests/e2e/source-owned-records.spec.ts`

#### Scenario: an override with an empty reason is refused
- GIVEN the same object and an override carrying no reason
- WHEN the delete runs
- THEN it is refused, nothing is deleted, and the message says a reason is required
- @e2e exclude a validation path; covered by PHPUnit on the guard

### Requirement: The consuming app reads ownership through one contract (REQ-SOR-006)

Integriq MUST answer, for an object reference, its ownership mode, its source,
its origin identifier, its last-seen timestamp and its absence state, through
one documented read. A consuming app MUST NOT need to read a contract, a
synchronisation or a source to render that answer, and integriq MUST NOT write
the answer onto anyone else's record.

#### Scenario: dossiq renders a party as the registry's
- GIVEN an object maintained by a synchronisation with mode `source`
- WHEN a consuming app asks for its ownership
- THEN one answer carries the mode, the source, the origin identifier, the last-seen timestamp and the absence state
- @e2e exclude a read contract; covered by PHPUnit on the read service

#### Scenario: an unknown object answers local rather than failing
- GIVEN an object reference no synchronisation maintains
- WHEN a consuming app asks for its ownership
- THEN the answer reads `local` and the call succeeds
- @e2e exclude a read contract; covered by PHPUnit on the read service
