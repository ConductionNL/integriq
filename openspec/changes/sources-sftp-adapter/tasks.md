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
- [x] Implement
- [x] Test (PHPUnit; one run against the SFTP container)
  > 2026-11-26, live against a real SFTP server (OpenSSH `internal-sftp` on loopback, password sign-in through an inject-only, organisation-scope broker credential, host key pinned as `SHA256:...`, loopback allowed through `egress_allowed_hosts`). Sandbox: Nextcloud 34.0.6 + MariaDB, OpenRegister main 292aa09d.
  > - A pickup synchronization (`outbox`, `*.csv`, `target: files`, `after: move`, `archivePath: archive`) test run lists the three new CSV files and changes nothing.
  > - The first run stores all three in `Partner/inbox` and moves each to the remote `archive` (`after: moved`). The second run picks up nothing. The stored files are byte-identical to the archived ones.
  > - Found and fixed by the run: Nextcloud's `Folder::newFile()` closes the stream it is given, and `FilePickup` closed it again in `finally`. On PHP 8 that throws a TypeError after the write, so the run answered 500 with the file stored but not archived or remembered, and every run stored it again (`export-1 (2).csv`, `export-1 (3).csv`). Fixed by closing only a stream still open. Tested by `FilePickupTest::testAStoreThatClosesTheStreamStillArchivesTheFile`, which errors without the fix. `RemoteFiles` PHPUnit 60/60.
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
  `target: mapping` reads each new file into records (`PickedFileRecords`:
  CSV by header row, JSON list/object, JSON lines, XML children of the root)
  through `MappingRecordStore`; `SynchronizationService` processes them as
  fetched objects (approval gate included, no deletion pass) and only then
  `FilePickupSynchronization::complete()` archives and remembers the files.
  The editor offers the target, the file format and the CSV delimiter.
- **left**: the PHPUnit run against an SFTP container needs the dev compose.

### Task 4: Source page, seed and documentation
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-partners-sftp-or-ftps-server-is-a-source-req-sftp-001`
- **files**: the source detail page (fingerprint confirmation on first test), `lib/Settings/integriq_seed_data.json`, `docs/`, `l10n/nl.json`, `l10n/en.json`
- **acceptance_criteria**:
  - GIVEN a new SFTP source WHEN the administrator tests it the first time THEN the server's fingerprint is shown for confirmation
- [x] Implement
- [x] Test (Playwright): run live 25 Nov 2026 on NC 34 + MariaDB, `tests/e2e/sftp-source.spec.ts` 4/4. The run found an unreachable server answered 500, not 502: phpseclib's connection exception is now a RemoteFileException.
- **note**: the seed has a dormant `example-sftp-partner` source and a
  `status: disabled` pickup `example-sftp-pickup` for `*.csv`, archiving to
  `processed`. The docs are `docs/features/sftp-ftps-file-pickup.md`. A first
  test of an unpinned source is refused with the server's fingerprint in the
  message. The source page has a "Host key" panel (`SourceHostKeyPanel`):
  "Check the host key" reads the presented fingerprint without logging in
  (`GET /api/sources/{id}/host-key`), and after the administrator ticks that
  they checked it with the partner, "Pin this host key" saves it (`POST`),
  refused with 409 when the server presents another key by then. Both
  endpoints are admin-only.

## Verification
- [x] `openspec validate sources-sftp-adapter --type change --strict` passes (openspec 2.2.0, 25 Nov 2026)
- [ ] No TimedJob class added (REQ-DIC-005)
- [ ] PHPUnit and Playwright run, exit codes read
