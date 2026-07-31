# synchronization-files Specification

**Status**: in-progress
**Scope**: openconnector
**OpenSpec changes**:
- stream-file-content

## Purpose

Defines how OpenConnector fetches file content from a synchronization source and
persists it through OpenRegister's `FileService`, with a focus on bounding peak
memory usage. Large synchronized files (multi-megabyte attachments) MUST not be
held in memory in their entirety when they can be streamed. This capability
covers the streaming path, the security guarantees that MUST be preserved when
streaming, and the compatibility contract with OpenRegister's storage layer.

## ADDED Requirements

### Requirement: Binary file downloads SHALL stream to storage without full in-memory buffering

The system MUST stream a binary-download response (a raw body or a
`Content-Disposition` attachment) through a disk-backed temporary stream
(`php://temp`) and pass that stream resource to OpenRegister's `FileService`,
rather than reading the whole body into a PHP string and copying it via
`base64_decode`. The temporary stream MUST be rewound before being handed to
`FileService` and MUST be closed after the save completes, including when the
save throws.

#### Scenario: Large binary download is streamed, not buffered
- GIVEN a synchronization source returns a multi-megabyte binary file as a raw response body
- WHEN `fetchFile` retrieves and persists that file
- THEN the response body is written into a `php://temp` stream that spills to disk past its in-memory threshold
- AND the stream resource is passed to `FileService::saveFile`/`addFile` as `$content`
- AND the full file content is never assigned to a PHP string variable on this path

#### Scenario: Temporary stream is always released
- GIVEN a binary download is being streamed to storage
- WHEN the save succeeds OR `FileService` throws during the save
- THEN the temporary stream handle is closed (`fclose`) in a `finally` block
- AND no temporary stream handle is leaked

### Requirement: Executable-file blocking SHALL be preserved on the streamed path

Streaming a file MUST NOT weaken the executable-file security guard that applies
to string uploads. Both the filename-extension check and the magic-byte
signature check MUST run for streamed (resource) content. The magic-byte check
MUST operate on a bounded prefix read from the stream, after which the stream is
rewound so the full content is still written to storage.

#### Scenario: Executable extension is blocked when streaming
- GIVEN a synchronization streams a file whose name has a blocked executable extension (for example `.exe`)
- WHEN the file is persisted via the streaming path
- THEN the extension check rejects the file exactly as it would for a string upload
- AND the file is not written to storage

#### Scenario: Executable magic bytes are blocked when streaming
- GIVEN a synchronization streams a file whose leading bytes match a blocked executable signature
- WHEN the file is persisted via the streaming path
- THEN a bounded prefix is read from the stream and the magic-byte check rejects the file
- AND the stream is rewound before any storage write occurs

#### Scenario: Safe streamed file passes and is stored intact
- GIVEN a synchronization streams a non-executable file (for example a PDF)
- WHEN the file is persisted via the streaming path
- THEN both checks pass
- AND the complete, unmodified file content is written to storage

### Requirement: Unchanged streamed content SHALL NOT be rewritten

The system MUST preserve, on the streamed path, the optimization that skips a
write when incoming content is byte-identical to the stored file. It MUST compute
the incoming content's checksum from the stream in a memory-bounded way
(chunked, e.g. `hash_update_stream`) and rewind the stream afterwards; when the
checksum equals the stored file's checksum, the storage write and version bump
MUST be skipped, exactly as on the string path.

#### Scenario: Re-syncing an unchanged file is a no-op on the streamed path
- GIVEN a file was previously synchronized and its content has not changed at the source
- WHEN the same file is fetched again and persisted via the streaming path
- THEN the incoming stream's checksum is computed chunk-by-chunk without buffering the whole file into a string
- AND the checksum matches the stored file's checksum
- AND no storage write and no version bump occur

#### Scenario: Changed streamed content is written
- GIVEN a file was previously synchronized and its content HAS changed at the source
- WHEN the same file is fetched again and persisted via the streaming path
- THEN the computed checksum differs from the stored file's checksum
- AND the stream is rewound and its full content is written to storage

### Requirement: base64-in-JSON content SHALL continue on the existing string path

The system MUST continue to decode and persist base64-in-JSON content via the
existing in-memory string path, and MUST NOT select the streaming path for it.
This applies to content that is base64-encoded inside a JSON body and addressed
by `config['contentPath']` (for example zaaksysteem responses).

#### Scenario: base64-in-JSON response is not routed to streaming
- GIVEN a synchronization source returns file content base64-encoded inside a JSON body addressed by `config['contentPath']`
- WHEN `fetchFile` retrieves and persists that file
- THEN the existing string path decodes and saves the content unchanged
- AND the behaviour is identical to before this change

## Non-Functional Requirements

- **Performance:** Peak additional memory for a streamed binary file MUST be
  bounded by the `php://temp` in-memory threshold (default ~2 MB) plus the
  magic-byte prefix, independent of the file's total size.
- **Compatibility:** The change to OpenRegister's `FileService` content parameter
  MUST be a pure type widening (`string` → `string|resource`); every existing
  string caller MUST behave exactly as before (see `contract.md`).
- **Internationalization:** No user-facing strings are introduced; Dutch and
  English support (hydra ADR-007) is unaffected.

## Acceptance Criteria

- A large binary download is persisted without its content ever being held in a PHP string.
- A blocked executable is rejected on the streaming path by both extension and magic-byte checks.
- The base64-in-JSON path persists content identically to pre-change behaviour.
- `FileService::saveFile`/`addFile` accept both a string and a resource; string callers are unchanged.
- The temporary stream is closed on both success and failure paths.

## Notes

- Cross-repo: the OpenRegister `FileService` content-type widening is specified in
  this change's `contract.md`. Deploy order is flexible because the widening is
  additive.
- Follow-up (out of scope): stream base64-in-JSON content via
  `php://filter/convert.base64-decode` so that path also avoids full buffering.
- Related parked topic: ReactPHP-based parallel file fetching is a separate future
  change and is not part of this capability yet.