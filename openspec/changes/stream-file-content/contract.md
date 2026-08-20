# Contract: stream-file-content

This change alters a **public PHP service interface in OpenRegister** that
OpenConnector consumes across the app boundary via the DI container
(`OCA\OpenRegister\Service\FileService`). The interface is a PHP method
surface, not a REST endpoint, so this contract documents the exact method
signatures (before/after) and the backward-compatibility guarantee rather than
HTTP request/response bodies.

## The contract type: `string|resource`

The `$content` parameter's contract type is **`string|resource`** — a string
of file bytes, or a readable stream resource that is passed straight to
`OCP\Files\File::putContent()` (which already accepts `string|resource`).

PHP has **no `resource` keyword usable in a native type declaration**, so
`string|resource` cannot be written as a native parameter type. The runtime
declaration is therefore widened to `mixed`, and the true, narrower contract is
declared in the docblock as `@param string|resource $content`. PHPStan/Psalm
understand `resource` and enforce `string|resource` at every call site — an
`int`, `array`, object, etc. is a static-analysis error. `mixed` is only the
runtime spelling PHP forces on us; the enforced contract remains
`string|resource`.

## Consumers
- `openconnector`: `SynchronizationService::fetchFile` resolves
  `OCA\OpenRegister\Service\FileService` from the container and calls
  `saveFile(...)` and `addFile(...)` to persist synchronized files. After this
  change it passes a **stream resource** (disk-backed `php://temp`) as
  `$content` on the binary-download path.
- Any other app that resolves `FileService` and calls `saveFile`/`addFile`
  continues to pass a `string` and is unaffected (union widening only).

## Provider
- `openregister`: `OCA\OpenRegister\Service\FileService`, delegating to
  `OCA\OpenRegister\Service\File\CreateFileHandler` and
  `OCA\OpenRegister\Service\File\UpdateFileHandler`.

## Interface: `FileService::saveFile`

**Before (OpenRegister `lib/Service/FileService.php`):**
```php
public function saveFile(
    ObjectEntity $objectEntity,
    string $fileName,
    string $content,
    bool $share = false,
    array $tags = []
): File
```

**After** (contract type `string|resource`; native declaration `mixed` because
PHP has no `resource` type keyword):
```php
/**
 * @param string|resource $content File content as a string, or a readable
 *                                  stream resource (streamed straight to
 *                                  storage via OCP\Files\File::putContent()).
 */
public function saveFile(
    ObjectEntity $objectEntity,
    string $fileName,
    mixed $content,
    bool $share = false,
    array $tags = []
): File
```

## Interface: `FileService::addFile`

**Before:**
```php
public function addFile(
    ObjectEntity|string $objectEntity,
    string $fileName,
    string $content,
    bool $share = false,
    array $tags = [],
    int|string|Schema|null $_schema = null,
    int|string|Register|null $_register = null,
    int|string|null $registerId = null
): File
```

**After:** identical, except the `$content` native type becomes `mixed` with a
`@param string|resource $content` docblock. All other parameters unchanged.

## Interface: `CreateFileHandler::saveFile` / `CreateFileHandler::addFile`

Both delegate targets take the same `$content` contract change: native `mixed`,
`@param string|resource`, same signatures otherwise. The value is passed through
unchanged to the final
`$file = $folder->newFile($fileName); $file->putContent($content);` — and
`putContent()` already accepts `string|resource`, so a resource streams to
storage with no logic rewrite.

## Interface: `UpdateFileHandler` (update content path)

The update path (`~lib/Service/File/UpdateFileHandler.php`) takes the same
`$content` contract change (native `mixed`, `@param string|resource`).
**Additional requirement:** this handler currently inspects the content as a
string (`md5($content)`, a base64 round-trip check, and
`blockExecutableFile(fileContent: $content)`). It MUST branch on
`is_resource($content)`: for a resource it skips the string-only
inspection/decoding and passes the resource straight to `putContent()`; for a
string it keeps the existing behaviour exactly.

## Backward-Compatibility Guarantee
- Passing a `string` to any of the above methods behaves **exactly as before**
  (same base64 handling, same executable-file blocking, same hashing).
- The change is a pure **type widening** (`string` to `string|resource`). No
  existing caller signature is broken; no return type changes.
- `File` return type and all non-content parameters are unchanged.

## Versioning
- No API/route/schema version bump. This is an additive PHP type relaxation.
- OpenConnector's `openspec/manifest.yaml` declares `or_min_version: "^v0.2.10"`;
  the OpenRegister release that ships the widened signature becomes the new
  effective minimum for the streaming behaviour. Because a string-only
  OpenConnector still works against the widened OpenRegister, and a widened
  OpenRegister still accepts strings, the two apps can deploy in either order.

## Breaking Change Policy
This is explicitly non-breaking. If a future change ever narrows the type back
to `string`-only (e.g. dropping resource support), that WOULD be breaking and
MUST be coordinated: announced in the OpenRegister changelog, gated behind an
`or_min_version` bump in OpenConnector's manifest, and paired with a
consumer-side migration off resources first.

## SLA
Not applicable — this is a synchronous in-process PHP call, not a networked
service. No response-time or availability SLA applies beyond existing
`FileService` behaviour.
