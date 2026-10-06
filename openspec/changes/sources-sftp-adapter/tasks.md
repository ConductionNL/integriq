# Tasks: sources-sftp-adapter

Kind: code. Size M. Row `integriq:src-sftp`.

## Implementation tasks

### Task 1: Settle the library
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001`
- **files**: `composer.json`, `composer.lock`
- **acceptance_criteria**:
  - GIVEN the supported Nextcloud versions WHEN the bundled phpseclib major is checked THEN the decision (use core's or add phpseclib 3) is recorded in the PR with the version found
- [ ] Implement
- [ ] Test (`composer audit`, the licence gate, and an app load on each supported Nextcloud version)

### Task 2: The SFTP and FTPS adapters
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001`
- **files**: `lib/Service/Adapter/DataInfra/SftpAdapter.php`, `lib/Service/Adapter/DataInfra/FtpsAdapter.php`, `lib/AppInfo/Application.php`, `lib/Settings/integriq_register.json` (source `hostKeyFingerprint`, `rootPath`)
- **acceptance_criteria**:
  - GIVEN a pinned fingerprint WHEN the server presents another key THEN the connection is refused naming both fingerprints
  - GIVEN a path with .. WHEN any operation is called THEN it is refused
- [ ] Implement
- [ ] Test (PHPUnit against an SFTP container and an FTPS container in the dev compose)

### Task 3: The pickup synchronization
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002`
- **files**: `lib/Service/SynchronizationService.php`, the synchronization editor
- **acceptance_criteria**:
  - GIVEN three new CSV files WHEN the pickup runs THEN three files are in the Nextcloud folder and moved to the remote archive, and a second run fetches nothing
  - GIVEN a failed local write WHEN the pickup runs THEN the remote file stays where it was
- [ ] Implement
- [ ] Test (PHPUnit; one run against the SFTP container)

### Task 4: Source page, seed and documentation
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001`
- **files**: the source detail page (fingerprint confirmation on first test), `lib/Settings/integriq_seed_data.json`, `docs/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a new SFTP source WHEN the administrator tests it the first time THEN the server's fingerprint is shown for confirmation
- [ ] Implement
- [ ] Test (Playwright)

## Verification
- [ ] `openspec validate sources-sftp-adapter --type change --strict` passes
- [ ] No TimedJob class added (REQ-DIC-005)
- [ ] PHPUnit and Playwright run, exit codes read
