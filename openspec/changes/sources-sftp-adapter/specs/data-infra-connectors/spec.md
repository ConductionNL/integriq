# data-infra-connectors Specification

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- sources-sftp-adapter

## Purpose

A partner's SFTP or FTPS server is a source: files are listed, fetched, delivered, moved and deleted, and a pickup runs on a schedule. Row `integriq:src-sftp`. The adapter follows the category contract REQ-DIC-001 to REQ-DIC-006.

## ADDED Requirements

### Requirement: A partner's SFTP or FTPS server is a source (REQ-SFTP-001)

Integriq MUST ship SFTP and FTPS adapters in the data infrastructure category with list, fetch, deliver, move and delete. An SFTP source MUST pin the server's host key fingerprint and MUST refuse a connection when the key differs. Paths MUST stay inside the source's root path. Credentials MUST come from the credential broker.

#### Scenario: an administrator pins a partner's server
- GIVEN an administrator creating an SFTP source for `sftp.example.nl`
- WHEN they test it for the first time
- THEN the server's SHA-256 fingerprint is shown, and after they confirm it the source is saved with the pin
- e2e: `tests/e2e/sftp-source.spec.ts`

#### Scenario: a changed host key stops the connection
- GIVEN a source pinned to one fingerprint
- WHEN the server presents a different key
- THEN the connection is refused and the message gives both fingerprints
- @e2e exclude covered by PHPUnit against an SFTP container

### Requirement: New files are picked up and archived after a safe local write (REQ-SFTP-002)

Integriq MUST let a synchronization pick up new files matching a pattern from a folder, store each in a Nextcloud folder or pass its content to a mapping, and only then move it to an archive folder or delete it on the server. A file fetched once MUST NOT be fetched again.

#### Scenario: the morning batch arrives
- GIVEN three new files `besluiten-*.csv` in `/outgoing`
- WHEN the pickup runs
- THEN the three files are in the Nextcloud folder `Partner/besluiten`, they have moved to `/outgoing/processed` on the server, and the next run fetches nothing
- @e2e exclude scheduled pickup; covered by PHPUnit and a run against an SFTP container

## ADDED Requirements (amendment 2026-10-05, Woo row 1.7, decision D4)

### Requirement: A folder in Nextcloud Files is a watched source (REQ-SFTP-003)

Integriq SHALL offer a source type `nextcloud-folder`, named by an owner and a
path, and a pickup synchronization on it. A file that lands in the folder and
matches the pattern SHALL be ingested exactly once, keyed by its Nextcloud
file id in the synchronization contract. Integriq SHALL notice a file through
a listener on `NodeCreatedEvent` and `NodeWrittenEvent`, which only queues a
`WatchedFileIngestJob`, and through a sweep run as an OpenRegister
`ScheduledWorkflow`. The listener SHALL NOT read the file or write an object
on the request that created it. A file modified less than `stableSeconds` ago
SHALL be left for the next sweep.

#### Scenario: a file dropped in the folder becomes a draft publication
- GIVEN a synchronization watching `Woo/inkomend` of user `woo-intake`, with `target: mapping` onto opencatalogi's `publication` schema and `after: move`
- WHEN the officer saves `besluit-2026-114.pdf` into `Woo/inkomend`
- THEN one publication object exists with the title mapped from the file name, no publication date and the PDF attached, and the file has moved to `Woo/inkomend/processed`
- e2e: `tests/e2e/watched-folder.spec.ts`

#### Scenario: the same file is never ingested twice
- GIVEN a file already ingested and still in the folder because `after: tag` was chosen
- WHEN the sweep runs, and when the file is written again
- THEN no second object is created
- @e2e exclude a background idempotency claim; covered by PHPUnit `WatchedFileIngestJobTest::testAFileInTheContractIsNotIngestedAgain`

#### Scenario: a file the upload event missed is found by the sweep
- GIVEN a file in the watched folder that no event announced
- WHEN the sweep runs
- THEN the file is queued and ingested once
- @e2e exclude a scheduled sweep; covered by PHPUnit `WatchedFolderSweepTest::testAnUnannouncedFileIsQueued`

#### Scenario: the upload request does no ingest work
- GIVEN a watched folder
- WHEN a file is created in it
- THEN the listener adds one `WatchedFileIngestJob` and makes no `ObjectService` call
- @e2e exclude a listener side effect; covered by PHPUnit `WatchedFolderListenerTest::testTheListenerOnlyQueues` with the real `NodeCreatedEvent`

### Requirement: A watched-folder run never publishes and only marks a file after a safe write (REQ-SFTP-004)

For `target: mapping` integriq SHALL remove the keys `published` and
`publicatiedatum` from the mapping output before the save and log each
removal on the run. Integriq SHALL move a file to `processedPath`, or tag it
`integriq-ingested`, only after the object and its attachment were saved. A
failed save SHALL move the file to `failedPath`, or tag it
`integriq-ingest-failed`, and record the reason on the synchronization log. A
target schema that does not resolve SHALL stop the run before any file is
read.

#### Scenario: a mapping that sets a publication date still makes a draft
- GIVEN a mapping that writes `published` from the file's modification time
- WHEN a file is ingested
- THEN the saved object has no `published` value, and the run log names the removed key
- @e2e exclude a mapping output filter; covered by PHPUnit `WatchedFileIngestJobTest::testThePublicationDateIsStripped`

#### Scenario: a failed save leaves the file unmarked
- GIVEN `ObjectService::saveObject()` throwing for a file
- WHEN the job runs with `after: move`
- THEN the file is in `failedPath`, not `processedPath`, and the log names the error
- @e2e exclude a failure path; covered by PHPUnit `WatchedFileIngestJobTest::testAFailedSaveMovesTheFileToFailed`

#### Scenario: opencatalogi is not installed
- GIVEN a synchronization whose target schema does not resolve
- WHEN the sweep runs
- THEN the run stops with "target schema not found", and every file stays where it was
- @e2e exclude an app-absent path; covered by PHPUnit `WatchedFolderSweepTest::testAnUnresolvedTargetStopsBeforeAnyFile`

### Requirement: A watched folder hands a file to a named intake through a typed event (REQ-SFTP-005)

For `target: intake` integriq SHALL dispatch
`OCA\Integriq\Event\WatchedFileArrivedEvent`, constructed as
`new WatchedFileArrivedEvent(string $synchronizationId, string $intakeApp,
int $fileId, string $path, string $ownerUid)`, with `accept(string
$reference): void` and `getResult(): array` answering `accepted` (bool),
`intakeApp` (string) and `reference` (string or null). The result SHALL start
as not accepted. Only an acceptance SHALL mark or move the file. A hand-over
nobody accepts SHALL leave the file in place and unmarked, and SHALL be
counted as `unclaimed` on the run.

#### Scenario: filinq's scan intake takes the batch
- GIVEN a synchronization with `target: intake` and `intakeApp: filinq`, and a listener that accepts with reference `batch-77`
- WHEN a scanned PDF lands in the folder
- THEN `getResult()` answers `accepted: true` and `reference: batch-77`, and the file is marked as ingested
- @e2e exclude a backend command; covered by PHPUnit `WatchedFileIngestJobTest::testAnAcceptedHandOverMarksTheFile` with the real event class dispatched through a real `IEventDispatcher`

#### Scenario: nobody takes the file
- GIVEN `intakeApp: filinq` and filinq not installed
- WHEN a file lands in the folder
- THEN the file stays where it was, unmarked, and the run reports it `unclaimed`
- @e2e exclude an app-absent path; covered by PHPUnit `WatchedFileIngestJobTest::testAnUnclaimedHandOverLeavesTheFile`
