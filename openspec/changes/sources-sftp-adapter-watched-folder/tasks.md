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
- [x] Implement
  > 2026-11-21, editor built: the synchronization editor offers the source kind `nextcloud-folder` ("Nextcloud folder (watched)", `WATCHED_FOLDER_OPTION`), with the source picker, and `FilePickupConfigFields` in its `watched` mode edits `pattern`, `target` (`mapping` by default, or `intake` with `intakeApp`), `after` (`move` or `tag`), `processedPath`, `failedPath` and `stableSeconds`, and none of the file server's settings. Vitest in `tests/vitest/filePickupConfigFields.spec.js`. A synchronization with `target: intake` is never written through the mapping; it is handed to its app (`sources-sftp-adapter-intake-hand-over` Task 8).
  > 2026-11-21, adapter built: `RemoteFiles\NextcloudFolderFiles` (`listFiles()`, `fetch()` and `test()` through `IRootFolder::getUserFolder($ownerUid)`, every path resolved inside `rootPath` by the same `RemotePath` the SFTP source uses, so `..` is refused before any file is touched) and `NextcloudFolderAdapter`, its data-infra provider face on the category base like the SFTP and FTPS adapters, registered with OpenRegister's `IntegrationRegistry` in `ServiceBindings::boot()`. `SourceTestService::run()` (the "Test connection" route and the hourly health probe) tests a `nextcloud-folder` source through `NextcloudFolderFiles`: a missing folder is a failed test naming the path. `ownerUid` and `rootPath` live in the source's free-form `configuration`, as `rootPath` does for an SFTP source, so the register needs no new property for them. Left, so the box stays unticked: the synchronization editor's fields for `target`, `intakeApp`, `after`, `processedPath`, `failedPath` and `stableSeconds`, which are built with tasks 6 and 7 that read them.
- [x] Test: PHPUnit `tests/Unit/Service/Adapter/DataInfra/NextcloudFolderAdapterTest.php` (`testAMissingFolderFailsTheTest`, `testDotDotIsRefused`)
  - Also `SourceTestServiceTest::testAFolderSourceIsTestedByItsAdapter` (the caller) and `ServiceBindingsFactoriesTest` (the registration).

### Task 6: The listener, the job and the sweep
- **spec_ref**: `openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003`
- **files**: `lib/EventListener/WatchedFolderListener.php`, `lib/BackgroundJob/WatchedFileIngestJob.php` (a `QueuedJob`), `lib/Service/WatchedFolderService.php` (the cached folder map, the sweep and the once-per-file contract), `lib/AppInfo/Application.php` (register the listener on `NodeCreatedEvent` and `NodeWrittenEvent`), the `ScheduledWorkflow` seed for the sweep
- **acceptance_criteria**:
  - GIVEN a watched folder WHEN a real `NodeCreatedEvent` for a file under it is handled THEN one `WatchedFileIngestJob` is added and no `ObjectService` method is called
  - GIVEN a file outside every watched folder WHEN the event is handled THEN nothing is queued
  - GIVEN a file already in the contract WHEN the job runs THEN it ends without a save
  - GIVEN a file modified 10 seconds ago and `stableSeconds` 60 WHEN the job runs THEN it ends without a contract, and the next sweep queues it again
  - GIVEN a file no event announced WHEN the sweep runs THEN it is queued once
- [x] Implement. No `TimedJob` class (REQ-DIC-005).
  > 2026-11-21, built except the sweep's schedule:
  > - `EventListener/WatchedFolderListener` on `NodeCreatedEvent` and `NodeWrittenEvent` (registered in `EventListenerBindings::registerNextcloudCoreListeners()`) queues one `Service/WatchedFolder/WatchedFileIngestJob` (a `QueuedJob`, beside its service like `FetchFilesJob`) per synchronization that watches the file's folder, and reads nothing else. Only a file directly in the watched folder counts, the same files the sweep lists, so the processed and failed folders below it are not watched.
  > - `Service/WatchedFolder/WatchedFolderMap` holds the folder-id map, built by `WatchedFolders`, in the distributed cache. It is rebuilt when the entry expires (60 s), not on a synchronization save, so a changed synchronization takes effect within a minute. It queues a job only once (`IJobList::has()`).
  > - `Service/WatchedFolderService::ingest()` asks the contract `nextcloud-file:{fileId}` first and leaves a file younger than `stableSeconds` for the sweep. `sweep()` lists each watched folder and queues every file without a contract.
  > - Open, so the box stays unticked: what runs `sweep()` on a schedule. REQ-DIC-005 names an OpenRegister `ScheduledWorkflow`, but neither integriq nor OpenRegister development has one, and integriq's own `job` rows do not reach the scheduler (#1534). This needs a decision.
  > 2026-11-24, decided and built: the sweep runs as integriq's own scheduled `job` record, not a `TimedJob` and not a `ScheduledWorkflow` (which OpenRegister still does not have). The seeded `job` `watched-folder-sweep` (enabled, `interval` 300) names `Service/Job/WatchedFolderSweepAction`, which `JobTask` (the one registered `TimedJob`) reaches through `JobService::run()`: that pass reads every enabled `job` row whose `nextRun` is due, without `jobListId`, so #1534 (`scheduleJob()` has no caller) does not stop it. The action logs `SUCCESS` with the count queued, or `WARNING` naming each synchronization stopped on an unresolved target. A record, so an administrator can re-time or disable it. When OpenRegister gains a `ScheduledWorkflow`, the job row moves there.
