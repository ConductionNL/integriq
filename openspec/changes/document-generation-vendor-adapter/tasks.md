# Tasks: document-generation-vendor-adapter

## Implementation tasks

### Task 1: Provider interface, registry and log binding
- **spec_ref**: `openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001`
- **files**: `lib/Service/DocumentGeneration/DocumentGenerationProviderInterface.php`, `lib/Service/DocumentGeneration/LogDocumentGenerationProvider.php`, `lib/Service/DocumentGeneration/DocumentGenerationProviderRegistry.php`
- [x] Implement
- [x] Test

### Task 2: `documentGenerationJob` schema, events and service
- **spec_ref**: `openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-a-render-is-a-typed-command-with-a-tracked-job-req-dgv-002`
- **files**: `lib/Settings/integriq_register.json`, `lib/Event/DocumentRenderRequestedEvent.php`, `lib/Event/DocumentRenderedEvent.php`, `lib/Service/DocumentGeneration/DocumentGenerationService.php`, `lib/BackgroundJob/DocumentGenerationStatusJob.php`
- [x] Implement (the job stores the data hash, never the data)
- [x] Test

### Task 3: SmartDocuments and Xential bindings
- **spec_ref**: `openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-one-provider-seam-with-log-smartdocuments-and-xential-bindings-req-dgv-001`
- **files**: `lib/Service/DocumentGeneration/SmartDocumentsProvider.php`, `lib/Service/DocumentGeneration/XentialProvider.php`
- [x] Implement (activation refused without `credentialRef`; mock mode with fixtures)
- [x] Test

### Task 4: Credentials by reference
- **spec_ref**: `openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-credentials-are-resolved-by-reference-never-passed-by-value-req-dgv-003`
- **files**: the two bindings, the broker resolver they call
- [x] Implement
- [x] Test (resolution, and a log assertion that no key is written)

### Task 5: Source page, template list, catalog entries, i18n, docs
- **spec_ref**: `openspec/changes/document-generation-vendor-adapter/specs/document-generation-vendor-adapter/spec.md#requirement-templates-are-listed-from-the-vendor-not-copied-req-dgv-004`
- **files**: the source form, `lib/Settings/catalog.seed.json` (or the file the catalog seeds from), Dutch and English strings, docs with screenshots
- [x] Implement
- [x] Test (`tests/e2e/document-generation-source.spec.ts`, `tests/e2e/connector-catalog.spec.ts`)

## Verification

- [x] `openspec validate document-generation-vendor-adapter --strict` passes
- [x] PHPUnit run, exit code read rather than the summary line (32 tests, 88 assertions)
- [x] No credential string appears in any method signature, source configuration or app-config key added by this change, asserted by `testNoApiKeyReachesACallArgumentOrALogLine`

## Cross-repo follow-ups

- [ ] Open the filinq follow-up on `document-creatie-sjablonen`: a template `engine` of `twig` or `vendor:<sourceSlug>` that dispatches `DocumentRenderRequestedEvent` and files the result (design D6)
- [ ] Tell dossiq to bind `TemplateEngineAdapterInterface` to filinq's contract and delete `MockTemplateEngineAdapter` (ADR-075); its task sits in `competitor-parity-2026-09`
