# Tasks: gateway-openapi-import-and-publish

Kind: code. Size M. Rows `integriq:gw-openapi-import`, `integriq:gw-openapi-publish`, `buildiq:int-openapi-import`.

## Implementation tasks

### Task 1: Read and preview a vendor document
- **spec_ref**: `openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001`
- **files**: `lib/Service/OpenApi/OpenApiImportService.php`, `lib/Controller/OpenApiController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a Swagger 2.0 and an OpenAPI 3.1 document WHEN previewed THEN both list the same operations, and nothing is written
- [ ] Implement
- [ ] Test (PHPUnit with the petstore fixture in both versions and a document with a colliding path)

### Task 2: Create the source and endpoints
- **spec_ref**: `openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001`
- **files**: `lib/Service/OpenApi/OpenApiImportService.php`, `lib/Service/ConfigurationHandlers/SourceHandler.php`, `lib/Service/ConfigurationHandlers/EndpointHandler.php`, `lib/Settings/integriq_register.json` (source `openApiDocument`)
- **acceptance_criteria**:
  - GIVEN three chosen operations WHEN imported THEN one source and three endpoints exist, and the source asks for its credential
- [ ] Implement
- [ ] Test (PHPUnit; Newman call through one created endpoint against a mock source)

### Task 3: The import dialog
- **spec_ref**: `openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001`
- **files**: `src/dialogs/OpenApiImportDialog.vue`, `src/manifest.json` (header action on the sources index), `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an administrator on the sources page WHEN they import from a URL and pick two operations THEN the new source and its two endpoints are listed
- [ ] Implement
- [ ] Test (Playwright)

### Task 4: Publish the gateway and product documents
- **spec_ref**: `openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002`
- **files**: `lib/Service/OpenApi/OpenApiPublishService.php`, `lib/Controller/OpenApiController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a public product with a register endpoint WHEN a developer fetches its document THEN the response schema matches the OpenRegister schema and no source location appears
- [ ] Implement
- [ ] Test (PHPUnit; the published document validated with an OpenAPI 3.1 validator in the test)

## Verification
- [ ] `openspec validate gateway-openapi-import-and-publish --type change --strict` passes
- [ ] PHPUnit, Newman and Playwright run, exit codes read
- [ ] The configuration export and import tests pass unchanged
