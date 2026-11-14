# Tasks: platform-integrations-as-code

Kind: code. Matrix rows `integriq:plt-cli` and `integriq:plt-git`.

### Task 1: List, run and test commands
- **spec_ref**: openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-sources-synchronizations-and-jobs-can-be-listed-run-and-tested-with-occ-req-iac-001
- **files**: `lib/Command/SourceList.php`, `lib/Command/SourceTest.php`, `lib/Command/SynchronizationList.php`, `lib/Command/SynchronizationRun.php`, `lib/Command/JobList.php`, `lib/Command/JobRun.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN a slug or a uuid WHEN a command runs THEN it resolves the object either way
  - GIVEN a failed test WHEN the command ends THEN it exits with 1
  - GIVEN an unknown object WHEN the command runs THEN it exits with 2
  - GIVEN `--test` on a synchronization WHEN it runs THEN no object is written
- [x] Implement
- [x] Test (PHPUnit per command with Symfony's CommandTester and mocked services)
      `lib/Command/Integration/`: the six commands over a shared `IntegrationCommand` base (`--output=plain|json`, exit 0, 1 failed, 2 not found) and `IntegrationObjectLookup` (uuid first, else the one object with that slug; a slug two objects share resolves to nothing rather than to a guess). Each calls the screen action's service: `SourceTestServiceInterface::run()`, `SynchronizationServiceInterface::synchronize()` with `isTest` for `--test`, `JobServiceInterface::executeJob()`. The deletion-ratio override is not offered on the command line. Tests: `tests/Unit/Command/Integration/` (15).

### Task 2: Directory codec and export command
- **spec_ref**: openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-configuration-exports-to-a-directory-of-stable-files-req-iac-002
- **files**: `lib/Command/Integration/ConfigurationDirectoryCodec.php`, `lib/Command/ConfigExport.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN an unchanged configuration WHEN exported twice THEN the files are identical
  - GIVEN an edited mapping WHEN exported THEN only its file differs
  - GIVEN an inline password WHEN exported THEN it is redacted
- [x] Implement
- [x] Test (PHPUnit on the codec; integration test exporting into a temporary directory under the test's own work dir)
      `lib/Command/Integration/ConfigurationDirectoryCodec.php` (split, join, writeDirectory, readDirectory) and `lib/Command/Integration/ConfigExport.php` (exit 0 written, 1 failed, 2 nothing to export; warns per entity without a slug). The codec takes both shapes of the export (grouped lists and slug-keyed maps), redacts through `SecretRedactorInterface::redactConfiguration()` (bound to `SensitiveFieldRegistry`) on top of the handlers' own redaction, keeps `credentialRef`, and refuses two entities on one file. Tests: `tests/Unit/Command/Integration/ConfigurationDirectoryCodecTest.php` (10, writes into a temporary directory) and the export half of `tests/Unit/Command/Integration/ConfigCommandsTest.php`.

### Task 3: Import command with preview and confirmation
- **spec_ref**: openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-directory-imports-with-a-preview-and-only-when-confirmed-req-iac-003
- **files**: `lib/Command/ConfigImport.php`, `lib/Command/Integration/ConfigurationDirectoryCodec.php`
- **acceptance_criteria**:
  - GIVEN a directory WHEN imported without `--confirm` THEN the preview prints and nothing is written
  - GIVEN `--confirm` WHEN imported THEN `importConfiguration()` runs and the result prints
- [x] Implement
- [ ] Test (integration test against OpenRegister)
      `lib/Command/Integration/ConfigImport.php`: reads the directory (only files the index lists, only at `<component>/<name>.json`), prints `ConfigurationImportPreviewServiceInterface::preview()` and writes nothing without `--confirm`; with it calls `ConfigurationServiceInterface::importConfiguration()`, the screen's path. Covered by PHPUnit with CommandTester and mocked services (`ConfigCommandsTest`, import half); the integration test against a live OpenRegister is left for a run with an instance.

### Task 4: Round-trip test and docs
- **spec_ref**: openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-export-and-import-round-trip-without-drift-req-iac-004
- **files**: `tests/Integration/ConfigurationRoundTripTest.php`, `docs/` page on keeping a configuration in git
- **acceptance_criteria**:
  - GIVEN a seeded configuration WHEN exported, imported into a clean register and exported again THEN the directories are identical
  - GIVEN the docs page WHEN read THEN it shows the export, commit, review and confirmed import steps
- [ ] Implement
- [ ] Test (the integration test; docs build)
      Docs page written: `docs/administrators/configuration-in-git.md` (export, commit, review, preview, confirmed import), linked from `docs/SUMMARY.md`. The codec half of the round trip (split, join, split gives the same files) is asserted in `ConfigurationDirectoryCodecTest::testJoinGivesTheImportDocument`. Left unchecked: `tests/Integration/ConfigurationRoundTripTest.php` needs a seeded instance and a clean register, and the docs build was not run here.

## Verification

- `openspec validate platform-integrations-as-code --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- A manual export, commit, import with preview and confirmed import on a second local instance
