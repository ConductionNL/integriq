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
- [ ] Implement
- [ ] Test (PHPUnit per command with Symfony's CommandTester and mocked services)

### Task 2: Directory codec and export command
- **spec_ref**: openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-configuration-exports-to-a-directory-of-stable-files-req-iac-002
- **files**: `lib/Service/ConfigurationDirectoryCodec.php`, `lib/Command/ConfigExport.php`, `appinfo/info.xml`
- **acceptance_criteria**:
  - GIVEN an unchanged configuration WHEN exported twice THEN the files are identical
  - GIVEN an edited mapping WHEN exported THEN only its file differs
  - GIVEN an inline password WHEN exported THEN it is redacted
- [ ] Implement
- [ ] Test (PHPUnit on the codec; integration test exporting into a temporary directory under the test's own work dir)

### Task 3: Import command with preview and confirmation
- **spec_ref**: openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-a-directory-imports-with-a-preview-and-only-when-confirmed-req-iac-003
- **files**: `lib/Command/ConfigImport.php`, `lib/Service/ConfigurationDirectoryCodec.php`
- **acceptance_criteria**:
  - GIVEN a directory WHEN imported without `--confirm` THEN the preview prints and nothing is written
  - GIVEN `--confirm` WHEN imported THEN `importConfiguration()` runs and the result prints
- [ ] Implement
- [ ] Test (integration test against OpenRegister)

### Task 4: Round-trip test and docs
- **spec_ref**: openspec/changes/platform-integrations-as-code/specs/integrations-as-code/spec.md#requirement-export-and-import-round-trip-without-drift-req-iac-004
- **files**: `tests/Integration/ConfigurationRoundTripTest.php`, `docs/` page on keeping a configuration in git
- **acceptance_criteria**:
  - GIVEN a seeded configuration WHEN exported, imported into a clean register and exported again THEN the directories are identical
  - GIVEN the docs page WHEN read THEN it shows the export, commit, review and confirmed import steps
- [ ] Implement
- [ ] Test (the integration test; docs build)

## Verification

- `openspec validate platform-integrations-as-code --type change --strict`
- `composer check:strict` once before push, then `npm run lint`
- A manual export, commit, import with preview and confirmed import on a second local instance
