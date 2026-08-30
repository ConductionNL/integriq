# Design: stream-file-content

## Architecture Overview

Today a synchronized file is resident in PHP memory 2–3× simultaneously:

```
BEFORE (in-memory)
  source ──HTTP──▶ Guzzle
                    └─ getContents() ─────▶ $body (full file, string)      [copy 1]
                       base64_decode($body) ▶ $content (full file, string) [copy 2]
                          FileService::saveFile(content: $content /*string*/)
                             CreateFileHandler ▶ putContent($content)      [copy 3 in storage write]

AFTER (streamed, binary-download path only)
  source ──HTTP──▶ Guzzle
                    └─ sink: $tmp (php://temp/maxmemory:2MB) ──▶ spills to disk past ~2 MB
                       rewind($tmp)
                          FileService::saveFile(content: $tmp /*resource*/)
                             CreateFileHandler ▶ putContent($tmp)  ── streams to storage
                          fclose($tmp)  (finally)
```

The memory ceiling for a streamed file drops from "size of the file × 2–3" to a
fixed ~2 MB (the `php://temp` in-memory threshold) plus a bounded magic-byte
prefix. The storage layer is unchanged: `OCP\Files\File::putContent()` already
accepts `string|resource`, so a resource streams straight to disk.

Two-repo split:
- **Integriq** — `SynchronizationService::fetchFile` drives the streaming.
- **OpenRegister** — `FileService` + `CreateFileHandler`/`UpdateFileHandler`
  relax `string $content` → `string|resource` and branch on `is_resource()`.

## Streaming vs base64-in-JSON

`fetchFile` handles two response shapes. Only one is streamable:

| Path | Shape | This change |
|------|-------|-------------|
| **Binary download** | Raw body / `Content-Disposition` attachment | **Streamed** via `sink` → `php://temp` resource |
| **base64-in-JSON** | Content wrapped in a JSON body, addressed by `config['contentPath']` (e.g. zaaksysteem `inhoud`) | **Kept on the existing in-memory string path** |

base64-in-JSON cannot be streamed with a plain `sink`: the bytes are
base64-encoded *inside* a JSON envelope, so the whole JSON must be parsed and
the field base64-decoded before any file bytes exist. Streaming it would
require piping the extracted field through a
`php://filter/convert.base64-decode` stream filter — a larger, separate change.

**Decision:** stream the binary-download path now (where files are largest and
the memory win is real); keep base64-in-JSON on the string path. The branch is
selected up front by detecting `config['contentPath']` / a JSON body, exactly as
`fetchFile` already distinguishes them today. Recorded follow-up:
*"Stream base64-in-JSON content via `php://filter/convert.base64-decode`"* —
out of scope here.

## API Design

No REST API endpoints are introduced or modified. The affected interface is the
in-process PHP `FileService` method surface; its before/after signatures and the
backward-compatibility guarantee are specified in `contract.md`.

## Database Changes

None. No tables, columns, migrations, or OpenRegister schemas change.

## Nextcloud Integration
- Controllers: none.
- Services: `OCA\Integriq\Service\SynchronizationService` (consumer);
  `OCA\OpenRegister\Service\FileService` (provider, resolved via the DI
  container).
- Handlers: `OCA\OpenRegister\Service\File\CreateFileHandler`,
  `OCA\OpenRegister\Service\File\UpdateFileHandler`.
- Storage: `OCP\Files\File::putContent()` (already accepts `string|resource`).
- Events/Hooks: none.

## Security Considerations

**Executable-file blocking must be preserved on the streamed path.** Both the
create path (`CreateFileHandler:171`) and the update path
(`UpdateFileHandler:438`) call `FileValidationHandler::blockExecutableFile()`.
That guard does two checks, both cheap to keep with a resource:

1. **Extension check** — filename-only (`pathinfo(...PATHINFO_EXTENSION)`); needs
   no content at all, so it runs unchanged for a resource.
2. **Magic-byte check** — `detectExecutableMagicBytes()` inspects file
   signatures, which live in the first bytes of the file. For a resource we
   `fread($content, N)` a bounded prefix (e.g. 512 bytes), run the magic-byte
   detection on that prefix, then `rewind($content)` before `putContent()`.

Result: **full parity** — a streamed file is subject to the same
extension + magic-byte executable blocking as a string upload. Peak extra memory
is the prefix (~512 bytes), not the whole file. If the guard throws, the temp
stream is closed in the caller's `finally`.

The string-only base64 auto-decode in `CreateFileHandler` (lines 146–171) is
**skipped for resources**: Integriq has already produced decoded bytes on
the binary-download path, so there is nothing to base64-decode. This is a
behaviour-preserving skip (not a security control), gated by `is_resource()`.

## Content-change detection (avoid unnecessary overwrites)

`UpdateFileHandler` (line 430) already skips the write — and the resulting
version bump — when the incoming content is byte-identical to what is stored:

