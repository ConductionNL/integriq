# Tasks: gateway-openapi-import-and-publish

Kind: code. Size M. Rows `integriq:gw-openapi-import`, `integriq:gw-openapi-publish`, `buildiq:int-openapi-import`.

## Implementation tasks

### Task 1: Read and preview a vendor document
- **spec_ref**: `openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001`
- **files**: `lib/Service/OpenApi/OpenApiImportService.php`, `lib/Controller/OpenApiController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a Swagger 2.0 and an OpenAPI 3.1 document WHEN previewed THEN both list the same operations, and nothing is written
- [x] Implement
- [x] Test (PHPUnit with the petstore fixture in both versions and a document with a colliding path)

### Task 2: Create the source and endpoints
- **spec_ref**: `openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001`
- **files**: `lib/Service/OpenApi/OpenApiImportService.php`, `lib/Service/ConfigurationHandlers/SourceHandler.php`, `lib/Service/ConfigurationHandlers/EndpointHandler.php`, `lib/Settings/integriq_register.json` (source `openApiDocument`)
- **acceptance_criteria**:
  - GIVEN three chosen operations WHEN imported THEN one source and three endpoints exist, and the source asks for its credential
- [x] Implement
- [x] Test (PHPUnit; Newman call through one created endpoint against a mock source)
  - Run 2026-11-25 on a live Nextcloud 34 (MariaDB, OpenRegister main, ci-seed): Newman folder 18, 12 requests, 23 assertions, 0 failed. The first run found two app bugs: the import wrote an empty `credentialRef` the source schema refuses (every document with a security scheme failed with 400; the source now keeps its placeholders and stays disabled until a credential is named), and `upstreamPath` lacked its leading slash, so the call went to `http://host:8095status.php` (now `/status.php`). 18.4 and 18.6 follow: the disabled source, and the upstream JSON in the gateway's answer envelope.
  - PHPUnit done (`OpenApiImportServiceTest`, `OpenApiImportControllerTest`). Newman written: folder 18 in `tests/postman/integriq.postman_collection.json` imports three operations (one source, three endpoints, the source's credential still to be named), then imports one operation of a mock source (the instance's own `status.php`, `oapiMockBase`) and calls through the created endpoint; it deletes what it created. Not run yet: needs a live instance.

### Task 3: The import dialog
- **spec_ref**: `openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-an-openapi-document-is-imported-as-a-source-and-endpoints-req-oapi-001`
- **files**: `src/dialogs/OpenApiImportDialog.vue`, `src/manifest.json` (header action on the sources index), `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN an administrator on the sources page WHEN they import from a URL and pick two operations THEN the new source and its two endpoints are listed
- [x] Implement
- [x] Test (Playwright)
  - Run 2026-11-25 on the same instance: `tests/e2e/spec-coverage/openapi-import.spec.ts` 1 passed, exit 0 (red before the `credentialRef` fix: the import answered 400).
  - Written: `tests/e2e/spec-coverage/openapi-import.spec.ts` (sources page Actions > import, paste a document, preview, pick two of three operations, import; the source and its two endpoints open, pointing at the source). The document is pasted: fetching by address passes the egress guard, which a CI instance cannot satisfy. Not run yet: needs a live instance.

### Task 4: Publish the gateway and product documents
- **spec_ref**: `openspec/changes/gateway-openapi-import-and-publish/specs/openapi-import-and-publish/spec.md#requirement-integriq-publishes-an-openapi-description-of-its-endpoints-req-oapi-002`
- **files**: `lib/Service/OpenApi/OpenApiPublishService.php`, `lib/Controller/OpenApiController.php`, `appinfo/routes.php`
- **acceptance_criteria**:
  - GIVEN a public product with a register endpoint WHEN a developer fetches its document THEN the response schema matches the OpenRegister schema and no source location appears
- [x] Implement
- [x] Test (PHPUnit; the published document validated with an OpenAPI 3.1 validator in the test)

## Verification
- [x] `openspec validate gateway-openapi-import-and-publish --type change --strict` passes (openspec 2.2.0, 25 Nov 2026)
- [x] PHPUnit, Newman and Playwright run, exit codes read
  - PHPUnit 2026-11-25, exit 0: `tests/Unit/Service/OpenApi` (21), `OpenApiImportControllerTest` (6), `OpenApiControllerTest` (3), `OpenApiDocumentTest` (2), `OpenApiCheckerGoldenTest` (2). Newman folder 18: 23/23. Playwright `openapi-import.spec.ts`: 1/1.
- [x] The configuration export and import tests pass unchanged
  - Checked on development 60f97d46: the merge of this change (#3582) only adds test files (`git diff --stat d7fc9c810^1 d7fc9c810 -- tests`, 3761 insertions, 0 deletions); the configuration export and import suites (`Configuration(Handler|Service|Import|Export)`, `HandlerImport`, `HandlerExport`, `ExportEnvelope`, `ImportPreview`) pass, 85 tests.
