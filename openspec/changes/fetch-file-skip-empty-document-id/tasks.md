# Tasks: fetch-file-skip-empty-document-id

- [x] `SynchronizationService::hasEmptyPathSegment()` — path only; scheme, query and fragment ignored.
- [x] `skipEmptyPathSegment()` in `fetchFileSafely()` (single, assoc, job paths) and in `resolveMultiFileWorkItems()` (indexed and multi paths): warning + `skipped` tally, no call.
- [x] Tally reports `skipped`; the rule outcome line names it.
- [x] Golden cases: single, absolute, double slash only in the query (not skipped), assoc, indexed, multi, job entry.
- [ ] Rig check on Nextcloud 35: two runs against a mock source with one empty document id → one object per case, zero `//download` requests at the source, the warning in nextcloud.log.
