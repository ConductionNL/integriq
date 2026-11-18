# Tasks: sources-sftp-adapter

Kind: code. Size M. Row `integriq:src-sftp`.

## Implementation tasks

### Task 1: Settle the library
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001`
- **files**: `composer.json`, `composer.lock`
- **acceptance_criteria**:
  - GIVEN the supported Nextcloud versions WHEN the bundled phpseclib major is checked THEN the decision (use core's or add phpseclib 3) is recorded in the PR with the version found
- [x] Implement (Nextcloud 32 to 34 bundle phpseclib 2.0.55, only 35 bundles 3.0.55, so `phpseclib/phpseclib ^3.0.54` is added; 3.0.57 locked, MIT; `composer.json`)
- [ ] Test: `composer audit` clean on the require (no advisories), licence gate in the Hydra gates run; app load on each supported Nextcloud version (live pass, decision 139)

### Task 2: The SFTP and FTPS adapters
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001`
- **files**: `lib/Service/Adapter/DataInfra/SftpAdapter.php`, `lib/Service/Adapter/DataInfra/FtpsAdapter.php`, `lib/AppInfo/Application.php`, `lib/Settings/integriq_register.json` (source `hostKeyFingerprint`, `rootPath`)
- **acceptance_criteria**:
  - GIVEN a pinned fingerprint WHEN the server presents another key THEN the connection is refused naming both fingerprints
  - GIVEN a path with .. WHEN any operation is called THEN it is refused
- [x] Implement (`lib/Service/Adapter/DataInfra/{AbstractFileTransferAdapter,SftpAdapter,FtpsAdapter}.php`, `FileTransfer/{SftpClient,FtpsClient,RemotePath,RemoteFileClient,RemoteFileClientFactory}.php`, `lib/Settings/register.d/sources-sftp-adapter.json`; FTPS runs on curl, not ext-ftp, because `ftp_ssl_connect()` never checks the certificate)
- [x] Test: `tests/Unit/Service/Adapter/FileTransfer/SftpAdapterTest.php` (pin mismatch names both, `..` refused, broker credential after the pin check, egress refusal, MLSD, fingerprint form)
- [ ] Test against an SFTP container and an FTPS container (live pass, decision 139)

### Task 3: The pickup synchronization
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002`
- **files**: `lib/Service/SynchronizationService.php`, the synchronization editor
- **acceptance_criteria**:
  - GIVEN three new CSV files WHEN the pickup runs THEN three files are in the Nextcloud folder and moved to the remote archive, and a second run fetches nothing
  - GIVEN a failed local write WHEN the pickup runs THEN the remote file stays where it was
- [x] Implement (`lib/Service/FilePickupService.php`; `SynchronizationService::synchronize()` hands `sourceType` sftp/ftps to `runFilePickup()`; the synchronization editor takes the keys in `sourceConfig`)
- [x] Test: `tests/Unit/Service/FilePickupServiceTest.php` (three files stored and archived, second run fetches nothing, failed write leaves the remote file, stableSeconds, test run, mapped rows never published), `SynchronizationFilePickupDispatchTest.php`
- [ ] One run against the SFTP container (live pass, decision 139)

### Task 4: Source page, seed and documentation
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001`
- **files**: the source detail page (fingerprint confirmation on first test), `lib/Settings/integriq_seed_data.json`, `docs/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a new SFTP source WHEN the administrator tests it the first time THEN the server's fingerprint is shown for confirmation
- [x] Implement (`src/modals/v2/FileServerTestModal.vue` opened by "Test connection" for sftp/ftps sources, `lib/Controller/FileServerSourcesController.php` test + pin, seed `example-sftp-partner` + `example-sftp-pickup`, `docs/features/sftp-sources.md`, en/nl strings)
- [x] Test: `tests/Unit/Controller/FileServerSourcesControllerTest.php`, `tests/vitest/fileServerTestState.spec.js`
- [ ] Playwright `tests/e2e/sftp-source.spec.ts` written, not run (live pass, decision 139)

## Verification
- [ ] `openspec validate sources-sftp-adapter --type change --strict` passes (openspec CLI not installed in the lane)
- [x] No TimedJob class added (REQ-DIC-005)
- [x] PHPUnit run, exit codes read; Playwright (live pass, decision 139)
