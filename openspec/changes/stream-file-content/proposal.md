---
kind: code
---

# Proposal: stream-file-content

## Summary
Reduce peak memory usage during file synchronization by streaming downloaded
file content through a disk-backed temporary stream instead of buffering the
entire file in a PHP string. Today Integriq's `fetchFile` pulls a whole
file into memory as a string and then makes a second full copy via
`base64_decode`, so a single large file is held 2–3× in memory. This change
streams the true binary-download path into a `php://temp` handle (spilling to
disk past ~2 MB) and passes the stream resource through to OpenRegister's
`FileService`, whose underlying `putContent()` already accepts a resource.

This change spans **two repositories**:
- **Integriq** (consumer / driver): rewrites `fetchFile` to stream.
- **OpenRegister** (provider): relaxes the hard `string $content` type on
  `FileService::saveFile`/`addFile` and their handlers to accept
  `string|resource`, while remaining fully backward compatible with existing
  string callers.

## Motivation
File synchronization is the most memory-intensive path in Integriq.
`SynchronizationService::fetchFile` currently does
`$body = ...getContents()` (or reads the whole call-log body into a string),
then `base64_decode(...)` produces a second full copy, and the string is then
handed to `FileService->saveFile()/addFile()`. For multi-megabyte documents
this means the same payload is resident 2–3× simultaneously, which drives
`memory_limit` exhaustion and OOM-killed sync jobs on large attachments.
Nextcloud's `OCP\Files\File::putContent()` already accepts `string|resource`,
so the storage layer can stream a resource straight to disk — the only thing
blocking us is the hard `string` type hints in OpenRegister's public
`FileService` surface. Relaxing those types unlocks a low-risk memory win
exactly where files are largest.

## Capabilities
- `synchronization-files` — synchronization file-fetch and persistence behaviour
  in Integriq (new capability spec created by this change).

## Affected Projects
- [ ] Project: `integriq` — rewrite `SynchronizationService::fetchFile` to
  stream the binary-download path into a `php://temp` handle and pass the
  stream resource to `FileService`; close the stream after save.
- [ ] Project: `openregister` — relax `string $content` → `string|resource` on
  `FileService::saveFile`, `FileService::addFile`,
  `CreateFileHandler::saveFile`/`addFile`, and `UpdateFileHandler`'s content
  path; pass the value through to `putContent()` unchanged. See `contract.md`.

## Scope

### In Scope
- Stream the true binary-download path (Content-Disposition / raw body
  responses) through a disk-backed temp stream in `fetchFile`.
- Relax the `FileService` public content type to `string|resource` in
  OpenRegister and thread it through the create/update handlers unchanged.
- Guarantee backward compatibility: every existing string caller keeps working.
- Unit tests in both repos (streaming path, string+resource acceptance, and the
  base64-in-JSON path still working via the existing string path).

### Out of Scope
- Streaming the **base64-in-JSON** path (content wrapped in a JSON body and
  addressed by `config['contentPath']`, e.g. zaaksysteem). This stays on the
  existing in-memory string path for now. A `php://filter/convert.base64-decode`
  streaming approach is recorded as an explicit follow-up (see `design.md`).
- Internal batching of synchronization work, and any consistent source
  ordering — deliberately not introduced.
- ReactPHP / `react/http` and any HTTP parallelism — a separate parked topic,
  not pulled in here.

## Approach
In Integriq `fetchFile`, replace the "read entire body into a string"
step for binary downloads with a disk-backed temp stream
(`$tmp = fopen('php://temp/maxmemory:2097152', 'r+')`), have the HTTP client
write the response body into it (Guzzle `sink` request option), rewind, and
pass the resource to `FileService->saveFile()/addFile()`; close the handle in a
`finally`. In OpenRegister, change the `string $content` parameter to a union
`string|resource` (documented via a `mixed` hint with `@param string|resource`)
and pass it straight to `putContent()`, which already streams a resource. The
base64-in-JSON branch is detected up front and kept on the string path.

## New Dependencies
None. Uses PHP built-in `php://temp` streams and Guzzle's existing `sink`
option; `OCP\Files\File::putContent()` already supports resources.

## Impact
- Integriq: `lib/Service/SynchronizationService.php` (`fetchFile`).
- OpenRegister: `lib/Service/FileService.php`,
  `lib/Service/File/CreateFileHandler.php`,
  `lib/Service/File/UpdateFileHandler.php`.
- No API endpoints, routes, DB tables, or OpenRegister schemas change.
- Many existing string callers of `saveFile`/`addFile` across both apps are
  affected only in that their type contract widens (no behaviour change).

## Cross-Project Dependencies
Integriq depends on the widened OpenRegister `FileService` signature to
accept a resource. The exact before/after signatures and the backward-compat
guarantee are captured in `contract.md`. The OpenRegister type relaxation MUST
land (or be co-deployed) before Integriq begins passing a resource;
because the change is additive (union widening), a string-only Integriq
continues to work against a widened OpenRegister, so deploy order is flexible.

## Risks

### Risk 1: UpdateFileHandler content inspection breaks on a resource
**Severity:** Medium — **Mitigation:** `UpdateFileHandler` currently inspects
`$content` with `md5(...)`, a `base64_encode(base64_decode(...))` round-trip,
and `blockExecutableFile(...)` on the string. A resource passed to these string
functions would error or misbehave. The handler MUST branch on
`is_resource($content)` and either skip the string-only inspection or stream it
safely; covered by design.md and a unit test for the resource path.

### Risk 2: Stream not rewound / not closed
**Severity:** Low — **Mitigation:** Always `rewind()` before handing the
resource to `FileService`, and `fclose()` in a `finally` so the temp file is
released even on exception.

### Risk 3: base64-in-JSON path silently routed to streaming
**Severity:** Low — **Mitigation:** Detect `config['contentPath']` (and JSON
bodies) up front and keep that branch on the existing string path; unit test
asserts the base64-in-JSON path still works unchanged.

## Rollback Strategy
Revert the two commits (Integriq `fetchFile` and OpenRegister
`FileService`/handlers). Because the OpenRegister change only widens a type
(string remains accepted), reverting OpenRegister after reverting Integriq
is safe and non-breaking. No data migration is involved.

## Open Questions
- Should the streaming capability live in a new `synchronization-files` spec or
  be folded into the existing `synchronization-engine` spec? (Provisional: new
  spec — see DEFERRED_QUESTIONS.)
- Confirm the base64-in-JSON streaming follow-up is acceptable to defer.