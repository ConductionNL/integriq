# openapi-import-and-publish Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- gateway-openapi-import-and-publish

## Purpose

A vendor's OpenAPI document becomes a source and ready endpoints in one step, and developers get an OpenAPI description of the endpoints they call. Rows `integriq:gw-openapi-import`, `integriq:gw-openapi-publish` and `buildiq:int-openapi-import`.

## ADDED Requirements

### Requirement: An OpenAPI document is imported as a source and endpoints (REQ-OAPI-001)

Integriq MUST read an OpenAPI 3.0 or 3.1 document or a Swagger 2.0 document, from an upload or from a URL fetched through the egress guard. It MUST show a preview that writes nothing. On import it MUST create one source from the chosen server, a credential placeholder per security scheme, and one endpoint per chosen operation, and MUST keep the document on the source. It MUST NOT read or store a secret from the document.

#### Scenario: an administrator connects a vendor API from its document
- GIVEN an administrator on the sources page with the vendor's OpenAPI URL
- WHEN they import it, pick the operations `listZaken` and `getZaak`, and confirm
- THEN one source and two endpoints are created, the source asks for its API key, and the endpoints are listed in buildiq's connector picker
- e2e: `tests/e2e/openapi-import.spec.ts`

#### Scenario: a colliding path is flagged before anything is written
- GIVEN an existing endpoint on `GET /vendor/zaken`
- WHEN the administrator previews a document with the same path
- THEN the preview marks that operation as colliding and nothing has been written
- e2e: `tests/e2e/openapi-import.spec.ts`

### Requirement: Integriq publishes an OpenAPI description of its endpoints (REQ-OAPI-002)

Integriq MUST serve an OpenAPI 3.1 document for the endpoints of public API products at a public route, and one document per API product. Schemas MUST come from the OpenRegister schema for register endpoints and from the stored vendor document for imported endpoints. Security schemes MUST follow the endpoint's authentication rules. The document MUST NOT contain a source location.

#### Scenario: a developer reads the document of a product
- GIVEN a public product "Zaken API" with a register endpoint and an imported endpoint
- WHEN a developer fetches `/api/products/<id>/openapi.json`
- THEN both paths are described with their schemas and the API key scheme, and the upstream address appears nowhere
- @e2e exclude document generation; covered by PHPUnit with a schema validator
