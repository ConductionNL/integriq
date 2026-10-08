# zgw-catalogi-facade Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- zgw-catalogi-served

## Purpose

Integriq serves the read half of the VNG Catalogi API for document types over
an OpenRegister schema, so a Documenten API references our catalogue and no
second one is kept. Woo row 17.18.

## ADDED Requirements

### Requirement: Integriq serves catalogussen and informatieobjecttypen read only (REQ-ZCS-001)

When a `catalogi_binding` exists and its schema resolves, integriq SHALL
answer `GET /catalogi/api/v1/catalogussen`, `/catalogussen/{uuid}`,
`/informatieobjecttypen` and `/informatieobjecttypen/{uuid}` in the Catalogi
API 1.3 shape. An `InformatieObjectType` SHALL carry `url`, `catalogus`,
`omschrijving`, `vertrouwelijkheidaanduiding`, `informatieobjectcategorie`,
`beginGeldigheid`, `concept` and `besluittypen` (empty list), mapped from the
bound schema by the binding's field map. Every `url` and `catalogus` SHALL be
the absolute URL integriq serves, so following it returns the same resource.
List answers SHALL use the paged envelope `count`, `next`, `previous`,
`results` and SHALL support the filters `catalogus` and `status`. `POST`,
`PUT`, `PATCH` and `DELETE` SHALL answer 405.

#### Scenario: a document store resolves a document type
- GIVEN a binding onto filinq's `documentType` with a type "Besluit" (confidentiality `openbaar`, category "besluiten")
- WHEN a Documenten API client with `catalogi.lezen` follows the type's `url`
- THEN it receives the type with `omschrijving` "Besluit", `vertrouwelijkheidaanduiding` `openbaar`, `informatieobjectcategorie` "besluiten", and a `catalogus` URL that resolves to the catalogue
- @e2e exclude a machine-to-machine API; covered by Newman `tests/postman/zgw-catalogi-served.postman_collection.json` and PHPUnit `InformatieobjecttypeEndpointHandlerTest::testATypeIsServedWithItsOwnUrl`

#### Scenario: a write is refused
- GIVEN a client with every catalogi scope
- WHEN it posts a new informatieobjecttype
- THEN the answer is 405 and nothing is written
- @e2e exclude a refusal; covered by Newman and PHPUnit `InformatieobjecttypeEndpointHandlerTest::testWritesAnswer405`

#### Scenario: no catalogue is configured
- GIVEN no `catalogi_binding`, as on an instance without filinq
- WHEN a client lists informatieobjecttypen
- THEN the answer is 404 with the problem code `catalogue-not-configured` and no rows
- @e2e exclude an unconfigured path; covered by PHPUnit `InformatieobjecttypeEndpointHandlerTest::testAnUnboundFacadeAnswersNotConfigured`

### Requirement: Only an authorised client reads, and concept types stay hidden (REQ-ZCS-002)

Every route SHALL require a ZGW JWT whose client holds the scope
`catalogi.lezen`, checked before OpenRegister is read, and SHALL answer 403
otherwise. A document type whose mapped `concept` is true SHALL be omitted
from lists and answer 404 by uuid, unless the client's authorisation allows
concept reads and the request asks `status=concept` or `status=alles`.

#### Scenario: a client without the scope reads nothing
- GIVEN a client whose JWT lacks `catalogi.lezen`
- WHEN it lists informatieobjecttypen
- THEN the answer is 403 and OpenRegister is not queried
- @e2e exclude an authorization refusal; covered by PHPUnit `CatalogiTokenServiceTest::testAMissingScopeIsRefusedBeforeAnyRead`

#### Scenario: an inactive type is not served as definitive
- GIVEN a document type with `active` false
- WHEN a client lists with the default status
- THEN the type is not in `results`, and a request by its uuid answers 404
- @e2e exclude a filter; covered by PHPUnit `InformatieobjecttypeEndpointHandlerTest::testConceptTypesAreHiddenByDefault`

### Requirement: A Woo starter set gives one type per Woo information category (REQ-ZCS-003)

An administrator SHALL be able to run a Woo starter set that reads the TOOI
informatiecategorie scheme from OpenRegister's concept register and creates,
in the bound schema, one document type for each category that no existing
type carries, with `informatieobjectcategorie` set to the category's label and
its TOOI URI kept. The run SHALL NOT change or delete an existing type, SHALL
report how many it created and skipped, and SHALL refuse with
`tooi-scheme-missing` when the scheme cannot be read.

#### Scenario: the Woo categories become document types
- GIVEN a bound schema with one type in category "besluiten" and the TOOI scheme with 17 Woo categories
- WHEN an administrator runs the starter set
- THEN 16 types are created, 1 is skipped, and listing informatieobjecttypen returns 17 types each with a Woo category
- e2e: `tests/e2e/zgw-catalogi-served.spec.ts`

#### Scenario: no TOOI scheme, no seeding
- GIVEN the TOOI informatiecategorie scheme is absent from the concept register
- WHEN an administrator runs the starter set
- THEN it refuses with `tooi-scheme-missing` and creates nothing
- @e2e exclude a refusal; covered by PHPUnit `WooStarterSetTest::testAMissingSchemeRefuses`
