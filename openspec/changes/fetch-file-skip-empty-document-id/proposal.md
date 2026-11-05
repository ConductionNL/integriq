# fetch-file-skip-empty-document-id

## Why

A case system handed a synchronization an attachment whose download endpoint was
`/api/v1/case/<zaak>/document//download`: the document id was empty. The `fetch_file` rule
passed it straight to the source, which answered 500 `Document UUID "" invalid` on every
run (Dijkenwaard, 2026-09-25, four times in the call logs). On the 0.2 line that exception
also fired before the contract was persisted, so each run created a fresh object; REQ-021
(the contract is persisted before the after-rules run) already closed that half. What
remained was a request that can never succeed, repeated on every run, reported as "failed"
as if the download had been tried, and indistinguishable in the tally from a source that
is down.

## What changes

- Before any request is made, an endpoint whose PATH contains an empty segment (`//`
  outside the scheme separator, a protocol-relative `//host` start, the query string and
  the fragment) is refused. No call reaches the source. This also refuses a key with an
  empty segment of its own (`bucket//key.pdf`), which cannot be told apart from a missing
  id; the spec states that trade-off.
- The refusal is logged as a warning naming the endpoint and the object, and counted in a
  new `skipped` bucket of the per-rule file tally, next to `succeeded` and `failed`. The
  rule engine's outcome line reports it (`files saved: N, failed: M, skipped: K`); the Flow
  engine's fetch-file step keeps no tally, so there the warning is the record.
- Applies on every path: a single endpoint, an attachment object, a list of endpoints or
  objects (the concurrent fetcher) and the queued `async` job, because they all resolve
  the endpoint through `fetchFileSafely()` or `resolveMultiFileWorkItems()`.
- A refused attachment in a list keeps its slot for the file cleanup, as a failed
  download already does, so a file stored for it on an earlier run is not deleted — and
  an object whose attachments are all refused keeps all its files.
- The contract is unaffected: REQ-021 of `synchronization-engine` already persists it
  before the after-rules run.

## Impact

No route, schema or version change. The rule engine's outcome line gains `, skipped: K`;
nothing in the app or its docs parses that line. The golden characterization of the
fetch_file rule gains ten cases and a `skipped` tally field. Tracked as WOO-583.
