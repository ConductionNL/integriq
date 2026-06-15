# storage-uploads Specification

## Purpose
TBD - created by archiving change retrofit-2026-05-24-storage-uploads. Update Purpose after archive.

@e2e exclude backend multipart upload storage service (no browser UI) — covered by PHPUnit/Newman

## Requirements
### Requirement: Multi-part upload initialisation with cache-tracked parts (REQ-001)

`createUpload(string $path, string $fileName, int $size, ?string $objectId = null): array`
MUST allocate an empty target file at `{$path}/{$fileName}` plus a sibling parts
folder at `{$path}/{$fileName}_parts/`. The method MUST split `$size` into
`ceil($size / openconnector.part-size)` parts, where `part-size` is read from
`IAppConfig` (default 1,000,000 bytes), and for each part MUST write a cache
entry under key `upload_<UUIDv4>` carrying:

| Field | Value |
|---|---|
| `UPLOAD_TARGET_ID` | the target file's NC node id |
| `UPLOAD_TARGET_PATH` | the parts-folder path |
| `NUMBER_OF_PARTS` | the total number of parts |

The method MUST return an array of part descriptors, each with keys
`id` (UUID), `size` (bytes; the last part carries the remainder, all others
carry `part-size`), `order` (1-based), `object` (the passed `$objectId` or
`null`), and `successful` (always `false` at creation).

The method MUST throw `NotFoundException` / `InvalidPathException` /
`NotPermittedException` if the target path does not exist, is invalid, or is not
writable.

#### Scenario: 2.5 MB upload at default 1 MB part size produces 3 parts

- **GIVEN** `openconnector.part-size = 1_000_000`
- **AND** the target path exists and is writable
- **WHEN** `createUpload('/Uploads', 'big.bin', 2_500_000, 'obj-1')` is called
- **THEN** an empty file `/Uploads/big.bin` is created
- **AND** a folder `/Uploads/big.bin_parts/` is created
- **AND** the return array has 3 entries with sizes `[1_000_000, 1_000_000, 500_000]` and orders `[1, 2, 3]`
- **AND** three cache entries `upload_<uuid>` exist, each carrying the target file id, parts folder path, and `NUMBER_OF_PARTS = 3`

#### Scenario: missing target path raises NotFoundException

- **GIVEN** `/Uploads` does not exist
- **WHEN** `createUpload('/Uploads', 'x.bin', 100)` is called
- **THEN** `NotFoundException` is thrown
- **AND** no cache entries are written

#### Notes

- The method resolves `userManager->get(APP_USER)` but never uses the result —
  the userFolder line is commented out. Treat the `userManager` dependency as
  best-effort / dead at this site; the path resolution actually goes via
  `rootFolder->get($path)`, which is server-relative, not user-relative.
- No authorisation check: any caller can mint cache entries pointing at
  arbitrary `$objectId`. Defence in depth is expected from the calling
  controller. See design.md for the IDOR shape.

---

### Requirement: Single-shot file write under the active user (REQ-002)

`writeFile(string $path, string $fileName, string $content): File` MUST write
`$content` to `{$path}/{$fileName}` under the currently logged-in user's root
folder, returning the resulting `OCP\Files\File`. If the file already exists,
its content MUST be overwritten via `File::putContent`. If it does not exist,
it MUST be created via `Folder::newFile($fileName, $content)`.

The method MUST surface storage errors as `GenericFileException`, locking errors
as `LockedException`, missing-path errors as `NotFoundException`, and
permission errors as `NotPermittedException`.

#### Scenario: overwrite existing file

- **GIVEN** a logged-in user with `/Docs/note.txt` present
- **WHEN** `writeFile('/Docs', 'note.txt', 'replacement')` is called
- **THEN** `note.txt`'s content becomes `'replacement'`
- **AND** the returned `File` references the same node id

#### Scenario: create new file

- **GIVEN** a logged-in user with `/Docs/` present and no `note.txt`
- **WHEN** `writeFile('/Docs', 'note.txt', 'fresh')` is called
- **THEN** `/Docs/note.txt` is created with content `'fresh'`

#### Notes

- **HIGH — Observed bug:** The method references `$this->userSession` but the
  class never declares or injects `IUserSession`. Calling this method at runtime
  raises `Error: Undefined property StorageService::$userSession`. As written,
  this method is dead code; this REQ documents the **intended** observable
  behaviour. A follow-up should either inject `IUserSession` into the
  constructor and re-enable the method or remove it. The retrofit deliberately
  does NOT silently fix this — the broken state has been in the tree long
  enough that we want it on the radar as a separate change.

