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
  outside the scheme separator, query string and fragment) is refused. No call reaches the
  source.
- The refusal is logged as a warning naming the endpoint and the object, and counted in a
  new `skipped` bucket of the per-rule file tally, next to `succeeded` and `failed`. The
  rule's outcome line reports it (`files saved: N, failed: M, skipped: K`).
- Applies on every path: a single endpoint, an attachment object, a list of endpoints or
  objects (the concurrent fetcher) and the queued `async` job, because they all resolve
  the endpoint through `fetchFileSafely()` or `resolveMultiFileWorkItems()`.
- The object and its contract are unaffected: the file is simply absent, as REQ-021
  already guarantees for a failed download.

## Impact

No route, schema or version change. The golden characterization of the fetch_file rule
gains seven cases and a `skipped` tally field. Tracked as WOO-583.
