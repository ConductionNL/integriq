# Tasks: fetch-file-skip-empty-document-id

- [x] `SynchronizationService::hasEmptyPathSegment()` — path only; scheme, query and fragment ignored.
- [x] `skipEmptyPathSegment()` in `fetchFileSafely()` (single, assoc, job paths) and in `resolveMultiFileWorkItems()` (indexed and multi paths): warning + `skipped` tally, no call.
- [x] Tally reports `skipped`; the rule outcome line names it.
- [x] Golden cases: single, absolute, double slash only in the query (not skipped), assoc, indexed, multi, job entry, protocol-relative (not skipped), stored file on a refused slot, every item refused.
- [x] A refused list item keeps its slot for the file cleanup (`trackingFilenameFor()`); golden cases for a stored file on a refused slot and for every item refused.
- [x] A protocol-relative `//host` start is not an empty segment; golden case.
- [x] Rig check on Nextcloud 35: two runs against a mock source with one empty document id → one object per case, zero `//download` requests at the source, the warning in nextcloud.log.
- [ ] After the release on a WOO environment: a sync over the case with the empty document id leaves one object and no 500 in the call log.