---

### Requirement: Upload part append with auto-reconciliation when all parts present (REQ-003)

`writePart(int $partId, string $partUuid, string $data): bool` MUST resolve the
upload metadata from the cache key `upload_$partUuid`, locate the target file
under `getUserFolder(APP_USER)::getFirstNodeById(<UPLOAD_TARGET_ID>)`, and
locate the parts folder at the recorded `UPLOAD_TARGET_PATH`. The method MUST
then write the incoming `$data` into a new file
`{partsFolder}/{$partId}.part.{$targetExtension}`.

If, after the write, the parts folder contains at least `NUMBER_OF_PARTS`
entries, the method MUST invoke `attemptCloseUpload(folderContents, target,
numParts)` (REQ-004). The method MUST return `true` on a successful part write
regardless of whether the close attempt occurred.

The method MUST throw `NotFoundException` if either the resolved target file
or parts folder is not of the expected node type (a `Folder` and a `File`
respectively).

#### Scenario: middle part is appended without reconciliation

- **GIVEN** a 3-part upload with metadata cached under `upload_<uuid-2>`
- **AND** only `1.part.bin` exists in the parts folder
- **WHEN** `writePart(2, '<uuid-2>', '<bytes>')` is called
- **THEN** `2.part.bin` is written into the parts folder
- **AND** `attemptCloseUpload` is NOT called (only 2 < 3 parts present)
- **AND** the method returns `true`

#### Scenario: last part triggers reconciliation

- **GIVEN** a 3-part upload with `1.part.bin` and `2.part.bin` already present
- **WHEN** `writePart(3, '<uuid-3>', '<bytes>')` is called
- **THEN** `3.part.bin` is written into the parts folder
- **AND** `attemptCloseUpload` is invoked with all 3 part files
- **AND** the method returns `true`

#### Notes

- IDOR surface: the method authorises on UUID knowledge only — anyone who learns
  a `partUuid` can append parts to the corresponding upload. The controller is
  the gatekeeper. Documented for defence-in-depth review.
- `getFirstNodeById` ambiguity: if the same node id is reachable from multiple
  paths under APP_USER, the first match wins; the recorded
  `UPLOAD_TARGET_PATH` is not cross-checked against the resolved file's parent.

---

### Requirement: In-memory part reconciliation into target file (REQ-004)

`attemptCloseUpload(array $folderContents, File $target, int $numParts): bool`
MUST verify that the parts folder contains exactly the set `{1, 2, …, numParts}`
of part files matching `^[0-9]+\.part\.{$targetExtension}$`. If the set is
incomplete, the method MUST return `false` without mutating state.

If the set is complete, the method MUST:

1. Read each part's content via `File::getContent` in part-number order.
2. Concatenate the contents into a single PHP string.
3. Delete each part file via `File::delete`.
4. Delete the parts folder if it is empty after part deletion.
5. Write the concatenated content into `$target` via `File::putContent`.
6. Return `true`.

#### Scenario: reconciliation merges 3 parts into target

- **GIVEN** a parts folder containing `1.part.bin`, `2.part.bin`, `3.part.bin` with contents `"AAA"`, `"BBB"`, `"CCC"`
- **AND** `$target` is `/Uploads/big.bin`
- **AND** `$numParts = 3`
- **WHEN** `attemptCloseUpload(<contents>, $target, 3)` is called
- **THEN** `/Uploads/big.bin` ends up with content `"AAABBBCCC"`
- **AND** all three part files are deleted
- **AND** `/Uploads/big.bin_parts/` is deleted
- **AND** the method returns `true`

#### Scenario: missing middle part returns false

- **GIVEN** a parts folder containing only `1.part.bin` and `3.part.bin`
- **AND** `$numParts = 3`
- **WHEN** `attemptCloseUpload(<contents>, $target, 3)` is called
- **THEN** the method returns `false`
- **AND** the parts folder is NOT modified
- **AND** `$target` is NOT written

#### Notes

- **Memory profile:** the concatenation buffer holds the full upload in PHP
  memory. Large files (multi-GB) will OOM the worker. The class imports
  `IObjectStoreMultiPartUpload` / `IChunkedFileWrite` but does not use them.
  Tightening to a streaming reconciliation is a separate change; this REQ
  documents the in-memory shape as the **observed** contract.
- Concatenation order is enforced by `ksort($files)` plus the
  `intval(extension-stripped)` regex match against `range(1, numParts)` —
  string-sorted names happen to match numeric order for the small `1..N`
  ranges used in practice.

