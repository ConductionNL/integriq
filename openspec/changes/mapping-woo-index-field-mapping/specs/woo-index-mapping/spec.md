# woo-index-mapping Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- mapping-woo-index-field-mapping

## Purpose

A sibling app runs an integriq mapping by slug through a typed event and reads
the result, and integriq ships an editable mapping of a publication onto the
Woo-index fields that opencatalogi can run. Matrix row
`opencatalogi:woo-metadata-map`, integriq's half.

## ADDED Requirements

### Requirement: A sibling app runs a mapping by slug through a typed event (REQ-WOOM-001)

Integriq MUST provide a public `MappingExecutionRequestedEvent` carrying a
mapping slug, an input array, the requesting app id and a correlation id, and
MUST answer it synchronously by setting the mapped output on the event. When
the slug resolves to no mapping, integriq MUST refuse with code `not-found`
and MUST NOT set an output.

#### Scenario: opencatalogi maps a publication
- GIVEN the seeded mapping `woo-index-publication` with `callableBy` containing `opencatalogi`
- WHEN opencatalogi dispatches the event with that slug and a publication whose title is `Besluit parkeerbeleid`
- THEN the event carries an output with `officieleTitel` `Besluit parkeerbeleid` and `isHandled()` is true
- @e2e exclude an in-process event between two apps; covered by PHPUnit on MappingExecutionRequestedListener with the real MappingService

#### Scenario: an unknown slug is refused
- GIVEN no mapping with slug `does-not-exist`
- WHEN a sibling app dispatches the event with it
- THEN the event is refused with code `not-found` and carries no output
- @e2e exclude an in-process event; covered by PHPUnit

### Requirement: A mapping names the apps allowed to run it by event (REQ-WOOM-002)

A mapping MUST carry `callableBy`, a list of app ids, empty by default.
Integriq MUST refuse a `MappingExecutionRequestedEvent` from an app not on the
list with code `not-allowed`, and MUST NOT run the mapping. Changing
`callableBy` MUST need the same right as changing the mapping's rules.

#### Scenario: a mapping written for a synchronization is not callable
- GIVEN the seeded mapping `lowercase-keys` with no `callableBy`
- WHEN a sibling app dispatches the event with its slug
- THEN the event is refused with code `not-allowed` and the mapping does not run
- @e2e exclude an in-process event; covered by PHPUnit

### Requirement: Integriq seeds an editable Woo-index mapping (REQ-WOOM-003)

Integriq MUST seed a mapping `woo-index-publication` mapping a publication onto
`publisher`, `officieleTitel`, `informatiecategorie` and `soortHandeling`. The
mapping MUST be editable on the mapping detail page without code, and a
re-import of the seed MUST NOT overwrite an administrator's edit.

#### Scenario: an administrator changes where the official title comes from
- GIVEN an administrator on the detail page of `woo-index-publication`
- WHEN they change the `officieleTitel` rule to read the publication's `summary` and save
- THEN the mapping test with a sample publication returns the summary as `officieleTitel`
- e2e: tests/e2e/woo-index-mapping.spec.ts

#### Scenario: an upgrade keeps the edit
- GIVEN an administrator edited the `officieleTitel` rule
- WHEN integriq is upgraded and the seed runs again
- THEN the edited rule is unchanged
- @e2e exclude an upgrade path; covered by an integration test on the seed import
