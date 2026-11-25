# staff-timetable-sync Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- connectors-hr-staff-sync

## Purpose

Integriq keeps the timetabling system's teachers and teacher absences in step
with the HR system, pushing each change as it happens and repairing drift
every night, while only the fields a timetable needs leave the HR register.
Row `planninq:tt-hr-sync`.

## ADDED Requirements

### Requirement: A new or departing employee reaches Untis without a manual step (REQ-HRS-001)

Integriq MUST ship a `staff-to-untis` set whose synchronizations run when a
humaniq `Employee` or `OrgAssignment` changes. An employee with an active
assignment MUST exist as an Untis teacher. An assignment that ends MUST make
the teacher inactive in Untis on its end date, and MUST NOT delete the
teacher. Every Untis call MUST go through the credential broker.

#### Scenario: a new teacher is on the timetable the day they start
- GIVEN a humaniq administrator who adds an employee with an assignment at a school starting on 1 August
- WHEN the employee is saved
- THEN the teacher exists in Untis with name, short code and e-mail, and the run log shows the push
- e2e: `tests/e2e/staff-to-untis.spec.ts`

#### Scenario: a leaver is made inactive, not deleted
- GIVEN a teacher whose assignment ends on 30 June
- WHEN the end date is saved
- THEN Untis marks the teacher inactive from 30 June, and lessons they gave before keep their teacher
- @e2e exclude a state on the Untis side; covered by PHPUnit against the mock target

### Requirement: Only approved leave becomes an Untis teacher absence (REQ-HRS-002)

A humaniq `LeaveRequest` MUST become an Untis teacher absence only when its
status is `approved`, carrying its dates and a neutral reason code. A leave
request that moves from approved to rejected, or is deleted, MUST remove the
absence. A draft or submitted request MUST NOT reach Untis.

#### Scenario: approved leave blocks the timetable
- GIVEN a teacher's leave request for 3 to 7 March
- WHEN the manager approves it in humaniq
- THEN Untis shows a teacher absence for 3 to 7 March with a neutral reason, and no leave type or note
- e2e: `tests/e2e/staff-to-untis.spec.ts`

#### Scenario: a submitted request stays in humaniq
- GIVEN a leave request with status `submitted`
- WHEN it is saved
- THEN nothing is sent to Untis
- @e2e exclude a negative on an outbound call; covered by PHPUnit on the synchronization trigger

### Requirement: Only allow-listed fields leave the HR register (REQ-HRS-003)

Every mapping in the set MUST name each target field and read only those
source fields. A mapping in the set MUST NOT use pass-through. BSN, salary,
bank account, contract wage and identity document fields MUST NOT reach
Untis.

#### Scenario: a BSN never leaves humaniq
- GIVEN an employee with a BSN and an IBAN
- WHEN the employee is pushed to Untis
- THEN the request to Untis carries neither
- @e2e exclude an outbound payload check; covered by PHPUnit on the mapping and the set's install check

### Requirement: A nightly run repairs drift without making teachers inactive on a partial read (REQ-HRS-004)

A nightly job MUST compare humaniq's employees and approved leave with
Untis's teachers and absences and write the differences. It MUST NOT make any
teacher inactive when its read of Untis was incomplete, and MUST list
unmatched Untis teachers in the run log instead of creating duplicates.

#### Scenario: a push missed during an outage is repaired
- GIVEN an employee added while Untis was unreachable
- WHEN the nightly run completes
- THEN the teacher exists in Untis, and the run log shows the repair
- e2e: `tests/e2e/staff-to-untis.spec.ts`

#### Scenario: an interrupted read changes nothing
- GIVEN a nightly run whose read of Untis stopped halfway
- WHEN the run finishes
- THEN no teacher is made inactive, and the log says the read was incomplete
- @e2e exclude a fetch completeness branch; covered by PHPUnit against REQ-009
