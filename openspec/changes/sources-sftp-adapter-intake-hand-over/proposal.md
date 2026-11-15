---
kind: code
depends_on: [sources-sftp-adapter-watched-folder]
---

# Proposal: sources-sftp-adapter-intake-hand-over

## Summary

Let a watched folder hand each file to a named intake app, such as filinq's scan intake, through a typed event, and mark the file only when that app accepts it.

- Rows: Woo row 1.7 "Records arrive from a watched folder or a file drop" (not statutory), the intake half, together with parts 1 and 2.
- Wave: 1.
- Depends on: `integriq/sources-sftp-adapter-watched-folder` (https://github.com/ConductionNL/integriq/issues/2550), part 2 of this chain, which depends on `integriq/sources-sftp-adapter` (https://github.com/ConductionNL/integriq/issues/2539).
- Decision: D4 (2026-10-05), integriq owns the folder watcher and filinq's scan intake consumes it.

Build rules: openspec/woo-build-rules.md

## Why

This is the third part of `sources-sftp-adapter`, split off on 2026-10-06 so each part stays
within 20 tasks. Woo row 1.7, "Records arrive from a watched folder or a file
drop", and Ruben's decision D4 of 2026-10-05: integriq owns the folder
watcher, and filinq's scan intake consumes it for separator-sheet splitting
(`filinq/scan-intake-with-separator-sheets` task 3.2, still open). The second
part, `sources-sftp-adapter-watched-folder`, watches a folder and maps each file onto a draft object
(`target: mapping`). This part adds the other target: hand the file to a named
intake app through a typed event.

## What changes

1. `target: intake` with `intakeApp` on a watched-folder synchronization.
2. `OCA\Integriq\Event\WatchedFileArrivedEvent`, constructed as
   `new WatchedFileArrivedEvent(string $synchronizationId, string $sourceId,
   string $intakeApp, int $fileId, string $path, string $ownerUid)`, with the
   getters `getSynchronizationId(): string`, `getSourceId(): string`,
   `getIntakeApp(): string`, `getFileId(): int`, `getPath(): string` and
   `getOwnerUid(): string`, `accept(string $appId, string $reference): bool`
   and `getResult(): array` answering `accepted` (bool), `intakeApp` (string)
   and `reference` (string or null). The result starts as not accepted.
   `accept()` answers `false` and changes nothing unless `$appId` equals
   `intakeApp`, the reference is not empty and the event was not accepted
   before. The dispatcher carries no caller identity, so the app id argument
   is how a listener says which app accepts.
3. `docs/features/watched-folder.md` states that contract for filinq and any
   other intake, with the `class_exists()` guard a consumer needs when
   integriq is absent.

## Fail closed

An intake hand-over nobody accepts is not a success. The file stays in the
folder, is not moved or tagged, and is reported as `unclaimed` on the run.

## App absent

- filinq absent with `intakeApp: filinq`: no listener accepts, so every file
  is `unclaimed` and stays.
- integriq absent: no folder is watched. filinq's scan intake keeps its
  controller upload, as it does today.

## Dependencies

- `integriq/sources-sftp-adapter-watched-folder`, the second part. Build after it has merged.
- filinq's consumer is `filinq/scan-intake-from-a-watched-folder`
  (https://github.com/ConductionNL/filinq/issues/1351), which carries out
  task 3.2 of `filinq/scan-intake-with-separator-sheets`. It is not part of
  this change. It names the identical signature and has its own test on the
  filinq side dispatching this same class.
- Wave 1. Implements decision D4.
