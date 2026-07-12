# Mapping and Search Specification

**Status**: in-progress
**Scope**: openconnector
**OpenSpec changes**:
- vng-klantinteracties-adapter

## Purpose

This delta adds VNG REST query-language translation to the search filter
compiler: double-underscore lookup operators and `partijIdentificator`-style
nested filters translated onto OpenRegister search, and `expand=` relation
embedding. Both are dialect-agnostic — the VNG Klantinteracties adapter is the
first consumer, but zaken and contactmomenten v1 reuse them. See ADR-022
(mapping engine largely in OpenRegister) and hydra ADR-031.

## ADDED Requirements

### Requirement: Translate VNG list-filter operators onto OpenRegister search (REQ-006)

The search compiler MUST translate VNG list-filter semantics into OpenRegister
search filters: double-underscore lookup operators (e.g. `field__icontains`,
`field__gte`) MUST map to the equivalent OpenRegister filter operators, and
nested identifier filters (e.g.
`partijIdentificator__codeSoortObjectId=bsn` combined with
`partijIdentificator__objectId=<hash>`) MUST resolve against the stored
(hash-backed) identity rather than a raw value.

@e2e exclude backend search compiler — covered by PHPUnit/Newman, no browser UI

#### Scenario: Double-underscore operator maps to a search filter
- GIVEN a request query `achternaam__icontains=moulin`
- WHEN the compiler translates it
- THEN it produces an OpenRegister case-insensitive contains filter on the mapped field

#### Scenario: partijIdentificator filter resolves against hashed identity
- GIVEN a request query `partijIdentificator__codeSoortObjectId=bsn`
- WHEN the compiler translates it
- THEN it constrains the search to BSN-typed identities and matches on the stored hash, never requiring a raw BSN in the query path

#### Scenario: Unknown operator is rejected, not silently ignored
- GIVEN a request query with an unsupported lookup operator
- WHEN the compiler translates it
- THEN it returns a clear error rather than dropping the filter and returning an over-broad result set

### Requirement: `expand=` relation embedding (REQ-007)

The search compiler MUST support VNG `expand=` semantics — embedding named
related resources inline in a list or detail response — by resolving the named
relations through the OpenRegister object shim and inlining them under the VNG
relation keys. The embedding depth MUST be bounded (documented cap) to keep the
search cost predictable.

@e2e exclude backend search compiler — covered by PHPUnit/Newman, no browser UI

#### Scenario: expand embeds a named relation inline
- GIVEN a request `GET /partijen?expand=digitaleAdressen`
- WHEN the compiler resolves the response
- THEN each partij carries its `digitaleAdressen` embedded inline rather than as bare references

#### Scenario: expand depth is bounded
- GIVEN a request that requests nested expansion beyond the documented depth cap
- WHEN the compiler resolves it
- THEN expansion stops at the cap and the response documents the truncation rather than fanning out unboundedly

## Non-Functional Requirements

- **Performance:** filter translation and bounded `expand=` MUST not add unbounded query fan-out; the depth cap is the guard.
- **Internationalization:** filter/expand errors MUST be localisable (Dutch + English, hydra ADR-007).

## Acceptance Criteria

- VNG double-underscore operators and `partijIdentificator` filters translate to OpenRegister search against hashed identities.
- `expand=` embeds named relations inline with a bounded depth.

## Notes

- Consumed by `vng-klantinteracties-adapter`; deliberately dialect-agnostic.
