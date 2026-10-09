---
kind: code
depends_on: []
---

# Proposal: gateway-openapi-import-and-publish

## Summary

Connecting an outside API means typing its source and each endpoint by hand, even when the vendor hands over an OpenAPI description. And the developers who call integriq's own endpoints get no OpenAPI description of them. This change imports an OpenAPI (or Swagger) document into a source plus one endpoint per chosen operation, and publishes an OpenAPI description of integriq's endpoints, per API product and for the whole gateway.

## Why

Three rows decided `build` in the OpenSpec pass of 2026-09-27, two from integriq's own matrix (gateway area, the core area) and one from buildiq's matrix, owned by integriq.

| row | rating | decision |
|---|---|---|
| `integriq:gw-openapi-import` | no | build: core area, three competitors yes |
| `integriq:gw-openapi-publish` | no | build: core area, three competitors yes |
| `buildiq:int-openapi-import` | no | build: a featureRequest demand row plus three competitors yes |

Demand and competitor cells, quoted from the matrices:

- `gw-openapi-import`: Tyk v5.15.0 `gateway/server.go:942` "POST /tyk/apis/oas/import runs makeImportedOASTykAPI, which builds a Tyk API from a plain OpenAPI document". MuleSoft https://docs.mulesoft.com/anypoint-code-builder/imp-implement-api-specs.md "use Anypoint Code Builder to scaffold your API into a Mule project". WSO2 v4.7.0 `publisher-api.yaml:5310` "/apis/import-openapi".
- `gw-openapi-publish`: MuleSoft https://docs.mulesoft.com/exchange/to-create-an-asset.md, Exchange shares "OAS, RAML, RAML fragments, AsyncAPI, HTTP, WSDL" assets. WSO2 v4.7.0 `devportal-api.yaml:237` "/apis/{apiId}/swagger serves the" OpenAPI. Frank!Framework v10.2.0 `ApiListenerServlet.java:169` "serves /api/openapi.json".
- `buildiq:int-openapi-import`: featureRequest https://github.com/appsmithorg/appsmith/issues/3920. Budibase v3.46.0 `CreateConnection.svelte:56` "Import OpenAPI spec creates a REST connection with its queries at once". Mendix https://docs.mendix.com/refguide/consumed-rest-service/ "consume a REST service from an OpenAPI or Swagger contract". Power Apps https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/register-custom-api "custom connectors are created from an OpenAPI definition".

The integriq matrix notes warn about a near miss: `ConfigurationService` uses an OpenAPI-shaped envelope for its own configuration export, which is not an API description for developers and does not read a vendor's document.

## What integriq already has

- `ConfigurationService::exportConfiguration()` (`lib/Service/ConfigurationService.php:340`) and `importConfiguration()` (`:1037`) round-trip integriq's own objects in an OpenAPI envelope (`openspec/specs/configuration-export-import`, REQ-001 to REQ-005, including credential redaction).
- The endpoint schema carries `endpoint`, `endpointArray`, `endpointRegex`, `method`, `targetType`, `targetId`, `inputMapping`, `outputMapping` and `rules`.
- API products group endpoints (`lib/Settings/register.d/api-product-gateway.json`).
- buildiq's page designer lists integriq sources and endpoints (buildiq `ConnectorSourcePicker.vue:256-262`), so every endpoint an import creates is pickable there.

## What this change builds

1. Import: upload a document or give its URL, see a preview of one source and the operations, choose operations, and create the source and one endpoint per chosen operation. Security schemes become credential placeholders the administrator fills through the credential broker.
2. The imported document is kept on the source, so later publishing and the mapping editor can use its schemas.
3. Publish: `GET /api/openapi.json` for all published endpoints, and one document per API product, built from the endpoint definitions, the target register schemas and the security rules.
4. The publish document is what `access-developer-portal-and-subscriptions` shows developers.

## Out of scope

- Generating mappings from the imported schemas.
- AsyncAPI documents for event streams (`events-async-api-products`).
- Linting a document against design rules (`gateway-api-design-rules-check`).
