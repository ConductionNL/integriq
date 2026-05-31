# Retrofit — storage-uploads

Describes observed behavior of 4 methods under `storage-uploads` as 4 new REQs. Code already exists — this change retroactively specifies it.

## Affected code units

- `lib/Service/StorageService.php::createUpload()`
- `lib/Service/StorageService.php::writeFile()`
- `lib/Service/StorageService.php::writePart()`
- `lib/Service/StorageService.php::attemptCloseUpload()` (private helper of writePart)

## Approach

- For each method: describe observed inputs, outputs, pre/postconditions, failure modes
- Draft REQs that match behavior (not aspirational)
- Notes section surfaces observed-but-suspicious behavior (undeclared `userSession` property in `writeFile`; full-file in-memory reconciliation; cache-derived path traversal surface)

Source: openspec/coverage-report.md generated 2026-05-24. See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
