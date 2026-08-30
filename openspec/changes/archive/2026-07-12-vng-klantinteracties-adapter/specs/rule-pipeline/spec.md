# Rule Pipeline Specification

**Status**: in-progress
**Scope**: openconnector
**OpenSpec changes**:
- vng-klantinteracties-adapter

## Purpose

This delta adds two dialect-agnostic Rule mechanics to the ordered rule pipeline:
a composite transactional fan-out Rule type (used for VNG's composite
`maak-klantcontact`, reusable for any multi-object create), and a
`referentienummer` generation Rule that stamps a unique message reference on
emitted resources/responses. See the existing ordered-pipeline execution
(REQ-RULE-001) and data-mutation rules (REQ-RULE-002), and hydra ADR-031
(external-integration exception for gateway rules).

## ADDED Requirements

### Requirement: Composite transactional fan-out Rule type (REQ-RULE-006)

The rule pipeline MUST support a composite fan-out Rule type that, from a single
request body, performs multiple related object writes as one logical
transaction, rolling back all writes if any child write fails and returning a
single error. The Rule MUST run in the before-timing slot and MUST compose with
the ordered pipeline (REQ-RULE-001) without breaking the existing before/after
ordering.

@e2e exclude backend rule engine — covered by PHPUnit/Newman, no browser UI

#### Scenario: Composite fan-out creates all children atomically
- GIVEN a composite fan-out Rule configured for `maak-klantcontact` and a request body with a parent and two child objects
- WHEN the Rule runs
- THEN the parent and both children are created and the response is the created parent resource

#### Scenario: Any child failure rolls the whole composite back
- GIVEN a composite fan-out Rule and a request where the second child write fails
- WHEN the Rule runs
- THEN neither the parent nor the first child remains persisted and a single error is returned

#### Scenario: Composite Rule respects pipeline ordering
- GIVEN a composite fan-out Rule ordered alongside an AVG BSN policy Rule
- WHEN both are configured on the endpoint
- THEN the AVG policy Rule runs before the fan-out writes, per the ordered pipeline

### Requirement: `referentienummer` generation Rule (REQ-RULE-007)

The rule pipeline MUST support a Rule that generates and stamps a unique
`referentienummer` (message reference) on emitted resources/responses. The
default MUST be a UUIDv4; a configured numbering scheme MAY override it. The
generated reference MUST be stable for the response in which it is issued.

@e2e exclude backend rule engine — covered by PHPUnit, no browser UI

#### Scenario: Response carries a unique referentienummer
- GIVEN an endpoint with the `referentienummer` Rule enabled
- WHEN a resource is emitted
- THEN the response carries a unique `referentienummer` (UUIDv4 by default)

#### Scenario: Configured numbering scheme overrides the default
- GIVEN the `referentienummer` Rule configured with a municipality numbering scheme
- WHEN a resource is emitted
- THEN the reference follows the configured scheme instead of a UUIDv4

## Non-Functional Requirements

- **Performance:** the composite Rule MUST bound its writes to the resources named in the request (no unbounded cascade).
- **Internationalization:** rule error messages MUST be localisable (Dutch + English, hydra ADR-007).

## Acceptance Criteria

- The composite fan-out Rule creates related objects atomically and rolls back on any child failure.
- The `referentienummer` Rule stamps a unique reference (UUIDv4 default, scheme-overridable).

## Notes

- Both Rules are consumed by `vng-klantinteracties-adapter` and are dialect-agnostic gateway mechanics (ADR-031 external-integration exception).
