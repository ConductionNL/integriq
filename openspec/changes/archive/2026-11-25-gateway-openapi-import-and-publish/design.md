# Design: gateway-openapi-import-and-publish

Kind: code. Size M. A new `OpenApiImportService` and `OpenApiPublishService`, two controller routes each, and one import dialog. The configuration envelope code is not touched.

## Context at development 92f282bc

- `ConfigurationService::importConfiguration(array $oas)` (`lib/Service/ConfigurationService.php:1037`) expects integriq's own envelope and dispatches to `lib/Service/ConfigurationHandlers/{Source,Endpoint,Mapping,Rule,Job,Synchronization}Handler.php`.
- The source schema carries `location`, `type`, auth fields and, since `migrate-inline-secrets-to-broker`, credentials as `credentialRef` into OpenRegister's broker.
- Endpoints of `targetType` `api` proxy to a source through `EndpointService::handleSourceRequest()` (`lib/Service/EndpointService.php:2174`); `register/schema` endpoints serve OpenRegister objects of a schema.
- Product pages: `ApiProducts` and `ApiProductDetail` in `src/manifest.json:1934-1979`.

## D1. A vendor document is not a configuration envelope

A separate `OpenApiImportService` reads OpenAPI 3.0, 3.1 and Swagger 2.0 (converted to 3.0 on read). Reusing `importConfiguration()` would mean pretending a vendor's document is integriq's own export, and one malformed vendor file would travel through handlers that trust their input. The two share the source and endpoint handlers for the final write, so an imported source looks exactly like a hand-made one.

## D2. What an import creates

- One source: `location` from `servers[0].url` (the administrator picks when there are several), `type` `api`, and one credential placeholder per security scheme (`apiKey` in header or query, `http` bearer or basic, `oauth2` client credentials). No secret is read from the document.
- One endpoint per chosen operation: path `/<prefix>/<operation path>` with path parameters turned into `endpointArray` segments, the operation's method, `targetType` `api`, `targetId` the new source, and `endpoint` the upstream path. The endpoint's `name` is the `operationId` or `METHOD path`.
- The original document, stored on the source as `openApiDocument` (a JSON property), so publishing can reuse its schemas.

The preview (`POST /api/openapi/import/preview`) writes nothing and lists what would be created, flagging operations whose path would collide with an existing endpoint. The import (`POST /api/openapi/import`) takes the preview's selection.

A URL import fetches through `CallService` with the egress guard (ADR-067), so an import cannot be pointed at an internal address.

## D3. Publishing

`OpenApiPublishService` builds an OpenAPI 3.1 document:

- `paths` from each published endpoint's path and method.
- Request and response schemas: for `register/schema` endpoints, from the OpenRegister schema definition read through OpenRegister's schema service (ADR-022, not re-typed); for `api` endpoints imported from a document, from the stored `openApiDocument` operation; otherwise an open object with a note.
- `securitySchemes` from the endpoint's authentication rule (API key header, bearer JWT, OAuth 2.0 client credentials from `access-oauth-and-token-validation`).
- `x-sunset` and `deprecated` from the product's status and sunset date.

`GET /api/openapi.json` serves endpoints in public products and is a public route. `GET /api/products/{id}/openapi.json` serves one product; a private product requires an administrator or a developer with an active subscription.

## Declarative versus imperative

No lifecycle or notification behaviour. Import and publish are services.

## Seed data

A fixture `lib/Settings/examples/petstore-openapi.json` used by tests and the docs page, not seeded as objects.

## Risks

- Large vendor documents (thousands of operations). Mitigation: the preview pages the operation list and the import writes in batches of 50 with per-item outcomes.
- A published document leaks an internal upstream path. Mitigation: only the gateway path is published, never the source location.