- [x] Test: PHPUnit `WatchedFolderListenerTest::testTheListenerOnlyQueues` and `::testAFileOutsideAWatchedFolderIsIgnored`, constructing the real `OCP\Files\Events\Node\NodeCreatedEvent` (constructor `(Node $node)`); `WatchedFileIngestJobTest::testAFileInTheContractIsNotIngestedAgain` and `::testAYoungFileWaitsForTheSweep`; `WatchedFolderSweepTest::testAnUnannouncedFileIsQueued`
- [x] Wiring: a test that boots `Application::register()` with a registration context double and asserts `WatchedFolderListener` is registered for both node events, and a test that the seeded `ScheduledWorkflow` names the sweep. A job with a green suite and no caller is the defect this line exists to prevent.
  > Half done: `ApplicationRegistrationOrderTest` boots `Application::register()` and now records `WatchedFolderListener` on both node events (`tests/fixtures/application-registration-order.json`). The seeded `ScheduledWorkflow` waits on the decision above.
  > Done: `WatchedFolderSeedTest::testTheSeededJobNamesTheSweep` (the seeded job names an existing class with `run()`, every 300 s, enabled, not a `TimedJob`) and `WatchedFolderSweepActionTest` (the action runs the real sweep: an unannounced file queued, a stopped synchronization a warning, no folder a no-op).

### Task 7: Target mapping, draft only, and marking after a safe write
- **spec_ref**: `openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-run-never-publishes-and-only-marks-a-file-after-a-safe-write-req-sftp-004`
- **files**: `lib/BackgroundJob/WatchedFileIngestJob.php`, `lib/Service/WatchedFolderService.php`
- **acceptance_criteria**:
  - GIVEN a mapping that sets `published` WHEN a file is ingested THEN the saved object has no `published` and no `publicatiedatum`, and the run log names each removed key
  - GIVEN a successful save WHEN `after: move` THEN the file is in `processedPath`; WHEN `after: tag` THEN it carries the system tag `integriq-ingested`
  - GIVEN `saveObject()` or `addFile()` throws WHEN the job runs THEN the file goes to `failedPath` or gets `integriq-ingest-failed`, and nothing is marked ingested
  - GIVEN a target schema that does not resolve WHEN the sweep runs THEN it stops before reading any file
