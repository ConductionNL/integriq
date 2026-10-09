# synchronization-files Specification

## Purpose
Defines how Integriq fetches file content from a synchronization source and
persists it through OpenRegister's `FileService`, with a focus on bounding peak
memory usage. Large synchronized files (multi-megabyte attachments) MUST not be
held in memory in their entirety when they can be streamed. This capability
covers the streaming path, the security guarantees that MUST be preserved when
streaming, and the compatibility contract with OpenRegister's storage layer.

## Requirements

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

### Requirement: A single object's multiple files SHALL be fetched concurrently

The system MUST fetch the multiple files of a single synchronized object
concurrently rather than one at a time, by issuing per-file asynchronous HTTP
requests via `SynchronizationService::callSourceObjectAsync()` on `CallService::callAsync()` (which
reuses the existing Guzzle async transport, auth, client-certificate handling,
rate limiting, and call logging) and settling them together. Each concurrent
fetch MUST stream into its own disk-backed temp **file**, handed to the transport
as a PATH and never as a stream handle (per `stream-file-content`: Guzzle closes a
resource-typed sink when its PSR-7 wrapper is destructed, returning a closed handle
to the caller), so concurrency multiplies neither peak memory nor file-size cost.
The system MUST NOT reimplement the HTTP, auth, certificate, or logging behaviour
already provided by `CallService`, and MUST NOT introduce `react/http`.

#### Scenario: N files for one object are fetched concurrently
- GIVEN a single object whose synchronized data references several file endpoints
- WHEN the multi-file path fetches those files
- THEN a per-file asynchronous request is issued via `callSourceObjectAsync()`
- AND the requests are settled together through a Guzzle concurrency primitive rather than one blocking call after another
- AND each request streams its response body into its own temp file, passed to the transport as a path (never a stream handle)

#### Scenario: Concurrent fetch reuses CallService behaviour
- GIVEN the source requires authentication, a client certificate, or is rate-limited
- WHEN the files are fetched concurrently
- THEN each async request retains CallService's auth, certificate cleanup, rate limiting, and call logging
- AND no separate HTTP client or `react/http` is used

### Requirement: Concurrency SHALL be capped and configurable

The system MUST cap the number of in-flight file fetches for one object at a
per-source configurable limit, defaulting to 5 and never exceeding a hard maximum
of 20, so that the source is not overloaded. The cap MUST be read from
`source.configuration`, because politeness is a property of the upstream rather
than of Integriq.

The system MUST additionally gate admission on a total in-flight BYTE budget
(default ~256 MB), derived from `Content-Length` where the source provides it and
falling back to count-only gating where it does not. A count alone is the wrong
unit: ten 5 MB attachments are trivial where ten 2 GB exports are not.

The cap is NOT a memory control. Per-request memory is curl buffers and headers —
tens of KB — because each fetch streams to a temp file; and file-descriptor
exhaustion is not the constraint either (2 descriptors per fetch, so 40 at the hard
maximum, against a measured `ulimit -n` of 1024).

When requests are held back because either limit is reached, the system MUST log
that throttling occurred.

#### Scenario: In-flight fetches never exceed the configured cap
- GIVEN an object with more file endpoints than the configured concurrency cap
- WHEN the files are fetched concurrently
- THEN the number of simultaneously in-flight requests never exceeds the configured cap
- AND remaining files are started only as in-flight requests complete

#### Scenario: Throttling is logged
- GIVEN more files than the concurrency cap are queued for one object
- WHEN requests are held back to respect the cap
- THEN a log entry records that fetches were throttled

### Requirement: Saves SHALL be pipelined behind the fetch window and remain serialized

The system MUST attach the file-save logic (the existing FileService save plus
filename, tags, and publish handling) to each fetch promise's `then()` callback
so that a resolved download is persisted while its sibling downloads are still in
flight, making net wall-clock time approach `max(fetch-window, Σ saves)` rather
than `Σ fetch + Σ saves`. Saves MUST remain serialized within the single PHP
process — the system MUST NOT perform concurrent OpenRegister object or file
writes, because PHP is single-threaded and Nextcloud uses one shared database
connection. The md5 change-detection skip from `stream-file-content` remains the
primary lever for reducing save-side cost, since an unchanged file incurs no
write.

#### Scenario: A resolved fetch is saved via the then() pipeline
- GIVEN one file's download resolves while other files are still downloading
- WHEN its fetch promise settles
- THEN its save runs from the promise's `then()` callback without waiting for the other downloads to finish
- AND the saved file carries its filename, tags, and publish state exactly as the sequential path produced

#### Scenario: Saves are not run concurrently
- GIVEN several file downloads resolve close together
- WHEN their saves are pipelined
- THEN the OpenRegister writes are executed one at a time, not concurrently
- AND an unchanged file (matching md5) performs no write

### Requirement: One file's failure SHALL NOT abort the others or the object

The system MUST isolate per-file fetch and save failures: a failure of one
file's download or save MUST NOT abort the remaining files or the object,
composing with the existing `fetchFileSafely` error handling. Failure handling
MUST be attached to each promise (for example via `otherwise()`) so a rejected
fetch is logged and skipped while sibling files continue.

#### Scenario: A failed fetch does not stop the others
- GIVEN one file endpoint returns an error while the others succeed
- WHEN the files are fetched concurrently
- THEN the failing file's error is isolated and logged
- AND the other files are still fetched and saved
- AND the object continues processing

#### Scenario: A failed save does not stop the others
- GIVEN one file's save throws while the other files save successfully
- WHEN the saves are pipelined
- THEN the failing save's error is isolated and logged
- AND the remaining files are still saved

### Requirement: Concurrency SHALL NOT depend on source ordering or split source load

The system MUST run this concurrency over an already-fully-fetched object file
list and MUST NOT introduce internal batching or paging (no
fetch-page-1 / process / fetch-page-2). Because the object's file list is
complete before concurrent fetching begins, the behaviour MUST NOT depend on any
consistent ordering from the source (zaaksysteem provides none) and MUST NOT
carry missing-file or double-sync risk.

#### Scenario: Unordered source still produces the complete file set
- GIVEN a source that returns file endpoints in no consistent order
- WHEN the object's files are fetched concurrently
- THEN every referenced file is fetched and saved exactly once
- AND no file is missed or fetched twice regardless of settle order
