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
`OCA\Integriq\Event\WatchedFileArrivedEvent` (extends `OCP\EventDispatcher\Event`) with exactly
this public surface:

```
public function __construct(string $synchronizationId, string $sourceId, string $intakeApp,
    int $fileId, string $path, string $ownerUid)
public function getSynchronizationId(): string
public function getSourceId(): string
public function getIntakeApp(): string
public function getFileId(): int
public function getPath(): string
public function getOwnerUid(): string
public function accept(string $appId, string $reference): bool
public function getResult(): array
```

`getSourceId()` answers the id of the `nextcloud-folder` source the synchronization reads.
`getPath()` answers the file's path relative to the owner's user folder, as the job read it. Each
getter SHALL answer exactly the value the job passed to the constructor.

`getResult()` SHALL answer `accepted` (bool), `intakeApp` (string) and `reference` (string or null),
and SHALL start as `['accepted' => false, 'intakeApp' => <intakeApp>, 'reference' => null]`.
`accept()` SHALL record the acceptance and answer `true` only when `$appId` equals the event's
`intakeApp`, `$reference` is not empty after trimming, and the event was not accepted before. In
every other case it SHALL answer `false` and leave the result unchanged. Nextcloud's event
dispatcher carries no caller identity, so the app id is how a listener states which app accepts:
a listener of another app, or one that skipped its own `getIntakeApp()` check, cannot claim the
file by mistake. It is not a security boundary against code in the same process.

Only an acceptance SHALL mark or move the file. A hand-over nobody accepts SHALL leave the file in
place and unmarked, and SHALL be counted as `unclaimed` on the run.

#### Scenario: filinq's scan intake takes the batch
- GIVEN a synchronization with `target: intake` and `intakeApp: filinq`, and a listener that calls `accept('filinq', 'batch-77')`
- WHEN a scanned PDF lands in the folder
- THEN `accept()` answers `true`, `getResult()` answers `accepted: true` and `reference: batch-77`, and the file is marked as ingested
- @e2e exclude a backend command; covered by PHPUnit `WatchedFileIngestJobTest::testAnAcceptedHandOverMarksTheFile` with the real event class dispatched through a real `IEventDispatcher`

#### Scenario: nobody takes the file
- GIVEN `intakeApp: filinq` and filinq not installed
- WHEN a file lands in the folder
- THEN the file stays where it was, unmarked, and the run reports it `unclaimed`
- @e2e exclude an app-absent path; covered by PHPUnit `WatchedFileIngestJobTest::testAnUnclaimedHandOverLeavesTheFile`

#### Scenario: another app cannot accept the file
- GIVEN an event with `intakeApp: filinq`
- WHEN a listener calls `accept('dossiq', 'case-12')`
- THEN `accept()` answers `false`, `getResult()` still answers `accepted: false` and `reference: null`, and the job counts the file `unclaimed` and leaves it in place
- @e2e exclude a backend contract; covered by PHPUnit `WatchedFileArrivedEventTest::testAnotherAppIdIsRefused` and `WatchedFileIngestJobTest::testAnAcceptanceByAnotherAppLeavesTheFile`

#### Scenario: the getters answer what the job passed
- GIVEN the job dispatching for synchronization `s-1` on source `src-9`, `intakeApp: filinq`, file id 4711, path `Scans/post/batch.pdf` and owner `scanner`
- WHEN a listener reads the event
- THEN `getSynchronizationId()` is `s-1`, `getSourceId()` is `src-9`, `getIntakeApp()` is `filinq`, `getFileId()` is 4711, `getPath()` is `Scans/post/batch.pdf` and `getOwnerUid()` is `scanner`
- @e2e exclude a backend contract; covered by PHPUnit `WatchedFileIngestJobTest::testTheEventCarriesTheFileItFound`