- [x] Implement
  > `Service/WatchedFolder/WatchedFileWriter` describes the file as `{fileId, name, path, mimetype, size, mtime, owner}` (plus `content` as text for CSV, JSON or XML; parsing it is the mapping's), runs the synchronization's mapping, removes `published` and `publicatiedatum` and logs each removed key, saves through `ObjectService::saveObject()` and attaches the file with `FileService::addFile(target: new FileTarget(object: $uuid), upload: new FileUpload(...))`, the signature on development (not the one this task names). Only then does `WatchedFolderService` make the contract, and `WatchedFileMarker` moves the file to `processedPath` or tags it `integriq-ingested`. A failed save or attach moves it to `failedPath` or tags it `integriq-ingest-failed`, and no contract is made. A target that does not resolve means a `targetId` that names no `register/schema`; the sweep stops on it before listing the folder.
- [x] Test: PHPUnit `WatchedFileIngestJobTest::testThePublicationDateIsStripped`, `::testAFailedSaveMovesTheFileToFailed`, `::testASuccessfulSaveMovesTheFileToProcessed`; `WatchedFolderSweepTest::testAnUnresolvedTargetStopsBeforeAnyFile`. Double OpenRegister's `ObjectService` and `FileService` with `environmentAwareDouble`, after reading the real `saveObject()` and `addFile(ObjectEntity|string $objectEntity, string $fileName, mixed $content, bool $share = false, array $tags = [], ...)` signatures on `openregister` `development`.
  > Doubled with `createMock()` and `getMockBuilder()->onlyMethods(['addFile'])`, because `environmentAwareDouble` does not exist in this repo. Also `::testAFailedSaveMovesTheFileToFailed` covers a failing attach, plus `::testTaggingInsteadOfMoving` and `::testAnUnresolvedTargetWritesNothing`.
- [x] `testThePublicationDateIsStripped` must be shown failing on `development` before the change (the class does not exist). Paste the failing line in the PR body.

### Task 9: Seed, page and documentation
- **spec_ref**: `openspec/changes/sources-sftp-adapter-watched-folder/specs/data-infra-connectors/spec.md#requirement-a-folder-in-nextcloud-files-is-a-watched-source-req-sftp-003`
- **files**: `lib/Settings/integriq_seed_data.json` (a disabled example `example-watched-folder-publications` onto opencatalogi's `publication` schema), the synchronization editor, `l10n/en.json`, `l10n/nl.json`, `docs/features/watched-folder.md`
- **acceptance_criteria**:
  - GIVEN a fresh install WHEN the seed loads THEN the example exists and is disabled
  - GIVEN the officer drops a PDF in the watched folder WHEN the job has run THEN the draft publication is listed in opencatalogi with the PDF attached and no publication date
- [x] Implement. Add the seed row by hand; do not run a generator over the seed file.
  > Seeded by hand in `lib/Settings/integriq_seed_data.json`: the dormant source `example-watched-folder` (`nextcloud-folder`, `woo-intake`'s `Woo/inkomend`) and the disabled synchronization `example-watched-folder-publications` (PDFs into `opencatalogi/publication` as drafts, moved to `processed` or `failed`). The editor fields are Task 5, the strings are in `l10n/en.js` and `l10n/nl.js`, and `docs/features/watched-folder.md` is the page (with the intake contract). Test: `tests/Unit/Settings/WatchedFolderSeedTest.php`. The screenshots and the Playwright run need a live instance.
- [x] Test: Playwright `tests/e2e/watched-folder.spec.ts` (enable the example, upload a file through the Files app, see the draft publication)
  > 2026-11-26, live: 1/1 (sandbox Nextcloud 34.0.6 + MariaDB, OpenRegister main 292aa09d, opencatalogi development 38125c5). It passed after one fix found by the run. `WatchedFileIngestJob` runs in cron with nobody signed in. The draft publication was written, then OpenRegister refused the synchronization contract ("User 'Anonymous' does not have permission to 'create' objects in schema 'SynchronizationContract'"), so the file never moved to `processed` and stayed open for another ingest. The job now runs its ingest as the system (`SystemWrite`, the 25 Nov decision that integriq jobs without a userId run in OpenRegister's `SystemOperationContext`). Tested by `WatchedFileIngestJobSystemContextTest`, which fails without the fix. Sandbox note: this instance's own cron spends ~10 minutes per run on an OpenRegister text-extraction backlog and then stops on an OpenRegister job (`Duplicate entry 'AnnotationNotificationDispatchJob' for key 'class_index'`), so the run executed the queued `WatchedFileIngestJob` rows directly with `occ background-job:execute`.

## Verification

The building agent follows `openspec/woo-build-rules.md`:

- [ ] Own clone, `git checkout --no-track -b <branch> origin/development`, `TMPDIR` a sibling outside the clone.
- [ ] PHPUnit judged by the `Tests:` line, or with `--no-coverage`; a green suite exits 1 without a coverage driver.
- [ ] `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run format`, `npm run check:l10n`, `npm run check:l10n-js`, `npm run check:manifest`, `npm run check:schema-l10n`, `npm run check:register` (the register changed).
- [ ] CI runs the gates on the full tree; the coverage guard needs a test for every added statement.
- [x] `openspec validate sources-sftp-adapter-watched-folder --type change --strict` passes.
- [ ] One PR, `--base development`; merge `development` in, never rebase; no `Co-Authored-By` trailer.
- [ ] Done means merged on `development` with CI green. Row 1.7 is `production` only once a store release carries it.
