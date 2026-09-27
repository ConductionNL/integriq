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
- [ ] Implement
- [ ] Test (PHPUnit)

### Task 2: CSV branch in the fetch path and the format select
- **spec_ref**: openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-synchronization-reads-a-csv-source-response-req-mfl-001
- **files**: `lib/Service/SynchronizationService.php`, `src/views/Synchronization/SyncConfigWidget.vue`
- **acceptance_criteria**:
  - GIVEN `sourceConfig.format: csv` WHEN a page is fetched THEN each row reaches the mapping as one object
  - GIVEN the synchronization settings WHEN the engineer opens the format field THEN it is a select and `csv` shows delimiter, enclosure and header options
- [ ] Implement
- [ ] Test (PHPUnit on the fetch path; Playwright `tests/e2e/mapping-formats-and-lookups.spec.ts`)

### Task 3: Output format on the mapping and the XML writer
- **spec_ref**: openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-declares-its-output-format-req-mfl-002
- **files**: `lib/Settings/register.d/mapping-formats-and-lookups.json`, `lib/Util/ArrayToXml.php`, `lib/Http/XMLResponse.php`, `lib/Service/MappingService.php`, `lib/Controller/MappingsController.php`, `src/views/wrappers/MappingDetailPage.vue`
- **acceptance_criteria**:
  - GIVEN the `xml-response` unit tests WHEN they run after the extraction THEN they pass unchanged
  - GIVEN `person-to-xml` WHEN the engineer previews a sample THEN XML with root `persoon` is shown
  - GIVEN a mapping without `outputFormat` WHEN tested THEN no rendered text is returned and JSON behaviour is unchanged
- [ ] Implement
- [ ] Test (PHPUnit on `renderOutput()`; Playwright for the preview)

### Task 4: Send XML or CSV to an API target
- **spec_ref**: openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-synchronization-sends-the-mappings-output-format-to-an-api-target-req-mfl-003
- **files**: `lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a target mapping with `xml` WHEN an object is written THEN CallService receives `body` and `Content-Type: application/xml`, and no `json`
  - GIVEN a target mapping without `outputFormat` WHEN an object is written THEN CallService receives `json` as before
- [ ] Implement
- [ ] Test (PHPUnit with a mocked CallService)

### Task 5: The lookup function and its allowlist
- **spec_ref**: openspec/changes/mapping-formats-and-lookups/specs/mapping-and-search/spec.md#requirement-a-mapping-can-look-up-a-value-in-an-allowed-register-schema-req-mfl-004
- **files**: `lib/Twig/MappingExtension.php`, `lib/Listener/MappingFunctionRegistrationListener.php`, `lib/Twig/MappingRuntime.php`, `lib/Twig/LookupAllowlist.php`, `lib/Controller/MappingLookupController.php`, `appinfo/routes.php`, the admin settings section
- **acceptance_criteria**:
  - GIVEN an allowed schema with one match WHEN `lookup` runs THEN the field value is returned
  - GIVEN a schema off the allowlist WHEN `lookup` runs THEN the mapping fails naming the schema and ObjectService is not called
  - GIVEN two matches WHEN `lookup` runs THEN the default is returned and a trace warning is recorded
  - GIVEN a non-administrator WHEN they change the allowlist THEN 403
- [ ] Implement
- [ ] Test (PHPUnit on the runtime and controller; Playwright for the admin list and a mapping preview using `lookup`)

## Verification

- `openspec validate mapping-formats-and-lookups --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- `tests/e2e/mapping-formats-and-lookups.spec.ts` green against a local instance
