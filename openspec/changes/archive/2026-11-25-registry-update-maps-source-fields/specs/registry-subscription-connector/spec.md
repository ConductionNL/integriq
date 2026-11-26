# registry-subscription-connector Specification

## ADDED Requirements

### Requirement: A change is posted in each target schema's own property names (REQ-RSC-004)

A provider whose source names fields differently from a requesting schema MUST declare, per target schema slug, which source field becomes which schema property. When a subscription goes active, integriq MUST record the requesting object's schema slug for that identity. The poll job MUST post one update per recorded target schema, carrying only the mapped fields under their schema names. A target schema the provider has no map for MUST receive the source's own names unchanged, and an identity with no recorded target MUST be posted once, unchanged. A mapped change that keeps no field MUST post nothing.

#### Scenario: A dossiq person moves house
- GIVEN an active BRP subscription requested by a dossiq `brpPerson` object
- AND `pollChanges()` returning `verblijfplaats` and `naam` for its BSN
- WHEN the poll job runs
- THEN one POST to `/api/registry/brp/updates` carries `residence` and `name`, and no source field name
- @e2e exclude {background job against a live registry endpoint; covered by PHPUnit with a faked update client}

#### Scenario: A target schema without a map keeps the source names
- GIVEN an active KvK subscription requested by a schema the provider has no map for
- WHEN the poll job posts a change for it
- THEN the POST carries the source's own field names
- @e2e exclude {background job; covered by PHPUnit}

#### Scenario: A field the target schema does not take is left out
- GIVEN a BRP change carrying `naam` and a field the `brpPerson` map does not list
- WHEN the change is mapped to `brpPerson`
- THEN only `name` is posted
- @e2e exclude {pure mapping; covered by PHPUnit}
