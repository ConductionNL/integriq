# objecten-api-facade Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- objecten-api-facade

## Purpose

Serve the VNG Objecten API and Objecttypen API from integriq, over
OpenRegister registers and schemas, so a municipality's landscape can read
and write the objects a case refers to without any leaf app encoding a
national standard. Competitor gap register row 12.3, statutory.

## ADDED Requirements

### Requirement: An objecttype is a declared mapping onto a register and schema (REQ-OAF-001)

Integriq MUST hold an `objecttype` configuration carrying the VNG uuid,
the name, the OpenRegister register and schema it stands for, and the list
of allowed schema versions. The mapping MUST NOT be inferred from a name.
An objecttype uuid MUST survive a reseed of the register it points at.

#### Scenario: two registers holding a schema with the same name
- GIVEN two registers that each hold a schema named `melding`
- WHEN an objecttype is configured for one of them
- THEN reads and writes for that objecttype reach only the configured register
- @e2e exclude configuration resolution; covered by PHPUnit

#### Scenario: a reseed does not change the published uuid
- GIVEN a configured objecttype and a register that is reseeded
- WHEN a counterparty reads the objecttype by its uuid
- THEN the same uuid still resolves
- @e2e exclude configuration durability; covered by PHPUnit

### Requirement: The Objecttypen API serves the schema it stands for (REQ-OAF-002)

Integriq MUST serve `GET /api/v2/objecttypes`,
`GET /api/v2/objecttypes/{uuid}` and
`GET /api/v2/objecttypes/{uuid}/versions/{version}`. The `jsonSchema` of a
version MUST be rendered from the OpenRegister schema at read time and
MUST NOT be stored as a second copy. A version the objecttype
configuration does not allow MUST answer 404 and MUST NOT fall back to
another version.

#### Scenario: a consumer reads the objecttype it registered against
- GIVEN a configured objecttype with versions 1 and 2 allowed
- WHEN a consumer reads `/api/v2/objecttypes/{uuid}/versions/1`
- THEN the response carries the `jsonSchema` of schema version 1
- e2e: `tests/e2e/objecten-api-facade.spec.ts`

#### Scenario: an edited schema shows through immediately
- GIVEN a configured objecttype whose OpenRegister schema gains a property
- WHEN the objecttype version is read again
- THEN the new property is in the `jsonSchema`
- @e2e exclude render-at-read-time; covered by PHPUnit

#### Scenario: an unlisted version is a 404, not a newer one
- GIVEN an objecttype whose configuration allows version 1 only
- WHEN a consumer reads version 2
- THEN the response is 404 and no schema is returned
- @e2e exclude version guard; covered by PHPUnit

### Requirement: The Objecten API reads objects in the standard's shape (REQ-OAF-003)

Integriq MUST serve `GET /api/v2/objects` and
`GET /api/v2/objects/{uuid}`, accepting `type`, `data_attrs`, `date`,
`registrationDate` and `ordering`, with page-based pagination, and MUST
serve the geometry search `POST /api/v2/objects/search`. A response MUST
carry the standard's record shape (`record.data`, `record.startAt`,
`record.registrationAt`, `record.correctionFor`, `record.geometry`) and
MUST NOT leak an OpenRegister field name a VNG consumer does not expect.

#### Scenario: a consumer lists objects of one objecttype
- GIVEN three objects of a configured objecttype and two of another
- WHEN a consumer lists with `type` set to the first objecttype
- THEN three records are returned in the standard's shape
- e2e: `tests/e2e/objecten-api-facade.spec.ts`

#### Scenario: no OpenRegister field reaches the consumer
- GIVEN an object carrying OpenRegister metadata
- WHEN it is read through the Objecten API
- THEN the response carries only the standard's fields and the object's own data
- @e2e exclude literal-leak guard; covered by PHPUnit

#### Scenario: a geometry search finds what is inside the radius
- GIVEN two objects with a point geometry, one inside a 500 metre radius and one outside
- WHEN a consumer posts that geometry to the search route
- THEN only the object inside the radius is returned
- @e2e exclude geo query; covered by Newman

### Requirement: A write lands in OpenRegister and announces (REQ-OAF-004)

`POST`, `PATCH`, `PUT` and `DELETE` on the Objecten API MUST write through
OpenRegister's object service, so validation, audit trail and versioning
apply unchanged, and MUST announce the change on the `objecten` kanaal
through `notificaties-api-connector` in the standard notification body.

#### Scenario: a create is validated by the schema
- GIVEN an objecttype whose schema requires a `straatnaam`
- WHEN a consumer posts a record without one
- THEN the response is a validation refusal and no object is created
- e2e: `tests/e2e/objecten-api-facade.spec.ts`

#### Scenario: a write is announced
- GIVEN a configured `objecten` kanaal
- WHEN a consumer creates an object
- THEN a notification for that object is published on the kanaal
- @e2e exclude publisher path; covered by PHPUnit

#### Scenario: the audit trail records the token's principal
- GIVEN a consumer writing with a configured token
- WHEN the object is created
- THEN the OpenRegister audit entry names the token's principal, not an anonymous caller
- @e2e exclude audit assertion; covered by PHPUnit

### Requirement: A token carries a permission per objecttype (REQ-OAF-005)

Every route MUST authenticate with `Authorization: Token <key>` and MUST
resolve a permission per objecttype, read or read and write. A request for
an objecttype the token does not name MUST be refused with 403; an unknown
objecttype MUST answer 404. The request MUST NOT depend on a Nextcloud
session, and after the token check OpenRegister's own RBAC, multitenancy
and field-level rules MUST still apply. The key MUST be resolved through
the credential broker and MUST NOT appear in a log line, an endpoint
configuration export or the objecttype configuration.

#### Scenario: a token scoped to one objecttype is refused on a second
- GIVEN a token with read on objecttype A only
- WHEN it reads objecttype B
- THEN the response is 403 and no object is returned
- e2e: `tests/e2e/objecten-api-facade.spec.ts`

#### Scenario: a read token cannot write
- GIVEN a token with read on objecttype A
- WHEN it posts a record of objecttype A
- THEN the response is 403 and nothing is created
- @e2e exclude permission matrix; covered by PHPUnit

#### Scenario: no request without a token
- GIVEN a request with no `Authorization` header
- WHEN it reaches any route of either API
- THEN the response is 401 and no register is read
- @e2e exclude fail-closed ordering; covered by PHPUnit

#### Scenario: the key never appears anywhere readable
- GIVEN a configured token
- WHEN an operator exports the endpoint configuration and reads the logs of a request
- THEN no key material appears in either
- @e2e exclude credential custody; covered by PHPUnit with a log assertion

### Requirement: A leaf app declares the objecttypes it publishes (REQ-OAF-006)

A leaf app MUST declare the objecttypes it publishes in its endpoint
declaration and MUST NOT ship a controller for either API. Integriq MUST
read that declaration and MUST NOT infer an app's intent. Every route MUST
be throttled under ADR-082.

#### Scenario: dossiq publishes its case objects without a controller
- GIVEN dossiq declaring its `caseObject` types as objecttypes
- WHEN integriq reads the declaration
- THEN the objecttypes are served and dossiq ships no controller for them
- @e2e exclude declaration reader; covered by PHPUnit

#### Scenario: a burst is throttled
- GIVEN a token making more requests than the configured rate allows
- WHEN the limit is passed
- THEN further requests are refused with the throttle response and the register is not read
- @e2e exclude throttling; covered by PHPUnit
