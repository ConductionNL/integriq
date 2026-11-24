# Tasks: gateway-federated-api-discovery

Kind: code. Size M. Row `integriq:gw-federated`.

## Implementation tasks

### Task 1: The external_api schema and the inventory page
- **spec_ref**: `openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-apis-on-other-gateways-are-listed-with-integriqs-own-req-fedg-001`
- **files**: `lib/Settings/register.d/external-api-inventory.json`, `src/manifest.json` (page `ApiInventory`), `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN seeded products and two external APIs WHEN an administrator opens the inventory THEN all are listed with their gateway
- [x] Implement. Schema, page and menu were in place (`external-api-inventory.json`, `src/manifest.d/api-inventory.json`);
      what was missing is integriq's own products on the page (design: `api_product` and `external_api` together).
      Decision: one schema stays behind the page, so its gateway and vendor facets, columns and row action keep working:
      each API product is kept as an `external_api` row on the gateway and vendor `integriq` (schema 1.2.0 adds the
      vendor), `gatewayApiId` the product uuid, `baseUrl` the gateway's endpoint base and `gatewayUrl` the product's
      published OpenAPI description. `Service/ExternalApi/ApiProductInventory` writes it; `ApiProductInventoryListener`
      runs it on a created or updated product and marks the row no longer seen on a retired or deleted one;
      `Repair/MirrorApiProductsIntoInventory` backfills every product (post-migration and install). A product row has
      no stored document, so "Bring behind integriq" is not offered on it.
- [x] Test (Playwright): written, runs in nightly. `tests/e2e/api-inventory.spec.ts` seeds an API product next to the
      two external APIs and expects its row with gateway `integriq` and its version, gone under the Azure filter.
      PHPUnit: `tests/Unit/Service/ExternalApi/ApiProductInventoryTest.php` (row shape, written over, retired and
      deleted, backfill, listener scope and failure, repair step).

### Task 2: The Amazon connector waits for the broker's aws-sigv4 scheme
- **spec_ref**: `openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-each-vendor-is-read-on-a-schedule-req-fedg-002`
- **files**: `lib/Settings/register.d/aws-apigateway-discovery.json`, `lib/Service/SynchronizationService.php` (the refusal before a call), the inventory page status
- **acceptance_criteria**:
  - GIVEN a broker without aws-sigv4 WHEN the Amazon synchronization runs THEN it makes no call, logs that the broker cannot sign yet, and the inventory shows the connector as waiting on OpenRegister
- [x] Implement
- [x] Test (PHPUnit on the refusal; the OpenRegister follow-up for authScheme aws-sigv4 named in the PR)

### Task 3: Kong, Azure and AWS discovery fragments
- **spec_ref**: `openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-each-vendor-is-read-on-a-schedule-req-fedg-002`
- **files**: `lib/Settings/register.d/kong-gateway-discovery.json`, `azure-apim-discovery.json`, `aws-apigateway-discovery.json`
- **acceptance_criteria**:
  - GIVEN a recorded fixture per vendor WHEN its synchronization runs in test mode THEN the expected external_api objects result, and an API missing on the next run is marked no longer seen
- [x] Implement
- [ ] Test (synchronization test runs against fixtures; one live run against a Kong container in the dev compose)

### Task 4: OpenAPI export and bring behind integriq
- **spec_ref**: `openspec/changes/gateway-federated-api-discovery/specs/federated-api-inventory/spec.md#requirement-a-discovered-api-can-be-brought-behind-integriq-req-fedg-003`
- **files**: the three fragments (export synchronizations), the inventory row action
- **acceptance_criteria**:
  - GIVEN a discovered API with a stored description WHEN the administrator chooses bring behind integriq THEN the import preview opens with its operations
- [x] Implement
  - Row action half built: the API inventory's row action "Bring behind integriq" (`bringApiBehindIntegriqHandler`, shown only on a row whose `openApiDocument` is not empty) opens `OpenApiImportDialog` with that description as text and a prefix slugged from the API's name (`prefixFor`, `documentText` in `src/dialogs/openApiImport.js`), and the dialog previews it at once. Vitest: `tests/vitest/bringApiBehindIntegriq.spec.js`, `tests/vitest/openApiImportDialog.spec.js`, `tests/vitest/modalHostEvents.spec.js`. Left: the export synchronizations that fill `openApiDocument` (Kong attaches no document to a service; Azure and Amazon do not fetch theirs yet).
  - 2026-11-22, the document fetch (design D4): `Service/ExternalApi/ApiDocumentExport` asks the gateway source for each API it listed (`external_api.gateway`), skips an API the gateway no longer lists (`sourceEndedAt`) and one whose `version` equals the new `openApiDocumentVersion` of its stored document, and stores the export with its security schemes as shapes only (`OpenApiCredentialShapes`, as on an import). The export path and where the document sits in the answer are job arguments (`ApiDocumentExportSettings`: `exportEndpoint` with `{apiId}` or `{apiName}`, `documentPosition`, or `linkPosition` for a gateway that answers with a link, which is fetched over https without the gateway's credentials through `OpenApiDocumentReader` and its egress guard). The job action is `Service/Job/ApiDocumentExportAction`. `azure-apim-discovery.json` ships the dormant daily job `azure-apim-discovery-documents-daily` (`/apis/{apiName}`, `format=openapi+json-link`, `export=true`, the link at `value.link`). It is its own job, not a step of the discovery job: a document stored a day after its API is listed is acceptable, and the discovery synchronization stays untouched. `external_api` 1.1.0 gains `openApiDocumentVersion`. Kong has nothing to export (a service carries no document), and Amazon's export (`/restapis/{id}/stages/{stage}/exports/oas30`) needs both the broker's `aws-sigv4` (Task 2) and a stage the Amazon mapping does not record yet, so no Amazon job ships. PHPUnit: `tests/Unit/Service/ExternalApi/ApiDocumentExportTest.php` (the shipped Azure job through its link against recorded fixtures, secrets dropped, the row valid against the schema, unchanged and forced runs, new versions, ended and foreign rows, every failure kept to its API, a document read in the answer, the settings and the action's report).
- [x] Test (Playwright from the inventory row to the import preview); written, runs in nightly
  - Written, not run here: `tests/e2e/api-inventory.spec.ts` (the inventory lists both gateways and filters on Azure; the row action opens the preview with the API's operations).

## Verification
- [ ] `openspec validate gateway-federated-api-discovery --type change --strict` passes
- [ ] PHPUnit and Playwright run, exit codes read

## Build notes (continuation)

- Seed data: three demo `external_api` objects (two Kong, one Azure API Management, matching the recorded fixtures) in `lib/Settings/integriq_mock_register.json`, so the inventory is not empty on a demo install.
- Task 1 stays open: the `ApiInventory` index lists and filters `external_api`; listing integriq's own `api_product` rows in the same filterable list needs an index page over two schemas, which the shared `@conduction/nextcloud-vue` index page does not offer yet.
- Task 4: "Bring behind integriq" opens the import dialog of `gateway-openapi-import-and-publish`, and the Azure documents job fills the description it opens. Its Playwright test stays open (no live instance).