```php
if ($content !== null && $file instanceof File
    && $file->hash(type: 'md5') !== md5(string: $content)) { /* write */ }
```

`saveFile` reaches this via its upsert branch (it routes to the update path when
the file already exists), so re-synchronizing an unchanged file is currently a
no-op. This optimization MUST be preserved on the streamed path, computed in a
memory-bounded way rather than by materializing the content into a string:

```php
if (is_resource($content)) {
    $ctx = hash_init('md5');
    hash_update_stream($ctx, $content);   // chunked read — not buffered into a string
    $incomingHash = hash_final($ctx);
    rewind($content);
    $changed = $file->hash('md5') !== $incomingHash;
} else {
    $changed = $file->hash('md5') !== md5($content);
}
```

`hash_update_stream` reads the resource in chunks, so peak memory stays bounded;
because the content is already in a disk-backed `php://temp`, hashing it is cheap
disk I/O. The stream is rewound between the magic-byte prefix read, the md5 hash,
and the final `putContent`. If unchanged, `putContent` is skipped exactly as the
string path skips it today.

No auth/CORS/CSRF surface changes (no new endpoints). Input validation on the
content is preserved as described above.

## CallService sink capability (2026-07-16 correction)

The original design assumed the Integriq side was "only
`SynchronizationService.php`". Verifying against the code found this is not
achievable there alone: `fetchFile` reads its bytes from the CallLog
(`callLogResponse()`), and `CallService::call()` buffers the whole body via
`$response->getBody()->getContents()` (`buildResponseData`, ~line 1080) before
`fetchFile` ever sees it. There is no streaming path on the transport today, so
a `sink` cannot be introduced purely in `fetchFile`.

**Decision:** add a first-class, optional `$sink` parameter to
`CallService::call()`, threaded to the private `dispatchRequest()` and injected
as Guzzle's `sink` request option **only** on the `$this->client->request(...)`
call. It is deliberately kept OUT of the `$config` array that
`buildResponseData()` logs, redacts, and persists — a stream resource is not
JSON-persistable and must never reach the CallLog object. With a sink in play,
`getBody()->getContents()` returns `''` (bytes were written to the sink), so the
CallLog naturally records an empty body while status, headers, and size are
preserved. Default `$sink = null` keeps every existing caller byte-for-byte
unchanged.

`SynchronizationService::callSourceObject()` gains a matching optional `$sink`
pass-through so `fetchFile` can supply its `php://temp` handle.

**Up-front branch selection.** Because a sunk response has no in-memory body to
inspect, `fetchFile` must choose the path BEFORE the call. It uses the sink
(binary) path only when neither `config['contentPath']` nor `config['filenamePath']`
is set — i.e. a raw binary download. Any JSON-envelope response (which needs the
body parsed for `contentPath`/`filenamePath`) stays on the existing string path.
The `write === false` dry-run case on the sink path base64-encodes the temp
handle's contents (a bounded, non-persist path) to preserve its return contract.

## File Structure
```
integriq/
  lib/Service/
    CallService.php                   # call()/dispatchRequest(): optional $sink → Guzzle 'sink' option, kept out of logged config
    SynchronizationService.php        # callSourceObject(): $sink pass-through; fetchFile: sink→php://temp, rewind, pass resource, fclose in finally
openregister/
  lib/Service/
    FileService.php                   # saveFile/addFile: string $content → mixed (@param string|resource)
    File/CreateFileHandler.php        # is_resource() branch: bounded-prefix exec check, skip base64 decode, putContent(resource)
    File/UpdateFileHandler.php        # is_resource() branch: same; skip md5/base64 round-trip string inspection
```

## Trade-offs

- **`php://temp/maxmemory:2097152` vs `php://memory` vs a named temp file.**
  `php://temp` keeps small files in memory and transparently spills large ones to
  disk at the 2 MB threshold — the best default for a mixed size distribution.
  `php://memory` would never spill (defeating the goal); a named temp file adds
  cleanup burden and a filesystem path to manage. Chosen: `php://temp`.
- **Native `mixed` vs dropping the type hint.** PHP has no `resource` type
  keyword, so `string|resource` is not expressible natively. `mixed` + a
  `@param string|resource` docblock lets PHPStan/Psalm enforce the true contract
  while remaining a pure widening of `string`. Chosen over an untyped parameter,
  which would lose all static checking. (See `contract.md`.)
- **Preserve the exec guard (prefix read + rewind) vs skip it for resources.**
  Skipping would be simpler but drops a security control on synchronized files.
  The prefix-read approach costs ~512 bytes and one rewind, so parity is kept at
  negligible cost. Chosen: preserve.
- **Stream binary now, defer base64-in-JSON** vs do both. Doing both pulls in a
  stream-filter decode pipeline and widens blast radius. The binary path carries
  the large-file memory risk, so it delivers most of the win at a fraction of the
  risk. Chosen: defer base64-in-JSON.