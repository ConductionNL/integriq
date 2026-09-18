# Tasks: migration-source-adapters

Kind: code. Size M, as integriq's half of cluster 8, which the build plan
sizes L with openregister as owner. Candidates C-configuration-88 and
C-configuration-16, both matrix holes, plus the read half of
C-configuration-95. Numbers 2 and 13 of the twenty-five loudest.

## Implementation tasks

### Task 1: The migration source contract
- **spec_ref**: `openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-migration-source-is-an-adapter-behind-one-contract-req-msa-001`
- **files**: `lib/Migration/MigrationSourceAdapterInterface.php`, `lib/Migration/MigrationSourceRegistry.php`, `lib/AppInfo/Application.php` (DI tag)
- [x] Implement (`describe`, `count`, `read`, `sample`; no write to source or target; record kinds and their identifiers declared)
- [x] Test (an unknown source id fails naming itself and reads nothing)

### Task 2: The file source and its stored mapping
- **spec_ref**: `openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-file-is-read-through-a-stored-column-mapping-req-msa-002`
- **files**: `lib/Migration/Source/FileMigrationSource.php`, `lib/Migration/ColumnMapping.php`, `lib/Migration/ColumnMappingValidator.php`, `lib/Controller/MigrationSourcesController.php`
- [x] Implement (stored, versioned, reusable; target field names validated before the save; an unmapped required field refused before the run)
- [x] Test (a second delivery reusing a saved mapping, and a mapping onto a missing field)
- [ ] The mapping editor surface itself. The check it calls is `POST /api/migration-sources/column-mapping/validate`, and the screen is still to be built.

### Task 3: The first incumbent adapter
- **spec_ref**: `openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-named-incumbent-has-an-adapter-and-a-supported-path-is-rehearsable-req-msa-003`
- **files**: `lib/Migration/Source/RedmineMigrationSource.php`
- [x] Choose the incumbent, and record the choice with its reason. **Redmine.** Its REST API is public, stable and versioned; Easy Redmine speaks the same one, so one adapter covers two of the six passers; and it is reachable without a customer contract, so the adapter could be built and tested now rather than waiting on the first migration customer. When that customer names a different incumbent, the second adapter is a class beside this one and a line in the registry.
- [x] Implement (the adapter behind the contract; every call is a read, so a rehearsal writes nothing anywhere)
- [x] Test (against a faked gateway, and a registered second adapter that needs no engine change)

### Task 4: The read-only pass
- **spec_ref**: `openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-a-read-only-pass-reports-what-a-migration-would-bring-req-msa-004`
- **files**: `lib/Migration/MigrationPreviewReader.php`
- [x] Implement (count per record kind, a bounded sample, and the count reported beside `countIs: complete|partial` so a truncated read can never read as a small source)
- [x] Test (a truncated fixture reports incomplete)

### Task 5: Foreign identity on every record
- **spec_ref**: `openspec/changes/migration-source-adapters/specs/migration-sources/spec.md#requirement-every-yielded-record-carries-its-foreign-identity-req-msa-005`
- **files**: `lib/Migration/MigrationRecord.php`, reusing `registry-backed-field-source` REQ-RFS-003 provenance
- [x] Implement (identifier and source on every record; a re-run matches; a record kind with no stable key declared in `describe()` and yielded as not matchable)
- [x] Test (a second run of the same source)

### Task 6: Coordination, docs and the hand-offs
- **files**: `docs/`, Dutch and English strings, the catalogue entries, this change's row in `competitor-parity-2026-09`
- [ ] Ask the openregister lane, cluster 8, for the import engine's preview and conflict policy, and agree the record shape and the match signal this change yields
- [ ] Say in the same message that C-integrations-50 and C-integrations-22 stay openregister's, and that integriq adds no export
- [ ] Tell the openregister lane that the foreign identity reuses `registry-backed-field-source` REQ-RFS-003 rather than a second shape
- [x] Test (`tests/e2e/migration-file-source.spec.ts`, `openspec validate migration-source-adapters --strict`)
