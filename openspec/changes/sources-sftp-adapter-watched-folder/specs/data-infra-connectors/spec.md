# data-infra-connectors Specification (delta)

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- sources-sftp-adapter-watched-folder

## Purpose

A folder in Nextcloud Files is a watched source: each file that lands in it is picked up once, mapped onto a draft object, and moved or tagged only after a safe write. Woo row 1.7, decision D4. Builds on `sources-sftp-adapter` (REQ-SFTP-001 and REQ-SFTP-002).

## ADDED Requirements

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
