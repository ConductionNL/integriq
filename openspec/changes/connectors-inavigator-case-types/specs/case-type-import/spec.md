# case-type-import Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- connectors-inavigator-case-types

## Purpose

Integriq imports case types and products from i-Navigator into the case type
schema an operator picks, with every custom attribute, through a gated
synchronization whose changes are previewed before they land. Row
`integriq:con-inavigator`.

## ADDED Requirements

### Requirement: The i-Navigator interface is pinned against a real export before the import is built (REQ-INAV-001)

The design MUST record which interface i-Navigator publishes its case types
over, and the export or document it was checked against, before the source
template and mapping are written. When no published interface can be
checked, the import MUST NOT ship.

#### Scenario: the interface is recorded before code
- GIVEN the i-Navigator import task
- WHEN a reviewer opens the change design
- THEN it names the interface and the export or document it was checked against
- @e2e exclude a design gate on the change; checked in review

### Requirement: An administrator imports i-Navigator case types with all their attributes (REQ-INAV-002)

Integriq MUST ship a dormant i-Navigator source template, a synchronization
and a mapping that write each i-Navigator case type onto the case type
schema the operator picks. Every custom attribute MUST arrive as an entry in
the case type's `eigenschappen`, and an attribute added in i-Navigator MUST
arrive on the next run without a mapping edit. The synchronization MUST be
gated by `requiresApproval`.

#### Scenario: a municipality imports its case types
- GIVEN an administrator who instantiated the i-Navigator template, pointed it at the municipality's i-Navigator and picked dossiq's case type schema
- WHEN the first run is approved
- THEN each i-Navigator case type exists in the case type catalogue with all of its attributes
- e2e: `tests/e2e/inavigator-case-types.spec.ts`

#### Scenario: a new attribute arrives without a mapping edit
- GIVEN an imported case type and a new attribute added to it in i-Navigator
- WHEN the next run is approved
- THEN the case type carries the new attribute, and the mapping was not edited
- e2e: `tests/e2e/inavigator-case-types.spec.ts`
