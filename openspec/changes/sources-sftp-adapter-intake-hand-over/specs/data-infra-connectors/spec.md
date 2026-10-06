# data-infra-connectors Specification (delta)

**Status**: proposed
**Scope**: integriq
**OpenSpec changes**:
- sources-sftp-adapter-intake-hand-over

## Purpose

A watched folder hands a file to a named intake app through a typed event, and only an acceptance marks the file. Woo row 1.7, decision D4. Builds on `sources-sftp-adapter-watched-folder` (REQ-SFTP-003 and REQ-SFTP-004).

## ADDED Requirements

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
