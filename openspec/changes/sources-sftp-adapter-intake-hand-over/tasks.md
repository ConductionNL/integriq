# Tasks: sources-sftp-adapter-intake-hand-over

Kind: code. Size S. Woo row 1.7, decision D4. Wave 1. The third part of
`sources-sftp-adapter`; task numbers continue from it. Build after `sources-sftp-adapter-watched-folder` has merged on
`development`: the job, the contract and the marking this task extends come
from there.

Every test named here fails on `development` today: no
`WatchedFileArrivedEvent` exists.

### Task 8: Target intake through `WatchedFileArrivedEvent`
- **spec_ref**: `openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005`
- **files**: `lib/Event/WatchedFileArrivedEvent.php`, `lib/BackgroundJob/WatchedFileIngestJob.php`, `docs/features/watched-folder.md` (the contract for filinq and any other intake: the constructor, the six getters with their return types, `accept(string $appId, string $reference): bool`, every result key, and the `class_exists()` guard a consumer needs when integriq is absent)
- **acceptance_criteria**:
  - The class has exactly the public surface of REQ-SFTP-005: the constructor `(string $synchronizationId, string $sourceId, string $intakeApp, int $fileId, string $path, string $ownerUid)`, `getSynchronizationId(): string`, `getSourceId(): string`, `getIntakeApp(): string`, `getFileId(): int`, `getPath(): string`, `getOwnerUid(): string`, `accept(string $appId, string $reference): bool` and `getResult(): array`. filinq's `scan-intake-from-a-watched-folder` copies these literals into its contract test, so a rename on either side fails a test.
  - GIVEN a listener that calls `accept('filinq', 'batch-77')` WHEN the job dispatches THEN `accept()` answers `true`, `getResult()` is `['accepted' => true, 'intakeApp' => 'filinq', 'reference' => 'batch-77']` and the file is marked
  - GIVEN no listener WHEN the job dispatches THEN the result stays `accepted: false`, the file is not moved or tagged, and the run counts it `unclaimed`
  - GIVEN `intakeApp: filinq` WHEN a listener calls `accept('dossiq', 'case-12')`, or `accept('filinq', '  ')`, or accepts a second time THEN `accept()` answers `false` and the result is unchanged
- [ ] Implement
- [ ] Test, through the caller: PHPUnit `WatchedFileIngestJobTest::testAnAcceptedHandOverMarksTheFile`, `::testAnUnclaimedHandOverLeavesTheFile`, `::testAnAcceptanceByAnotherAppLeavesTheFile` and `::testTheEventCarriesTheFileItFound` (the six getters answer the synchronization, source, intake app, file id, path and owner the job found), dispatching the real event class through a real `IEventDispatcher`. Each fails on `development` today: the class does not exist.
- [ ] Test, the class itself: PHPUnit `WatchedFileArrivedEventTest::testTheNamedIntakeMayAccept`, `::testAnotherAppIdIsRefused`, `::testAnEmptyReferenceIsRefused`, `::testASecondAcceptIsRefused`, and `::testThePublicSurfaceIsTheContract`, which asserts by reflection the constructor parameter names and types, the six getters and their return types and `accept(string $appId, string $reference): bool`, as literals.
- [ ] Cross-app: record in the PR body that filinq's consumer is `filinq/scan-intake-from-a-watched-folder` (https://github.com/ConductionNL/filinq/issues/1351), whose `WatchedFileArrivedContractTest` asserts the same literals on the filinq side and whose listener test dispatches this same class.

## Verification

The building agent follows `openspec/woo-build-rules.md`:

- [ ] Own clone, `git checkout --no-track -b <branch> origin/development`, `TMPDIR` a sibling outside the clone.
- [ ] PHPUnit judged by the `Tests:` line, or with `--no-coverage`; a green suite exits 1 without a coverage driver.
- [ ] `run-hydra-gates.sh --base origin/development`, counting the gates that ran.
- [ ] Once before push: `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict`, `npm run lint`, `npm run format`, `npm run check:l10n`, `npm run check:l10n-js`, `npm run check:manifest`, `npm run check:schema-l10n`, `npm run check:register` (the register changed).
- [ ] CI runs the gates on the full tree; the coverage guard needs a test for every added statement.
- [ ] `openspec validate sources-sftp-adapter-intake-hand-over --type change --strict` passes.
- [ ] One PR, `--base development`; merge `development` in, never rebase; no `Co-Authored-By` trailer.
- [ ] Done means merged on `development` with CI green. Row 1.7 is `production` only once a store release carries it.
