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

## Amendment 2026-10-05: tasks for Woo row 1.7 (decision D4)

Build these after tasks 1 to 4, in the same PR or a follow-up. Every test named
here fails on `development` today: no `nextcloud-folder` source type, watcher
listener, ingest job or `WatchedFileArrivedEvent` exists.

### Task 5: The `nextcloud-folder` source and its pickup settings
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003`
- **files**: `lib/Settings/integriq_register.json` (source type `nextcloud-folder` with `ownerUid` and `rootPath`; synchronization `sourceConfig` keys `target`, `intakeApp`, `after`, `processedPath`, `failedPath`, `stableSeconds`; register version bump), the synchronization editor, `lib/Service/Adapter/DataInfra/NextcloudFolderAdapter.php` (`list()` and `fetch()` through `IRootFolder::getUserFolder($ownerUid)`)
- **acceptance_criteria**:
  - GIVEN a source with an owner and a path that does not exist WHEN it is tested THEN the test fails naming the path
  - GIVEN a path with `..` WHEN any operation is called THEN it is refused
- [ ] Implement
- [ ] Test: PHPUnit `tests/Unit/Service/Adapter/DataInfra/NextcloudFolderAdapterTest.php` (`testAMissingFolderFailsTheTest`, `testDotDotIsRefused`)

### Task 6: The listener, the job and the sweep
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003`
- **files**: `lib/EventListener/WatchedFolderListener.php`, `lib/BackgroundJob/WatchedFileIngestJob.php` (a `QueuedJob`), `lib/Service/WatchedFolderService.php` (the cached folder map, the sweep and the once-per-file contract), `lib/AppInfo/Application.php` (register the listener on `NodeCreatedEvent` and `NodeWrittenEvent`), the `ScheduledWorkflow` seed for the sweep
- **acceptance_criteria**:
  - GIVEN a watched folder WHEN a real `NodeCreatedEvent` for a file under it is handled THEN one `WatchedFileIngestJob` is added and no `ObjectService` method is called
  - GIVEN a file outside every watched folder WHEN the event is handled THEN nothing is queued
  - GIVEN a file already in the contract WHEN the job runs THEN it ends without a save
  - GIVEN a file modified 10 seconds ago and `stableSeconds` 60 WHEN the job runs THEN it ends without a contract, and the next sweep queues it again
  - GIVEN a file no event announced WHEN the sweep runs THEN it is queued once
- [ ] Implement. No `TimedJob` class (REQ-DIC-005).
- [ ] Test: PHPUnit `WatchedFolderListenerTest::testTheListenerOnlyQueues` and `::testAFileOutsideAWatchedFolderIsIgnored`, constructing the real `OCP\Files\Events\Node\NodeCreatedEvent` (constructor `(Node $node)`); `WatchedFileIngestJobTest::testAFileInTheContractIsNotIngestedAgain` and `::testAYoungFileWaitsForTheSweep`; `WatchedFolderSweepTest::testAnUnannouncedFileIsQueued`
- [ ] Wiring: a test that boots `Application::register()` with a registration context double and asserts `WatchedFolderListener` is registered for both node events, and a test that the seeded `ScheduledWorkflow` names the sweep. A job with a green suite and no caller is the defect this line exists to prevent.

