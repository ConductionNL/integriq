---
kind: code
depends_on: []
---

# openconnector — POST-body sources + body-based pagination (oc#94)

## Why

TED (Tenders Electronic Daily, the EU-wide procurement notice aggregator)'s
v3 search endpoint is POST-only with a JSON request body (`GET` returns
405, confirmed live). `spectr/connectors/ted_eu.json` works around this
today by baking the fixed search query onto the Source's own
`configuration.listMethod: "POST"` + `configuration.body`. Its own
`$comment` documents two findings from that verification, one of which
turns out to be worse than described:

1. **Pagination cannot advance.** `OpenConnector`'s page-increment
   pagination only ever rewrites a query-string parameter
   (`CallService::normaliseRequestConfig()`: `$config['query']
   [$config['pagination']['paginationQuery']] = $config['pagination']
   ['page'];`) — it has no way to bump a field inside a JSON request body.
   `ted_eu.json`'s search query is baked into the body as a static
   `"page":1`, so **the synchronization can only ever fetch page 1**,
   forever, regardless of `maxPages`.
2. **The method override does not even reach page 1.** Diagnostic testing
   against HEAD (see design.md) proved `CallService::call()` resolves the
   HTTP method (`decideMethod()`) BEFORE merging the source's own
   `configuration` (`mergeSourceConfiguration()`) — so a Source-level
   `configuration.listMethod: "POST"` override, with no explicit `method:`
   argument from the caller (exactly how
   `SynchronizationService::callSourceObject()` invokes `call()`), was
   **silently ignored**: the dispatched method stayed the default `GET`,
   which TED's real API rejects with 405. `ted_eu.json`'s own `$comment`
   candidly notes it was "NOT live-verified end-to-end... verified against
   one real POST response captured 2026-07-05" — that capture was a
   manually-crafted POST outside the engine, not a real synchronization run,
   which is exactly why this ordering bug went unnoticed.

## What Changes

- **Fix: `configuration.listMethod`/`createMethod`/`updateMethod`/
  `destroyMethod`/`readMethod` overrides now actually take effect.**
  `CallService::call()`'s method-resolution step (`decideMethod()` + the
  override-key `unset()`) is moved to run AFTER `mergeSourceConfiguration()`
  instead of before it, so a Source's own configured method override is
  visible when the method is decided — not just a call-time
  `config['listMethod']` override (the only thing that worked before). The
  override-key unset also now correctly strips these keys from the merged
  config before they can leak into the persisted `call_log.request`
  envelope. A source with no method override keeps dispatching its default
  (`GET` for a list/fetch call) byte-for-byte.
- **Body-based pagination**: `Synchronization.sourceConfig.paginationIn:
  "body"` (default, unchanged: `"query"`) tells the pagination step to
  substitute the next page value into the source's JSON body template at
  the `paginationQuery` dot-path, instead of the query string.
  `CallService::normaliseRequestConfig()` decodes the per-call-rendered
  static body (`mergeSourceConfiguration()` re-merges it fresh from source
  configuration on every call — never accumulated across pages, so
  decode → bump one path → re-encode cannot compound), sets the path via
  `Adbar\Dot`, and re-encodes.
- **No change to query-string pagination.** Every existing source (the
  overwhelming majority) has no `paginationIn` key, defaults to `"query"`,
  and takes the identical pre-existing code path.

## Impact

- Affected specs: `http-call-engine` (new REQ-006: method resolution timing
  + body-based pagination).
- Affected code: `lib/Service/CallService.php` (`call()`'s phase ordering,
  new `applyBodyPagination()` helper), `lib/Service/
  SynchronizationService.php` (`getNextPage()` threads `paginationIn`
  through to `CallService`), `lib/Settings/openconnector_register.json`
  (`sourceConfig.paginationIn` documented, no schema shape change), unit
  tests.
- Not affected: SOAP dispatch, the brokered-credential dispatch branch
  (`source-broker-credentials` — `paginationIn`/method-ordering both apply
  identically on the brokered path, proven by tests using that same
  dispatch seam), query-string pagination, any source without a method
  override.
- Unblocks (follow-up, not this change): flipping `spectr/connectors/
  ted_eu.json` from a page-1-only, not-live-verified draft to a fully
  paginated, live synchronization (still needs the language-dict/
  country-code data-quality caveats documented in that file's own
  `$comment` addressed separately — out of scope here).
