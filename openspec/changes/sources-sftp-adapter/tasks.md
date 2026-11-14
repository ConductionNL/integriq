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
- [x] Implement
- [ ] Test (PHPUnit against an SFTP container and an FTPS container in the dev compose)

### Task 3: The pickup synchronization
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-new-files-are-picked-up-and-archived-after-a-safe-local-write-req-sftp-002`
- **files**: `lib/Service/SynchronizationService.php`, the synchronization editor
- **acceptance_criteria**:
  - GIVEN three new CSV files WHEN the pickup runs THEN three files are in the Nextcloud folder and moved to the remote archive, and a second run fetches nothing
  - GIVEN a failed local write WHEN the pickup runs THEN the remote file stays where it was
- [ ] Implement (all but the mapping target, see the note)
- [ ] Test (PHPUnit; one run against the SFTP container)
- **note on what is built**: `FilePickup` (the pickup itself) and
  `FilePickupSynchronization`, which `SynchronizationService` runs for a
  synchronization whose `sourceType` is `sftp` or `ftps`, instead of fetching
  objects. Files go to the Files of the synchronization's owner, never of a
  user the configuration names. Each picked-up file is remembered in one
  synchronization contract (`originId` the file name, `originHash` its
  modification time and size, `targetId` where it was stored), so a second run
  fetches nothing and a file sent again with new content is picked up again.
  A test run only lists. A pickup that cannot run stops the run with its
  reason on the run log. The synchronization editor offers "SFTP (file
  pickup)" and "FTPS (file pickup)" as source kinds with
  `FilePickupConfigFields` for the settings.
- **left**: `target: mapping` (hand the parsed CSV, JSON or XML content to
  the mapping) is refused with a clear message; it needs the engine's object
  path fed from a file and is its own piece of work. The PHPUnit run against
  an SFTP container needs the dev compose.

### Task 4: Source page, seed and documentation
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001`
- **files**: the source detail page (fingerprint confirmation on first test), `lib/Settings/integriq_seed_data.json`, `docs/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a new SFTP source WHEN the administrator tests it the first time THEN the server's fingerprint is shown for confirmation
- [ ] Implement (seed and docs done; the confirmation step on the source page is left)
- [ ] Test (Playwright)
- **note**: the seed has a dormant `example-sftp-partner` source and a
  `status: disabled` pickup `example-sftp-pickup` for `*.csv`, archiving to
  `processed`. The docs are `docs/features/sftp-ftps-file-pickup.md`. A first
  test of an unpinned source is refused with the server's fingerprint in the
  message, so it can already be checked and filled in; a dedicated confirm
  button on the source page is not built.

## Verification
- [ ] `openspec validate sources-sftp-adapter --type change --strict` passes
- [ ] No TimedJob class added (REQ-DIC-005)
- [ ] PHPUnit and Playwright run, exit codes read
