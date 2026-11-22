# Watched folders

## Overview

An officer drops a file into a folder in Nextcloud Files, and integriq picks
it up from there. A watched folder is the folder of a **Nextcloud folder**
source. A synchronization on that source says what happens to each file
dropped there. It is either written into a register as a **draft** through
the synchronization's mapping, or handed to another app that takes it in,
such as filinq's scan intake. Integriq never publishes such a draft: an
officer publishes it after checking it.

## How a file is picked up

1. A file is created or written directly in the watched folder. Files in
   folders below it are not picked up, so the processed and failed folders
   are never read again.
2. Integriq queues one background job for the file, per synchronization that
   watches the folder. Nothing is read or written on the upload itself.
3. The job ends at once when the file already has a synchronization contract
   (`nextcloud-file:<file id>`), so a file is handled once.
4. A file changed more recently than **Wait before picking up** is left for
   the next run, so a file still being written is not read half.
5. The file is delivered to the synchronization's target, see below.
6. Only after a safe delivery is the contract made and the file marked:
   moved to the processed folder, or given the system tag `integriq-ingested`.
   A failed delivery moves it to the failed folder, or tags it
   `integriq-ingest-failed`, and makes no contract, so it can be tried again.

The sweep lists each watched folder and queues every file that has no
contract yet, for files the event did not reach. What runs the sweep on a
schedule is still open.

## Set it up

1. Create a source of type `nextcloud-folder` with, in its configuration,
   `ownerUid` (whose Files hold the folder) and `rootPath` (the folder). The
   source's **Test connection** fails naming the path when the folder does
   not exist. A path with `..` is refused.
2. Create a synchronization with source kind **Nextcloud folder (watched)**,
   pick the source, and fill in the pickup settings:

| Setting | Key | Default | Meaning |
|---------|-----|---------|---------|
| File name pattern | `pattern` | `*` | Only matching names are picked up |
| Dropped files go to | `target` | `mapping` | `mapping`: a draft object; `intake`: an app |
| Intake app | `intakeApp` | | The app id that takes the file in, for `intake` |
| After a file is handled | `after` | `move` | `move` to a folder, or `tag` it |
| Processed folder | `processedPath` | `processed` | Inside the watched folder, for `move` |
| Failed folder | `failedPath` | `failed` | Inside the watched folder, for `move` |
| Wait before picking up | `stableSeconds` | `60` | Seconds a file must be left alone |

## Target `mapping`

The mapping sees the file as
`{fileId, name, path, mimetype, size, mtime, owner}`, plus `content` as text
for a CSV, JSON or XML file. The synchronization's `targetId` names the
register and schema (`register/schema`). Integriq removes `published` and
`publicatiedatum` from the mapped object, and logs each key it removed. It
then saves the draft and attaches the file to it.

## Target `intake`: the contract for an intake app

For `target: intake` integriq dispatches
`OCA\Integriq\Event\WatchedFileArrivedEvent`, which extends
`OCP\EventDispatcher\Event`. Its public surface is a contract, and filinq's
contract test asserts the same literals:

```php
public function __construct(string $synchronizationId, string $sourceId, string $intakeApp,
    int $fileId, string $path, string $ownerUid)
public function getSynchronizationId(): string
public function getSourceId(): string      // the nextcloud-folder source
public function getIntakeApp(): string
public function getFileId(): int
public function getPath(): string          // relative to the owner's user folder
public function getOwnerUid(): string
public function accept(string $appId, string $reference): bool
public function getResult(): array
```

`getResult()` answers three keys: `accepted` (bool), `intakeApp` (string) and
`reference` (string or null). It starts as
`['accepted' => false, 'intakeApp' => <intakeApp>, 'reference' => null]`.

`accept()` answers `true`, and records the acceptance, only when all three
hold:

- `$appId` is the event's `intakeApp`;
- the reference is not empty after trimming;
- nobody accepted the event before.

Otherwise it answers `false` and leaves the result as it was.

The app id is how a listener says which app accepts. It keeps a listener of
another app from claiming the file by mistake. It is not a security boundary
against code in the same process.

Only an acceptance makes the contract, with the reference as its target, and
marks the file. A hand-over nobody accepts leaves the file in place and
unmarked, and counts as `unclaimed`. A synchronization that names no intake
app announces nothing.

A consuming app registers its listener only when integriq is installed:

```php
if (class_exists(\OCA\Integriq\Event\WatchedFileArrivedEvent::class)) {
    $context->registerEventListener(\OCA\Integriq\Event\WatchedFileArrivedEvent::class, ScanIntakeListener::class);
}
```

and in the listener:

```php
if ($event->getIntakeApp() !== 'filinq') {
    return;
}
$batch = $this->scanBatches->receive($event->getFileId(), $event->getOwnerUid());
$event->accept('filinq', $batch->getUuid());
```
