# Tasks: stream-file-content

> **Split delivery (2026-07-16):** Tasks 1–3 (the OpenRegister provider side)
> ship as their own PR from the `openregister` repo (branch
> `feature/stream-file-content`, commit `1c938ed52`) and are marked done below.
> Tasks 4–6 (the Integriq consumer side) are implemented on this branch
> (`feature/110/stream-file-content`).
>
> **Design correction (2026-07-16):** the original design assumed the Integriq
> side was "only `SynchronizationService.php`". In fact `fetchFile` obtains its
> bytes from the CallLog, which `CallService` buffers via
> `$response->getBody()->getContents()` — there is no streaming path today. True
> streaming therefore requires a first-class `sink` option on `CallService`
> (Task 4). See `design.md` → "CallService sink capability".

## Implementation Tasks

### Task 1: [OpenRegister] Widen `FileService` content type to `string|resource`
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-binary-file-downloads-shall-stream-to-storage-without-full-in-memory-buffering`
- **files**: `openregister/lib/Service/FileService.php`
- **acceptance_criteria**:
  - GIVEN `FileService::saveFile` and `FileService::addFile` WHEN their `$content` parameter is declared THEN it is native `mixed` with a `@param string|resource $content` docblock, all other params unchanged (see `contract.md`)
  - GIVEN an existing string caller WHEN it calls `saveFile`/`addFile` THEN behaviour is identical to before
- [x] Implement — shipped via openregister PR (`feature/stream-file-content`)
- [x] Test — `tests/Unit/Service/File/*FileHandlerTest.php`, 8 tests green in Docker

### Task 2: [OpenRegister] `CreateFileHandler` resource branch
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-executable-file-blocking-shall-be-preserved-on-the-streamed-path`
- **files**: `openregister/lib/Service/File/CreateFileHandler.php`
- **acceptance_criteria**:
  - GIVEN `$content` is a resource WHEN saving THEN the string-only base64 auto-decode is skipped, the extension check runs on the filename, the magic-byte check runs on a bounded prefix read from the stream, the stream is rewound, and `putContent($content)` streams it
  - GIVEN `$content` is a string WHEN saving THEN the existing decode + `blockExecutableFile` behaviour is unchanged
- [x] Implement — shipped via openregister PR
- [x] Test — `CreateFileHandlerTest` (resource stream, exec-block, string BC)

### Task 3: [OpenRegister] `UpdateFileHandler` resource branch + streamed change-detection
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-unchanged-streamed-content-shall-not-be-rewritten`
- **files**: `openregister/lib/Service/File/UpdateFileHandler.php`
- **acceptance_criteria**:
  - GIVEN `$content` is a resource WHEN updating THEN the incoming md5 is computed via `hash_update_stream` and the stream rewound; `putContent` and the version bump are skipped when it equals the stored file's md5
  - GIVEN a resource whose md5 differs WHEN updating THEN the extension + bounded-prefix magic-byte checks run, the stream is rewound, and the content is written
  - GIVEN a string WHEN updating THEN the existing `md5($content)` compare, base64 round-trip, and `blockExecutableFile` behaviour is unchanged
- [x] Implement — shipped via openregister PR
- [x] Test — `UpdateFileHandlerTest` (resource stream, md5-skip, exec-block, string BC)

### Task 4: [Integriq] Add a `sink` option to `CallService`
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-binary-file-downloads-shall-stream-to-storage-without-full-in-memory-buffering`
- **files**: `integriq/lib/Service/CallService.php`
- **acceptance_criteria**:
  - GIVEN a caller passes a stream resource as a new `$sink` argument to `CallService::call()` WHEN the request is dispatched THEN the resource is passed to Guzzle as its `sink` request option so the response body streams into that resource instead of being buffered into a string
  - GIVEN a `$sink` is supplied THEN the resource MUST NOT be merged into the `$config` that `buildResponseData()` logs/redacts/persists (a resource is not JSON-persistable); the CallLog records an empty body (bytes went to the sink) with the status, headers, and size preserved
  - GIVEN no `$sink` is supplied WHEN `call()` runs THEN behaviour is byte-for-byte unchanged (default `null`)
- [x] Implement — `call()` → `dispatchWithRetry()` → `dispatchRequest()` thread `$sink` into the Guzzle `sink` option, kept out of the logged `$config`
- [x] Test — `CallServiceTest::testCallPassesSinkToGuzzleAndKeepsItOutOfTheCallLog` + `testCallWithoutSinkPassesNoSinkOptionToGuzzle` (green; full suite 37/37)

### Task 5: [Integriq] Stream the binary-download path in `fetchFile`
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-binary-file-downloads-shall-stream-to-storage-without-full-in-memory-buffering`
- **files**: `integriq/lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a binary-download response (no `config['contentPath']`/`config['filenamePath']`) WHEN `fetchFile` runs THEN a `fopen('php://temp/maxmemory:2097152','r+')` handle is opened, passed as the `$sink` through `callSourceObject` → `CallService::call`, rewound, passed as `$content` (resource) to `FileService::saveFile`/`addFile`, and closed in a `finally`
  - GIVEN a base64-in-JSON response addressed by `config['contentPath']` (or `filenamePath`) WHEN `fetchFile` runs THEN the existing in-memory string path is used unchanged (no sink)
  - GIVEN the binary path AND `config['write'] === false` WHEN `fetchFile` returns THEN the streamed content is base64-encoded from the temp handle (non-persist dry-run path preserved)
  - GIVEN the file content on the binary path WHEN streaming THEN it is never assigned to a PHP string variable except the bounded `write===false` dry-run case
- [x] Implement — `fetchFile` chooses sink vs string up front, streams into `php://temp`, passes the resource to `saveFile`/`addFile`, and `fclose`s in a `finally`
- [x] Test — `SynchronizationServiceTest::testFetchFileStreamsRawBinaryDownloadIntoASinkResource` and `::testFetchFileKeepsBase64InJsonResponsesOffTheStreamingPath` assert the private branch selection directly (reflection + `callService` mock). The container is not needed: `$source['_transient'] => true` bypasses source resolution and `config['write'] === false` returns before `FileService` is touched. Mutation-checked — forcing `$useSink = false` fails the streaming test. Full unit suite green: 2074 tests, 0 failures/errors (2 pre-existing warnings in `EventServiceTest.php:619/:711`, unrelated). The earlier `DomCrawler` error no longer occurs.

### Task 6: Tests for streaming, dual-type acceptance, and preserved behaviour
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-base64-in-json-content-shall-continue-on-the-existing-string-path`
- **files**: `integriq/tests/Unit/Service/CallServiceTest.php` (or existing), `integriq/tests/Unit/Service/SynchronizationServiceTest.php`, `openregister/tests/Unit/Service/File/CreateFileHandlerTest.php` *(done)*, `openregister/tests/Unit/Service/File/UpdateFileHandlerTest.php` *(done)*
- **acceptance_criteria**:
  - `CallService::call` passes a supplied `$sink` to Guzzle as its `sink` option and keeps it out of the persisted config
  - `fetchFile` uses the sink/resource path when no `contentPath`/`filenamePath` is set and the string path when one is
  - `saveFile`/`addFile` accept both a string and a resource (dual-type test) — *covered by the OpenRegister handler tests (done)*
  - A blocked executable is rejected on the resource path by extension and by magic bytes — *covered (done)*
  - An unchanged file re-synced on the resource path performs no write (md5 skip) — *covered (done)*
  - The base64-in-JSON path persists content identically to pre-change behaviour
- [x] Implement — CallService sink tests (integriq) + Create/UpdateFileHandler resource tests (openregister, 8 green)
- [x] Test — transport (`sink` option), branch selection (both `fetchFile` tests, see Task 5) and write side (resource stream, exec-block, md5-skip, dual-type) are all unit-covered across the two repos.

## Verification
- All tasks checked off
- `openspec validate stream-file-content` passes
- Manual test: sync a large binary attachment and confirm bounded memory (e.g. via `memory_get_peak_usage`) plus correct stored content
- Code review against spec requirements and `contract.md`

## Tests (company-wide ADR-009)
- PHPUnit unit tests for the streaming path, dual-type acceptance, executable blocking, and md5 skip (both repos, `tests/Unit/`)
- Newman/Postman tests — N/A (no API endpoints changed)
- Browser tests — N/A (no UI changes)
- All tests pass (`composer test` in both repos)

## Documentation (company-wide ADR-010)
- N/A — internal memory/streaming behaviour, no user-facing feature or UI surface

## i18n (company-wide hydra ADR-007)
- N/A — no new user-facing strings
