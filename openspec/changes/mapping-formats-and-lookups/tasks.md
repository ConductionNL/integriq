# Tasks: mapping-formats-and-lookups

Kind: code. Matrix rows `integriq:map-csv`, `integriq:map-lookup` and
`integriq:map-xml`.

### Task 1: CSV codec shared with the migration source
- **spec_ref**: openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-synchronization-reads-a-csv-source-response-req-mfl-001
- **files**: `lib/Util/CsvCodec.php`, `lib/Migration/Source/FileMigrationSource.php`, `tests/Unit/Util/CsvCodecTest.php`
- **acceptance_criteria**:
  - GIVEN a quoted field with a line break WHEN decoded THEN it stays one field
  - GIVEN the existing migration source tests WHEN they run on the codec THEN they pass
  - GIVEN two rows WHEN encoded with a header and `;` THEN three lines are written
- [x] Implement
- [x] Test (PHPUnit)

### Task 2: CSV branch in the fetch path and the format select
- **spec_ref**: openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-synchronization-reads-a-csv-source-response-req-mfl-001
- **files**: `lib/Service/SynchronizationService.php`, `src/views/Synchronization/SyncConfigWidget.vue`
- **acceptance_criteria**:
  - GIVEN `sourceConfig.format: csv` WHEN a page is fetched THEN each row reaches the mapping as one object
  - GIVEN the synchronization settings WHEN the engineer opens the format field THEN it is a select and `csv` shows delimiter, enclosure and header options
- [x] Implement
- [ ] Test (PHPUnit on the fetch path; Playwright `tests/e2e/mapping-formats-and-lookups.spec.ts`)

### Task 3: Output format on the mapping and the XML writer
- **spec_ref**: openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-declares-its-output-format-req-mfl-002
- **files**: `lib/Settings/register.d/mapping-formats-and-lookups.json`, `lib/Util/ArrayToXml.php`, `lib/Controller/XMLResponse.php`, `lib/Service/MappingService.php`, `lib/Controller/MappingsController.php`, `src/views/wrappers/MappingDetailPage.vue`
- **acceptance_criteria**:
  - GIVEN the `xml-response` unit tests WHEN they run after the extraction THEN they pass unchanged
  - GIVEN `person-to-xml` WHEN the engineer previews a sample THEN XML with root `persoon` is shown
  - GIVEN a mapping without `outputFormat` WHEN tested THEN no rendered text is returned and JSON behaviour is unchanged
- [x] Implement
- [ ] Test (PHPUnit on `renderOutput()`; Playwright for the preview)

### Task 4: Send XML or CSV to an API target
- **spec_ref**: openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-synchronization-sends-the-mappings-output-format-to-an-api-target-req-mfl-003
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a target mapping with `xml` WHEN an object is written THEN CallService receives `body` and `Content-Type: application/xml`, and no `json`
  - GIVEN a target mapping without `outputFormat` WHEN an object is written THEN CallService receives `json` as before
- [x] Implement
- [x] Test (PHPUnit with a mocked CallService)

### Task 5: The lookup function and its allowlist
- **spec_ref**: openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
- **files**: `lib/Twig/MappingExtension.php`, `lib/Twig/MappingFunctionRegistrationListener.php`, `lib/Twig/MappingRuntime.php`, `lib/Twig/LookupAllowlist.php`, `lib/Controller/MappingLookupController.php`, `appinfo/routes.php`, the admin settings section
- **acceptance_criteria**:
  - GIVEN an allowed schema with one match WHEN `lookup` runs THEN the field value is returned
  - GIVEN a schema off the allowlist WHEN `lookup` runs THEN the mapping fails naming the schema and ObjectService is not called
  - GIVEN two matches WHEN `lookup` runs THEN the default is returned and a trace warning is recorded
  - GIVEN a non-administrator WHEN they change the allowlist THEN 403
- [x] Implement
- [ ] Test (PHPUnit on the runtime and controller; Playwright for the admin list and a mapping preview using `lookup`)

## Build notes

Task 1 and the implementation of task 2 are built: `CsvCodec` reads through `fgetcsv()`, the file migration source uses it, a synchronization with `sourceConfig.format: csv` hands one array per row to the mapping, and the file format field is a select with the three CSV options for `csv`. Task 2's PHPUnit half is in `SynchronizationFetchSinglePageTest`; its Playwright half needs a running instance and is open, so its Test box stays unchecked. Task 3 is built: `ArrayToXml` holds the writer `XMLResponse::arrayToXml()` now calls (the xml-response tests pass unchanged), `MappingOutput` writes a result as XML or CSV and leaves JSON to the caller, `MappingService::renderOutput()` reads the mapping's `outputFormat` and `outputOptions` from the fragment `mapping-formats-and-lookups.json` (which also seeds `person-to-xml`), the mapping test answers `renderedOutput` for an XML or CSV mapping only, and the preview shows it read-only. Its PHPUnit half is `MappingOutputTest` and `MappingsControllerRenderedOutputTest`; the Playwright half is open. Task 4 is built: `SynchronizationService::formattedTargetConfig()` reads the output format of the mapping that made the payload (the synchronization's `sourceTargetMapping`, or `updateMapping` on an update, each read once per engine) and sends XML or CSV as `body` with its `Content-Type` and no `json`; a JSON mapping, an unknown one or none send JSON as before (`SynchronizationTargetOutputFormatTest`). Task 5 is built: `lookup(schema, filters, field, default)` is registered in integriq's engine (`MappingExtension`) and contributed to OpenRegister's (`MappingFunctionRegistrationListener`); `MappingLookup` refuses a schema off `LookupAllowlist` before reading anything, rejects a non-scalar filter, reads at most two objects in system context, returns the default for none and for several (recording a warning that names the filters), and caches each question for the run. `MappingLookupController` (`/api/admin/mapping-lookups`, administrators only, every change logged with the administrator) backs the admin section `MappingLookupSettings.vue`. PHPUnit: `MappingLookupTest`, `MappingLookupControllerTest`; vitest: `mappingLookupSettings.spec.js`. Open: the warning reaches the logger and `MappingLookup::warnings()`, not yet the execution trace step, because the trace context does not reach the Twig runtime; and the Playwright half of tasks 2, 3 and 5 (`tests/e2e/mapping-formats-and-lookups.spec.ts`) needs a running instance.

## Verification

- `openspec validate mapping-formats-and-lookups --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- `tests/e2e/mapping-formats-and-lookups.spec.ts` green against a local instance
