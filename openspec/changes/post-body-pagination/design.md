# Design — POST-body sources + body-based pagination

## Context

Verified against HEAD (`origin/development`, `44755371`):

- `CallService::call()` (`lib/Service/CallService.php:1541`) ran, pre-change,
  in this order: Phase 1 (expiry) → **Phase 2: `decideMethod()` + unset
  override keys** → Phases 3-6 (`guardCallPreconditions()`, reads only
  `$sourceData`, never `$config`) → **Phase 7: `mergeSourceConfiguration()`**
  → Phase 7b (brokered-credential resolution) → Phase 8 (preRequest hook) →
  Phase 9 (`normaliseRequestConfig()` — pagination substitution, Twig
  render, cert materialisation, auth-key stripping) → Phase 10 (dispatch).
- `decideMethod(string $default, array $configuration, bool $read)`
  (`CallService.php:275`) reads `$configuration['listMethod']` (etc.) — but
  at Phase 2 time, `$configuration` is the CALL-level `$config` argument
  only; the source's `configuration.listMethod` has not been merged in yet
  (that happens two phases later, at Phase 7). A diagnostic test
  constructing a source with `configuration.listMethod: 'POST'` and calling
  `service->call(source: $source, endpoint: '/x')` with no `method:`
  argument (exactly how `SynchronizationService::callSourceObject()` always
  invokes it) proved the dispatched method stayed `'GET'` — confirmed via a
  throwaway PHPUnit test run against HEAD before writing the fix, removed
  afterwards.
- `mergeSourceConfiguration()` (`CallService.php:562`) does
  `array_merge_recursive($config, $this->applyConfigDot($sourceData
  ['configuration']))`. Important PHP subtlety: for a scalar-valued key
  present in BOTH arrays, `array_merge_recursive` does NOT overwrite — it
  turns the value into `[callValue, sourceValue]`. This does not bite today
  because `SynchronizationService` never sets `listMethod` et al. in its
  per-page `$config` (only `headers`/`query`/`body`/`pagination`), so the
  merge is always a clean scalar add from source configuration with no
  collision. Pre-existing behaviour, not introduced by this change.
- Pagination substitution lives in
  `CallService::normaliseRequestConfig()` (`CallService.php:747`):
  `if (isset($config['pagination'])) { $config['query'][$config
  ['pagination']['paginationQuery']] = $config['pagination']['page']; }` —
  query-string only, no body path.
  `SynchronizationService::getNextPage()` (`SynchronizationService.php`,
  called from `getNextPageInfo()` inside `fetchAllPagesOptimized()`'s
  per-page loop) is what sets `$config['pagination']` for the next page.
- `mergeSourceConfiguration()` already merges `configuration.body` (a JSON
  string template) into `$config['body']` fresh on every call — this part
  already worked; `dispatchRequest()` passes `$config` (including `body`)
  straight to Guzzle's `$this->client->request($method, $url, $config)`,
  and Guzzle's `body` request option is exactly what's needed. `renderValue()`
  only Twig-renders a string containing BOTH `{{` and `}}` — a plain JSON
  body string (single braces) passes through untouched, confirmed by
  `ted_eu.json`'s static body template rendering byte-for-byte.

## Decisions

- **Fix the method-resolution ordering rather than work around it in
  `SynchronizationService`.** The alternative — have
  `SynchronizationService` read `source.configuration.listMethod` itself
  and pass `method: 'POST'` explicitly into `callSourceObject()` — would
  duplicate `decideMethod()`'s CRUD-override logic (`createMethod`/
  `updateMethod`/`destroyMethod`/`listMethod`/`readMethod`, `$read`
  branching) in a second place, and wouldn't fix the same latent bug for
  any OTHER caller of `CallService::call()`. Moving `decideMethod()` + its
  paired `unset()` to run on the MERGED config (right after Phase 7,
  renumbered Phase 7a) is a minimal, localised fix: `guardCallPreconditions`
  doesn't depend on `$config` at all (only `$sourceData`), and
  `resolveBrokeredDispatch`/`extractAndFirePreRequest` don't depend on
  `$method` being already resolved, so nothing downstream cares about the
  reordering except the thing that was actually broken. Full existing
  PHPUnit suite (417 tests, all pre-existing plus this change's new ones)
  is green after the move — no observed regression.
- **The override-key `unset()` moves with it, and this is itself a
  latent-bug fix.** Before this change, a method override declared ONLY in
  source `configuration` was never stripped (the `unset()` ran on the
  pre-merge config, where the key didn't exist yet) — it would have leaked
  into the persisted `call_log.request` envelope. Running the unset on the
  merged config now correctly cleans it, same as it always did for a
  call-time override.
- **`paginationIn` lives on `Synchronization.sourceConfig`, alongside
  `paginationQuery`** — not on `Source.configuration` — because pagination
  strategy is a per-synchronization concern (how THIS sync advances through
  THIS source), matching where `resultsPosition`/`maxPages`/
  `paginationQuery` already live. `getNextPage()` threads it into
  `$config['pagination']['paginationIn']` alongside the existing
  `paginationQuery`/`page` keys; `normaliseRequestConfig()` branches on it.
- **Body substitution decodes fresh, bumps one path, re-encodes — it does
  not accumulate.** Because `mergeSourceConfiguration()` re-merges the
  source's static `configuration.body` template into `$config['body']` on
  EVERY call (not threaded/mutated across pages), `applyBodyPagination()`
  always starts from the clean static template for the current page, sets
  exactly one dot-path (`Adbar\Dot`, already imported in `CallService`) to
  the current page number, and re-encodes. Page N's body can never
  contain leftover mutations from page N-1.
- **Missing/invalid body still gets a sane result.** If
  `paginationIn: "body"` is set but there's no static
  `configuration.body` (or it isn't valid JSON), `applyBodyPagination()`
  starts from an empty object rather than silently dropping the page
  directive — the dispatched body becomes `{"page": N}` at minimum.
- **Query-string pagination is completely untouched.** The branch is
  `if (($config['pagination']['paginationIn'] ?? 'query') === 'body') {
  ... } else { /* original one-liner, unchanged */ }` — a source with no
  `paginationIn` takes the exact original code.

## Backward compatibility

- A source with no method override (`configuration.listMethod` etc. absent)
  dispatches its caller-supplied default method exactly as before — proven
  by a dedicated regression test.
- A synchronization with no `sourceConfig.paginationIn` defaults to
  `"query"` and substitutes the page number into the query string exactly
  as before — proven by a dedicated regression test plus the pre-existing
  `testBrokeredPaginationOneRequestPerPageWithRateLimitTracking` (unchanged,
  still green).
- Both fixes apply identically on the brokered-credential dispatch path
  (`source-broker-credentials`) since they run in `CallService::call()`
  before the Guzzle-vs-broker branch point — proven by tests exercising the
  brokered `dispatch()` seam.
