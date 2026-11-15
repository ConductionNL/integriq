# Design: sources-sftp-adapter-watched-folder

The second part of `sources-sftp-adapter`; its design D1 to D5 is in that change. The intake target described here is built in `sources-sftp-adapter-intake-hand-over`.

## D6. A watched Nextcloud folder (amendment 2026-10-05, row 1.7, decision D4)

A source of type `nextcloud-folder` stores `ownerUid` and `rootPath`. A
synchronization on it carries the pickup `sourceConfig` of D4 plus
`target: mapping|intake`, `intakeApp` (for `intake`), `after: move|tag`,
`processedPath` and `failedPath` (for `move`).

Noticing a file. `WatchedFolderListener` handles `NodeCreatedEvent` and
`NodeWrittenEvent`. It reads a cached map of watched folder ids to
synchronization ids (rebuilt when a synchronization is saved), walks the
node's parents up to that map, and when one matches it adds a
`WatchedFileIngestJob` (a `QueuedJob`, like `FetchFilesJob`) with the file id
and the synchronization id. It never reads the file or writes an object on the
upload's request. A sweep, registered as an OpenRegister `ScheduledWorkflow`
per REQ-DIC-005, lists each watched folder and queues the same job for every
file not yet in the contract. The sweep is the safety net, the listener is the
fast path, and both end in the one job.

Once per file. The job takes the synchronization contract keyed by
`nextcloud-file:{fileId}` before it does anything. A contract that exists ends
the job. A file younger than `stableSeconds` is left for the next sweep.

Target `mapping`. The job builds a source object `{fileId, name, path,
mimetype, size, mtime, owner, content}` (`content` only for CSV, JSON or XML,
through the mapping formats), runs the synchronization's mapping, strips
`published` and `publicatiedatum` from the result, saves the object through
OpenRegister's `ObjectService::saveObject()`, then attaches the file with
`FileService::addFile(objectEntity: $object, fileName: $name, content:
$stream)`. The real signatures are read on `openregister` `development` before
they are doubled.

Target `intake`. The job dispatches
`OCA\Integriq\Event\WatchedFileArrivedEvent`, constructed as
`new WatchedFileArrivedEvent(string $synchronizationId, string $sourceId,
string $intakeApp, int $fileId, string $path, string $ownerUid)`, with a getter
for each argument (REQ-SFTP-005 in `sources-sftp-adapter-intake-hand-over` is
the contract). A consumer whose app id equals `intakeApp` calls
`accept(string $appId, string $reference): bool`. `getResult(): array` answers
`accepted` (bool), `intakeApp` (string) and `reference` (string or null), and
starts as `['accepted' => false, 'intakeApp' => $intakeApp, 'reference' =>
null]`. filinq's scan intake will call `ScanBatchService::receive()` from its
listener and accept as `filinq` with the batch uuid.

After. Only when the save or the accept succeeded, the job moves the file to
`processedPath` or assigns the system tag `integriq-ingested` through
`ISystemTagObjectMapper`. On failure it moves the file to `failedPath` or tags
it `integriq-ingest-failed`. An unclaimed hand-over neither moves nor tags.
