# Endpoint Runtime Dispatch Specification

**Status**: in-progress
**Scope**: openconnector
**OpenSpec changes**:
- vng-klantinteracties-adapter

## Purpose

This delta adds two dialect-agnostic gateway behaviours to the endpoint dispatch
pipeline, needed by the VNG Klantinteracties adapter and reusable by every future
VNG REST dialect (zaken, contactmomenten v1, klachten): absolute self-URL / HAL
`_links` rendering on emitted resources, and enforcement of the REST contract
that PUT requires all mandatory fields while PATCH is a partial update. See
ADR-008 (polymorphic targetType) and hydra ADR-031 (external-integration
exception for gateway mechanics).

## ADDED Requirements

### Requirement: Absolute self-URL and HAL `_links` rendering helper (REQ-EP-006)

The endpoint runtime MUST provide a generic output helper that renders an
absolute `url` self-link and HAL `_links` on emitted resources, derived from
`IURLGenerator` and the resolved endpoint path so the value is correct across
hosts and environments. The helper MUST be selectable per Endpoint (via an
after-Rule / output flag) and MUST NOT require the value to be hard-coded in a
Mapping.

@e2e exclude backend output rendering — covered by PHPUnit/Newman, no browser UI

#### Scenario: Emitted resource carries an absolute self-URL
- GIVEN an Endpoint with the self-URL/HAL output helper enabled
- WHEN a resource is emitted from the dispatch pipeline
- THEN the resource carries an absolute `url` self-link built from the request host and the resolved endpoint path

#### Scenario: HAL `_links` reflect the resource and its relations
- GIVEN a resource with related sub-resources
- WHEN the self-URL/HAL helper renders it
- THEN a `_links.self.href` and relation links are present as absolute URLs

#### Scenario: Self-URL is stable across environments
- GIVEN the same object served from two environments with different hosts
- WHEN each renders the resource
- THEN each `url` reflects its own host (no hard-coded host leaks from a Mapping)

### Requirement: PUT-all-mandatory vs PATCH-partial enforcement (REQ-EP-007)

The endpoint runtime MUST enforce, per the VNG/REST contract, that a PUT request
supplies all mandatory fields of the target schema (rejecting the request when a
mandatory field is absent) while a PATCH request applies a partial update
(leaving unspecified fields unchanged). The behaviour MUST be selectable per
Endpoint so non-VNG endpoints are unaffected.

@e2e exclude backend dispatch semantics — covered by PHPUnit/Newman, no browser UI

#### Scenario: PUT without a mandatory field is rejected
- GIVEN an Endpoint with PUT/PATCH semantics enabled and a PUT body missing a mandatory field
- WHEN the request is dispatched
- THEN the request is rejected with a validation error and the object is not modified

#### Scenario: PATCH updates only the supplied fields
- GIVEN an existing object and a PATCH body supplying a subset of fields
- WHEN the request is dispatched
- THEN only the supplied fields are updated and all other fields retain their prior values

#### Scenario: Semantics are opt-in per endpoint
- GIVEN an endpoint without PUT/PATCH semantics enabled
- WHEN a PUT request omits a mandatory field
- THEN the existing (pre-change) dispatch behaviour applies unchanged

## Non-Functional Requirements

- **Performance:** the output helper MUST add negligible per-response overhead (URL construction only, no extra storage round-trip).
- **Internationalization:** validation errors MUST be localisable (Dutch + English, hydra ADR-007).

## Acceptance Criteria

- Emitted VNG resources carry absolute `url` self-links and HAL `_links`.
- PUT rejects missing mandatory fields; PATCH performs partial updates; both are per-endpoint opt-in.

## Notes

- Both behaviours are consumed by the `vng-klantinteracties-adapter` capability and are intentionally dialect-agnostic.
