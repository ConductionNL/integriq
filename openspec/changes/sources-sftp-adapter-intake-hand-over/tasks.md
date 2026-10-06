# Tasks: sources-sftp-adapter-intake-hand-over

Kind: code. Size S. Woo row 1.7, decision D4. Wave 1. The third part of
`sources-sftp-adapter`; task numbers continue from it. Build after `sources-sftp-adapter-watched-folder` has merged on
`development`: the job, the contract and the marking this task extends come
from there.

Every test named here fails on `development` today: no
`WatchedFileArrivedEvent` exists.

### Task 8: Target intake through `WatchedFileArrivedEvent`
- **spec_ref**: `openspec/changes/sources-sftp-adapter-intake-hand-over/specs/data-infra-connectors/spec.md#requirement-a-watched-folder-hands-a-file-to-a-named-intake-through-a-typed-event-req-sftp-005`
- **files**: `lib/Event/WatchedFileArrivedEvent.php`, `lib/BackgroundJob/WatchedFileIngestJob.php`, `docs/features/watched-folder.md` (the contract for filinq and any other intake: constructor, `accept()`, every result key, and the `class_exists()` guard a consumer needs when integriq is absent)
- **acceptance_criteria**:
  - GIVEN a listener that accepts WHEN the job dispatches THEN `getResult()` is `['accepted' => true, 'intakeApp' => 'filinq', 'reference' => 'batch-77']` and the file is marked
  - GIVEN no listener WHEN the job dispatches THEN the result stays `accepted: false`, the file is not moved or tagged, and the run counts it `unclaimed`
  - GIVEN a listener for another app id WHEN it calls `accept()` THEN the event refuses it and the result stays not accepted
- [ ] Implement
- [ ] Test: PHPUnit `WatchedFileIngestJobTest::testAnAcceptedHandOverMarksTheFile` and `::testAnUnclaimedHandOverLeavesTheFile`, dispatching the real event class through a real `IEventDispatcher`; `WatchedFileArrivedEventTest::testOnlyTheNamedIntakeMayAccept`
- [ ] Cross-app: record in the PR body that filinq's consumer is `filinq/scan-intake-with-separator-sheets` task 3.2, which needs its own test on the filinq side dispatching this same class.

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
