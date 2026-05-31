# Design — Retrofit storage-uploads

Retrofit change. Tasks describe retroactive annotation, not new implementation work.

## Context

`lib/Service/StorageService.php` reconciles multi-part file uploads into a single
target file in Nextcloud's file storage. The flow is:

1. `createUpload($path, $fileName, $size, $objectId?)` — allocates an empty target
   file plus a sibling `{$fileName}_parts/` folder, splits `$size` by
   `openconnector.part-size` (default 1 MB), and writes one cache entry per part
   describing the target file id, parts folder path, and total part count.
2. `writePart($partId, $partUuid, $data)` — reads the cache entry, writes the
   incoming part data into `{$partsFolder}/{$partId}.part.{$ext}`, and if the
   parts folder now contains at least `numParts` entries, calls
   `attemptCloseUpload(...)`.
3. `attemptCloseUpload($folderContents, $target, $numParts)` — checks every part
   `1..numParts` is present, concatenates them in-memory, writes the
   concatenation into `$target` via `File::putContent`, deletes each part file,
   and removes the parts folder if it ends up empty.

`writeFile($path, $fileName, $content)` is a separate single-shot helper that
writes a file under the **current logged-in user's** root folder (not the
APP_USER fixture the multi-part path uses).

## Observed-but-suspicious behaviour (flagged, not fixed)

| Site | Issue | Severity |
|---|---|---|
| `writeFile::$this->userSession` | The class never declares `IUserSession $userSession` and the constructor never injects it. Calling `writeFile()` triggers an `Error: Undefined property StorageService::$userSession`. The method appears to be **dead code** at runtime. | **HIGH — broken method, latent bug** |
| `attemptCloseUpload::$totalContent` | Concatenates every part's content into a single PHP string before `putContent`. Memory scales with upload size — large files (multi-GB) will exhaust PHP memory. The class even imports `IObjectStoreMultiPartUpload` / `IChunkedFileWrite` interfaces but does not use them. | medium — soft DoS via upload size |
| `writePart::$partData` | Reads upload metadata from the distributed cache by key `upload_$partUuid` with no auth check on the caller. Anyone who learns a UUID can append parts to a foreign upload, including a chosen-ID `objectId` reference. | medium — IDOR via cache-key knowledge |
| `writePart::$targetFile` resolution | Resolves the target via `rootFolder->getUserFolder(APP_USER)->getFirstNodeById(...)`. If the same id exists in multiple paths under APP_USER, the first match wins — `$partData[UPLOAD_TARGET_PATH]` is recorded but not cross-checked against the resolved file's parent. | low |
| `attemptCloseUpload::preg_match` | Regex `^[0-9]+\.part\.{$target->getExtension()}$` is not anchored with `\A\z` and uses the raw extension as a regex fragment. An extension containing regex metacharacters (e.g. `.`) is interpreted literally because `.` happens to match itself, but adversarial filenames with regex-special chars in the extension could open false-positives or matching skips. | low |
| `createUpload::$user` | Resolves `userManager->get(APP_USER)` but never uses the result — the `userFolder` line is commented out. Dead variable. | informational |
| `createUpload` no auth check | Same IDOR shape as `writePart`: any caller can allocate uploads under arbitrary `$objectId`. Authz is presumably enforced by the controller that calls this service, but the service offers no defence in depth. | low |

These are documented in REQ Notes rather than silently fixed via spec text.

## REQ → method map

| REQ | Methods |
|---|---|
| REQ-001 | `createUpload` |
| REQ-002 | `writeFile` |
| REQ-003 | `writePart` |
| REQ-004 | `attemptCloseUpload` |

The private `attemptCloseUpload` is kept as its own REQ rather than folded into
REQ-003 because its reconciliation contract — total-content rebuild + part
deletion + folder cleanup — is a distinct observable behaviour worth pinning.

## What the spec deliberately does NOT cover

- `StorageService::__construct` — DI plumbing.
- Distributed-cache implementation choice (`ICacheFactory::createDistributed`) —
  internal.
- The HTTP endpoints that call these methods — covered by the
  `endpoint-runtime` cluster.

## Validation

After archive, `openspec validate storage-uploads --strict` MUST pass.
