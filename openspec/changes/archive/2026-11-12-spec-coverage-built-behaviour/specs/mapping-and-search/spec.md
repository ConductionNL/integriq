# mapping-and-search Specification

## ADDED Requirements

### Requirement: One mapping is reused by id (REQ-SCB-007)

A mapping MUST be its own object, referenced by id. A synchronization, an endpoint rule and a flow step MUST be able to point at the same mapping. A change to that mapping MUST apply everywhere it is referenced.

#### Scenario: a synchronization and a flow share a mapping
- GIVEN a mapping referenced by a synchronization and by a flow's apply-mapping step
- WHEN an administrator renames one output field in the mapping
- THEN the next synchronization run and the next flow run both write the new field name
- @e2e exclude cross-reference resolution; covered by PHPUnit

### Requirement: The unset list leaves fields out (REQ-SCB-008)

A mapping MAY list fields to unset. Integriq MUST remove every listed field from the mapped output, also when the field came through a pass-through copy of the input.

#### Scenario: an internal field is not passed on
- GIVEN a pass-through mapping that unsets `interneNotitie`
- WHEN a record with `interneNotitie` is mapped
- THEN the output carries every other field and no `interneNotitie`
- @e2e exclude mapping engine; covered by PHPUnit on MappingService
