# endpoint-runtime Specification (Delta)

## ADDED Requirements

### Requirement: Declarative id-fetch guard for single-object GET (REQ-EP-010)

`getObjects()`'s id-branch (`isset($pathParams['id']) === true &&
$pathParams['id'] === end($pathParams)`) currently calls
`$mapper->find($pathParams['id'], ...)` with no parameters, so any
`inputMapping`-injected fixed filters that gate the collection path (see
`mapping-and-search` REQ-001, and this change's `ori-public-serving`
REQ-ORIPUB-002) are silently not applied when fetching a single object by
id. The system MUST, when an Endpoint declares a fixed filter set (the same
set its `inputMapping` injects for collection requests), re-check the
fetched object against that filter set after `$mapper->find()` resolves and
return HTTP 404 — not the object — when the object does not match, so a
single-item GET enforces the same declarative gates as its collection
sibling. This closes the gap named in `ori-public-serving`'s design.md
(Gap 2) generically, for any Endpoint with this shape of requirement, not
only the ORI ones.

@e2e exclude backend dispatch guard — covered by Newman/PHPUnit, not browser UI

#### Scenario: A fetched object matching the fixed filter set is returned normally

- **GIVEN** an Endpoint whose fixed filter set is `{lifecycle: "published"}`
- **AND** the object at the requested id has `lifecycle: "published"`
- **WHEN** `getObjects()`'s id-branch resolves the object
- **THEN** the object is returned as today, unchanged

#### Scenario: A fetched object failing the fixed filter set 404s instead of returning

- **GIVEN** an Endpoint whose fixed filter set is `{lifecycle: "published"}`
- **AND** the object at the requested id has `lifecycle: "draft"`
- **WHEN** `getObjects()`'s id-branch resolves the object
- **THEN** the response is HTTP 404, not the draft object

#### Scenario: An Endpoint with no declared fixed filter set is unaffected

- **GIVEN** an Endpoint with no fixed filter set configured (today's default
  for every existing Endpoint)
- **WHEN** `getObjects()`'s id-branch resolves an object
- **THEN** behaviour is unchanged from pre-change — the object is returned
  as long as it exists, exactly as today

#### Scenario: The guard checks the object's own field, not a request parameter

- **GIVEN** an Endpoint whose fixed filter set includes a discriminator field
  (e.g. `decisionType: "motion"`)
- **WHEN** the fetched object's own `decisionType` field is `"amendment"`
- **THEN** the response is HTTP 404, regardless of what (if anything) the
  caller supplied in the request

**Notes:**
- This is additive and opt-in per Endpoint (a new, optional "fixed filter
  set" config surface, reusing the same recipe shape `inputMapping` already
  uses for collection requests) — no existing Endpoint's id-fetch behaviour
  changes unless it opts in.
- Not a substitute for OpenRegister-level `x-openregister-authorization` RBAC
  (which already gates reads unconditionally, at the storage layer,
  regardless of caller) — this guard covers the case where the gating field
  is *not* an OR-RBAC-declared property (e.g. `lifecycle`, `decisionType` on
  decidesk's schemas today), matching the distinction drawn in
  `ori-public-serving`'s design.md between Gap 2's RBAC-covered and
  application-logic-covered sub-cases.
