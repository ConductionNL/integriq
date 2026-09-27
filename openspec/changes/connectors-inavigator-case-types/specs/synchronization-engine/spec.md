# synchronization-engine Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- connectors-inavigator-case-types

## Purpose

A gated synchronization shows what it would create, change and remove before
an approver accepts it, and an accept writes exactly what was shown. Row
`integriq:nl-zgw-resync`.

## ADDED Requirements

### Requirement: A gated run stores its change set on the approval request (REQ-INAV-003)

When a run pauses at the approval gate of REQ-015, the engine MUST store on
the `approval_request` a change set listing, per object, whether it would be
created, changed with the fields that differ before and after, or removed,
plus a count of unchanged objects and a fingerprint of the set. Removals
MUST only be listed when REQ-010 would allow a deletion in that run. A run
MUST NOT write any target object while it builds the change set.

#### Scenario: an administrator sees what a case type re-import would change
- GIVEN a gated case type synchronization and a source where one case type gained a status type and one was withdrawn
- WHEN the run pauses for approval
- THEN the approval screen lists one changed case type with the added status type, one removed case type, and the number unchanged
- e2e: `tests/e2e/inavigator-case-types.spec.ts`

#### Scenario: an incomplete fetch lists no removals
- GIVEN a gated run whose fetch was incomplete
- WHEN the change set is built
- THEN it lists no removed objects, because REQ-010 forbids deletion in that run
- @e2e exclude an engine branch with no screen of its own; covered by PHPUnit on SynchronizationService

### Requirement: Accepting writes the previewed change set or asks again (REQ-INAV-004)

When an approved request resumes the run, the engine MUST rebuild the
change set and compare its fingerprint with the stored one. When they match,
the engine MUST write. When they differ, it MUST write nothing, record the
request as superseded, and open a new request with the new change set.

#### Scenario: the source changed after the preview
- GIVEN an approval request whose source changed between preview and accept
- WHEN the approver accepts
- THEN no case type is written, the request shows superseded, and a new request shows the new changes
- e2e: `tests/e2e/inavigator-case-types.spec.ts`

#### Scenario: an unchanged source is written as previewed
- GIVEN an approval request whose source did not change
- WHEN the approver accepts
- THEN exactly the previewed creates, changes and removals are written
- @e2e exclude write counts are checked on the run log; covered by PHPUnit
