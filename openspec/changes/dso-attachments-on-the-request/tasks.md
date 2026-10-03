## 1. Data

- [x] 1.1 Add `attachments` (array of `name`, `url`, `status`, `fileId`, `attempts`, `error`) to `dso_verzoek` in `lib/Settings/integriq_register.json`, and verify a save of a request carrying it keeps every field (PHPUnit against the real schema fragment, not a mock)
  - Evidence: `tests/Unit/Settings/DsoVerzoekAttachmentsSchemaTest.php` validates through `RegisterSchemaValidator` (the merged register, opis/json-schema). Red on development (2 of 2 fail: field not declared), green after. Same property added to `integriq_mock_register.json`; `dso_verzoek` version 1.0.0 -> 1.1.0. Item schema is `additionalProperties: false`, so an undeclared field is refused, not silently dropped.
- [x] 1.2 Have `DSOParserService` return the bijlage references as `name` + `url`, and `DsoIngestService::ingest()` write them as `attachments` with status `pending`, and verify both in PHPUnit
  - Evidence: `DSOParserServiceTest::testParseRequestReturnsAttachmentsAsNameAndUrl` (wire `naam`, URL-path fallback, entry without URL kept) and `DsoIngestServiceTest::testIngestWritesBijlagenAsPendingAttachments` (entries `pending`, `attempts: 0`, accepted by the real schema). Both red on development, green after.

## 2. Download

- [x] 2.1 Build `Dso\DsoAttachmentFetcher`: GET through the active source's `DsoClient` (token or mTLS), stream into OpenRegister `FileService::addFile()` tagged `dso-bijlage`, cap at the configured size, retry 3 times with backoff; verify each outcome (`stored`, `failed`, `too-large`) in PHPUnit, and pin the `addFile()` call shape
  - Evidence: `tests/Unit/Service/Dso/DsoAttachmentFetcherTest.php`, 9 tests over the real `DsoClient` (Guzzle MockHandler): `stored` with GET + Bearer token, mTLS through `MtlsTransportService` with no token, 3 attempts with backoff 1 s and 2 s then `failed`, transient failure then `stored`, `too-large` by Content-Length and by streamed size (not retried), default cap 100 MB, missing or non-https URL refused without a request, no FileService leaves entries `pending`. The `addFile()` call shape is pinned: request object, file name, a stream resource, `share: false`, `tags: ['dso-bijlage']`. All 9 error on development (class absent); mutations (no tag, 1 attempt, no cap) each turn their tests red. `DsoClient::download()` added (it had only POST `send()`); the size cap is the source's `configuration.maxFileSize`, the key `SynchronizationService` already uses. Ingest makes names unique within a request, because OpenRegister refuses a second file with the same name (`testIngestMakesAttachmentNamesUnique`, red without the change).
- [x] 2.2 Build `FetchDsoAttachmentsJob` (QueuedJob), enqueued by ingest when a request has attachments; it touches only entries that are not `stored`. Verify the "rerun finishes what a crash left" scenario
  - Evidence: `tests/Unit/BackgroundJob/FetchDsoAttachmentsJobTest.php` (a QueuedJob, hands `requestUuid` to the fetcher, drops a malformed argument, logs a failure without throwing) and `DsoIngestServiceTest::testIngestQueuesTheDownloadOnlyWhenThereAreBijlagen` (one job per request with bijlagen, also when mapping failed, none without; queued after the last intake save). The rerun scenario is `DsoAttachmentFetcherTest::testRerunFinishesWhatACrashLeft`: the worker dies on the 3rd of 5, 2 stay `stored`, the rerun downloads only c, d and e. All red on development; removing the `IJobList::add()` turns the ingest test red.
- [x] 2.3 Flag the request for the behandelaar when an entry ends `failed` or `too-large`, and verify the flag
  - Evidence: new boolean `dso_verzoek.attachmentMissing` (both registers), written with every attachments save: true while any entry is `failed` or `too-large`, false again once a rerun stores it. Asserted in `DsoAttachmentFetcherTest` (failed, too-large, all stored, flag clears) and `DsoVerzoekAttachmentsSchemaTest::testAttachmentMissingFlagIsADeclaredBoolean`. 6 tests red with the change reverted.

## 3. Retire DSOAdapterService

- [ ] 3.1 For each public method of `DSOAdapterService`, record its live equivalent or its absence of callers and requirements in this file; move what a requirement still needs next to the live path with its tests
- [ ] 3.2 Delete `DSOAdapterService` and `DSOAdapterServiceTest`, and verify `git grep -n DSO-verzoeken -- lib` is empty and the suite still passes

## 4. Proof

- [ ] 4.1 On a live instance, push a verzoek with three bijlagen to the STAM endpoint (pre-production HMAC mode, a local file server as DSO-LV), run cron, and verify the three files are on the request object in Files and nothing appeared under `/DSO-verzoeken`
- [ ] 4.2 Run `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint` once before push, and record the exit codes in the PR body
