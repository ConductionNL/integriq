# Tasks: stream-file-content

## Implementation Tasks

### Task 1: [OpenRegister] Widen `FileService` content type to `string|resource`
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-binary-file-downloads-shall-stream-to-storage-without-full-in-memory-buffering`
- **files**: `openregister/lib/Service/FileService.php`
- **acceptance_criteria**:
  - GIVEN `FileService::saveFile` and `FileService::addFile` WHEN their `$content` parameter is declared THEN it is native `mixed` with a `@param string|resource $content` docblock, all other params unchanged (see `contract.md`)
  - GIVEN an existing string caller WHEN it calls `saveFile`/`addFile` THEN behaviour is identical to before
- [ ] Implement
- [ ] Test

### Task 2: [OpenRegister] `CreateFileHandler` resource branch
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-executable-file-blocking-shall-be-preserved-on-the-streamed-path`
- **files**: `openregister/lib/Service/File/CreateFileHandler.php`
- **acceptance_criteria**:
  - GIVEN `$content` is a resource WHEN saving THEN the string-only base64 auto-decode is skipped, the extension check runs on the filename, the magic-byte check runs on a bounded prefix read from the stream, the stream is rewound, and `putContent($content)` streams it
  - GIVEN `$content` is a string WHEN saving THEN the existing decode + `blockExecutableFile` behaviour is unchanged
- [ ] Implement
- [ ] Test

### Task 3: [OpenRegister] `UpdateFileHandler` resource branch + streamed change-detection
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-unchanged-streamed-content-shall-not-be-rewritten`
- **files**: `openregister/lib/Service/File/UpdateFileHandler.php`
- **acceptance_criteria**:
  - GIVEN `$content` is a resource WHEN updating THEN the incoming md5 is computed via `hash_update_stream` and the stream rewound; `putContent` and the version bump are skipped when it equals the stored file's md5
  - GIVEN a resource whose md5 differs WHEN updating THEN the extension + bounded-prefix magic-byte checks run, the stream is rewound, and the content is written
  - GIVEN a string WHEN updating THEN the existing `md5($content)` compare, base64 round-trip, and `blockExecutableFile` behaviour is unchanged
- [ ] Implement
- [ ] Test

### Task 4: [OpenConnector] Stream the binary-download path in `fetchFile`
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-binary-file-downloads-shall-stream-to-storage-without-full-in-memory-buffering`
- **files**: `openconnector/lib/Service/SynchronizationService.php`
- **acceptance_criteria**:
  - GIVEN a binary-download response WHEN `fetchFile` runs THEN the body is streamed into `fopen('php://temp/maxmemory:2097152','r+')` via Guzzle's `sink` option, rewound, passed as `$content` to `FileService`, and the handle closed in a `finally`
  - GIVEN a base64-in-JSON response addressed by `config['contentPath']` WHEN `fetchFile` runs THEN the existing string path is used unchanged
  - GIVEN the file content WHEN streaming THEN it is never assigned to a PHP string variable on the binary path
- [ ] Implement
- [ ] Test

### Task 5: Cross-repo tests for streaming, dual-type acceptance, and preserved behaviour
- **spec_ref**: `openspec/specs/synchronization-files/spec.md#requirement-base64-in-json-content-shall-continue-on-the-existing-string-path`
- **files**: `openconnector/tests/Unit/Service/SynchronizationServiceTest.php`, `openregister/tests/Unit/Service/File/CreateFileHandlerTest.php`, `openregister/tests/Unit/Service/File/UpdateFileHandlerTest.php`
- **acceptance_criteria**:
  - `saveFile`/`addFile` accept both a string and a resource (dual-type test)
  - A blocked executable is rejected on the resource path by extension and by magic bytes
  - An unchanged file re-synced on the resource path performs no write (md5 skip)
  - The base64-in-JSON path persists content identically to pre-change behaviour
- [ ] Implement
- [ ] Test

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
