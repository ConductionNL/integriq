# Tasks — POST-body sources + body-based pagination (oc#94)

> Verified the exact `CallService::call()` phase ordering at HEAD with a
> throwaway diagnostic PHPUnit test before writing the fix (see design.md
> `Context`) — confirmed a Source-level `configuration.listMethod` override
> was silently ignored, not merely "pagination can't advance" as originally
> scoped. Both the method-ordering fix and body-pagination are implemented
> together since the former blocks the latter from ever reaching page 1.

- [x] Diagnostic: confirm (and then, after the fix, re-confirm) that a
  Source's `configuration.listMethod` override reaches `decideMethod()` —
  proved broken before, fixed after, full suite green throughout
- [x] Move `decideMethod()` + the override-key `unset()` in `CallService::
  call()` to run AFTER `mergeSourceConfiguration()` (Phase 7) instead of
  before it (previously Phase 2) — `guardCallPreconditions()` reads only
  `sourceData`, confirmed order-independent; brokered-dispatch resolution
  and the preRequest hook confirmed not to depend on `$method` being
  pre-resolved
- [x] Add `sourceConfig.paginationIn` (default `"query"`) threading:
  `SynchronizationService::getNextPage()` sets
  `$config['pagination']['paginationIn']` alongside the existing
  `paginationQuery`/`page`
- [x] Add `CallService::applyBodyPagination()`: decodes `$config['body']`
  as JSON (empty object if missing/invalid), sets the `paginationQuery`
  dot-path to the current page via `Adbar\Dot`, re-encodes; wired into
  `normaliseRequestConfig()`'s pagination branch when `paginationIn ===
  "body"`
- [x] Document `sourceConfig.paginationIn` inline in
  `lib/Settings/openconnector_register.json` — no schema shape change
  (`sourceConfig` is already a free-form object)
- [x] Unit tests (`CallServiceTest`, using the existing brokered-dispatch
  test seam to avoid live network calls): Source-level `listMethod`
  override promotes a default-GET call to POST with the static body sent
  correctly; a source without the override still dispatches GET
  (regression); body-pagination substitutes the page value across three
  consecutive calls while leaving every other body field untouched;
  `paginationIn: "body"` with no static body template still produces
  `{"page": N}`; query-string pagination (`paginationIn` omitted) is
  unaffected
- [x] Unit tests (`SynchronizationServiceTest`): a real multi-page
  `getAllObjectsFromApi()` run threads `paginationIn: "body"` +
  incrementing `page` into `CallService::call()`'s config for every page
  after the first; a matching regression test proves the default
  (`"query"`) path is threaded unchanged when `paginationIn` is omitted
- [x] `composer phpcs` + `composer phpstan` clean on the touched files
- [x] Full existing PHPUnit suite green (417/417) — no regressions from the
  phase-ordering move or from the co-located bulk-gzip-jsonl-ingestion change

Acceptance criteria (plain bullets — verified by /opsx-verify):

- A Source whose `configuration.listMethod` is `"POST"` dispatches as POST
  even when the caller (mirroring `SynchronizationService`) never passes an
  explicit `method:` argument
- The Source's static `configuration.body` JSON template is sent as the
  actual request body byte-for-byte
- A synchronization with `sourceConfig.paginationIn: "body"` and
  `paginationQuery: "page"` re-renders the request body with the correct
  page number for pages 2..N, leaving every other body field untouched
- A source with no method override, or a synchronization with no
  `paginationIn`, behaves byte-for-byte as before this change (both proven
  by regression tests in the same PHPUnit run as the new feature tests)