### Task 7: Target mapping, draft only, and marking after a safe write
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-run-never-publishes-and-only-marks-a-file-after-a-safe-write-req-sftp-004`
- **files**: `lib/BackgroundJob/WatchedFileIngestJob.php`, `lib/Service/WatchedFolderService.php`
- **acceptance_criteria**:
  - GIVEN a mapping that sets `published` WHEN a file is ingested THEN the saved object has no `published` and no `publicatiedatum`, and the run log names each removed key
  - GIVEN a successful save WHEN `after: move` THEN the file is in `processedPath`; WHEN `after: tag` THEN it carries the system tag `integriq-ingested`
  - GIVEN `saveObject()` or `addFile()` throws WHEN the job runs THEN the file goes to `failedPath` or gets `integriq-ingest-failed`, and nothing is marked ingested
  - GIVEN a target schema that does not resolve WHEN the sweep runs THEN it stops before reading any file
- [ ] Implement
- [ ] Test: PHPUnit `WatchedFileIngestJobTest::testThePublicationDateIsStripped`, `::testAFailedSaveMovesTheFileToFailed`, `::testASuccessfulSaveMovesTheFileToProcessed`; `WatchedFolderSweepTest::testAnUnresolvedTargetStopsBeforeAnyFile`. Double OpenRegister's `ObjectService` and `FileService` with `environmentAwareDouble`, after reading the real `saveObject()` and `addFile(ObjectEntity|string $objectEntity, string $fileName, mixed $content, bool $share = false, array $tags = [], ...)` signatures on `openregister` `development`.
- [ ] `testThePublicationDateIsStripped` must be shown failing on `development` before the change (the class does not exist). Paste the failing line in the PR body.

### Task 8: Target intake through `WatchedFileArrivedEvent`
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005`
- **files**: `lib/Event/WatchedFileArrivedEvent.php`, `lib/BackgroundJob/WatchedFileIngestJob.php`, `docs/features/watched-folder.md` (the contract for filinq and any other intake: constructor, `accept()`, every result key, and the `class_exists()` guard a consumer needs when integriq is absent)
- **acceptance_criteria**:
  - GIVEN a listener that accepts WHEN the job dispatches THEN `getResult()` is `['accepted' => true, 'intakeApp' => 'filinq', 'reference' => 'batch-77']` and the file is marked
  - GIVEN no listener WHEN the job dispatches THEN the result stays `accepted: false`, the file is not moved or tagged, and the run counts it `unclaimed`
  - GIVEN a listener for another app id WHEN it calls `accept()` THEN the event refuses it and the result stays not accepted
- [ ] Implement
- [ ] Test: PHPUnit `WatchedFileIngestJobTest::testAnAcceptedHandOverMarksTheFile` and `::testAnUnclaimedHandOverLeavesTheFile`, dispatching the real event class through a real `IEventDispatcher`; `WatchedFileArrivedEventTest::testOnlyTheNamedIntakeMayAccept`
- [ ] Cross-app: record in the PR body that filinq's consumer is `filinq/scan-intake-with-separator-sheets` task 3.2, which needs its own test on the filinq side dispatching this same class.

### Task 9: Seed, page and documentation
- **spec_ref**: `openspec/changes/sources-sftp-adapter/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003`
- **files**: `lib/Settings/integriq_seed_data.json` (a disabled example `example-watched-folder-publications` onto opencatalogi's `publication` schema), the synchronization editor, `l10n/en.json`, `l10n/nl.json`, `docs/features/watched-folder.md`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the seed loads THEN the example exists and is disabled
  - GIVEN the officer drops a PDF in the watched folder WHEN the job has run THEN the draft publication is listed in opencatalogi with the PDF attached and no publication date
- [ ] Implement. Add the seed row by hand; do not run a generator over the seed file.
- [ ] Test: Playwright `tests/e2e/watched-folder.spec.ts` (enable the example, upload a file through the Files app, see the draft publication)

### Verification for tasks 5 to 9

The building agent follows `~/memcap-work/woo-build/LANE-RULES-BUILD.md`:

- [ ] Own clone, `git checkout --no-track -b <branch> origin/development`, `TMPDIR` a sibling outside the clone.
- [ ] PHPUnit judged by the `Tests:` line, or with `--no-coverage`; a green suite exits 1 without a coverage driver.
- [ ] `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run format`, `npm run check:l10n`, `npm run check:l10n-js`, `npm run check:manifest`, `npm run check:schema-l10n`, `npm run check:register` (the register changed).
- [ ] CI runs the gates on the full tree; the coverage guard needs a test for every added statement.
- [ ] `openspec validate sources-sftp-adapter --type change --strict` passes.
- [ ] One PR, `--base development`; merge `development` in, never rebase; no `Co-Authored-By` trailer.
- [ ] Done means merged on `development` with CI green. Row 1.7 is `production` only once a store release carries it.
