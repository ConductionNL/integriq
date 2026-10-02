## 1. Data

- [ ] 1.1 Add `attachments` (array of `name`, `url`, `status`, `fileId`, `attempts`, `error`) to `dso_verzoek` in `lib/Settings/integriq_register.json`, and verify a save of a request carrying it keeps every field (PHPUnit against the real schema fragment, not a mock)
- [ ] 1.2 Have `DSOParserService` return the bijlage references as `name` + `url`, and `DsoIngestService::ingest()` write them as `attachments` with status `pending`, and verify both in PHPUnit

## 2. Download

- [ ] 2.1 Build `Dso\DsoAttachmentFetcher`: GET through the active source's `DsoClient` (token or mTLS), stream into OpenRegister `FileService::addFile()` tagged `dso-bijlage`, cap at the configured size, retry 3 times with backoff; verify each outcome (`stored`, `failed`, `too-large`) in PHPUnit, and pin the `addFile()` call shape
- [ ] 2.2 Build `FetchDsoAttachmentsJob` (QueuedJob), enqueued by ingest when a request has attachments; it touches only entries that are not `stored`. Verify the "rerun finishes what a crash left" scenario
- [ ] 2.3 Flag the request for the behandelaar when an entry ends `failed` or `too-large`, and verify the flag

## 3. Retire DSOAdapterService

- [ ] 3.1 For each public method of `DSOAdapterService`, record its live equivalent or its absence of callers and requirements in this file; move what a requirement still needs next to the live path with its tests
- [ ] 3.2 Delete `DSOAdapterService` and `DSOAdapterServiceTest`, and verify `git grep -n DSO-verzoeken -- lib` is empty and the suite still passes

## 4. Proof

- [ ] 4.1 On a live instance, push a verzoek with three bijlagen to the STAM endpoint (pre-production HMAC mode, a local file server as DSO-LV), run cron, and verify the three files are on the request object in Files and nothing appeared under `/DSO-verzoeken`
- [ ] 4.2 Run `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` and `npm run lint` once before push, and record the exit codes in the PR body
