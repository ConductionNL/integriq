# Design: sources-sftp-adapter

Kind: code. Size M. An `SftpAdapter` and an `FtpsAdapter` in `lib/Service/Adapter/DataInfra/`, a pickup synchronization mode, and source fields.

## Context at development 92f282bc

- `AbstractCategoryAdapterProvider` (`lib/Service/Adapter/AbstractCategoryAdapterProvider.php:56`), `S3Adapter` registered in `lib/AppInfo/Application.php:1565`.
- `openspec/specs/data-infra-connectors/spec.md` REQ-DIC-004 allows schema posture `none` for an SFTP file-list adapter; REQ-DIC-005 makes scheduled pulls OpenRegister ScheduledWorkflow records.
- File storage into Nextcloud through `IRootFolder` (`lib/Service/IBabsConnectorService.php:71`).
- No SSH or FTP library in `composer.lock`. Nextcloud core's external storage SFTP backend uses phpseclib.

## D1. The library

SFTP through phpseclib 3 (MIT), whose `phpseclib3\` namespace does not clash with the older `phpseclib\` namespace. The first task checks which phpseclib major the supported Nextcloud versions bundle for external storage; if one already provides `phpseclib3\Net\SFTP` in a compatible version, the adapter uses it and adds no copy, else it adds the dependency. FTPS through PHP's `ftp` extension (`ftp_ssl_connect`), reported unavailable when the extension is missing.

## D2. Credentials and host keys

The password or private key is a `credentialRef` resolved with `resolveInjectable()` on a `generic-*` provider, `organisation` scope, because an SSH connection cannot go through the broker's HTTP proxy (ADR-064 decision 3). The SFTP source requires `hostKeyFingerprint` (SHA-256). The first test connection shows the server's fingerprint for the administrator to confirm; after that a different key refuses the connection with both fingerprints in the message. The host is checked by the egress guard (ADR-067) so a source cannot point at an internal address.

## D3. Operations

`list(path, pattern)`, `fetch(path)` streamed to a temporary file (as `stream-file-content` does), `deliver(path, stream)`, `move(from, to)`, `delete(path)`. Each call opens and closes its own connection. Paths are confined to the source's `rootPath`; `..` is refused.

## D4. Pickup synchronization

A synchronization with `sourceType` `sftp` or `ftps` and `sourceConfig` `{ path, pattern, after: move|delete|keep, archivePath, target: files|mapping, filesFolder }`. For each new file (by name and modification time, remembered in the synchronization contract) it fetches, stores the file in `filesFolder` in Nextcloud or passes the parsed content (CSV, JSON or XML, through the mapping formats) to the mapping, and then moves or deletes it remotely only after the local write succeeded.

## D5. Category contract

REQ-DIC-001 registration, REQ-DIC-002 manifest entry (`slug` `sftp` and `ftps`, `authModes` [`basic`, `ssh-key`], `capabilities` [`read`, `write`, `bulk-import`]), REQ-DIC-003 credentials on the source, REQ-DIC-004 `pollingMode: poll` and schema posture `none`, REQ-DIC-005 ScheduledWorkflow for scheduled pickups, REQ-DIC-006 health on the metrics endpoint.

## Declarative versus imperative

No lifecycle or notification behaviour. The adapter is the ADR-031 exception for an external integration.

## Seed data

A dormant source `example-sftp-partner` (`type: sftp`, host `sftp.example.nl`, `rootPath` `/outgoing`, no credential, no fingerprint) and a disabled pickup synchronization for `*.csv` that archives to `/outgoing/processed`.

## Risks

- A file is deleted remotely before it is safe locally. Mitigation: remote move or delete runs only after the local write is confirmed.
- A partially written file is fetched. Mitigation: an optional `stableSeconds` (default 60) skips files modified more recently than that.

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
`new WatchedFileArrivedEvent(string $synchronizationId, string $intakeApp,
int $fileId, string $path, string $ownerUid)`. A consumer whose app id equals
`intakeApp` calls `accept(string $reference)`. `getResult(): array` answers
`accepted` (bool), `intakeApp` (string) and `reference` (string or null), and
starts as `['accepted' => false, 'intakeApp' => $intakeApp, 'reference' =>
null]`. filinq's scan intake will call `ScanBatchService::receive()` from its
listener and accept with the batch id.

After. Only when the save or the accept succeeded, the job moves the file to
`processedPath` or assigns the system tag `integriq-ingested` through
`ISystemTagObjectMapper`. On failure it moves the file to `failedPath` or tags
it `integriq-ingest-failed`. An unclaimed hand-over neither moves nor tags.
