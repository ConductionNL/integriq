# Tasks: sources-sftp-adapter-watched-folder

Kind: code. Size S. Woo row 1.7, decision D4. Wave 1. The second part of
`sources-sftp-adapter`; task numbers continue from it. Task 8 (the intake hand-over) is the
third part, `sources-sftp-adapter-intake-hand-over`.

Build these after tasks 1 to 4 of `sources-sftp-adapter` have merged on `development`. Every test named
here fails on `development` today: no `nextcloud-folder` source type, watcher
listener, ingest job or `WatchedFileArrivedEvent` exists.

### Task 5: The `nextcloud-folder` source and its pickup settings
- **spec_ref**: `openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003`
- **files**: `lib/Settings/integriq_register.json` (source type `nextcloud-folder` with `ownerUid` and `rootPath`; synchronization `sourceConfig` keys `target`, `intakeApp`, `after`, `processedPath`, `failedPath`, `stableSeconds`; register version bump), the synchronization editor, `lib/Service/Adapter/DataInfra/NextcloudFolderAdapter.php` (`list()` and `fetch()` through `IRootFolder::getUserFolder($ownerUid)`)
- **acceptance_criteria**:
  - GIVEN a source with an owner and a path that does not exist WHEN it is tested THEN the test fails naming the path
  - GIVEN a path with `..` WHEN any operation is called THEN it is refused
- [ ] Implement
- [ ] Test: PHPUnit `tests/Unit/Service/Adapter/DataInfra/NextcloudFolderAdapterTest.php` (`testAMissingFolderFailsTheTest`, `testDotDotIsRefused`)

### Task 6: The listener, the job and the sweep
- **spec_ref**: `openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003`
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
- **spec_ref**: `openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-run-never-publishes-and-only-marks-a-file-after-a-safe-write-req-sftp-004`
- **files**: `lib/BackgroundJob/WatchedFileIngestJob.php`, `lib/Service/WatchedFolderService.php`
- **acceptance_criteria**:
  - GIVEN a mapping that sets `published` WHEN a file is ingested THEN the saved object has no `published` and no `publicatiedatum`, and the run log names each removed key
  - GIVEN a successful save WHEN `after: move` THEN the file is in `processedPath`; WHEN `after: tag` THEN it carries the system tag `integriq-ingested`
  - GIVEN `saveObject()` or `addFile()` throws WHEN the job runs THEN the file goes to `failedPath` or gets `integriq-ingest-failed`, and nothing is marked ingested
  - GIVEN a target schema that does not resolve WHEN the sweep runs THEN it stops before reading any file
- [ ] Implement
- [ ] Test: PHPUnit `WatchedFileIngestJobTest::testThePublicationDateIsStripped`, `::testAFailedSaveMovesTheFileToFailed`, `::testASuccessfulSaveMovesTheFileToProcessed`; `WatchedFolderSweepTest::testAnUnresolvedTargetStopsBeforeAnyFile`. Double OpenRegister's `ObjectService` and `FileService` with `environmentAwareDouble`, after reading the real `saveObject()` and `addFile(ObjectEntity|string $objectEntity, string $fileName, mixed $content, bool $share = false, array $tags = [], ...)` signatures on `openregister` `development`.
- [ ] `testThePublicationDateIsStripped` must be shown failing on `development` before the change (the class does not exist). Paste the failing line in the PR body.

### Task 9: Seed, page and documentation
- **spec_ref**: `openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003`
- **files**: `lib/Settings/integriq_seed_data.json` (a disabled example `example-watched-folder-publications` onto opencatalogi's `publication` schema), the synchronization editor, `l10n/en.json`, `l10n/nl.json`, `docs/features/watched-folder.md`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the seed loads THEN the example exists and is disabled
  - GIVEN the officer drops a PDF in the watched folder WHEN the job has run THEN the draft publication is listed in opencatalogi with the PDF attached and no publication date
- [ ] Implement. Add the seed row by hand; do not run a generator over the seed file.
- [ ] Test: Playwright `tests/e2e/watched-folder.spec.ts` (enable the example, upload a file through the Files app, see the draft publication)

## Verification

The building agent follows `openspec/woo-build-rules.md`:

- [ ] Own clone, `git checkout --no-track -b <branch> origin/development`, `TMPDIR` a sibling outside the clone.
- [ ] PHPUnit judged by the `Tests:` line, or with `--no-coverage`; a green suite exits 1 without a coverage driver.
- [ ] `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run format`, `npm run check:l10n`, `npm run check:l10n-js`, `npm run check:manifest`, `npm run check:schema-l10n`, `npm run check:register` (the register changed).
- [ ] CI runs the gates on the full tree; the coverage guard needs a test for every added statement.
- [ ] `openspec validate sources-sftp-adapter-watched-folder --type change --strict` passes.
- [ ] One PR, `--base development`; merge `development` in, never rebase; no `Co-Authored-By` trailer.
- [ ] Done means merged on `development` with CI green. Row 1.7 is `production` only once a store release carries it.
